<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\jwt_token_refresh\Event\JwtLoginResponseEvent;
use Drupal\jwt_token_refresh\Event\JwtRefreshResponseEvent;
use Drupal\jwt_token_refresh\Event\JwtTokenRefreshEvents;
use Drupal\jwt_token_refresh\EventSubscriber\JwtClaimsSubscriber;
use Drupal\jwt_token_refresh\Service\PasswordRehashFlag;
use Drupal\jwt_token_refresh\Service\RefreshTokenManagerInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login, refresh and revoke endpoints for JWT + refresh tokens.
 *
 * All token responses carry Cache-Control: no-store (RFC 6749 §5.1).
 * Errors use a single anti-enumeration code per endpoint.
 */
final class TokenController implements ContainerInjectionInterface {

  /**
   * Flood identifiers.
   */
  private const FLOOD_LOGIN_IP = 'jwt_token_refresh.login_ip';
  private const FLOOD_LOGIN_USER = 'jwt_token_refresh.login_user';
  private const FLOOD_REFRESH_IP = 'jwt_token_refresh.refresh_ip';

  /**
   * Flood windows (seconds), aligned on core user.flood defaults.
   */
  private const WINDOW_IP = 3600;
  private const WINDOW_USER = 21600;

  /**
   * State key holding the hash used to equalize timing for unknown users.
   */
  private const DUMMY_HASH_STATE = 'jwt_token_refresh.dummy_hash';

  public function __construct(
    private readonly RefreshTokenManagerInterface $refreshTokens,
    private readonly JwtAuth $jwtAuth,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PasswordInterface $passwordChecker,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly PasswordRehashFlag $rehashFlag,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jwt_token_refresh.refresh_token_manager'),
      $container->get('jwt.authentication.jwt'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('password'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('logger.channel.jwt_token_refresh'),
      $container->get('event_dispatcher'),
      $container->get('database'),
      $container->get('state'),
      $container->get(PasswordRehashFlag::class),
    );
  }

  /**
   * POST /auth/token — authenticate and issue an access + refresh pair.
   */
  public function login(Request $request): JsonResponse {
    $config = $this->settings();
    $ip = $request->getClientIp() ?? '';

    if (!$this->flood->isAllowed(
      self::FLOOD_LOGIN_IP,
      (int) $config->get('flood_ip_limit'),
      self::WINDOW_IP,
      $ip,
    )) {
      return $this->tooManyAttempts();
    }

    [$name, $pass] = $this->credentials($request);
    if ($name === NULL || $pass === NULL) {
      return $this->error('validation_failed', 'Malformed request.', 400);
    }

    // The account has to be loaded before the counter is consulted, because
    // the counter is keyed on its id.
    $candidate = $this->lookup($name);
    $floodUser = $this->floodIdentifier($candidate, $name, $ip);
    if (!$this->flood->isAllowed(
      self::FLOOD_LOGIN_USER,
      (int) $config->get('flood_user_limit'),
      self::WINDOW_USER,
      $floodUser,
    )) {
      return $this->tooManyAttempts();
    }

    $account = $this->authenticate($candidate, $pass);
    if (!$account instanceof UserInterface) {
      $this->flood->register(self::FLOOD_LOGIN_IP, self::WINDOW_IP, $ip);
      $this->flood->register(
        self::FLOOD_LOGIN_USER,
        self::WINDOW_USER,
        $floodUser,
      );
      return $this->error(
        'invalid_credentials',
        'Invalid credentials.',
        401,
      );
    }

    // Successful login clears the per-user counter.
    $this->flood->clear(self::FLOOD_LOGIN_USER, $floodUser);

    // Wrap the refresh-token creation and the JWT generation in one
    // transaction: if generation fails, the refresh token is rolled
    // back so no half-issued state survives.
    $transaction = $this->database->startTransaction();
    $pair = $this->refreshTokens->create((int) $account->id());
    return $this->issue($request, $account, $pair, FALSE, $transaction);
  }

  /**
   * POST /auth/token/refresh — rotate a refresh token, issue a new pair.
   */
  public function refresh(Request $request): JsonResponse {
    $config = $this->settings();
    $ip = $request->getClientIp() ?? '';

    if (!$this->flood->isAllowed(
      self::FLOOD_REFRESH_IP,
      (int) $config->get('flood_ip_limit'),
      self::WINDOW_IP,
      $ip,
    )) {
      return $this->tooManyAttempts();
    }

    $data = json_decode((string) $request->getContent(), TRUE);
    $raw = is_array($data) ? ($data['refresh_token'] ?? NULL) : NULL;

    // Malformed input is reported as invalid_token: no state leak, one
    // code for every refresh failure.
    if (!is_string($raw) || $raw === '') {
      $this->flood->register(self::FLOOD_REFRESH_IP, self::WINDOW_IP, $ip);
      return $this->error('invalid_token', 'Invalid or expired token.', 401);
    }

    // The rotation and the JWT generation share one transaction so a
    // generation failure rolls the rotation back (the old token stays
    // usable instead of being burned). A replay returns NULL after
    // revoking the family — that revocation must commit, so it is not
    // rolled back.
    $transaction = $this->database->startTransaction();
    $pair = $this->refreshTokens->rotate($raw);
    if ($pair === NULL) {
      $this->flood->register(self::FLOOD_REFRESH_IP, self::WINDOW_IP, $ip);
      return $this->error('invalid_token', 'Invalid or expired token.', 401);
    }

    $uid = $this->refreshTokens->validate($pair['token']);
    $account = $uid === NULL
      ? NULL
      : $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return $this->error('invalid_token', 'Invalid or expired token.', 401);
    }

    return $this->issue($request, $account, $pair, TRUE, $transaction);
  }

