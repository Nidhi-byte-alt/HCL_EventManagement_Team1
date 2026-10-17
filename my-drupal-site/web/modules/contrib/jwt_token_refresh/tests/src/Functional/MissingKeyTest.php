<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavior when no usable JWT signing key is configured.
 *
 * A site can reach this state easily: install the module, forget to select a
 * key. The jwt module then fails hard from inside its transcoder, so every
 * call site here has to absorb that rather than let a 500 escape.
 *
 * @group jwt_token_refresh
 */
#[RunTestsInSeparateProcesses]
final class MissingKeyTest extends BrowserTestBase {

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
   * The test account's raw password.
   */
  private string $pass;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Deliberately no key entity and no jwt.config:key_id.
    $this->pass = $this->randomMachineName(16);
    $this->account = $this->drupalCreateUser();
    $this->account->setPassword($this->pass);
    $this->account->save();
  }

  /**
   * The token endpoint reports a typed error instead of a fatal.
   */
  public function testLoginReportsTypedErrorWithoutKey(): void {
    $response = $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);

    $this->assertSame(500, $response->getStatusCode());
    $data = $this->json($response);
    $this->assertSame('internal_error', $data['error'] ?? NULL);
    // A fatal would render HTML, so the envelope itself is the assertion.
    $this->assertArrayHasKey('message', $data);
  }

  /**
   * A failed rotation leaves no refresh token behind.
   */
  public function testLoginIssuesNoRefreshTokenWithoutKey(): void {
    $this->post('/auth/token', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);

    $count = (int) \Drupal::database()
      ->select('jwt_refresh_tokens')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, $count, 'The transaction rolled back.');
  }

  /**
   * The core login route still answers 200, just without tokens.
   *
   * The jwt_auth_issuer module decorates the same route and calls
   * generateToken() unguarded (JwtAuthIssuerLoginController::login()), so its
   * own injection is switched off here. Otherwise the route is a 500 before
   * this module gets a say, and the test would be measuring jwt_auth_issuer
   * rather than us.
   */
  public function testCoreLoginDegradesWithoutKey(): void {
    $this->config('jwt_auth_issuer.config')
      ->set('jwt_in_login_response', FALSE)
      ->save();
    // jwt_auth_issuer swaps the route controller from a route alter, and its
    // config listener only flags the router as needing a rebuild. Do it now,
    // or the next request still routes to its controller.
    \Drupal::service('router.builder')->rebuild();
    $this->config('jwt_token_refresh.settings')
      ->set('inject_into_user_login', TRUE)
      ->save();

    $response = $this->post('/user/login?_format=json', [
      'name' => $this->account->getAccountName(),
      'pass' => $this->pass,
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $data = $this->json($response);
    // Core's own payload is intact.
    $this->assertArrayHasKey('current_user', $data);
    // Ours is absent rather than NULL: a frontend checking for the key gets a
    // truthful answer, and no refresh token was burned to produce nothing.
    $this->assertArrayNotHasKey('access_token', $data);
    $this->assertArrayNotHasKey('refresh_token', $data);
  }

  /**
   * POSTs a JSON body and returns the raw response.
   *
   * @phpstan-param array<string, mixed> $body
   */
  private function post(string $path, array $body): ResponseInterface {
    return $this->getHttpClient()->request('POST', $this->getAbsoluteUrl($path), [
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'application/json'],
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

}
