<?php

declare(strict_types=1);

namespace Drupal\jwt_token_refresh\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configures token lifetimes, flood limits and revocation.
 */
final class JwtTokenRefreshSettingsForm extends ConfigFormBase {

  /**
   * The settings config name.
   */
  private const CONFIG = 'jwt_token_refresh.settings';

  /**
   * Bounds enforced on save, carried by the elements as #min and #max.
   */
  private const MIN_ACCESS_LIFETIME = 60;
  private const MAX_ACCESS_LIFETIME = 86400;
  private const MIN_REFRESH_LIFETIME = 300;
  private const MAX_REFRESH_LIFETIME = 2592000;
  private const MIN_REFRESH_BYTES = 32;
  private const MAX_REFRESH_BYTES = 128;
  private const MIN_JTI_BYTES = 16;
  private const MAX_JTI_BYTES = 64;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'jwt_token_refresh_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return list<string>
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Every element carries a #config_target, so the parent class loads the
    // default values and saves the submitted ones; this form has no submit
    // handler of its own. The casts are not cosmetic: a number element
    // submits a string and a checkbox an integer, and writing either into
    // config as-is would store a string where the schema promises an integer
    // and 1 where it promises true.
    //
    // The bounds are on the elements rather than in a validateForm(): the
    // number element validates its own #min and #max, and that also renders
    // them in the markup, so a browser reports them before the round trip.
    $to_int = fn (mixed $value): int => (int) $value;
    $to_bool = fn (mixed $value): bool => (bool) $value;

    $form['access_token_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Access token lifetime (seconds)'),
      '#description' => $this->t('Sets the JWT exp claim. Between @min and @max.', [
        '@min' => self::MIN_ACCESS_LIFETIME,
        '@max' => self::MAX_ACCESS_LIFETIME,
      ]),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'access_token_lifetime',
        toConfig: $to_int,
      ),
      '#min' => self::MIN_ACCESS_LIFETIME,
      '#max' => self::MAX_ACCESS_LIFETIME,
      '#required' => TRUE,
    ];

    $form['refresh_token_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh token lifetime (seconds)'),
      '#description' => $this->t('Validity of a refresh token. Between @min and @max.', [
        '@min' => self::MIN_REFRESH_LIFETIME,
        '@max' => self::MAX_REFRESH_LIFETIME,
      ]),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'refresh_token_lifetime',
        toConfig: $to_int,
      ),
      '#min' => self::MIN_REFRESH_LIFETIME,
      '#max' => self::MAX_REFRESH_LIFETIME,
      '#required' => TRUE,
    ];

    $form['refresh_token_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Refresh token entropy (bytes)'),
      '#description' => $this->t(
        'Random bytes per token, between @min and @max (unsalted SHA256 is only safe with high input entropy).',
        [
          '@min' => self::MIN_REFRESH_BYTES,
          '@max' => self::MAX_REFRESH_BYTES,
        ],
      ),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'refresh_token_bytes',
        toConfig: $to_int,
      ),
      '#min' => self::MIN_REFRESH_BYTES,
      '#max' => self::MAX_REFRESH_BYTES,
      '#required' => TRUE,
    ];

    $form['max_tokens_per_user'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum refresh tokens per user'),
      '#description' => $this->t('Caps concurrent sessions. 0 disables the cap.'),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'max_tokens_per_user',
        toConfig: $to_int,
      ),
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['inject_into_user_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Inject a refresh token into /user/login'),
      '#description' => $this->t('Adds refresh_token, refresh_expires_in and token_type to the core <code>POST /user/login?_format=json</code> response, plus access_token if the jwt module did not already put one there. Leave this off if your frontend uses this module\'s own <code>POST /auth/token</code>, which is the intended endpoint. If you leave it off, consider also unchecking <em>Include a JWT token in the user login response</em> on the <a href=":jwt">JWT Configuration</a> page: that box is on by default and puts an access token in the core login response that nothing can refresh, which behaves like a working login until it expires.', [
        ':jwt' => Url::fromRoute('jwt.jwt_config_form')->toString(),
      ]),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'inject_into_user_login',
        toConfig: $to_bool,
      ),
    ];

    $form['cleanup_on_cron'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Purge expired tokens on cron'),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'cleanup_on_cron',
        toConfig: $to_bool,
      ),
    ];

    $form['flood_ip_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Login attempts per hour per IP'),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'flood_ip_limit',
        toConfig: $to_int,
      ),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['flood_user_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Login attempts per user/IP pair'),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'flood_user_limit',
        toConfig: $to_int,
      ),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['enable_access_token_revocation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable server-side access token revocation'),
      '#description' => $this->t(
        'Adds a jti claim and a DB allowlist for immediate logout. One indexed SELECT per authenticated request.'
      ),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'enable_access_token_revocation',
        toConfig: $to_bool,
      ),
    ];

    $form['jti_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('jti entropy (bytes)'),
      '#description' => $this->t('Between @min and @max. Ignored when revocation is off.', [
        '@min' => self::MIN_JTI_BYTES,
        '@max' => self::MAX_JTI_BYTES,
      ]),
      '#config_target' => new ConfigTarget(
        self::CONFIG,
        'jti_bytes',
        toConfig: $to_int,
      ),
      '#min' => self::MIN_JTI_BYTES,
      '#max' => self::MAX_JTI_BYTES,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

}
