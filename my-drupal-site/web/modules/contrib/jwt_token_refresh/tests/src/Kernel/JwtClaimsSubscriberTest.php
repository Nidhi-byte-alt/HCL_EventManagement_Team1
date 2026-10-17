<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jwt\Authentication\Event\JwtAuthEvents;
use Drupal\jwt\Authentication\Event\JwtAuthGenerateEvent;
use Drupal\jwt\JsonWebToken\JsonWebToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the JWT exp claim follows configuration.
 *
 * @group jwt_token_refresh
 * @coversDefaultClass \Drupal\jwt_token_refresh\EventSubscriber\JwtClaimsSubscriber
 */
#[RunTestsInSeparateProcesses]
final class JwtClaimsSubscriberTest extends KernelTestBase {

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
   * Dispatches GENERATE and returns the resulting token.
   */
  private function generate(): JsonWebToken {
    $jwt = new JsonWebToken();
    $event = new JwtAuthGenerateEvent($jwt);
    $this->container->get('event_dispatcher')
      ->dispatch($event, JwtAuthEvents::GENERATE);
    return $jwt;
  }

  /**
   * @covers ::onGenerate
   */
  public function testExpFollowsConfiguredLifetime(): void {
    $this->config('jwt_token_refresh.settings')
      ->set('access_token_lifetime', 120)
      ->save();

    $now = $this->container->get('datetime.time')->getRequestTime();
    $jwt = $this->generate();

    // Our subscriber (priority 90) overrides the issuer's +1h default.
    $this->assertSame($now + 120, $jwt->getClaim('exp'));
    $this->assertSame($now, $jwt->getClaim('iat'));
  }

  /**
   * @covers ::onGenerate
   */
  public function testExpUsesDefaultWhenUnset(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $jwt = $this->generate();

    // Default access_token_lifetime is 3600.
    $this->assertSame($now + 3600, $jwt->getClaim('exp'));
  }

}
