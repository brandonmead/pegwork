<?php
/**
 * Submission pipeline for entry-only forms.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api\Events;

/**
 * Handles a same-page POST on template_redirect, then redirects (POST-redirect-GET) or leaves the
 * outcome for the renderer to re-render the form with errors and the visitor's values.
 *
 * Order: resolve the form from the registry (never from the request), access, nonce (logged-in users
 * only), signed time token, IP rate limit, collect and sanitize values (conditions false: ignored),
 * spam layers (honeypot, time trap, `pegwork_forms_spam_check`), validation, email rate limit,
 * duplicate fingerprint, store, notify, emit `form.submitted`.
 */
final class Submission {

	/**
	 * POST array holding field values, by item key. Prefixed: bare names such as `name` are query vars.
	 */
	public const VALUES = 'pegwork';

	/**
	 * POST array holding the control fields: `key`, `token`, `nonce`, and the honeypot `url`.
	 */
	public const CONTROL = 'pegwork_form';

	/**
	 * Query arg of the success state after the redirect.
	 */
	public const SENT = 'pegwork_sent';

	/**
	 * Window, in seconds, in which an identical submission is treated as a repeat (double click, back button).
	 */
	public const DUPLICATE_WINDOW = 600;

	/**
	 * Longest accepted value, in characters, for any field.
	 */
	public const MAX_LENGTH = 65535;

	/**
	 * Outcomes of this request's POST, by form key, for the renderer.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $state = array();

	/**
	 * Registers the handler. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'template_redirect', array( self::class, 'handle' ) ) ) {
			add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
		}
	}

	/**
	 * The outcome of this request's POST for a form, or null.
	 *
	 * @param string $key Form key.
	 * @return array<string, mixed>|null `outcome` plus `errors` and `values` for a re-render.
	 */
	public static function state( string $key ): ?array {
		return self::$state[ $key ] ?? null;
	}

	/**
	 * Clears per-request state (tests).
	 */
	public static function reset(): void {
		self::$state = array();
	}

	/**
	 * Handles a form POST. Hooked to template_redirect, priority 1.
	 */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anonymous forms cannot use nonces; process() verifies one for logged-in users and checks the signed token for everyone.
		if ( 'POST' !== $method || ! isset( $_POST[ self::CONTROL ] ) || ! is_array( $_POST[ self::CONTROL ] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above; every value is sanitized per field type in process().
		$control = wp_unslash( $_POST[ self::CONTROL ] );
		$raw     = isset( $_POST[ self::VALUES ] ) && is_array( $_POST[ self::VALUES ] ) ? wp_unslash( $_POST[ self::VALUES ] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$key = is_string( $control['key'] ?? null ) ? $control['key'] : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) ) {
			return;
		}
		$form = pegwork_registry()->form( $key );
		if ( null === $form || null !== Definition::unsupported( $form ) ) {
			return;
		}

		$result = self::process( $form, $raw, $control, time(), get_queried_object_id() );

		if ( in_array( $result['outcome'], array( 'sent', 'spam', 'duplicate' ), true ) ) {
			// No #fragment: browsers skip `autofocus` when the URL targets an element, and the focused
			// success region scrolls itself into view.
			$target = add_query_arg( self::SENT, $key, self::current_url() );
			wp_safe_redirect( $target, 303 );
			exit;
		}

		self::$state[ $key ] = $result;
		if ( 'invalid' === $result['outcome'] ) {
			add_filter( 'document_title_parts', array( self::class, 'error_title' ) );
		}
		nocache_headers();
	}

