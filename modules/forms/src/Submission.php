<?php
/**
 * Submission pipeline.
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
 * Order: resolve the form from the registry (never from the request), access, record to update
 * (`edit_post`), nonce (logged-in users only), signed time token, IP rate limit, collect and sanitize
 * values (conditions false: ignored) and check uploads, spam layers (honeypot, time trap,
 * `pegwork_forms_spam_check`), validation (unbound items with pegwork_validate_fields(), bound ones with
 * pegwork_validate_record()), email rate limit, duplicate fingerprint, confirmation email cap, Turnstile,
 * move uploads into private storage, store, then either hold for email confirmation or write the target
 * record, notify, emit `form.submitted`.
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
	 * Query arg added to SENT when the entry was held for review (Turnstile could not be reached).
	 */
	public const HELD = 'pegwork_held';

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
		Confirm::register();
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
		Confirm::reset();
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

		$result = self::process( $form, $raw, $control, time(), get_queried_object_id(), Uploads::from_request( $form ) );

		if ( in_array( $result['outcome'], array( 'sent', 'spam', 'duplicate', 'held' ), true ) ) {
			// No #fragment: browsers skip `autofocus` when the URL targets an element, and the focused
			// success region scrolls itself into view.
			$args   = 'held' === $result['outcome'] ? array(
				self::SENT => $key,
				self::HELD => '1',
			) : array( self::SENT => $key );
			$target = add_query_arg( $args, self::current_url() );
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
	 * @param array<string, array> $files   Uploads by item key (Uploads::from_request(): only files PHP
	 *                                      received for this request).
	 * @return array<string, mixed> `outcome`: `sent`, `spam`, `duplicate` (all redirect to the success state),
	 *                              `held` (stored as spam `captcha_unverified`; redirects to the held state),
	 *                              or `invalid`, `denied`, `expired`, `rate_limited`, `failed` (re-render, nothing
	 *                              stored), `captcha_failed` (re-render; stored as spam), `captcha_missing` (no
	 *                              token field at all, so no JavaScript: re-render; stored as spam), `record_failed` (re-render;
	 *                              the entry is stored as `failed` and the admin told); plus `entry_id`, `record_id`,
	 *                              `errors` (message by item key), `values` (posted text), `files` (names of the
	 *                              files chosen, which a re-render cannot keep) and `issued` (render time of the
	 *                              posted token when it passed, for the re-render's token; else null); `rate_limited`
	 *                              adds `limit` (`ip`, `email` or `confirm`) and `retry_after` (seconds).
	 */
	public static function process( array $form, mixed $raw, mixed $control, int $now, int $page_id = 0, array $files = array() ): array {
		$key     = (string) $form['key'];
		$raw     = is_array( $raw ) ? $raw : array();
		$control = is_array( $control ) ? $control : array();
		$posted  = self::posted_text( $form, $raw );
		$chosen  = array();
		foreach ( $files as $file_key => $file ) {
			$chosen[ (string) $file_key ] = sanitize_file_name( wp_basename( str_replace( '\\', '/', (string) ( $file['name'] ?? '' ) ) ) );
		}
		// A re-render keeps the first render's time (see Token::issued()), so correcting an error and
		// sending at once is not mistaken for a bot.
		$issued = Token::issued( $key, $control['token'] ?? null, $now );
		$fail   = fn( string $outcome, array $errors = array() ) => array(
			'outcome' => $outcome,
			'errors'  => $errors,
			'values'  => $posted,
			'files'   => $chosen,
			'issued'  => $issued,
		);

		if ( ! Access::allowed( $form ) ) {
			return $fail( 'denied' );
		}
		$record_id = 0;
		if ( Definition::updates( $form ) ) {
			$record_id = is_scalar( $control['record'] ?? null ) ? absint( $control['record'] ) : 0;
			if ( ! RecordWriter::editable( $form, $record_id ) ) {
				return $fail( 'denied' );
			}
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
			return $fail( 'rate_limited' ) + array(
				'limit'       => 'ip',
				'retry_after' => RateLimit::retry_after( 'ip', $key, $ip, $now ),
			);
		}

		[ $values, $format_errors ]         = self::collect( $form, $raw );
		[ $values, $uploads, $file_errors ] = self::uploads( $form, $values, $files );
		$format_errors                     += $file_errors;

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

		$errors = $format_errors + self::validate( $form, $values, $record_id );
		if ( $errors ) {
			return $fail( 'invalid', self::ordered( $form, $errors ) );
		}

		if ( RateLimit::exceeded( 'email', $key, $email, $now ) ) {
			return $fail( 'rate_limited' ) + array(
				'limit'       => 'email',
				'retry_after' => RateLimit::retry_after( 'email', $key, $email, $now ),
			);
		}
		if ( Entries::fingerprint_seen( $fingerprint, $now - self::DUPLICATE_WINDOW ) ) {
			return array(
				'outcome'  => 'duplicate',
				'entry_id' => 0,
			);
		}
		$confirm = isset( $form['confirm'] );
		if ( $confirm && Confirm::cap_reached( $email, $now ) ) {
			return $fail( 'rate_limited' ) + array(
				'limit'       => 'confirm',
				'retry_after' => Confirm::cap_retry_after( $email, $now ),
			);
		}

		// Turnstile last, after the cheap checks, so a valid token is spent only on a submission that
		// can be stored (a double click hits the duplicate check above, not a reused-token failure).
		$verdict = Turnstile::enabled( $form ) ? Turnstile::verify( $control[ Turnstile::FIELD ] ?? null ) : 'pass';
		if ( 'fail' === $verdict ) {
			// No token field at all: the widget never ran, so the browser has no JavaScript.
			$reason   = array_key_exists( Turnstile::FIELD, $control ) ? 'captcha_failed' : 'captcha_missing';
			$entry_id = Entries::insert( $base + array( 'status' => 'spam' ) + array( 'meta' => $meta + array( 'spam' => array( $reason ) ) ) );
			RateLimit::hit( 'ip', $key, $ip, $now );
			return $fail( $reason ) + array( 'entry_id' => $entry_id );
		}

		$values = self::store_files( $form, $values, $uploads );
		if ( null === $values ) {
			return $fail( 'failed' );
		}

		if ( 'unverified' === $verdict ) {
			// Nobody could tell whether this is a person: keep everything (files too) as spam for an
			// admin to rescue with "Not spam"; no mail, no events.
			$base['values'] = $values;
			$entry_id       = Entries::insert( $base + array( 'status' => 'spam' ) + array( 'meta' => $meta + array( 'spam' => array( 'captcha_unverified' ) ) ) );
			if ( 0 === $entry_id ) {
				Uploads::delete( $values );
				return $fail( 'failed' );
			}
			RateLimit::hit( 'ip', $key, $ip, $now );
			return array(
				'outcome'  => 'held',
				'entry_id' => $entry_id,
			);
		}

		if ( $record_id > 0 ) {
			$meta['record'] = $record_id;
		}
		$base['values'] = $values;
		$entry_id       = Entries::insert( $base + array( 'status' => $confirm ? 'unconfirmed' : 'received' ) + array( 'meta' => $meta ) );
		if ( 0 === $entry_id ) {
			Uploads::delete( $values );
			return $fail( 'failed' );
		}
		RateLimit::hit( 'ip', $key, $ip, $now );
		RateLimit::hit( 'email', $key, $email, $now );

		$context = array(
			'entry_id' => $entry_id,
			'values'   => $values,
		);
		$written = null;
		if ( $confirm ) {
			$meta = Confirm::start( $form, $entry_id, $values, $page_id, $now, $meta );
		} elseif ( null !== Definition::target_type( $form ) ) {
			$result = RecordWriter::write( $form, $values, get_current_user_id(), $record_id );
			if ( is_wp_error( $result ) ) {
				$meta['errors']         = self::error_messages( $result );
				$meta['failure_notice'] = Notifications::failure( $form, $entry_id, null, $meta['errors'] );
				EntryState::set( $entry_id, array( 'status' => 'failed' ) );
				Entries::update_meta( $entry_id, $meta );
				return $fail( 'record_failed' ) + array( 'entry_id' => $entry_id );
			}
			$written            = $result;
			$context['post_id'] = $written;
			EntryState::set( $entry_id, array( 'post_id' => $written ) );
		}

		$meta['notifications'] = Notifications::send( $form, 'submitted', $context );
		Entries::update_meta( $entry_id, $meta );

		Events::emit(
			'form.submitted',
			array(
				'form'      => $key,
				'entry_id'  => $entry_id,
				'record_id' => $written,
				'values'    => Uploads::without_paths( $values ),
			)
		);

		return array(
			'outcome'   => 'sent',
			'entry_id'  => $entry_id,
			'record_id' => $written,
		);
	}

	/**
	 * Messages of a failed record write, by key (`''` for a message that names no field).
	 *
	 * @param \WP_Error $error Error.
	 * @return array<string, string>
	 */
	public static function error_messages( \WP_Error $error ): array {
		$fields = $error->get_error_data()['fields'] ?? null;

		return is_array( $fields ) && array() !== $fields ? array_map( 'strval', $fields ) : array( '' => $error->get_error_message() );
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
			$value = $raw[ $key ] ?? null;
			if ( ! empty( $item['multiple'] ) ) {
				[ $clean[ $key ], $valid ] = self::choices( $item, $value );
				if ( ! $valid ) {
					$errors[ $key ] = self::format_message( $item );
				}
				continue;
			}
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

		$visible = Definition::visible( $by_key, $clean );
		$values  = array();
		foreach ( array_keys( $by_key ) as $key ) {
			if ( $visible[ $key ] ) {
				$values[ $key ] = $clean[ $key ];
			} else {
				unset( $errors[ $key ] );
			}
		}

		return array( $values, $errors );
	}

	/**
	 * Checks the uploads of the visible file items (nothing is moved yet). A file that passes becomes
	 * its metadata `{name, size, mime}` in the values; a visible file item without a file stays ''.
	 *
	 * @param array<string, mixed> $form   Canonical form.
	 * @param array<string, mixed> $values Collected values (visible items only).
	 * @param array<string, array> $files  Uploads by item key.
	 * @return array{0: array<string, mixed>, 1: array<string, array>, 2: array<string, string>} Values,
	 *         uploads that passed by key, errors by key.
	 */
	private static function uploads( array $form, array $values, array $files ): array {
		$uploads = array();
		$errors  = array();
		foreach ( Definition::items_by_key( $form ) as $key => $item ) {
			if ( 'file' !== ( $item['type'] ?? '' ) || ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$values[ $key ] = '';
			if ( ! isset( $files[ $key ] ) || ! is_array( $files[ $key ] ) ) {
				continue;
			}
			$file   = $files[ $key ] + array(
				'name'     => '',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_NO_FILE,
				'size'     => 0,
			);
			$result = Uploads::check( $item, $file );
			if ( is_string( $result ) ) {
				$errors[ $key ] = $result;
				continue;
			}
			$values[ $key ]  = $result;
			$uploads[ $key ] = $file;
		}
		return array( $values, $uploads, $errors );
	}

	/**
	 * Moves the checked uploads into private storage and adds each `path` to its value.
	 *
	 * @param array<string, mixed> $form    Canonical form.
	 * @param array<string, mixed> $values  Values (file items hold their metadata).
	 * @param array<string, array> $uploads Uploads that passed, by key.
	 * @return array<string, mixed>|null Values with paths, or null when a file could not be saved (any
	 *                                   file already moved is deleted again).
	 */
	private static function store_files( array $form, array $values, array $uploads ): ?array {
		$by_key = Definition::items_by_key( $form );
		$stored = array();
		foreach ( $uploads as $key => $file ) {
			$result = Uploads::store( $by_key[ $key ], $file, $values[ $key ] );
			if ( is_wp_error( $result ) ) {
				Uploads::delete( $stored );
				return null;
			}
			$stored[ $key ] = $result;
		}
		return array_merge( $values, $stored );
	}

	/**
	 * A multi-choice item's posted values: the unique chosen option values, and whether every posted
	 * value was an option.
	 *
	 * @param array<string, mixed> $item  Item with `options`.
	 * @param mixed                $value Posted value: a list of strings, or missing.
	 * @return array{0: string[], 1: bool}
	 */
	private static function choices( array $item, mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array( array(), true );
		}
		if ( ! is_array( $value ) ) {
			return array( array(), false );
		}

		$allowed = array_map( fn( array $option ) => (string) $option['value'], $item['options'] ?? array() );
		$chosen  = array();
		$valid   = true;
		foreach ( $value as $choice ) {
			if ( is_string( $choice ) && in_array( $choice, $allowed, true ) ) {
				$chosen[ $choice ] = $choice;
			} elseif ( '' !== $choice ) {
				$valid = false;
			}
		}

		// Keep the options' order, so equal choices give equal values (and fingerprints).
		return array( array_values( array_intersect( $allowed, $chosen ) ), $valid );
	}

	/**
	 * Field errors for the collected (visible) items: unbound items through pegwork_validate_fields(),
	 * bound items through pegwork_validate_record() on the target type (its rules live once, in the
	 * registry). Confirmation re-runs this against the current definition.
	 *
	 * @param array<string, mixed> $form      Canonical form.
	 * @param array<string, mixed> $values    Collected values.
	 * @param int                  $record_id Record being updated, 0 for none.
	 * @return array<string, string> Message by item key; a record rule on a field the form does not bind keeps that field's key.
	 */
	public static function validate( array $form, array $values, int $record_id = 0 ): array {
		$fields = array();
		$checks = $values;
		foreach ( Definition::items( $form ) as $item ) {
			$key = (string) ( $item['key'] ?? '' );
			if ( isset( $item['bind'] ) || ! array_key_exists( $key, $values ) ) {
				continue;
			}
			if ( 'file' === ( $item['type'] ?? '' ) ) {
				// A file counts as given by its name, so `required` and `required_when` apply as to text;
				// type and size were checked at upload.
				$item           = array( 'type' => 'text' ) + array_diff_key( $item, array_flip( array( 'type', 'validate', 'accept', 'max_size_mb' ) ) );
				$checks[ $key ] = is_array( $values[ $key ] ) && is_string( $values[ $key ]['name'] ?? null ) ? $values[ $key ]['name'] : '';
			}
			$fields[] = $item;
		}
		$errors = array();
		$result = pegwork_validate_fields( $fields, $checks );
		if ( true !== $result ) {
			$data   = $result->get_error_data();
			$errors = is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
		}

		if ( null !== Definition::target_type( $form ) ) {
			$errors += RecordWriter::errors( $form, $values, $record_id );
		}

		return $errors;
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
			'url'    => sprintf( __( 'Enter %s as a web address, like https://example.org.', 'pegwork' ), $label ),
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
	 * Errors in form order; errors for keys the form does not show (a record rule on an unbound field) last.
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
		return $ordered + $errors;
	}

	/**
	 * Posted values as plain strings (lists of strings for multi-choice items), for refilling the form
	 * on a re-render (escaped at output).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @param array<string, mixed> $raw  Posted values.
	 * @return array<string, string|string[]>
	 */
	private static function posted_text( array $form, array $raw ): array {
		$text = array();
		foreach ( Definition::items_by_key( $form ) as $key => $item ) {
			if ( ! empty( $item['multiple'] ) && isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				$text[ $key ] = array_values( array_map( fn( $v ) => mb_substr( (string) $v, 0, 200 ), array_filter( $raw[ $key ], 'is_string' ) ) );
			} elseif ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
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
	 * The current URL on the site's origin, without the success and confirmation args (a form sent
	 * from a confirmation page must not carry the old token along).
	 */
	public static function current_url(): string {
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$uri    = '' === $uri || '/' !== $uri[0] ? '/' : $uri;

		return remove_query_arg( array( self::SENT, self::HELD, Confirm::QUERY, Confirm::DONE, Confirm::REVIEW ), $origin . $uri );
	}
}
