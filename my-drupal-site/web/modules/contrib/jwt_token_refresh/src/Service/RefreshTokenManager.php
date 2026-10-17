<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Default refresh token manager backed by the jwt_refresh_tokens table.
 *
 * Owns the refresh token lifecycle: creation, rotation with OWASP reuse
 * detection, revocation and expiry. Revoking a refresh token cascades to the
 * access token sessions it issued, so a logout takes the access tokens with
 * it when server-side revocation is on.
 */
final class RefreshTokenManager implements RefreshTokenManagerInterface {

  /**
   * The settings config name.
   */
  private const CONFIG = 'jwt_token_refresh.settings';

  /**
   * The refresh tokens table.
   */
  private const TABLE = 'jwt_refresh_tokens';

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly AccessTokenSessionManagerInterface $accessSessions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function create(int $uid, ?string $family_id = NULL): array {
    $config = $this->configFactory->get(self::CONFIG);
    // Hard floor: an unsalted SHA256 is only safe because the input
    // entropy is high, so clamp even if config was tampered with.
    $bytes = max(32, (int) ($config->get('refresh_token_bytes') ?? 64));
    $lifetime = (int) ($config->get('refresh_token_lifetime') ?? 604800);
    $max = (int) ($config->get('max_tokens_per_user') ?? 10);

    $raw_token = bin2hex(random_bytes($bytes));
    $family_id ??= bin2hex(random_bytes(16));
    $now = $this->time->getRequestTime();
    $expires = $now + $lifetime;

    $this->evictOverflow($uid, $max);

    $id = $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'token_hash' => hash('sha256', $raw_token),
        'family_id' => $family_id,
        'revoked' => 0,
        'expires' => $expires,
        'created' => $now,
      ])
      ->execute();

    return ['token' => $raw_token, 'expires' => $expires, 'id' => (int) $id];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string $raw_token): ?int {
    if ($raw_token === '') {
      return NULL;
    }
    $uid = $this->database->select(self::TABLE, 't')
      ->fields('t', ['uid'])
      ->condition('token_hash', hash('sha256', $raw_token))
      ->condition('revoked', 0)
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->execute()
      ->fetchField();

    return $uid === FALSE ? NULL : (int) $uid;
  }

  /**
   * {@inheritdoc}
   */
  public function rotate(string $old_raw_token): ?array {
    if ($old_raw_token === '') {
      return NULL;
    }
    $hash = hash('sha256', $old_raw_token);
    $now = $this->time->getRequestTime();

    // Atomic claim: only one concurrent rotation can flip revoked
    // 0 -> 1. The conditional UPDATE *is* the lock — no SELECT FOR
    // UPDATE, no race window between a validate() and a revoke().
    $claimed = $this->database->update(self::TABLE)
      ->fields(['revoked' => 1])
      ->condition('token_hash', $hash)
      ->condition('revoked', 0)
      ->condition('expires', $now, '>')
      ->execute();

    if ($claimed === 1) {
      $row = $this->database->select(self::TABLE, 't')
        ->fields('t', ['id', 'uid', 'family_id'])
        ->condition('token_hash', $hash)
        ->execute()
        ->fetchObject();

      // The UPDATE above matched this row, so it exists — unless it was
      // purged in between (a row expiring exactly as cleanup runs). Treat
      // that as a failed claim rather than dying on a missing object.
      if (!$row) {
        return NULL;
      }

      // Re-check account state: a user blocked after login must not
      // be able to refresh.
      $account = $this->entityTypeManager->getStorage('user')
        ->load($row->uid);
      if (!$account instanceof UserInterface || !$account->isActive()) {
        $this->revokeAllForUser((int) $row->uid);
        return NULL;
      }

      // The access tokens issued by the rotated refresh die with it.
      $this->accessSessions->revokeForRefreshToken((int) $row->id);

      return $this->create((int) $row->uid, $row->family_id);
    }

    // Nothing claimed: unknown, expired… or already rotated. A replay
    // of a rotated token is a theft indicator (OWASP reuse detection)
    // -> revoke the whole family. Response stays NULL either way.
    $family_id = $this->database->select(self::TABLE, 't')
      ->fields('t', ['family_id'])
      ->condition('token_hash', $hash)
      ->condition('revoked', 1)
      ->execute()
      ->fetchField();
    if ($family_id !== FALSE) {
      $this->logger->warning(
        'Refresh token reuse detected; revoking token family.'
      );
      $this->revokeFamily((string) $family_id);
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function revoke(string $raw_token): void {
    if ($raw_token === '') {
      return;
    }
    $id = $this->database->select(self::TABLE, 't')
      ->fields('t', ['id'])
      ->condition('token_hash', hash('sha256', $raw_token))
      ->execute()
      ->fetchField();
    if ($id !== FALSE) {
      $this->revokeById((int) $id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function revokeById(int $id): void {
    $this->accessSessions->revokeForRefreshToken($id);
    $this->database->delete(self::TABLE)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function revokeAllForUser(int $uid): void {
    $this->accessSessions->revokeAllForUser($uid);
    $this->database->delete(self::TABLE)
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function revokeFamily(string $family_id): void {
    $ids = $this->database->select(self::TABLE, 't')
      ->fields('t', ['id'])
      ->condition('family_id', $family_id)
      ->execute()
      ->fetchCol();
    if ($ids) {
      $this->accessSessions->revokeForRefreshTokens(array_map('intval', $ids));
    }
    $this->database->delete(self::TABLE)
      ->condition('family_id', $family_id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function cleanupExpired(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('expires', $this->time->getRequestTime(), '<')
      ->execute();
  }

  /**
   * Evicts the oldest active tokens to keep within the per-user cap.
   *
   * @param int $uid
   *   The user to enforce the cap for.
   * @param int $max
   *   The maximum number of concurrent active tokens. <= 0 disables.
   */
  private function evictOverflow(int $uid, int $max): void {
    if ($max <= 0) {
      return;
    }
    $count = (int) $this->database->select(self::TABLE, 't')
      ->condition('uid', $uid)
      ->condition('revoked', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count < $max) {
      return;
    }

    $ids = $this->database->select(self::TABLE, 't')
      ->fields('t', ['id'])
      ->condition('uid', $uid)
      ->condition('revoked', 0)
      ->orderBy('created', 'ASC')
      ->orderBy('id', 'ASC')
      ->range(0, $count - $max + 1)
      ->execute()
      ->fetchCol();

    if ($ids) {
      $this->accessSessions
        ->revokeForRefreshTokens(array_map('intval', $ids));
      $this->database->delete(self::TABLE)
        ->condition('id', $ids, 'IN')
        ->execute();
    }
  }

}
