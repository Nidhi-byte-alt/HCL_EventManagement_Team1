<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\jwt\Transcoder\JwtDecodeException;
use Drupal\jwt\Transcoder\JwtTranscoderInterface;
use Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns JWT auth failures into a typed 401 response.
 *
 * Runs before Drupal's AuthenticationSubscriber (priority 300) so a
 * stale, malformed or revoked Bearer token short-circuits with a
 * deterministic code the frontend can act on:
 *  - token_expired: try /auth/token/refresh.
 *  - invalid_token: forged or malformed, re-login.
 *  - session_revoked: logged out server-side, re-login.
 *
 * The jwt provider otherwise swallows the decode exception and lets the
 * request fall through to a generic 401/403, leaking nothing usable.
 */
final class JwtAuthErrorSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly JwtTranscoderInterface $transcoder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccessTokenSessionManagerInterface $accessSessions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 310]];
  }

  /**
   * Decodes the Bearer token and short-circuits on failure/revocation.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $header = (string) $event->getRequest()
      ->headers->get('Authorization', '');
    if (!str_starts_with($header, 'Bearer ')) {
      return;
    }
    $bearer = substr($header, 7);

    // Only act on JWT-shaped tokens (header.payload.signature). Opaque
    // Bearer tokens used by other auth providers pass through untouched.
    if (substr_count($bearer, '.') !== 2) {
      return;
    }

    try {
      $token = $this->transcoder->decode($bearer);
    }
    catch (JwtDecodeException $e) {
      $expired = $e->getCode() === JwtDecodeException::EXPIRED;
      $event->setResponse($this->error(
        $expired ? 'token_expired' : 'invalid_token',
        $expired ? 'The access token has expired.' : 'Invalid token.',
      ));
      return;
    }
    catch (\Throwable) {
      // Defensive: any other transcoder failure is an invalid token.
      $event->setResponse($this->error('invalid_token', 'Invalid token.'));
      return;
    }

    // The signature is valid; check the server-side allowlist.
    if (!$this->configFactory->get('jwt_token_refresh.settings')
      ->get('enable_access_token_revocation')) {
      return;
    }
    $jti = $token->getClaim('jti');
    if (is_string($jti) && $jti !== ''
      && !$this->accessSessions->isValid($jti)) {
      $event->setResponse($this->error(
        'session_revoked',
        'This session has been revoked.',
      ));
    }
  }

  /**
   * Builds a no-store typed 401.
   */
  private function error(string $code, string $message): JsonResponse {
    $response = new JsonResponse(
      ['error' => $code, 'message' => $message],
      401,
    );
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
