<?php
/**
 * Cloudflare Turnstile spam check.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Turnstile for forms with `"captcha": "turnstile"`: the widget on the last step, a `noscript` notice,
 * and one siteverify call per submission.
 *
 * Keys come only from the wp-config constants `PEGWORK_TURNSTILE_SITE_KEY` and
 * `PEGWORK_TURNSTILE_SECRET_KEY`, never from registry JSON, options or exports. Without both keys a
 * Turnstile form fails closed (Definition::unsupported()) and users who manage forms get an admin notice.
 */
final class Turnstile {

	/**
	 * Cloudflare's widget script (implicit rendering of `.cf-turnstile` elements).
	 */
	public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/**
	 * Cloudflare's server-side validation endpoint.
	 */
	public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Script handle.
	 */
	public const HANDLE = 'pegwork-turnstile';

	/**
	 * Control field the widget writes its token to (`data-response-field-name`).
	 */
	public const FIELD = 'captcha';

	/**
	 * Siteverify error codes that mean the site, not the visitor, has a problem: the submission is
	 * stored for an admin to rescue (`captcha_unverified`) instead of being treated as a failed check.
	 */
	private const SITE_ERRORS = array( 'missing-input-secret', 'invalid-input-secret', 'internal-error' );

	/**
	 * Names of the wp-config constants holding the keys. Private and never read from anywhere else;
	 * tests point it at undefined names to check the missing-keys path, since a defined constant
	 * cannot be undefined within one PHP process.
	 *
	 * @var array{site: string, secret: string}
	 */
	private static array $constants = array(
		'site'   => 'PEGWORK_TURNSTILE_SITE_KEY',
		'secret' => 'PEGWORK_TURNSTILE_SECRET_KEY',
	);

	/**
	 * Hooks the admin notice. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'admin_notices', array( self::class, 'admin_notice' ) ) ) {
			add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		}
	}

	/**
	 * Whether a form uses Turnstile.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function enabled( array $form ): bool {
		return 'turnstile' === ( $form['captcha'] ?? null );
	}

	/**
	 * The site and secret keys from wp-config, or null when either is missing or empty.
	 *
	 * @return array{site: string, secret: string}|null
	 */
	public static function keys(): ?array {
		$site   = defined( self::$constants['site'] ) ? constant( self::$constants['site'] ) : '';
		$secret = defined( self::$constants['secret'] ) ? constant( self::$constants['secret'] ) : '';
		if ( ! is_string( $site ) || ! is_string( $secret ) || '' === trim( $site ) || '' === trim( $secret ) ) {
			return null;
		}

		return array(
			'site'   => trim( $site ),
			'secret' => trim( $secret ),
		);
	}

	/**
	 * The widget for the end of the last step. Enqueues Cloudflare's script (footer, deferred).
	 *
	 * The widget writes its token to `pegwork_form[captcha]`, so it arrives with the other control fields.
	 *
	 * @param string $base Base ID of the form instance.
	 */
	public static function widget( string $base ): string {
		$keys = self::keys();
		if ( null === $keys ) {
			return '';
		}
		self::enqueue();

		return '<div class="pegwork-form__captcha" id="' . esc_attr( $base ) . '-captcha">'
			. '<div class="cf-turnstile" data-sitekey="' . esc_attr( $keys['site'] ) . '" data-response-field-name="' . esc_attr( Submission::CONTROL . '[' . self::FIELD . ']' ) . '" data-theme="auto" data-size="flexible"></div>'
			. '</div>';
	}

	/**
	 * The notice shown without JavaScript (top of the form, before anyone fills it in).
	 */
	public static function noscript(): string {
		return '<noscript><p class="pegwork-form__notice pegwork-form__notice--problem">' . esc_html__( 'This form needs JavaScript for its spam check. Turn on JavaScript in your browser to send it.', 'pegwork' ) . '</p></noscript>';
	}

	/**
	 * Enqueues Cloudflare's script. Loaded without a version query: Cloudflare serves api.js from one
	 * fixed URL and does not support altered or cached copies.
	 */
	public static function enqueue(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			// phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare requires the exact URL, no version query.
			wp_register_script(
				self::HANDLE,
				self::SCRIPT_URL,
				array(),
				null,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
			// phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Checks a widget token with siteverify (`wp_safe_remote_post`).
	 *
	 * @param mixed $token Posted token.
	 * @return string `pass`; `fail` (no token, or Cloudflare rejected it: a bot, an expired or reused
	 *                token); `unverified` (no keys, network error, unexpected answer, or a site-side error
	 *                code such as a wrong secret: nobody can tell whether the visitor is a person).
	 */
	public static function verify( mixed $token ): string {
		$keys = self::keys();
		if ( null === $keys ) {
			return 'unverified';
		}
		$token = is_string( $token ) ? trim( $token ) : '';
		// Cloudflare tokens are at most 2048 characters.
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return 'fail';
		}

		$response = wp_safe_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $keys['secret'],
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'unverified';
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! array_key_exists( 'success', $body ) ) {
			return 'unverified';
		}
		if ( true === $body['success'] ) {
			return 'pass';
		}
		$codes = is_array( $body['error-codes'] ?? null ) ? $body['error-codes'] : array();

		return array() !== array_intersect( $codes, self::SITE_ERRORS ) ? 'unverified' : 'fail';
	}

	/**
	 * Labels of registry forms that use Turnstile.
	 *
	 * @return string[]
	 */
	public static function forms(): array {
		$labels = array();
		foreach ( pegwork_registry()->forms() as $form ) {
			if ( self::enabled( $form ) ) {
				$labels[] = (string) $form['label'];
			}
		}
		return $labels;
	}

	/**
	 * Admin notice while forms use Turnstile but the keys are missing. Hooked to admin_notices.
	 */
	public static function admin_notice(): void {
		if ( null !== self::keys() || ! current_user_can( Module::capability() ) ) {
			return;
		}
		$forms = self::forms();
		if ( array() === $forms ) {
			return;
		}

		echo '<div class="notice notice-error pegwork-turnstile-notice"><p>' . esc_html(
			sprintf(
				/* translators: 1: list of form labels, 2: constant name, 3: constant name. */
				_n(
					'Pegwork Forms: the form %1$s uses Cloudflare Turnstile, but its keys are not set, so visitors cannot see or send it. Define %2$s and %3$s in wp-config.php.',
					'Pegwork Forms: the forms %1$s use Cloudflare Turnstile, but its keys are not set, so visitors cannot see or send them. Define %2$s and %3$s in wp-config.php.',
					count( $forms ),
					'pegwork'
				),
				'"' . implode( '", "', $forms ) . '"',
				'PEGWORK_TURNSTILE_SITE_KEY',
				'PEGWORK_TURNSTILE_SECRET_KEY'
			)
		) . '</p></div>';
	}
}
