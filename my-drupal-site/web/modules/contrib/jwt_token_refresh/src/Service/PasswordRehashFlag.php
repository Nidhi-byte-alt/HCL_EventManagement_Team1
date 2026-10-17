<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Service;

/**
 * Marks the account saves that are a password rehash, not a new password.
 *
 * A successful login rehashes a password whose stored hash is outdated, which
 * saves the account with a different hash. From hook_user_update() that is
 * indistinguishable from a user choosing a new password — both are a hash that
 * moved — so the module would revoke every refresh token of that user and log
 * their other devices out because the site had changed its hashing settings.
 *
 * The login flags the account here before it saves, and the hook takes the
 * flag back, so exactly one save is exempt. The flag lives for one request:
 * nothing outside it, and nothing later in it, is affected.
 *
 * @see \Drupal\jwt_token_refresh\Controller\TokenController::rehash()
 * @see \Drupal\jwt_token_refresh\Hook\JwtTokenRefreshHooks::userUpdate()
 */
final class PasswordRehashFlag {

  /**
   * The accounts whose next save is a rehash, keyed by uid.
   *
   * @var array<int, true>
   */
  private array $uids = [];

  /**
   * Flags the next save of an account as a rehash.
   */
  public function flag(int $uid): void {
    $this->uids[$uid] = TRUE;
  }

  /**
   * Returns whether an account was flagged, and clears the flag.
   */
  public function take(int $uid): bool {
    $flagged = isset($this->uids[$uid]);
    unset($this->uids[$uid]);
    return $flagged;
  }

}
