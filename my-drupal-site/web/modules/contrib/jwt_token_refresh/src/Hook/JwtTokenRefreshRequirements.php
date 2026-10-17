<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Reports on the status report whether a JWT signing key is configured.
 *
 * Without one this module cannot issue a token, and any request carrying a
 * Bearer token fails inside the jwt module rather than returning a typed
 * error. The site builder gets no hint of it until the first API call, so say
 * it here instead.
 *
 * Two requirements APIs have to be served, and the split is not arbitrary:
 * hook_runtime_requirements() only exists from Drupal 11.2 and attribute
 * hooks only from 11.1, so every core that has the newer API can discover
 * this class. The older API therefore stays procedural, in the .install file
 * where core looks for it, and does nothing but call ::keyRequirement().
 *
 * @see jwt_token_refresh_requirements()
 */
final class JwtTokenRefreshRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * The replacement for hook_requirements(), available from Drupal 11.2 and
   * mandatory from Drupal 13. Runtime by definition, so there is no phase to
   * check.
   *
   * @phpstan-return array<string, mixed>
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return $this->keyRequirement();
  }

  /**
   * Builds the signing-key requirement.
   *
   * @return array<string, mixed>
   *   A requirements array, keyed as core expects.
   */
  public function keyRequirement(): array {
    $error = $this->errorSeverity();
    $jwt_settings = Url::fromRoute('jwt.jwt_config_form')->toString();
    $requirement = ['title' => $this->t('JWT signing key')];

    $key_id = $this->configFactory->get('jwt.config')->get('key_id');
    if (!is_string($key_id) || $key_id === '') {
      $requirement['value'] = $this->t('Not configured');
      $requirement['severity'] = $error;
      $requirement['description'] = $this->t('JWT Token Refresh cannot issue or validate tokens until a signing key is selected. Create one at <a href=":keys">Keys</a>, then select it at <a href=":jwt">JWT Configuration</a>.', [
        ':keys' => Url::fromRoute('entity.key.collection')->toString(),
        ':jwt' => $jwt_settings,
      ]);
      return ['jwt_token_refresh_key' => $requirement];
    }

    $key = $this->entityTypeManager->getStorage('key')->load($key_id);
    if ($key === NULL) {
      $requirement['value'] = $this->t('Missing');
      $requirement['severity'] = $error;
      $requirement['description'] = $this->t('The configured signing key %id no longer exists. Select an existing key at <a href=":jwt">JWT Configuration</a>.', [
        '%id' => $key_id,
        ':jwt' => $jwt_settings,
      ]);
      return ['jwt_token_refresh_key' => $requirement];
    }

    // Leaving the severity unset is how core reports a met requirement.
    $requirement['value'] = $this->t('Configured (%label)', [
      '%label' => $key->label(),
    ]);
    return ['jwt_token_refresh_key' => $requirement];
  }

  /**
   * The error severity value the running core understands.
   *
   * Drupal 11.2 introduced the RequirementSeverity enum and deprecated the
   * REQUIREMENT_* constants. Both carry the same integer value, so either is
   * accepted by the core it belongs to. The class is resolved by name rather
   * than imported, because this method also serves the procedural
   * hook_requirements() on cores where the enum does not exist.
   *
   * @return mixed
   *   A RequirementSeverity case, or the legacy integer constant.
   */
  private function errorSeverity(): mixed {
    $enum = 'Drupal\Core\Extension\Requirement\RequirementSeverity';
    return enum_exists($enum) ? $enum::Error : REQUIREMENT_ERROR;
  }

}
