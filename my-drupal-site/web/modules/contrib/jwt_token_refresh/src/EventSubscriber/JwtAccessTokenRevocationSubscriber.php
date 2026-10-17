<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\jwt\Authentication\Event\JwtAuthEvents;
use Drupal\jwt\Authentication\Event\JwtAuthValidateEvent;
use Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rejects revoked access tokens inside the jwt_auth provider.
 *
 * Runs on JwtAuthEvents::VALIDATE (after signature verification), so it
 * covers every route authenticated by jwt_auth — REST resources via
 * _auth included — not only those flagged _jwt_auth. This is the
 * authoritative, route-agnostic revocation check; the request-level
 * subscriber adds the typed 401 for the common path. No-op (no DB
 * lookup) when revocation is disabled.
 */
final class JwtAccessTokenRevocationSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccessTokenSessionManagerInterface $accessSessions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [JwtAuthEvents::VALIDATE => ['onValidate', 0]];
  }

  /**
   * Invalidates a token whose jti is no longer allow-listed.
   */
  public function onValidate(JwtAuthValidateEvent $event): void {
    if (!$this->configFactory->get('jwt_token_refresh.settings')
      ->get('enable_access_token_revocation')) {
      return;
    }
    $jti = $event->getToken()->getClaim('jti');
    // Tokens minted while revocation was off carry no jti and are left
    // to expire naturally.
    if (!is_string($jti) || $jti === '') {
      return;
    }
    if (!$this->accessSessions->isValid($jti)) {
      $event->invalidate('session_revoked');
    }
  }

}
