<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface;
use Drupal\jwt_token_refresh\Service\PasswordRehashFlag;
use Drupal\jwt_token_refresh\Service\RefreshTokenManagerInterface;
use Drupal\user\UserInterface;

/**
 * Cron cleanup and account-lifecycle revocation.
 *
 * A credential or account-state change must invalidate sessions, else a
 * blocked account keeps refreshing for the whole lifetime of its token.
 *
 * Attribute-based hooks only exist from Drupal 11.1, so the procedural
 * implementations in jwt_token_refresh.module have to stay for 10.3 to 11.0.
 * They do nothing but call the methods here, and they carry #[LegacyHook] so
 * that a core which understands both runs this class once rather than both
 * twice. There is therefore one implementation to maintain, not two.
 *
 * @see \Drupal\Core\Hook\Attribute\LegacyHook
 */
final class JwtTokenRefreshHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RefreshTokenManagerInterface $refreshTokenManager,
    private readonly AccessTokenSessionManagerInterface $accessTokenSessionManager,
    private readonly LoggerChannelInterface $logger,
    private readonly PasswordRehashFlag $rehashFlag,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Purges expired refresh tokens (including revoked rows past their
   * reuse-detection window) and, when server-side revocation is enabled,
   * expired access token sessions.
   */
  #[Hook('cron')]
  public function cron(): void {
    $config = $this->configFactory->get('jwt_token_refresh.settings');
    if (!$config->get('cleanup_on_cron')) {
      return;
    }
    $removed = $this->refreshTokenManager->cleanupExpired();
    if ($config->get('enable_access_token_revocation')) {
      $removed += $this->accessTokenSessionManager->cleanupExpired();
    }
    if ($removed > 0) {
      $this->logger->info(
        'Purged @count expired token(s)/session(s).',
        ['@count' => $removed],
      );
    }
  }

  /**
   * Implements hook_user_update().
   *
   * Revokes every refresh token of a user when their password changes or
   * the account goes from active to blocked.
   */
  #[Hook('user_update')]
  public function userUpdate(UserInterface $account): void {
    // Cross-version original-entity access (->original is deprecated in
    // favour of ::getOriginal() on Drupal 11.2+).
    $original = method_exists($account, 'getOriginal')
      ? $account->getOriginal()
      : ($account->original ?? NULL);
    if (!$original instanceof UserInterface) {
      return;
    }

    // A login that re-encodes an outdated password saves the account with a
    // different hash; from here that looks exactly like a new password. The
    // login flags the account first so that one save does not log the user out
    // of their other devices.
    $rehash = $this->rehashFlag->take((int) $account->id());
    $password_changed = !$rehash
      && $account->getPassword() !== $original->getPassword();
    $just_blocked = $original->isActive() && !$account->isActive();

    if ($password_changed || $just_blocked) {
      $this->refreshTokenManager->revokeAllForUser((int) $account->id());
    }
  }

  /**
   * Implements hook_user_cancel().
   *
   * Purges refresh tokens when an account is cancelled.
   *
   * @phpstan-param array<string, mixed> $edit
   */
  #[Hook('user_cancel')]
  public function userCancel(
    array $edit,
    UserInterface $account,
    string $method,
  ): void {
    $this->refreshTokenManager->revokeAllForUser((int) $account->id());
  }

  /**
   * Implements hook_user_delete().
   *
   * Purges refresh tokens when a user is deleted (cleanup is applicative;
   * the Schema API creates no real foreign key).
   */
  #[Hook('user_delete')]
  public function userDelete(EntityInterface $entity): void {
    if ($entity instanceof UserInterface) {
      $this->refreshTokenManager->revokeAllForUser((int) $entity->id());
    }
  }

}
