<?php

declare(strict_types=1);

namespace Drupal\Tests\jwt_token_refresh\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jwt_token_refresh\Service\RefreshTokenManagerInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the refresh token manager.
 *
 * @group jwt_token_refresh
 * @coversDefaultClass \Drupal\jwt_token_refresh\Service\RefreshTokenManager
 */
#[RunTestsInSeparateProcesses]
final class RefreshTokenManagerTest extends KernelTestBase {

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
   * The service under test.
   */
  private RefreshTokenManagerInterface $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Deleting a user runs core's own hook_user_delete(), which purges
    // users_data; without its schema the delete test fails on core, not here.
    $this->installSchema('user', ['users_data']);
    $this->installSchema('jwt_token_refresh', [
      'jwt_refresh_tokens',
      'jwt_access_token_sessions',
    ]);
    $this->installConfig(['jwt_token_refresh']);
    $this->manager = $this->container
      ->get('jwt_token_refresh.refresh_token_manager');
  }

  /**
   * Creates and saves a user.
   */
  private function makeUser(int $status = 1): int {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'status' => $status,
    ]);
    $user->save();
    return (int) $user->id();
  }

  /**
   * Inserts a token row directly, bypassing create() (and its hooks).
   */
  private function insertToken(
    int $uid,
    string $raw,
    string $family_id,
    int $revoked,
    int $expires,
  ): void {
    $this->container->get('database')->insert('jwt_refresh_tokens')
      ->fields([
        'uid' => $uid,
        'token_hash' => hash('sha256', $raw),
        'family_id' => $family_id,
        'revoked' => $revoked,
        'expires' => $expires,
        'created' => $this->now(),
      ])
      ->execute();
  }

  /**
   * The request time the service also reads.
   */
  private function now(): int {
    return $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * Loads the raw DB row for a raw token, or NULL.
   *
   * @phpstan-return \stdClass|null
   */
  private function rowFor(string $raw): ?object {
    $row = $this->container->get('database')
      ->select('jwt_refresh_tokens', 't')
      ->fields('t')
      ->condition('token_hash', hash('sha256', $raw))
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * @covers ::create
   */
  public function testCreateStoresHashedTokenOnly(): void {
    $uid = $this->makeUser();
    $pair = $this->manager->create($uid);

    $this->assertNotEmpty($pair['token']);
    $this->assertGreaterThan($this->now(), $pair['expires']);

    $row = $this->rowFor($pair['token']);
    $this->assertNotNull($row);
    // The raw token is never persisted: only its SHA256.
    $this->assertSame(hash('sha256', $pair['token']), $row->token_hash);
    $this->assertNotSame($pair['token'], $row->token_hash);
    $this->assertNotEmpty($row->family_id);
    $this->assertSame('0', (string) $row->revoked);
  }

  /**
   * @covers ::validate
   */
  public function testValidate(): void {
    $uid = $this->makeUser();
    $pair = $this->manager->create($uid);

    $this->assertSame($uid, $this->manager->validate($pair['token']));
    $this->assertNull($this->manager->validate('not-a-real-token'));
    $this->assertNull($this->manager->validate(''));
  }

  /**
   * @covers ::rotate
   */
  public function testRotateRevokesOldKeepsRowIssuesNew(): void {
    $uid = $this->makeUser();
    $old = $this->manager->create($uid);
    $oldFamily = $this->rowFor($old['token'])->family_id;

    $new = $this->manager->rotate($old['token']);
    $this->assertNotNull($new);

    // Old token no longer validates but its row survives (revoked = 1)
    // for the reuse-detection window.
    $this->assertNull($this->manager->validate($old['token']));
    $oldRow = $this->rowFor($old['token']);
    $this->assertSame('1', (string) $oldRow->revoked);

    // New token validates and inherits the same family.
    $this->assertSame($uid, $this->manager->validate($new['token']));
    $this->assertSame($oldFamily, $this->rowFor($new['token'])->family_id);
  }

  /**
   * @covers ::rotate
   * @covers ::revokeFamily
   */
  public function testReuseDetectionRevokesWholeFamily(): void {
    $uid = $this->makeUser();
    $old = $this->manager->create($uid);
    $new = $this->manager->rotate($old['token']);

    // Replay the already-rotated token: theft indicator.
    $this->assertNull($this->manager->rotate($old['token']));

    // The whole family is revoked, including the still-active new token.
    $this->assertNull($this->manager->validate($new['token']));
  }

  /**
   * @covers ::rotate
   */
  public function testRotateRejectsBlockedAccount(): void {
    // User blocked from the start, so no hook_user_update fires; the
    // token is inserted directly so rotate's own isActive() guard is
    // what we exercise.
    $uid = $this->makeUser(status: 0);
    $raw = bin2hex(random_bytes(32));
    $this->insertToken($uid, $raw, 'fam-blocked', 0, $this->now() + 1000);

    $this->assertNull($this->manager->rotate($raw));
    // Defensive revocation wiped the user's tokens.
    $this->assertNull($this->manager->validate($raw));
  }

  /**
   * @covers ::create
   */
  public function testMaxTokensPerUserEviction(): void {
    $this->config('jwt_token_refresh.settings')
      ->set('max_tokens_per_user', 2)
      ->save();

    $uid = $this->makeUser();
    $t1 = $this->manager->create($uid);
    $t2 = $this->manager->create($uid);
    $t3 = $this->manager->create($uid);

    // Oldest evicted, cap respected.
    $this->assertNull($this->manager->validate($t1['token']));
    $this->assertSame($uid, $this->manager->validate($t2['token']));
    $this->assertSame($uid, $this->manager->validate($t3['token']));
  }

  /**
   * @covers ::cleanupExpired
   */
  public function testCleanupExpired(): void {
    $uid = $this->makeUser();
    $valid = $this->manager->create($uid);

    // One expired-active and one expired-revoked row.
    $this->insertToken($uid, 'exp-active', 'f1', 0, $this->now() - 100);
    $this->insertToken($uid, 'exp-revoked', 'f2', 1, $this->now() - 100);

    $deleted = $this->manager->cleanupExpired();
    $this->assertSame(2, $deleted);
    // The valid token survives.
    $this->assertSame($uid, $this->manager->validate($valid['token']));
  }

  /**
   * @covers ::revokeAllForUser
   */
  public function testRevokeAllForUserScopedToUser(): void {
    $uidA = $this->makeUser();
    $uidB = $this->makeUser();
    $a1 = $this->manager->create($uidA);
    $a2 = $this->manager->create($uidA);
    $b1 = $this->manager->create($uidB);

    $this->manager->revokeAllForUser($uidA);

    $this->assertNull($this->manager->validate($a1['token']));
    $this->assertNull($this->manager->validate($a2['token']));
    // Other users untouched.
    $this->assertSame($uidB, $this->manager->validate($b1['token']));
  }

  /**
   * @covers ::revoke
   */
  public function testRevokeSingleTokenIsIdempotent(): void {
    $uid = $this->makeUser();
    $pair = $this->manager->create($uid);

    $this->manager->revoke($pair['token']);
    $this->assertNull($this->manager->validate($pair['token']));

    // Revoking again (or an unknown token) is a silent no-op: no exception,
    // and the token stays invalid rather than coming back to life.
    $this->manager->revoke($pair['token']);
    $this->manager->revoke('never-existed');
    $this->assertNull($this->manager->validate($pair['token']));
  }

  /**
   * Tests that two concurrent rotations of one token yield a single pair.
   *
   * Only one of them can win, because the atomic conditional UPDATE is the
   * lock.
   *
   * @covers ::rotate
   */
  public function testConcurrentRotationYieldsSinglePair(): void {
    $uid = $this->makeUser();
    $old = $this->manager->create($uid);

    $first = $this->manager->rotate($old['token']);
    $second = $this->manager->rotate($old['token']);

    $this->assertNotNull($first);
    // The second rotation finds the token already claimed: it returns
    // NULL and (reuse detection) revokes the family.
    $this->assertNull($second);
  }

  /**
   * Hook: a password change revokes every refresh token of the user.
   */
  public function testHookRevokesOnPasswordChange(): void {
    $user = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
      'pass' => 'initial-pass',
    ]);
    $user->save();
    $uid = (int) $user->id();
    $pair = $this->manager->create($uid);

    $user->setPassword('a-new-password');
    $user->save();

    $this->assertNull($this->manager->validate($pair['token']));
  }

  /**
   * Hook: blocking an account revokes every refresh token.
   */
  public function testHookRevokesOnBlock(): void {
    $uid = $this->makeUser();
    $pair = $this->manager->create($uid);

    $user = User::load($uid);
    $user->block();
    $user->save();

    $this->assertNull($this->manager->validate($pair['token']));
  }

  /**
   * Hook: deleting an account purges every refresh token.
   */
  public function testHookRevokesOnDelete(): void {
    $uid = $this->makeUser();
    $pair = $this->manager->create($uid);

    User::load($uid)->delete();

    $this->assertNull($this->manager->validate($pair['token']));
  }

  /**
   * Hook: canceling an account purges every refresh token.
   *
   * This is the one lifecycle hook no entity operation fires on its own: core
   * invokes it from user_cancel(), and every cancellation method either blocks
   * or deletes the account, so hook_user_update() or hook_user_delete() would
   * revoke the tokens anyway and hide whether this implementation is wired at
   * all. Invoking the hook through the module handler is therefore the only
   * way to test that it is.
   */
  public function testHookRevokesOnCancel(): void {
    $uid = $this->makeUser();
    $pair = $this->manager->create($uid);

    $this->container->get('module_handler')->invokeAll('user_cancel', [
      [],
      User::load($uid),
      'user_cancel_block',
    ]);

    $this->assertNull($this->manager->validate($pair['token']));
  }

}
