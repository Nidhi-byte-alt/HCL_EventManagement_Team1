<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Service;

/**
 * Manages refresh tokens: creation, rotation, validation and revocation.
 *
 * Tokens are stored hashed (SHA256). The raw token is returned to the
 * caller exactly once, at creation, and never persisted in clear.
 */
interface RefreshTokenManagerInterface {

  /**
   * Creates a new refresh token for a user.
   *
   * @param int $uid
   *   The user the token is issued to.
   * @param string|null $family_id
   *   Family inherited on rotation; NULL means a fresh login (a new
   *   family is generated). All tokens descending from the same login
   *   share a family, used for OWASP reuse detection.
   *
   * @return array{token: string, expires: int, id: int}
   *   The raw (unhashed) token, its expiry timestamp, and the new row
   *   id (used to link issued access token sessions for revocation).
   */
  public function create(int $uid, ?string $family_id = NULL): array;

  /**
   * Validates a refresh token and returns the associated user id.
   *
   * Only non-revoked, non-expired tokens validate. NULL-safe.
   *
   * @param string $raw_token
   *   The raw token to validate.
   *
   * @return int|null
   *   The uid, or NULL if invalid/expired/revoked.
   */
  public function validate(string $raw_token): ?int;

  /**
   * Rotates a token: revokes the old one, issues a new pair.
   *
   * The revocation is an atomic conditional UPDATE (revoked 0 -> 1)
   * which doubles as the concurrency lock. A replay of an already
   * rotated token triggers reuse detection: the whole family is
   * revoked (return stays NULL — nothing leaks to the caller).
   *
   * @param string $old_raw_token
   *   The raw token being rotated.
   *
   * @return array{token: string, expires: int, id: int}|null
   *   The new token, or NULL if the old one was invalid/expired/
   *   replayed, or the account is no longer active.
   */
  public function rotate(string $old_raw_token): ?array;

  /**
   * Revokes a single token.
   *
   * @param string $raw_token
   *   The raw token to revoke. Unknown tokens are a no-op (idempotent).
   */
  public function revoke(string $raw_token): void;

  /**
   * Revokes a refresh token by its row id (per-device logout).
   *
   * Typical flow: resolve the authenticated request's access token jti
   * to its issuing refresh token id via
   * AccessTokenSessionManagerInterface::getRefreshTokenIdForJti(), then
   * revoke just that session — other devices stay logged in. Cascades
   * to the linked access token sessions. Unknown ids are a no-op.
   *
   * Callers are responsible for authorization: only pass ids resolved
   * from credentials owned by the current user.
   *
   * @param int $id
   *   The jwt_refresh_tokens row id.
   */
  public function revokeById(int $id): void;

  /**
   * Revokes every token of a user (logout all devices).
   *
   * @param int $uid
   *   The user whose tokens are revoked.
   */
  public function revokeAllForUser(int $uid): void;

  /**
   * Revokes a whole token family (OWASP reuse detection).
   *
   * Replaying a token that was already rotated is a theft indicator,
   * so the entire family — including the attacker's (or the victim's)
   * currently active token — is revoked.
   *
   * @param string $family_id
   *   The family to revoke.
   */
  public function revokeFamily(string $family_id): void;

  /**
   * Deletes expired tokens (for cron).
   *
   * Removes any token past its expiry, including revoked rows kept
   * until then for the reuse-detection window.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function cleanupExpired(): int;

}
