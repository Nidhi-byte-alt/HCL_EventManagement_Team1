<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings form: access, persistence and bounds.
 *
 * @group jwt_token_refresh
 */
#[RunTestsInSeparateProcesses]
final class TokenRefreshSettingsFormTest extends BrowserTestBase {

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
   * The settings form path.
   */
  private const PATH = '/admin/config/system/jwt-token-refresh';

  /**
   * Users without the permission are denied.
   */
  public function testAccessRequiresPermission(): void {
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Valid values are persisted.
   */
  public function testSavesValidValues(): void {
    $this->drupalLogin(
      $this->drupalCreateUser(['administer jwt token refresh']),
    );
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'access_token_lifetime' => 7200,
      'refresh_token_bytes' => 48,
      'max_tokens_per_user' => 3,
      'enable_access_token_revocation' => 1,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains(
      'The configuration options have been saved.',
    );
    // The strict comparisons are the point: the form maps its elements onto
    // config with #config_target, and a number element submits a string while
    // a checkbox submits an integer. Without the casts the schema would
    // promise an integer and get "7200", or a boolean and get 1.
    $config = $this->config('jwt_token_refresh.settings');
    $this->assertSame(7200, $config->get('access_token_lifetime'));
    $this->assertSame(48, $config->get('refresh_token_bytes'));
    $this->assertSame(3, $config->get('max_tokens_per_user'));
    $this->assertTrue($config->get('enable_access_token_revocation'));
    // Untouched boxes, one on each side of their default.
    $this->assertFalse($config->get('inject_into_user_login'));
    $this->assertTrue($config->get('cleanup_on_cron'));

    // And a box going from on to off, which is the other half of the cast.
    $this->submitForm(['cleanup_on_cron' => FALSE], 'Save configuration');
    $this->assertFalse(
      $this->config('jwt_token_refresh.settings')->get('cleanup_on_cron'),
    );
  }

  /**
   * Refresh token entropy below the floor is rejected.
   */
  public function testRejectsLowRefreshBytes(): void {
    $this->drupalLogin(
      $this->drupalCreateUser(['administer jwt token refresh']),
    );
    $this->drupalGet(self::PATH);
    $this->submitForm(['refresh_token_bytes' => 4], 'Save configuration');

    $this->assertSession()->pageTextContains(
      'Refresh token entropy (bytes) must be higher than or equal to 32.',
    );
    // Unchanged.
    $this->assertSame(
      64,
      $this->config('jwt_token_refresh.settings')->get('refresh_token_bytes'),
    );
  }

  /**
   * Rejects jti entropy below the floor.
   */
  public function testRejectsLowJtiBytes(): void {
    $this->drupalLogin(
      $this->drupalCreateUser(['administer jwt token refresh']),
    );
    $this->drupalGet(self::PATH);
    $this->submitForm(['jti_bytes' => 8], 'Save configuration');

    $this->assertSession()->pageTextContains(
      'jti entropy (bytes) must be higher than or equal to 16.',
    );
  }

  /**
   * An excessive access lifetime is rejected.
   */
  public function testRejectsExcessiveLifetime(): void {
    $this->drupalLogin(
      $this->drupalCreateUser(['administer jwt token refresh']),
    );
    $this->drupalGet(self::PATH);
    $this->submitForm(
      ['access_token_lifetime' => 999999],
      'Save configuration',
    );

    $this->assertSession()->pageTextContains(
      'Access token lifetime (seconds) must be lower than or equal to 86400.',
    );
  }

  /**
   * A negative lifetime is rejected rather than saved.
   */
  public function testRejectsNegativeLifetime(): void {
    $this->drupalLogin(
      $this->drupalCreateUser(['administer jwt token refresh']),
    );
    $this->drupalGet(self::PATH);
    $this->submitForm(
      ['refresh_token_lifetime' => -1],
      'Save configuration',
    );

    $this->assertSession()->pageTextContains(
      'Refresh token lifetime (seconds) must be higher than or equal to 300.',
    );
    $this->assertSame(
      604800,
      $this->config('jwt_token_refresh.settings')
        ->get('refresh_token_lifetime'),
    );
  }

}
