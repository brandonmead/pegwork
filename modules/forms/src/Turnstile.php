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
	 * `data-action` names the form, so a token solved for another form is refused (verify()).
	 *
	 * @param string $base   Base ID of the form instance.
	 * @param string $action Widget action (action()).
	 */
	public static function widget( string $base, string $action = '' ): string {
		$keys = self::keys();
		if ( null === $keys ) {
			return '';
		}
		self::enqueue();

		return '<div class="pegwork-form__captcha" id="' . esc_attr( $base ) . '-captcha">'
			. '<div class="cf-turnstile" data-sitekey="' . esc_attr( $keys['site'] ) . '"' . ( '' !== $action ? ' data-action="' . esc_attr( $action ) . '"' : '' ) . ' data-response-field-name="' . esc_attr( Submission::CONTROL . '[' . self::FIELD . ']' ) . '" data-theme="auto" data-size="flexible"></div>'
			. '</div>';
	}

	/**
	 * The widget action of a form: its key, cut to Turnstile's 32 characters.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function action( array $form ): string {
		return substr( (string) $form['key'], 0, 32 );
	}

	/**
	 * The notice shown without JavaScript (top of the form, before anyone fills it in).
	 */
	public static function noscript(): string {
		return '<noscript><p class="pegwork-form__notice pegwork-form__notice--problem">' . esc_html__( 'This form needs JavaScript for its spam check. Turn on JavaScript in your browser to send it.', 'pegwork' ) . '</p></noscript>';
	}

	/**
	 * Inline script placed right after the submit button, which is rendered `disabled`: it enables the
	 * button, so only a browser running JavaScript (and so the widget) can send the form. Without
	 * JavaScript the button stays disabled under the noscript notice.
	 */
	public static function enable_submit(): string {
		return wp_get_inline_script_tag( 'document.currentScript.previousElementSibling.disabled=false;' );
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
	 * Checks a widget token with siteverify (`wp_safe_remote_post`, with the client IP as `remoteip`). A
	 * passing answer must also name this site's hostname (filter `pegwork_forms_turnstile_hostnames`) and,
	 * when an action is given, that action, as Cloudflare recommends; Cloudflare's published test secrets
	 * answer for any hostname, so those two checks are skipped for them.
	 *
	 * @param mixed  $token  Posted token.
	 * @param string $action Expected widget action (action()), or '' for none.
	 * @return string `pass`; `fail` (no token, or Cloudflare rejected it: a bot, an expired or reused
	 *                token, another hostname or form); `unverified` (no keys, network error, unexpected
	 *                answer, or a site-side error code such as a wrong secret: nobody can tell whether the
	 *                visitor is a person).
	 */
	public static function verify( mixed $token, string $action = '' ): string {
		$keys = self::keys();
		if ( null === $keys ) {
			return 'unverified';
		}
		$token = is_string( $token ) ? trim( $token ) : '';
		// Cloudflare tokens are at most 2048 characters.
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return 'fail';
		}

		$fields = array(
			'secret'   => $keys['secret'],
			'response' => $token,
		);
		$ip     = RateLimit::client_ip();
		if ( '' !== $ip ) {
			$fields['remoteip'] = $ip;
		}
		$response = wp_safe_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => $fields,
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
			if ( self::test_secret( $keys['secret'] ) ) {
				return 'pass';
			}

			/**
			 * Filters the hostnames a Turnstile token may have been solved on.
			 *
			 * @param string[] $hostnames Default: the host of home_url().
			 */
			$hostnames = (array) apply_filters( 'pegwork_forms_turnstile_hostnames', array( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			$hostname  = is_string( $body['hostname'] ?? null ) ? strtolower( $body['hostname'] ) : '';
			if ( ! in_array( $hostname, array_map( 'strtolower', array_filter( $hostnames, 'is_string' ) ), true ) ) {
				return 'fail';
			}
			return '' === $action || ( $body['action'] ?? null ) === $action ? 'pass' : 'fail';
		}
		$codes = is_array( $body['error-codes'] ?? null ) ? $body['error-codes'] : array();

		return array() !== array_intersect( $codes, self::SITE_ERRORS ) ? 'unverified' : 'fail';
	}

	/**
	 * Whether a secret is one of Cloudflare's published test secrets (`1x`, `2x` or `3x`, then zeros, `AA`).
	 *
	 * @param string $secret Secret key.
	 */
	private static function test_secret( string $secret ): bool {
		return 1 === preg_match( '/^[123]x0{31}AA$/', $secret );
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