	/**
	 * Runs the pipeline for one submission.
	 *
	 * @param array<string, mixed> $form    Canonical, supported form.
	 * @param mixed                $raw     Posted values by item key (unslashed).
	 * @param mixed                $control Posted control fields (unslashed).
	 * @param int                  $now     Unix time.
	 * @param int                  $page_id Page that held the form, 0 if none.
	 * @return array<string, mixed> `outcome`: `sent`, `spam`, `duplicate` (all redirect to the success state),
	 *                              or `invalid`, `denied`, `expired`, `rate_limited`, `failed` (re-render, nothing
	 *                              stored); plus `entry_id`, `errors` (message by item key) and `values` (posted text).
	 */
	public static function process( array $form, mixed $raw, mixed $control, int $now, int $page_id = 0 ): array {
		$key     = (string) $form['key'];
		$raw     = is_array( $raw ) ? $raw : array();
		$control = is_array( $control ) ? $control : array();
		$posted  = self::posted_text( $form, $raw );
		$fail    = fn( string $outcome, array $errors = array() ) => array(
			'outcome' => $outcome,
			'errors'  => $errors,
			'values'  => $posted,
		);

		if ( ! Access::allowed( $form ) ) {
			return $fail( 'denied' );
		}
		if ( is_user_logged_in() && ! wp_verify_nonce( is_string( $control['nonce'] ?? null ) ? $control['nonce'] : '', Renderer::nonce_action( $key ) ) ) {
			return $fail( 'expired' );
		}

		$token = Token::check( $key, $control['token'] ?? null, $now );
		if ( 'invalid' === $token || 'expired' === $token ) {
			return $fail( 'expired' );
		}

		$ip = RateLimit::client_ip();
		if ( RateLimit::exceeded( 'ip', $key, $ip, $now ) ) {
			return $fail( 'rate_limited' );
		}

		[ $values, $format_errors ] = self::collect( $form, $raw );

		$reasons = array();
		if ( '' !== trim( is_scalar( $control['url'] ?? null ) ? (string) $control['url'] : 'x' ) ) {
			$reasons[] = 'honeypot';
		}
		if ( 'too_fast' === $token ) {
			$reasons[] = 'too_fast';
		}
		/**
		 * Filters the spam reasons for a submission. Return a non-empty list to store it as spam
		 * (no mail, no events; the visitor sees the normal success message).
		 *
		 * @param string[] $reasons Reasons found so far (`honeypot`, `too_fast`).
		 * @param array    $form    Canonical form.
		 * @param array    $values  Sanitized values by item key.
		 */
		$reasons = apply_filters( 'pegwork_forms_spam_check', $reasons, $form, $values );
		$reasons = is_array( $reasons ) ? array_values( array_unique( array_filter( $reasons, 'is_string' ) ) ) : array();

		$fingerprint = hash( 'sha256', $key . "\n" . wp_json_encode( self::canonical( $values ) ) );
		$email_key   = Definition::email_key( $form );
		$email       = null !== $email_key && is_string( $values[ $email_key ] ?? null ) ? strtolower( $values[ $email_key ] ) : '';
		$base        = array(
			'form_key'    => $key,
			'email'       => $email,
			'user_id'     => get_current_user_id(),
			'values'      => $values,
			'fingerprint' => $fingerprint,
			'created_at'  => gmdate( 'Y-m-d H:i:s', $now ),
		);
		$meta        = array(
			'page_id'         => $page_id,
			'definition_hash' => Definition::hash( $form ),
		);

		if ( $reasons ) {
			$entry_id = Entries::insert( $base + array( 'status' => 'spam' ) + array( 'meta' => $meta + array( 'spam' => $reasons ) ) );
			RateLimit::hit( 'ip', $key, $ip, $now );
			return array(
				'outcome'  => 'spam',
				'entry_id' => $entry_id,
			);
		}

		$errors = $format_errors + self::validate( $form, $values );
		if ( $errors ) {
			return $fail( 'invalid', self::ordered( $form, $errors ) );
		}

		if ( RateLimit::exceeded( 'email', $key, $email, $now ) ) {
			return $fail( 'rate_limited' );
		}
		if ( Entries::fingerprint_seen( $fingerprint, $now - self::DUPLICATE_WINDOW ) ) {
			return array(
				'outcome'  => 'duplicate',
				'entry_id' => 0,
			);
		}

		$entry_id = Entries::insert( $base + array( 'status' => 'received' ) + array( 'meta' => $meta ) );
		if ( 0 === $entry_id ) {
			return $fail( 'failed' );
		}
		RateLimit::hit( 'ip', $key, $ip, $now );
		RateLimit::hit( 'email', $key, $email, $now );

		$meta['notifications'] = Notifications::send(
			$form,
			'submitted',
			array(
				'entry_id' => $entry_id,
				'values'   => $values,
			)
		);
		Entries::update_meta( $entry_id, $meta );

		Events::emit(
			'form.submitted',
			array(
				'form'      => $key,
				'entry_id'  => $entry_id,
				'record_id' => null,
				'values'    => $values,
			)
		);

		return array(
			'outcome'  => 'sent',
			'entry_id' => $entry_id,
		);
	}

