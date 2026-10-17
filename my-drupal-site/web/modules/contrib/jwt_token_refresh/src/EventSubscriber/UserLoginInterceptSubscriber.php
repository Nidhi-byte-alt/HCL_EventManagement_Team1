<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface;
use Drupal\jwt_token_refresh\Service\RefreshTokenManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Opt-in: adds a refresh token to the core /user/login JSON response.
 *
 * The jwt_auth_issuer submodule can already add the access_token JWT
 * to /user/login?_format=json (its jwt_in_login_response setting), but
 * it has no concept of refresh. When inject_into_user_login is enabled,
 * this subscriber adds refresh_token / refresh_expires_in / token_type
 * (and access_token too, if the issuer did not), so a frontend can keep
 * using the core login endpoint and still get a refresh-capable
 * session.
 */
final class UserLoginInterceptSubscriber implements EventSubscriberInterface {

  /**
   * The core user-login JSON route.
   */
  private const ROUTE = 'user.login.http';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly JwtAuth $jwtAuth,
    private readonly RefreshTokenManagerInterface $refreshTokens,
    private readonly TimeInterface $time,
    private readonly AccessTokenSessionManagerInterface $accessSessions,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

  /**
   * Adds tokens to a successful core login response.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if (!$this->configFactory->get('jwt_token_refresh.settings')
      ->get('inject_into_user_login')) {
      return;
    }
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== self::ROUTE) {
      return;
    }
    $response = $event->getResponse();
    if ($response->getStatusCode() !== 200) {
      return;
    }
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return;
    }
    $data = json_decode((string) $response->getContent(), TRUE);
    if (!is_array($data)) {
      return;
    }

    // Add an access_token only if the issuer did not already. current_user
    // is the just-authenticated account, so the JWT is minted for them.
    if (empty($data['access_token'])) {
      // generateToken() is documented to return FALSE on failure but hands
      // back whatever the transcoder returns, which is NULL when no key is
      // usable; it also throws outright on some misconfigurations. Core has
      // already logged the user in by now, so a failure here must leave the
      // response as core built it rather than turn a successful login into a
      // 500.
      try {
        $jwt = $this->jwtAuth->generateToken();
      }
      catch (\Throwable $e) {
        $this->logger->error('JWT generation error on user login: @message', [
          '@message' => $e->getMessage(),
        ]);
        return;
      }
      if (!is_string($jwt) || $jwt === '') {
        $this->logger->error('JWT generation failed on user login; no token added to the response. Check that a JWT signing key is configured.');
        return;
      }
      $data['access_token'] = $jwt;
    }

    $pair = $this->refreshTokens->create($uid);

    // Link the access token session (jti) issued during this login to
    // the refresh token just created, so revoking the refresh cascades.
    $jti = $request->attributes->get(
      JwtClaimsSubscriber::ISSUED_JTI_ATTRIBUTE,
    );
    if (is_string($jti) && $jti !== '') {
      $this->accessSessions->linkToRefreshToken($jti, $pair['id']);
    }

    $data['refresh_token'] = $pair['token'];
    $data['refresh_expires_in'] =
      $pair['expires'] - $this->time->getRequestTime();
    $data['token_type'] = 'Bearer';

    $response->setContent((string) json_encode($data));
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Pragma', 'no-cache');
  }

}