  /**
   * POST /auth/token/revoke — logout (Bearer only, idempotent 204).
   */
  public function revoke(Request $request): Response {
    $uid = (int) $this->currentUser->id();
    $data = json_decode((string) $request->getContent(), TRUE);
    $raw = is_array($data) ? ($data['refresh_token'] ?? NULL) : NULL;

    if (is_string($raw) && $raw !== '') {
      // Only revoke a token that belongs to the caller — never expose
      // an oracle on another user's token state.
      if ($this->refreshTokens->validate($raw) === $uid) {
        $this->refreshTokens->revoke($raw);
      }
    }
    else {
      // No token given: full logout for the authenticated caller.
      $this->refreshTokens->revokeAllForUser($uid);
    }

    $response = new Response('', Response::HTTP_NO_CONTENT);
    $this->noStore($response);
    return $response;
  }

  /**
   * Issues an access + refresh pair for an account.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request, used to expose the issuing refresh token id.
   * @param \Drupal\user\UserInterface $account
   *   The authenticated account.
   * @param array{token: string, expires: int, id: int} $pair
   *   The refresh pair already minted by the caller (login or rotation).
   * @param bool $isRefresh
   *   TRUE for the refresh flow, FALSE for login. Selects which event
   *   is dispatched so subscribers can enrich the body.
   * @param \Drupal\Core\Database\Transaction $transaction
   *   The open transaction; rolled back if JWT generation fails.
   */
  private function issue(
    Request $request,
    UserInterface $account,
    array $pair,
    bool $isRefresh,
    Transaction $transaction,
  ): JsonResponse {
    // The JWT issuer reads the current user; set it without starting a
    // Drupal session (stateless — no cookie).
    $this->currentUser->setAccount($account);

    // Expose the issuing refresh token id so the jti session links to it.
    $request->attributes->set(
      JwtClaimsSubscriber::REFRESH_TOKEN_ID_ATTRIBUTE,
      $pair['id'],
    );

    // generateToken() is documented to return FALSE on failure, but it
    // returns the transcoder's ?string, so a missing key yields NULL; it may
    // also throw when the key is misconfigured. Treat all three the same, and
    // keep the reason for later rather than logging it here: see below.
    $reason = NULL;
    try {
      $jwt = $this->jwtAuth->generateToken();
    }
    catch (\Throwable $e) {
      $jwt = NULL;
      $reason = $e->getMessage();
    }
    if (!is_string($jwt) || $jwt === '') {
      // Undo the refresh token mutation so the caller is not left in a
      // half-issued state (e.g. a rotation that burned the old token).
      $transaction->rollBack();
      // Log only now: a log written before the rollback is written inside the
      // transaction, and the rollback discards it along with the one message
      // naming the cause. The response stays deliberately vague; the log is
      // where the reason belongs.
      $this->logger->error('Token generation failed, rolled back: @reason', [
        '@reason' => $reason ?? 'the JWT issuer returned no token, which usually means no signing key is configured.',
      ]);
      return $this->error(
        'internal_error',
        'Token generation failed.',
        500,
      );
    }

    $data = [
      'access_token' => $jwt,
      'refresh_token' => $pair['token'],
      'refresh_expires_in' => $pair['expires'] - $this->time->getRequestTime(),
      'token_type' => 'Bearer',
    ];

    // Let application modules enrich the body.
    $event = $isRefresh
      ? new JwtRefreshResponseEvent($account, $data)
      : new JwtLoginResponseEvent($account, $data);
    $this->eventDispatcher->dispatch(
      $event,
      $isRefresh
        ? JwtTokenRefreshEvents::REFRESH_RESPONSE
        : JwtTokenRefreshEvents::LOGIN_RESPONSE,
    );

    $response = new JsonResponse($event->getResponseData());
    $this->noStore($response);
    return $response;
  }

