<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers the token endpoints in JSON when no credential was presented.
 *
 * The token routes promise a JSON envelope on every failure, but a request
 * carrying no Authorization header at all never reaches the controller: the
 * route access check denies it and Drupal renders an HTML page. A frontend
 * whose token has just gone missing then gets HTML where it expected
 * {"error": ..., "message": ...}, which is the one error case it is most
 * likely to hit.
 *
 * Only the *absent* credential is answered here. If a credential was
 * presented, it is left alone: an opaque Bearer token may belong to another
 * authentication provider, and this module must not claim it. JWT-shaped
 * tokens that are expired, forged or revoked are handled earlier, and with a
 * more precise code, by JwtAuthErrorSubscriber.
 */
final class JwtApiAccessDeniedSubscriber implements EventSubscriberInterface {

  /**
   * The routes this module answers in JSON.
   */
  private const ROUTES = [
    'jwt_token_refresh.token',
    'jwt_token_refresh.token_refresh',
    'jwt_token_refresh.token_revoke',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of Drupal's own exception subscribers, which render HTML.
    return [KernelEvents::EXCEPTION => ['onException', 100]];
  }

  /**
   * Replaces an access denial with the module's typed 401.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');
    if (!in_array($route, self::ROUTES, TRUE)) {
      return;
    }

    $throwable = $event->getThrowable();
    if (!$throwable instanceof AccessDeniedHttpException
      && !$throwable instanceof UnauthorizedHttpException) {
      return;
    }

    // Something was presented: not ours to interpret.
    if ((string) $request->headers->get('Authorization', '') !== '') {
      return;
    }

    $response = new JsonResponse([
      'error' => 'invalid_token',
      'message' => 'Missing or invalid token.',
    ], 401);
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Pragma', 'no-cache');
    // RFC 9110: a 401 must say how to authenticate.
    $response->headers->set('WWW-Authenticate', 'Bearer');
    $event->setResponse($response);
  }

}
