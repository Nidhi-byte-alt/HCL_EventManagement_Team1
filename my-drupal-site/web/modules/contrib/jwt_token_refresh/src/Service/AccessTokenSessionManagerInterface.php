<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Service;

/**
 * Server-side access token (jti) allowlist for immediate logout.
 *
 * Opt-in: only populated when enable_access_token_revocation is TRUE.
 * The raw jti is embedded in the JWT; only its SHA256 is stored.
 */
interface AccessTokenSessionManagerInterface {

  /**
   * Records a newly issued access token's jti.
   *
   * @param int $uid
   *   The user the token is issued to.
   * @param int|null $linked_refresh_token_id
   *   The refresh token row that issued this access token, or NULL if
   *   issued outside the refresh flow. Drives the applicative cascade.
   *
   * @return string
   *   The raw jti to embed in the JWT (its hash is stored internally).
   */
  public function create(int $uid, ?int $linked_refresh_token_id = NULL): string;

  /**
   * Returns TRUE if the jti is allow-listed and not expired.
   *
   * NULL-safe: returns FALSE on empty/garbage input. Memoized per
   * request so the same jti costs at most one DB lookup.
   */
  public function isValid(string $jti): bool;

  /**
   * Links an already-recorded session to its issuing refresh token.
   *
   * Used when the refresh token id is not known at issuance time (e.g.
   * the /user/login injection path). Only links a session that is not
   * already linked; a no-op otherwise.
   */
  public function linkToRefreshToken(string $jti, int $refresh_token_id): void;

  /**
   * Resolves a jti to the refresh token row that issued it.
   *
   * Enables per-device logout: from the authenticated request's access
   * token jti, find the issuing refresh token and revoke only that one
   * (RefreshTokenManagerInterface::revokeById()).
   *
   * @param string $jti
   *   The raw jti claim from the JWT.
   *
   * @return int|null
   *   The jwt_refresh_tokens row id, or NULL if the session is unknown
   *   or was issued outside the refresh flow.
   */
  public function getRefreshTokenIdForJti(string $jti): ?int;

  /**
   * Revokes all access tokens issued by a given refresh token row.
   *
   * Applicative cascade — there is no real FK in the database.
   */
  public function revokeForRefreshToken(int $refresh_token_id): void;

  /**
   * Bulk variant used by the max_tokens_per_user eviction.
   *
   * @param int[] $refresh_token_ids
   *   The refresh token row ids whose sessions are revoked.
   */
  public function revokeForRefreshTokens(array $refresh_token_ids): void;

  /**
   * Revokes every access token of a user (logout all devices).
   */
  public function revokeAllForUser(int $uid): void;

  /**
   * Deletes expired sessions (for cron).
   *
   * @return int
   *   Number of rows deleted.
   */
  public function cleanupExpired(): int;

}
