<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Event;

/**
 * Events dispatched by jwt_token_refresh.
 *
 * Application modules can subscribe to enrich the login / refresh
 * response body without patching this module.
 */
final class JwtTokenRefreshEvents {

  /**
   * Fired after a successful login, before the response is returned.
   */
  public const LOGIN_RESPONSE = 'jwt_token_refresh.login_response';

  /**
   * Fired after a successful refresh, before the response is returned.
   */
  public const REFRESH_RESPONSE = 'jwt_token_refresh.refresh_response';

}
