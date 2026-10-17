<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that cron purges expired refresh tokens.
 *
 * @group jwt_token_refresh
 */
#[RunTestsInSeparateProcesses]
final class CronCleanupTest extends KernelTestBase {

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
    $this->installSchema('jwt_token_refresh', [
      'jwt_refresh_tokens',
      'jwt_access_token_sessions',
    ]);
    $this->installConfig(['jwt_token_refresh']);
  }

  /**
   * Inserts a refresh token row with an explicit expiry.
   */
  private function insert(string $raw, int $revoked, int $expires): void {
    $this->container->get('database')->insert('jwt_refresh_tokens')
      ->fields([
        'uid' => 1,
        'token_hash' => hash('sha256', $raw),
        'family_id' => 'fam',
        'revoked' => $revoked,
        'expires' => $expires,
        'created' => $expires - 100,
      ])
      ->execute();
  }

  /**
   * Counts rows in the refresh token table.
   */
  private function rowCount(): int {
    return (int) $this->container->get('database')
      ->select('jwt_refresh_tokens')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Runs the module's cron hook.
   */
  private function cron(): void {
    $this->container->get('module_handler')
      ->invoke('jwt_token_refresh', 'cron');
  }

  /**
   * Cron deletes expired (active and revoked) rows, keeps valid ones.
   */
  public function testCronPurgesExpired(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->insert('valid', 0, $now + 1000);
    $this->insert('expired-active', 0, $now - 10);
    $this->insert('expired-revoked', 1, $now - 10);
    $this->assertSame(3, $this->rowCount());

    $this->cron();

    $this->assertSame(1, $this->rowCount());
  }

  /**
   * With cleanup_on_cron disabled, nothing is purged.
   */
  public function testCronRespectsDisabledFlag(): void {
    $this->config('jwt_token_refresh.settings')
      ->set('cleanup_on_cron', FALSE)
      ->save();

    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->insert('expired', 0, $now - 10);

    $this->cron();

    $this->assertSame(1, $this->rowCount());
  }

}
