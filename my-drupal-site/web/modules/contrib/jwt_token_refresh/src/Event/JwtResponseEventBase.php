<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Event;

use Drupal\user\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base for the login / refresh response events.
 *
 * Carries the authenticated user and the (mutable) response body so
 * subscribers can add fields such as a CSRF token or user profile.
 */
abstract class JwtResponseEventBase extends Event {

  /**
   * Constructs a response event.
   *
   * @param \Drupal\user\UserInterface $user
   *   The authenticated user.
   * @param array $responseData
   *   The response body being built.
   *
   * @phpstan-param array<string, mixed> $responseData
   */
  public function __construct(
    protected readonly UserInterface $user,
    protected array $responseData,
  ) {}

  /**
   * The authenticated user.
   */
  public function getUser(): UserInterface {
    return $this->user;
  }

  /**
   * The current response body.
   *
   * @phpstan-return array<string, mixed>
   */
  public function getResponseData(): array {
    return $this->responseData;
  }

  /**
   * Replaces the response body.
   *
   * @phpstan-param array<string, mixed> $data
   */
  public function setResponseData(array $data): void {
    $this->responseData = $data;
  }

  /**
   * Adds (or overwrites) a single field in the response body.
   */
  public function addData(string $key, mixed $value): void {
    $this->responseData[$key] = $value;
  }

}
