<?php

/**
 * @file
 * Documents the extension points of the JWT Token Refresh module.
 *
 * This module implements no hooks of its own for other modules to alter. It
 * exposes two extension points instead: response events, and two tagged
 * services. Both are documented below.
 */

declare(strict_types=1);

/**
 * @defgroup jwt_token_refresh_events JWT Token Refresh events
 * @{
 * Events dispatched when a token pair is handed to a client.
 *
 * An application module can enrich the login and refresh response bodies
 * without patching this module, by subscribing to:
 *
 * - \Drupal\jwt_token_refresh\Event\JwtTokenRefreshEvents::LOGIN_RESPONSE
 *   ('jwt_token_refresh.login_response'), dispatched by
 *   POST /auth/token, carrying a
 *   \Drupal\jwt_token_refresh\Event\JwtLoginResponseEvent.
 * - \Drupal\jwt_token_refresh\Event\JwtTokenRefreshEvents::REFRESH_RESPONSE
 *   ('jwt_token_refresh.refresh_response'), dispatched by
 *   POST /auth/token/refresh, carrying a
 *   \Drupal\jwt_token_refresh\Event\JwtRefreshResponseEvent.
 *
 * Both events expose the authenticated user and the mutable response body:
 * ::getUser(), ::getResponseData(), ::setResponseData() and ::addData().
 * Keys added to the body appear as-is in the JSON response, so do not
 * overwrite 'access_token', 'refresh_token', 'refresh_expires_in' or
 * 'token_type' unless that is the intent.
 *
 * Do not put anything secret in there: the body is what the client receives.
 *
 * @code
 * // my_module/src/EventSubscriber/MyLoginSubscriber.php
 * use Drupal\jwt_token_refresh\Event\JwtLoginResponseEvent;
 * use Drupal\jwt_token_refresh\Event\JwtTokenRefreshEvents;
 * use Symfony\Component\EventDispatcher\EventSubscriberInterface;
 *
 * final class MyLoginSubscriber implements EventSubscriberInterface {
 *
 *   public static function getSubscribedEvents(): array {
 *     return [JwtTokenRefreshEvents::LOGIN_RESPONSE => 'onLogin'];
 *   }
 *
 *   public function onLogin(JwtLoginResponseEvent $event): void {
 *     $event->addData('display_name', $event->getUser()->getDisplayName());
 *   }
 *
 * }
 * @endcode
 *
 * @}
 */

/**
 * @defgroup jwt_token_refresh_services JWT Token Refresh services
 * @{
 * Services an application module can call directly.
 *
 * - 'jwt_token_refresh.refresh_token_manager', implementing
 *   \Drupal\jwt_token_refresh\Service\RefreshTokenManagerInterface: the
 *   refresh token lifecycle (::create(), ::validate(), ::rotate(),
 *   ::revoke(), ::revokeById(), ::revokeAllForUser(), ::revokeFamily(),
 *   ::cleanupExpired()).
 * - 'jwt_token_refresh.access_token_session_manager', implementing
 *   \Drupal\jwt_token_refresh\Service\AccessTokenSessionManagerInterface:
 *   the opt-in server-side revocation allowlist, keyed by the JWT 'jti'
 *   claim. Only meaningful when 'enable_access_token_revocation' is on.
 *
 * Signing out every device of a user is one call:
 *
 * @code
 * \Drupal::service('jwt_token_refresh.refresh_token_manager')
 *   ->revokeAllForUser($uid);
 * @endcode
 *
 * Signing out only the device that made the current request means resolving
 * that request's access token back to the refresh token that issued it. This
 * requires server-side revocation to be enabled:
 *
 * @code
 * $jti = $jwt->getClaim('jti');
 * $id = \Drupal::service('jwt_token_refresh.access_token_session_manager')
 *   ->getRefreshTokenIdForJti($jti);
 * if ($id !== NULL) {
 *   \Drupal::service('jwt_token_refresh.refresh_token_manager')
 *     ->revokeById($id);
 * }
 * @endcode
 *
 * Authorization is the caller's responsibility: only revoke ids resolved from
 * the current user's own token, never an id taken from a request parameter.
 *
 * @}
 */
