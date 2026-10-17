<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Default access token session manager (jti allowlist).
 */
final class AccessTokenSessionManager implements AccessTokenSessionManagerInterface {

  /**
   * The settings config name.
   */
  private const CONFIG = 'jwt_token_refresh.settings';

  /**
   * The sessions table.
   */
  private const TABLE = 'jwt_access_token_sessions';

  /**
   * Per-request memoization of isValid() results, keyed by jti hash.
   *
   * @var array<string, bool>
   */
  private array $validCache = [];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function create(int $uid, ?int $linked_refresh_token_id = NULL): string {
    $config = $this->configFactory->get(self::CONFIG);
    $bytes = max(16, (int) ($config->get('jti_bytes') ?? 16));
    $lifetime = (int) ($config->get('access_token_lifetime') ?? 3600);
    $now = $this->time->getRequestTime();

    $jti = bin2hex(random_bytes($bytes));
    $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'jti_hash' => hash('sha256', $jti),
        'refresh_token_id' => $linked_refresh_token_id,
        'expires' => $now + $lifetime,
        'created' => $now,
      ])
      ->execute();

    return $jti;
  }

  /**
   * {@inheritdoc}
   */
  public function isValid(string $jti): bool {
    if ($jti === '') {
      return FALSE;
    }
    $hash = hash('sha256', $jti);
    if (isset($this->validCache[$hash])) {
      return $this->validCache[$hash];
    }

    $found = $this->database->select(self::TABLE, 's')
      ->fields('s', ['id'])
      ->condition('jti_hash', $hash)
      ->condition('expires', $this->time->getRequestTime(), '>')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $this->validCache[$hash] = ($found !== FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function linkToRefreshToken(string $jti, int $refresh_token_id): void {
    if ($jti === '') {
      return;
    }
    $this->database->update(self::TABLE)
      ->fields(['refresh_token_id' => $refresh_token_id])
      ->condition('jti_hash', hash('sha256', $jti))
      ->isNull('refresh_token_id')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getRefreshTokenIdForJti(string $jti): ?int {
    if ($jti === '') {
      return NULL;
    }
    $id = $this->database->select(self::TABLE, 's')
      ->fields('s', ['refresh_token_id'])
      ->condition('jti_hash', hash('sha256', $jti))
      ->execute()
      ->fetchField();
    // FALSE: no such session. NULL column: issued outside refresh flow.
    return ($id === FALSE || $id === NULL) ? NULL : (int) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function revokeForRefreshToken(int $refresh_token_id): void {
    $this->revokeForRefreshTokens([$refresh_token_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function revokeForRefreshTokens(array $refresh_token_ids): void {
    if ($refresh_token_ids === []) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('refresh_token_id', $refresh_token_ids, 'IN')
      ->execute();
    $this->validCache = [];
  }

  /**
   * {@inheritdoc}
   */
  public function revokeAllForUser(int $uid): void {
    $this->database->delete(self::TABLE)
      ->condition('uid', $uid)
      ->execute();
    $this->validCache = [];
  }

  /**
   * {@inheritdoc}
   */
  public function cleanupExpired(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('expires', $this->time->getRequestTime(), '<')
      ->execute();
  }

}
