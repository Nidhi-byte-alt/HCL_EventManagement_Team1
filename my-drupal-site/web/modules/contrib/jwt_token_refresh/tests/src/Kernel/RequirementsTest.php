<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the status report entry for the JWT signing key.
 *
 * Without a configured key the module cannot issue or validate anything, and
 * the failure only surfaces on the first API call. The status report has to
 * say so beforehand.
 *
 * @group jwt_token_refresh
 */
#[RunTestsInSeparateProcesses]
final class RequirementsTest extends KernelTestBase {

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
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['jwt_token_refresh']);
  }

  /**
   * Collects this module's runtime requirements.
   *
   * Drupal 11.2 replaced hook_requirements() with hook_runtime_requirements();
   * the module implements both so it keeps working on 10.3, and this picks
   * whichever the running core registered. The newer one is a method on
   * JwtTokenRefreshRequirements; the older one is the procedural function
   * that calls it.
   *
   * @return array<string, mixed>
   *   The requirements this module reports.
   */
  private function requirements(): array {
    $handler = $this->container->get('module_handler');
    // The legacy hook lives in the .install file, which is not loaded on a
    // normal request: core loads it before gathering requirements, and so
    // must this.
    $handler->loadInclude('jwt_token_refresh', 'install');
    if ($handler->hasImplementations('runtime_requirements', ['jwt_token_refresh'])) {
      return $handler->invoke('jwt_token_refresh', 'runtime_requirements') ?? [];
    }
    return $handler->invoke('jwt_token_refresh', 'requirements', ['runtime']) ?? [];
  }

  /**
   * Normalizes a severity to its integer value.
   *
   * Older core reports an int, Drupal 11.2 and later a RequirementSeverity
   * enum case. The enum is int-backed, so its value is directly comparable.
   */
  private function severity(mixed $severity): ?int {
    if (is_object($severity)) {
      return (int) $severity->value;
    }
    return is_int($severity) ? $severity : NULL;
  }

  /**
   * With no key selected, the requirement is an error.
   */
  public function testErrorWhenNoKeyConfigured(): void {
    $requirements = $this->requirements();
    $this->assertArrayHasKey('jwt_token_refresh_key', $requirements);

    $entry = $requirements['jwt_token_refresh_key'];
    // 2 is REQUIREMENT_ERROR, and RequirementSeverity::Error->value.
    $this->assertSame(2, $this->severity($entry['severity'] ?? NULL));
    // The description has to point at both pages a site builder needs.
    $description = (string) $entry['description'];
    $this->assertStringContainsString('/admin/config/system/keys', $description);
    $this->assertStringContainsString('/admin/config/system/jwt', $description);
  }

  /**
   * A key_id pointing at a deleted key is an error too, not a silent pass.
   */
  public function testErrorWhenConfiguredKeyIsMissing(): void {
    $this->config('jwt.config')->set('key_id', 'gone')->save();

    $entry = $this->requirements()['jwt_token_refresh_key'];
    $this->assertSame(2, $this->severity($entry['severity'] ?? NULL));
    $this->assertStringContainsString('gone', (string) $entry['description']);
  }

  /**
   * With a usable key, the requirement carries no error severity.
   */
  public function testNoErrorWhenKeyConfigured(): void {
    Key::create([
      'id' => 'jtr_requirements_key',
      'label' => 'Signing key',
      'key_type' => 'jwt_hs',
      'key_type_settings' => ['algorithm' => 'HS512'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => str_repeat('k', 64)],
      'key_input' => 'text_field',
    ])->save();
    $this->config('jwt.config')->set('key_id', 'jtr_requirements_key')->save();

    $entry = $this->requirements()['jwt_token_refresh_key'];
    $this->assertNotSame(2, $this->severity($entry['severity'] ?? NULL));
    $this->assertStringContainsString('Signing key', (string) $entry['value']);
  }

}
