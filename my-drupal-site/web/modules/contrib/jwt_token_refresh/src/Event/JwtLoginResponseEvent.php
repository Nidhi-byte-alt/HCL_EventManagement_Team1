<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Event;

/**
 * Dispatched after a successful login (POST /auth/token).
 */
final class JwtLoginResponseEvent extends JwtResponseEventBase {}
