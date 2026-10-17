<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\jwt\JsonWebToken\JsonWebToken;
use Drupal\key\Entity\Key;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional tests for the token endpoints.
 *
 * Runs in an isolated install (no project routes), so /auth/token*
 * resolve to this module only.
 *
 * @group jwt_token_refresh
 */
#[RunTestsInSeparateProcesses]
final class TokenControllerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'jwt',
    'jwt_auth_issuer',
    'jwt_auth_consumer',
    'jwt_token_refresh',
    'jwt_token_refresh_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The test account.
   */
  private UserInterface $account;

  /**
   * The test account's raw password.
   */
  private string $pass;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A config-stored HMAC key so the JWT issuer can sign tokens.
    Key::create([
      'id' => 'jtr_test_key',
      'label' => 'JWT test key',
      'key_type' => 'jwt_hs',
      'key_type_settings' => ['algorithm' => 'HS512'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => str_repeat('k', 64)],
      'key_input' => 'text_field',
    ])->save();
    $this->config('jwt.config')->set('key_id', 'jtr_test_key')->save();

    // Set the password rather than reading ::passRaw, the dynamic property
    // drupalCreateUser() attaches: the type stays honest and no static
    // analysis exclusion is needed.
    $this->pass = $this->randomMachineName(16);
    $this->account = $this->drupalCreateUser();
    $this->account->setPassword($this->pass);
    $this->account->save();
  }

  /**
   * POSTs a JSON body and returns the raw response.
   *
   * @phpstan-param array<string, mixed> $body
   * @phpstan-param array<string, string> $headers
   */
  private function post(
    string $path,
    array $body,
    array $headers = [],
  ): ResponseInterface {
    return $this->getHttpClient()->request('POST', $this->getAbsoluteUrl($path), [
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'application/json'] + $headers,
      'body' => json_encode($body),
    ]);
  }

  /**
   * Decodes a JSON response body.
   *
   * @phpstan-return array<string, mixed>
   */
  private function json(ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), TRUE) ?? [];
  }

  /**
   * Logs in and returns the decoded token pair.
   *
   * @phpstan-return array<string, mixed>
   */
  private function login(): array {
    $response = $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    return $this->json($response);
  }

  /**
   * Login succeeds and the response is shaped + no-store.
   */
  public function testLoginSuccess(): void {
    $response = $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString(
      'no-store',
      $response->getHeaderLine('Cache-Control'),
    );
    $data = $this->json($response);
    $this->assertNotEmpty($data['access_token']);
    $this->assertNotEmpty($data['refresh_token']);
    $this->assertSame('Bearer', $data['token_type']);
    $this->assertGreaterThan(0, $data['refresh_expires_in']);
  }

  /**
   * Unknown user and wrong password are byte-for-byte identical.
   */
  public function testLoginAntiEnumeration(): void {
    $wrong = $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => 'definitely-wrong',
    ]);
    $unknown = $this->post('/auth/token', [
      'name' => 'does-not-exist',
      'pass' => 'whatever',
    ]);

    $this->assertSame(401, $wrong->getStatusCode());
    $this->assertSame(401, $unknown->getStatusCode());
    $this->assertSame('invalid_credentials', $this->json($wrong)['error']);
    $this->assertSame(
      (string) $wrong->getBody(),
      (string) $unknown->getBody(),
    );
  }

  /**
   * A malformed body is a 400 validation_failed.
   */
  public function testLoginMalformed(): void {
    $response = $this->post('/auth/token', ['nope' => 'nope']);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('validation_failed', $this->json($response)['error']);
  }

  /**
   * Refresh rotates, and replaying a rotated token kills the family.
   */
  public function testRefreshRotationAndReuseDetection(): void {
    $first = $this->login();

    $rotated = $this->post('/auth/token/refresh', [
      'refresh_token' => $first['refresh_token'],
    ]);
    $this->assertSame(200, $rotated->getStatusCode());
    $this->assertStringContainsString(
      'no-store',
      $rotated->getHeaderLine('Cache-Control'),
    );
    $new = $this->json($rotated);
    $this->assertNotEmpty($new['refresh_token']);

    // Replay the consumed token: 401 + reuse detection.
    $replay = $this->post('/auth/token/refresh', [
      'refresh_token' => $first['refresh_token'],
    ]);
    $this->assertSame(401, $replay->getStatusCode());
    $this->assertSame('invalid_token', $this->json($replay)['error']);

    // The whole family is revoked: the rotated token is dead too.
    $afterReuse = $this->post('/auth/token/refresh', [
      'refresh_token' => $new['refresh_token'],
    ]);
    $this->assertSame(401, $afterReuse->getStatusCode());
  }

  /**
   * Every refresh failure maps to the same code (no state leak).
   */
  public function testRefreshInvalidVariantsAreIdentical(): void {
    $absent = $this->post('/auth/token/refresh', []);
    $garbage = $this->post('/auth/token/refresh', [
      'refresh_token' => 'not-a-token',
    ]);

    foreach ([$absent, $garbage] as $response) {
      $this->assertSame(401, $response->getStatusCode());
      $this->assertSame('invalid_token', $this->json($response)['error']);
    }
  }

  /**
   * Revoke is Bearer-only, idempotent, and ends the session.
   */
  public function testRevoke(): void {
    $tokens = $this->login();
    $auth = ['Authorization' => 'Bearer ' . $tokens['access_token']];

    $revoked = $this->post('/auth/token/revoke', [], $auth);
    $this->assertSame(204, $revoked->getStatusCode());

    // The refresh token no longer works.
    $afterRevoke = $this->post('/auth/token/refresh', [
      'refresh_token' => $tokens['refresh_token'],
    ]);
    $this->assertSame(401, $afterRevoke->getStatusCode());

    // Revoking again is a silent 204 (no oracle).
    $again = $this->post('/auth/token/revoke', [], $auth);
    $this->assertSame(204, $again->getStatusCode());
  }

  /**
   * Revoke without a Bearer token is denied.
   */
  public function testRevokeRequiresBearer(): void {
    $response = $this->post('/auth/token/revoke', []);

    // No credential at all: the endpoint still answers in JSON, with the
    // envelope every other failure uses, rather than an HTML access-denied
    // page. This is the error case a frontend hits most often.
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame(
      'invalid_token',
      $this->json($response)['error'] ?? NULL,
    );
    $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    $this->assertStringContainsString(
      'no-store',
      $response->getHeaderLine('Cache-Control'),
    );
  }

  /**
   * An expired Bearer token yields a typed 401 token_expired.
   */
  public function testExpiredTokenIsTyped(): void {
    $jwt = new JsonWebToken();
    $jwt->setClaim('iat', time() - 3600);
    $jwt->setClaim('exp', time() - 1800);
    $jwt->setClaim(['drupal', 'uid'], (int) $this->account->id());
    $encoded = \Drupal::service('jwt.transcoder')->encode($jwt);

    $response = $this->post('/auth/token/revoke', [], [
      'Authorization' => 'Bearer ' . $encoded,
    ]);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('token_expired', $this->json($response)['error']);
  }

  /**
   * A malformed but JWT-shaped Bearer yields a typed 401 invalid_token.
   */
  public function testMalformedTokenIsTyped(): void {
    $response = $this->post('/auth/token/revoke', [], [
      'Authorization' => 'Bearer aaa.bbb.ccc',
    ]);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('invalid_token', $this->json($response)['error']);
  }

  /**
   * An opaque (non JWT-shaped) Bearer is left for other auth providers.
   */
  public function testOpaqueBearerIsNotHijacked(): void {
    $response = $this->post('/auth/token/revoke', [], [
      'Authorization' => 'Bearer opaque-token-without-dots',
    ]);
    // The module must not claim it as invalid_token; it falls through
    // to Drupal's access handling.
    $this->assertNotSame(
      'invalid_token',
      $this->json($response)['error'] ?? NULL,
    );
  }

  /**
   * Subscribers enrich the login and refresh response bodies.
   */
  public function testResponseEnrichedByEvents(): void {
    $login = $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);
    $data = $this->json($login);
    $this->assertSame((int) $this->account->id(), $data['login_marker']);

    $refresh = $this->post('/auth/token/refresh', [
      'refresh_token' => $data['refresh_token'],
    ]);
    $this->assertSame(
      (int) $this->account->id(),
      $this->json($refresh)['refresh_marker'],
    );
  }

  /**
   * Core /user/login carries no refresh token unless the flag is on.
   *
   * (jwt_auth_issuer may add an access_token on its own; the refresh
   * token is this module's opt-in contribution.)
   */
  public function testUserLoginInjectionDisabledByDefault(): void {
    $response = $this->post('/user/login?_format=json', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('refresh_token', $this->json($response));
  }

  /**
   * With the flag on, core /user/login gains a refresh token.
   */
  public function testUserLoginInjectionEnabled(): void {
    $this->config('jwt_token_refresh.settings')
      ->set('inject_into_user_login', TRUE)
      ->save();

    $response = $this->post('/user/login?_format=json', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $data = $this->json($response);
    $this->assertNotEmpty($data['access_token']);
    $this->assertNotEmpty($data['refresh_token']);
    $this->assertSame('Bearer', $data['token_type']);
  }

  /**
   * A refresh that fails to mint a JWT rolls back (no lockout).
   *
   * The old token stays usable instead of being burned by the attempt.
   */
  public function testRefreshRollsBackOnGenerationFailure(): void {
    $first = $this->login();

    // Break JWT signing so generateToken() returns FALSE.
    $this->config('jwt.config')->set('key_id', '')->save();
    $broken = $this->post('/auth/token/refresh', [
      'refresh_token' => $first['refresh_token'],
    ]);
    $this->assertSame(500, $broken->getStatusCode());

    // Restore signing; the original token must not have been burned.
    $this->config('jwt.config')->set('key_id', 'jtr_test_key')->save();
    $recovered = $this->post('/auth/token/refresh', [
      'refresh_token' => $first['refresh_token'],
    ]);
    $this->assertSame(200, $recovered->getStatusCode());
  }

  /**
   * Repeated failures on one account trip the flood gate.
   */
  public function testLoginFloodControl(): void {
    $name = $this->account->getAccountName();
    $last = NULL;
    for ($i = 0; $i < 6; $i++) {
      $last = $this->post('/auth/token', [
        'name' => $name,
        'pass' => 'wrong',
      ]);
    }
    $this->assertSame(429, $last->getStatusCode());
    $this->assertSame('too_many_attempts', $this->json($last)['error']);

    // The counter is keyed on the account id, not on the submitted name.
    $identifier = \Drupal::database()->select('flood', 'f')
      ->fields('f', ['identifier'])
      ->condition('event', 'jwt_token_refresh.login_user')
      ->execute()
      ->fetchField();
    $this->assertStringStartsWith(
      $this->account->id() . '-',
      (string) $identifier,
    );
  }

  /**
   * A name the ASCII flood column cannot hold still logs in and floods.
   */
  public function testFloodControlWithNonAsciiName(): void {
    $name = 'Zoë' . $this->randomMachineName(6);
    $pass = $this->randomMachineName(16);
    $account = $this->drupalCreateUser([], $name);
    $account->setPassword($pass);
    $account->save();

    // Keying the counter on the name as typed writes this name into the
    // identifier column, which is ASCII: on MySQL and MariaDB that throws and
    // the account gets a 500 with the right password.
    $ok = $this->post('/auth/token', ['name' => $name, 'pass' => $pass]);
    $this->assertSame(200, $ok->getStatusCode());

    $last = NULL;
    for ($i = 0; $i < 6; $i++) {
      $last = $this->post('/auth/token', ['name' => $name, 'pass' => 'wrong']);
      $this->assertNotSame(500, $last->getStatusCode());
    }
    $this->assertSame(429, $last->getStatusCode());
  }

  /**
   * An unknown name is answered against a hash the site would produce today.
   */
  public function testDummyHashFollowsSiteSettings(): void {
    $response = $this->post('/auth/token', [
      'name' => 'nobody' . $this->randomMachineName(8),
      'pass' => $this->randomMachineName(16),
    ]);
    $this->assertSame(401, $response->getStatusCode());

    \Drupal::state()->resetCache();
    $hash = \Drupal::state()->get('jwt_token_refresh.dummy_hash');
    $this->assertIsString($hash);
    // The hash is there to cost exactly what a real check costs. One the
    // password service considers outdated — a lower bcrypt cost, say — is
    // verified faster, which times the difference back out and tells a known
    // name from an unknown one.
    $this->assertFalse(\Drupal::service('password')->needsRehash($hash));
  }

  /**
   * An outdated hash is replaced on login, without ending other sessions.
   */
  public function testOutdatedPasswordHashIsRehashed(): void {
    // A hash this site would no longer produce: bcrypt at a cost other than
    // the one it hashes with. Reading that cost off the stored hash rather
    // than naming one keeps the test honest wherever the site — or a test
    // install, which lowers it — has put it.
    $info = password_get_info((string) $this->account->getPassword());
    $cost = (int) ($info['options']['cost'] ?? 10);
    $legacy = password_hash($this->pass, PASSWORD_BCRYPT, ['cost' => $cost + 1]);
    $password = \Drupal::service('password');
    $this->assertTrue($password->needsRehash($legacy));

    // pre_hashed stores the value as given instead of hashing it again.
    $this->account->set('pass', ['value' => $legacy, 'pre_hashed' => TRUE]);
    $this->account->save();

    // A token the account already holds, minted through the service so that
    // it predates the login below without a second login creating it.
    $existing = \Drupal::service('jwt_token_refresh.refresh_token_manager')
      ->create((int) $this->account->id());

    $response = $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);
    $this->assertSame(200, $response->getStatusCode());

    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $storage->resetCache([$this->account->id()]);
    $stored = $storage->load($this->account->id())->getPassword();
    $this->assertNotSame($legacy, $stored);
    $this->assertFalse($password->needsRehash($stored));

    // Re-encoding a password is not a credential change: the account keeps
    // the sessions it already had.
    $refreshed = $this->post('/auth/token/refresh', [
      'refresh_token' => $existing['token'],
    ]);
    $this->assertSame(200, $refreshed->getStatusCode());
  }

}
