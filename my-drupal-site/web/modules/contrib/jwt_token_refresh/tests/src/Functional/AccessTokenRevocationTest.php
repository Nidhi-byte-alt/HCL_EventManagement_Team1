<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\key\Entity\Key;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * E2E tests for opt-in server-side access token revocation.
 *
 * @group jwt_token_refresh
 */
#[RunTestsInSeparateProcesses]
final class AccessTokenRevocationTest extends BrowserTestBase {

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
   * The account's raw password.
   */
  private string $pass;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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

    // Revocation on by default for this suite.
    $this->config('jwt_token_refresh.settings')
      ->set('enable_access_token_revocation', TRUE)
      ->save();

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
   * GETs a path with optional headers.
   *
   * @phpstan-param array<string, string> $headers
   */
  private function get(string $path, array $headers = []): ResponseInterface {
    return $this->getHttpClient()->request('GET', $this->getAbsoluteUrl($path), [
      'http_errors' => FALSE,
      'headers' => $headers,
    ]);
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
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Login records a jti claim and an allowlist row linked to a refresh.
   */
  public function testJtiIssuedAndSessionRecorded(): void {
    $tokens = $this->login();

    $jwt = \Drupal::service('jwt.transcoder')
      ->decode($tokens['access_token']);
    $this->assertNotEmpty($jwt->getClaim('jti'));

    $row = \Drupal::database()
      ->select('jwt_access_token_sessions', 's')
      ->fields('s')
      ->execute()
      ->fetchObject();
    $this->assertNotFalse($row);
    $this->assertSame((int) $this->account->id(), (int) $row->uid);
    $this->assertNotNull($row->refresh_token_id);
  }

  /**
   * A revoked access token is rejected immediately (before exp).
   */
  public function testImmediateLogout(): void {
    $tokens = $this->login();
    $bearer = ['Authorization' => 'Bearer ' . $tokens['access_token']];

    // The token works on a JWT-protected route.
    $this->assertSame(200, $this->get('/jwt/token', $bearer)->getStatusCode());

    // Log out (revokes the user's refresh tokens and jti sessions).
    $this->assertSame(
      204,
      $this->post('/auth/token/revoke', [], $bearer)->getStatusCode(),
    );

    // The very same JWT — still cryptographically valid — is now 401.
    $after = $this->get('/jwt/token', $bearer);
    $this->assertSame(401, $after->getStatusCode());
    $this->assertSame(
      'session_revoked',
      json_decode((string) $after->getBody(), TRUE)['error'],
    );
  }

  /**
   * The /user/login injection links its session to the refresh token.
   */
  public function testUserLoginInjectionLinksSession(): void {
    $this->config('jwt_token_refresh.settings')
      ->set('inject_into_user_login', TRUE)
      ->save();

    $response = $this->post('/user/login?_format=json', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);
    $this->assertSame(200, $response->getStatusCode());

    $row = \Drupal::database()
      ->select('jwt_access_token_sessions', 's')
      ->fields('s')
      ->execute()
      ->fetchObject();
    $this->assertNotFalse($row);
    // The session created during login is linked to the new refresh.
    $this->assertNotNull($row->refresh_token_id);
  }

  /**
   * With the flag off, tokens are stateless: no jti, no allowlist rows.
   */
  public function testStatelessWhenDisabled(): void {
    $this->config('jwt_token_refresh.settings')
      ->set('enable_access_token_revocation', FALSE)
      ->save();

    $tokens = $this->login();
    $jwt = \Drupal::service('jwt.transcoder')
      ->decode($tokens['access_token']);
    $this->assertNull($jwt->getClaim('jti'));

    $count = \Drupal::database()
      ->select('jwt_access_token_sessions', 's')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, (int) $count);
  }

}