	/**
	 * Sanitized values of the items whose `show_when` holds, plus format errors (a value given but not valid).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @param array<string, mixed> $raw  Posted values by item key.
	 * @return array{0: array<string, mixed>, 1: array<string, string>}
	 */
	private static function collect( array $form, array $raw ): array {
		$by_key = Definition::items_by_key( $form );
		$clean  = array();
		$errors = array();

		foreach ( $by_key as $key => $item ) {
			$value         = $raw[ $key ] ?? null;
			$clean[ $key ] = pegwork_sanitize_field( $item, $value );

			$given = is_scalar( $value ) && '' !== trim( (string) $value );
			if ( $given && mb_strlen( (string) $value ) > self::MAX_LENGTH ) {
				/* translators: %s: field label. */
				$errors[ $key ] = sprintf( __( '%s is too long.', 'pegwork' ), $item['label'] );
			} elseif ( $given && '' === $clean[ $key ] ) {
				$errors[ $key ] = self::format_message( $item );
			} elseif ( null !== $value && ! is_scalar( $value ) ) {
				$errors[ $key ] = self::format_message( $item );
			}
		}

		$visible = array();
		$check   = function ( string $key, int $depth ) use ( &$check, &$visible, $by_key, $clean ): bool {
			if ( isset( $visible[ $key ] ) ) {
				return $visible[ $key ];
			}
			$item      = $by_key[ $key ];
			$condition = $item['show_when']['field'] ?? null;
			$shown     = true;
			if ( is_string( $condition ) ) {
				$shown = $depth < 10 && isset( $by_key[ $condition ] ) && $check( $condition, $depth + 1 )
					&& Definition::shown( $item, $clean );
			}
			$visible[ $key ] = $shown;
			return $shown;
		};

		$values = array();
		foreach ( array_keys( $by_key ) as $key ) {
			if ( $check( $key, 0 ) ) {
				$values[ $key ] = $clean[ $key ];
			} else {
				unset( $errors[ $key ] );
			}
		}

		return array( $values, $errors );
	}

	/**
	 * Field errors from pegwork_validate_fields() for the collected (visible) items.
	 *
	 * @param array<string, mixed> $form   Canonical form.
	 * @param array<string, mixed> $values Collected values.
	 * @return array<string, string>
	 */
	private static function validate( array $form, array $values ): array {
		$fields = array_values( array_filter( Definition::items( $form ), fn( array $item ) => array_key_exists( $item['key'] ?? '', $values ) ) );
		$result = pegwork_validate_fields( $fields, $values );
		if ( true === $result ) {
			return array();
		}
		$data = $result->get_error_data();

		return is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
	}

	/**
	 * Message for a value that is present but not valid for its type.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	private static function format_message( array $item ): string {
		$label = (string) $item['label'];

		return match ( $item['type'] ?? 'text' ) {
			/* translators: %s: field label. */
			'email'  => sprintf( __( 'Enter %s as an email address, like name@example.com.', 'pegwork' ), $label ),
			/* translators: %s: field label. */
			'url'    => sprintf( __( 'Enter %s as a web address starting with https://.', 'pegwork' ), $label ),
			/* translators: %s: field label. */
			'date'   => sprintf( __( 'Enter %s as a real date, like 2026-03-31.', 'pegwork' ), $label ),
			/* translators: %s: field label. */
			'number' => sprintf( __( '%s must be a number.', 'pegwork' ), $label ),
			/* translators: %s: field label. */
			'select' => sprintf( __( 'Choose one of the options for %s.', 'pegwork' ), $label ),
			/* translators: %s: field label. */
			default  => sprintf( __( '%s has an invalid value.', 'pegwork' ), $label ),
		};
	}

	/**
	 * Errors in form order.
	 *
	 * @param array<string, mixed>  $form   Canonical form.
	 * @param array<string, string> $errors Errors by item key.
	 * @return array<string, string>
	 */
	private static function ordered( array $form, array $errors ): array {
		$ordered = array();
		foreach ( array_keys( Definition::items_by_key( $form ) ) as $key ) {
			if ( isset( $errors[ $key ] ) ) {
				$ordered[ $key ] = $errors[ $key ];
			}
		}
		return $ordered;
	}

	/**
	 * Posted values as plain strings, for refilling the form on a re-render (escaped at output).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @param array<string, mixed> $raw  Posted values.
	 * @return array<string, string>
	 */
	private static function posted_text( array $form, array $raw ): array {
		$text = array();
		foreach ( array_keys( Definition::items_by_key( $form ) ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$text[ $key ] = mb_substr( (string) $raw[ $key ], 0, self::MAX_LENGTH );
			}
		}
		return $text;
	}

	/**
	 * Values with keys sorted, for the fingerprint.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, mixed>
	 */
	private static function canonical( array $values ): array {
		ksort( $values, SORT_STRING );
		return $values;
	}

	/**
	 * Prefixes the document title with "Error: " on a re-render with errors. Hooked to document_title_parts.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function error_title( $parts ): array {
		$parts = is_array( $parts ) ? $parts : array();
		/* translators: %s: page title. */
		$parts['title'] = sprintf( __( 'Error: %s', 'pegwork' ), $parts['title'] ?? '' );
		return $parts;
	}

	/**
	 * The current URL on the site's origin, without the success arg.
	 */
	public static function current_url(): string {
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$uri    = '' === $uri || '/' !== $uri[0] ? '/' : $uri;

		return remove_query_arg( self::SENT, $origin . $uri );
	}
}
