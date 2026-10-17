<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh_test\EventSubscriber;

use Drupal\jwt_token_refresh\Event\JwtLoginResponseEvent;
use Drupal\jwt_token_refresh\Event\JwtRefreshResponseEvent;
use Drupal\jwt_token_refresh\Event\JwtTokenRefreshEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds marker fields so tests can prove response enrichment.
 */
final class TestResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      JwtTokenRefreshEvents::LOGIN_RESPONSE => 'onLogin',
      JwtTokenRefreshEvents::REFRESH_RESPONSE => 'onRefresh',
    ];
  }

  /**
   * Enriches the login response.
   */
  public function onLogin(JwtLoginResponseEvent $event): void {
    $event->addData('login_marker', (int) $event->getUser()->id());
  }

  /**
   * Enriches the refresh response.
   */
  public function onRefresh(JwtRefreshResponseEvent $event): void {
    $event->addData('refresh_marker', (int) $event->getUser()->id());
  }

}