  /**
   * Loads the account a submitted name refers to, if there is one.
   */
  private function lookup(string $name): ?UserInterface {
    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => $name]);
    $account = $accounts ? reset($accounts) : NULL;

    return $account instanceof UserInterface && (int) $account->id() !== 0
      ? $account
      : NULL;
  }

  /**
   * The per-user flood key: the account id, never the name as typed.
   *
   * The identifier column of the flood table is ASCII, so a name such as Zoë
   * makes isAllowed() throw on MySQL and MariaDB and costs that user a 500
   * with the right password. The same column compares case-sensitively on
   * PostgreSQL and SQLite, where user names do not, so "Admin" and "admin"
   * would reach one account through two counters. Core keys on the account id
   * for both reasons.
   *
   * Core then leaves an unknown name with no per-user counter at all, which
   * turns the 429 into an enumeration oracle: a name that can be flooded
   * exists. This endpoint answers the same way for every name, so an unknown
   * one is keyed on a hash of it instead — ASCII, fixed width, and case-folded
   * so that it counts the way a real lookup would have.
   *
   * @see \Drupal\user\Controller\UserAuthenticationController::getLoginFloodIdentifier()
   */
  private function floodIdentifier(
    ?UserInterface $account,
    string $name,
    string $ip,
  ): string {
    $key = $account instanceof UserInterface
      ? (string) $account->id()
      : hash('sha256', mb_strtolower($name));

    return $key . '-' . $ip;
  }

  /**
   * Validates a password in constant time, returns the account or NULL.
   */
  private function authenticate(
    ?UserInterface $account,
    #[\SensitiveParameter] string $pass,
  ): ?UserInterface {
    if (!$account instanceof UserInterface) {
      // Unknown user: burn equivalent time to defeat enumeration.
      $this->passwordChecker->check($pass, $this->dummyHash());
      return NULL;
    }

    $hash = $account->getPassword();
    if (!$this->passwordChecker->check($pass, $hash) || !$account->isActive()) {
      return NULL;
    }

    $this->rehash($account, $pass, $hash);
    return $account;
  }

  /**
   * A dummy hash that follows the site's own hashing settings.
   *
   * Hard-coding the hash freezes the cost it was generated with. Against a
   * cost 10 hash password_verify() returns in a quarter of the time a cost 12
   * hash takes, and 12 is PHP's default from 8.4, so the constant this
   * replaces told a known name from an unknown one by latency alone — the
   * opposite of what it was there for. Hashing a throwaway string with the
   * password service follows whatever the site hashes with, and keeping the
   * result in state costs one hash when the settings change rather than one
   * per login of an unknown user.
   */
  private function dummyHash(): string {
    $hash = $this->state->get(self::DUMMY_HASH_STATE);
    if (is_string($hash) && $hash !== ''
      && !$this->passwordChecker->needsRehash($hash)) {
      return $hash;
    }

    // hash() is documented to return FALSE on failure; the cast turns that
    // into the empty string.
    $fresh = (string) $this->passwordChecker->hash(
      base64_encode(random_bytes(32)),
    );
    if ($fresh === '') {
      // Keep whatever is stored rather than checking against an empty string,
      // which check() rejects without touching a hash and therefore times
      // differently.
      return is_string($hash) ? $hash : '';
    }

    $this->state->set(self::DUMMY_HASH_STATE, $fresh);
    return $fresh;
  }

  /**
   * Re-hashes a password whose stored hash is outdated, as core login does.
   *
   * Without this, a user who only ever signs in here keeps the hash their
   * account was created with, through any later change of algorithm or cost.
   *
   * @see \Drupal\user\UserAuthentication::authenticateAccount()
   */
  private function rehash(
    UserInterface $account,
    #[\SensitiveParameter] string $pass,
    ?string $hash,
  ): void {
    if (!$this->passwordChecker->needsRehash($hash)) {
      return;
    }

    $uid = (int) $account->id();
    // The save below is this module re-encoding a password, not a user
    // choosing a new one, and hook_user_update() cannot tell those apart from
    // the hash alone. Unflagged, it would revoke every refresh token of this
    // user and log their other devices out the day the site changes its
    // hashing settings.
    $this->rehashFlag->flag($uid);
    try {
      $account->setPassword($pass);
      $account->save();
    }
    catch (\Throwable $e) {
      // A valid login must not fail because the rehash could not be stored.
      $this->rehashFlag->take($uid);
      $this->logger->warning(
        'Could not rehash the password of user @uid: @reason',
        ['@uid' => $uid, '@reason' => $e->getMessage()],
      );
    }
  }

  /**
   * Extracts {name, pass} from a JSON body.
   *
   * @return array{0: string|null, 1: string|null}
   *   The username and password, or [NULL, NULL] if malformed.
   */
  private function credentials(Request $request): array {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return [NULL, NULL];
    }
    $name = $data['name'] ?? NULL;
    $pass = $data['pass'] ?? NULL;
    if (!is_string($name) || !is_string($pass)
      || $name === '' || $pass === '') {
      return [NULL, NULL];
    }
    return [$name, $pass];
  }

  /**
   * The module settings.
   */
  private function settings(): ImmutableConfig {
    return $this->configFactory->get('jwt_token_refresh.settings');
  }

  /**
   * Builds a no-store JSON error.
   */
  private function error(string $code, string $message, int $status): JsonResponse {
    $response = new JsonResponse(
      ['error' => $code, 'message' => $message],
      $status,
    );
    $this->noStore($response);
    return $response;
  }

  /**
   * The shared 429 response.
   */
  private function tooManyAttempts(): JsonResponse {
    return $this->error(
      'too_many_attempts',
      'Too many attempts. Try again later.',
      429,
    );
  }

  /**
   * Applies no-store caching headers in place (RFC 6749 §5.1).
   */
  private function noStore(Response $response): void {
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Pragma', 'no-cache');
  }

}
