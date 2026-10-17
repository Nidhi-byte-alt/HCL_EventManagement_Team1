<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\jwt\Authentication\Event\JwtAuthEvents;
use Drupal\jwt\Authentication\Event\JwtAuthGenerateEvent;
use Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sets the JWT lifetime, and (opt-in) a revocable jti claim.
 *
 * The jwt_auth_issuer submodule hardcodes exp at +1 hour (priority
 * 100). This runs just after (priority 90) and overwrites exp/iat with
 * the configured access_token_lifetime, so every issued token honours
 * the admin setting — whatever path issued it (login, refresh, or a
 * project issuer such as Azure AD).
 *
 * When enable_access_token_revocation is on, it also adds a jti claim
 * and records it in the allowlist at the same point, so no legitimate
 * JWT is ever emitted outside the allowlist (a token issued without a
 * matching session would be rejected as session_revoked).
 */
final class JwtClaimsSubscriber implements EventSubscriberInterface {

  /**
   * Request attribute carrying the issuing refresh token row id (in).
   */
  public const REFRESH_TOKEN_ID_ATTRIBUTE = '_jwt_token_refresh_rtid';

  /**
   * Request attribute carrying the jti just issued (out).
   *
   * Lets a later subscriber (e.g. the /user/login injector) link the
   * session to a refresh token it creates after issuance.
   */
  public const ISSUED_JTI_ATTRIBUTE = '_jwt_token_refresh_issued_jti';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly AccessTokenSessionManagerInterface $accessSessions,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [JwtAuthEvents::GENERATE => ['onGenerate', 90]];
  }

  /**
   * Overwrites exp/iat and, when enabled, adds a revocable jti.
   */
  public function onGenerate(JwtAuthGenerateEvent $event): void {
    $config = $this->configFactory->get('jwt_token_refresh.settings');
    $now = $this->time->getRequestTime();
    $lifetime = (int) ($config->get('access_token_lifetime') ?? 3600);
    $event->addClaim('iat', $now);
    $event->addClaim('exp', $now + $lifetime);

    if (!$config->get('enable_access_token_revocation')) {
      return;
    }

    // The issuer set the uid claim at priority 99; a non-Drupal token
    // (no uid) is left untracked.
    $uid = $event->getToken()->getClaim(['drupal', 'uid']);
    if ($uid === NULL) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $rtid = $request?->attributes->get(self::REFRESH_TOKEN_ID_ATTRIBUTE);
    $jti = $this->accessSessions->create(
      (int) $uid,
      $rtid !== NULL ? (int) $rtid : NULL,
    );
    $event->addClaim('jti', $jti);
    // Expose the jti so a post-issuance subscriber can link it.
    $request?->attributes->set(self::ISSUED_JTI_ATTRIBUTE, $jti);
  }

}
