<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Event;

/**
 * Dispatched after a successful refresh (POST /auth/token/refresh).
 */
final class JwtRefreshResponseEvent extends JwtResponseEventBase {}
