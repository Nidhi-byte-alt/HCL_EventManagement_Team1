<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface;
use Drupal\jwt_token_refresh\Service\RefreshTokenManagerInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the access token session manager and its cascade.
 *
 * @group jwt_token_refresh
 * @coversDefaultClass \Drupal\jwt_token_refresh\Service\AccessTokenSessionManager
 */
#[RunTestsInSeparateProcesses]
final class AccessTokenSessionManagerTest extends KernelTestBase {

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
   * The session manager under test.
   */
  private AccessTokenSessionManagerInterface $sessions;

  /**
   * The refresh token manager (for cascade tests).
   */
  private RefreshTokenManagerInterface $refresh;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('jwt_token_refresh', [
      'jwt_refresh_tokens',
      'jwt_access_token_sessions',
    ]);
    $this->installConfig(['jwt_token_refresh']);
    $this->sessions = $this->container
      ->get('jwt_token_refresh.access_token_session_manager');
    $this->refresh = $this->container
      ->get('jwt_token_refresh.refresh_token_manager');
  }

  /**
   * Creates and saves a user.
   */
  private function makeUser(): int {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
    ]);
    $user->save();
    return (int) $user->id();
  }

  /**
   * The request time the manager also reads.
   */
  private function now(): int {
    return $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * @covers ::create
   * @covers ::isValid
   */
  public function testCreateAndIsValid(): void {
    $jti = $this->sessions->create(1);

    $this->assertNotEmpty($jti);
    $this->assertTrue($this->sessions->isValid($jti));
    $this->assertFalse($this->sessions->isValid('garbage'));
    $this->assertFalse($this->sessions->isValid(''));
  }

  /**
   * @covers ::revokeForRefreshToken
   */
  public function testRevokeForRefreshTokenIsScoped(): void {
    $a = $this->sessions->create(1, 10);
    $b = $this->sessions->create(1, 20);

    $this->sessions->revokeForRefreshToken(10);

    $this->assertFalse($this->sessions->isValid($a));
    $this->assertTrue($this->sessions->isValid($b));
  }

  /**
   * @covers ::revokeAllForUser
   */
  public function testRevokeAllForUser(): void {
    $a = $this->sessions->create(1);
    $b = $this->sessions->create(2);

    $this->sessions->revokeAllForUser(1);

    $this->assertFalse($this->sessions->isValid($a));
    $this->assertTrue($this->sessions->isValid($b));
  }

  /**
   * @covers ::cleanupExpired
   */
  public function testCleanupExpired(): void {
    $valid = $this->sessions->create(1);
    // An expired row inserted directly.
    $this->container->get('database')->insert('jwt_access_token_sessions')
      ->fields([
        'uid' => 1,
        'jti_hash' => hash('sha256', 'old'),
        'refresh_token_id' => NULL,
        'expires' => $this->now() - 10,
        'created' => $this->now() - 100,
      ])
      ->execute();

    $this->assertSame(1, $this->sessions->cleanupExpired());
    $this->assertTrue($this->sessions->isValid($valid));
  }

  /**
   * Tests that isValid() is memoized for the rest of the request.
   *
   * A row deleted out-of-band after a positive check still reads as valid
   * until the cache is cleared.
   *
   * @covers ::isValid
   */
  public function testIsValidIsMemoized(): void {
    $jti = $this->sessions->create(1);
    $this->assertTrue($this->sessions->isValid($jti));

    $this->container->get('database')->delete('jwt_access_token_sessions')
      ->condition('jti_hash', hash('sha256', $jti))
      ->execute();

    // Still cached from the first call.
    $this->assertTrue($this->sessions->isValid($jti));
  }

  /**
   * @covers ::getRefreshTokenIdForJti
   */
  public function testGetRefreshTokenIdForJti(): void {
    $linked = $this->sessions->create(1, 42);
    $unlinked = $this->sessions->create(1);

    $this->assertSame(42, $this->sessions->getRefreshTokenIdForJti($linked));
    // Issued outside the refresh flow: no linkage.
    $this->assertNull($this->sessions->getRefreshTokenIdForJti($unlinked));
    $this->assertNull($this->sessions->getRefreshTokenIdForJti('garbage'));
    $this->assertNull($this->sessions->getRefreshTokenIdForJti(''));
  }

  /**
   * Per-device logout: jti -> refresh id -> revokeById, others survive.
   */
  public function testPerDeviceLogoutViaRevokeById(): void {
    $this->makeUser();
    // Two devices: each has its own refresh token + access session.
    $deviceA = $this->refresh->create(1);
    $jtiA = $this->sessions->create(1, $deviceA['id']);
    $deviceB = $this->refresh->create(1);
    $jtiB = $this->sessions->create(1, $deviceB['id']);

    // Logout on device A, resolved from its access token's jti.
    $id = $this->sessions->getRefreshTokenIdForJti($jtiA);
    $this->assertSame($deviceA['id'], $id);
    $this->refresh->revokeById($id);

    // Device A is fully dead: refresh and access token both revoked.
    $this->assertNull($this->refresh->validate($deviceA['token']));
    $this->assertFalse($this->sessions->isValid($jtiA));
    // Device B is untouched.
    $this->assertSame(1, $this->refresh->validate($deviceB['token']));
    $this->assertTrue($this->sessions->isValid($jtiB));
  }

  /**
   * Revoking a refresh token kills the access tokens it issued.
   */
  public function testCascadeOnRefreshRevoke(): void {
    $this->makeUser();
    $pair = $this->refresh->create(1);
    $jti = $this->sessions->create(1, $pair['id']);
    $this->assertTrue($this->sessions->isValid($jti));

    $this->refresh->revoke($pair['token']);

    $this->assertFalse($this->sessions->isValid($jti));
  }

  /**
   * Rotating a refresh token kills the access tokens it issued.
   */
  public function testCascadeOnRotate(): void {
    $this->makeUser();
    $pair = $this->refresh->create(1);
    $jti = $this->sessions->create(1, $pair['id']);

    $this->refresh->rotate($pair['token']);

    $this->assertFalse($this->sessions->isValid($jti));
  }

  /**
   * Revoking all of a user's refresh tokens kills their sessions too.
   */
  public function testCascadeOnRevokeAllForUser(): void {
    $this->makeUser();
    $pair = $this->refresh->create(1);
    $jti = $this->sessions->create(1, $pair['id']);

    $this->refresh->revokeAllForUser(1);

    $this->assertFalse($this->sessions->isValid($jti));
  }

}
