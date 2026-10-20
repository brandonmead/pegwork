<?php
/**
 * Email confirmation (double opt-in).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api\Events;

/**
 * Holds a `confirm` form's entry as `unconfirmed` and emails a link; the record is created only when
 * the submitter confirms.
 *
 * - Token: 32 random bytes, base64url, sent as `?pegwork_confirm=<entry id>.<token>` on the page that
 *   held the form (home when that page is not publicly viewable). Only its sha256 is stored
 *   (`token_hash`), compared with hash_equals(). It expires after `confirm.expires_hours` (default 72),
 *   fixed at submit time in `meta.confirm_expires`.
 * - The link opens a page with a "Confirm my submission" button that POSTs. GET never confirms,
 *   because mail security scanners prefetch links.
 * - The claim is atomic (`unconfirmed` to `processing` in one UPDATE), so a second confirm, or two at
 *   once, does nothing more than show "already confirmed".
 * - Values are validated again against the current definition. Valid: the record is written as the
 *   definition says, the entry becomes `received` with `post_id` and `confirmed_at`, `confirmed`
 *   notifications go out and `form.confirmed` is emitted. Invalid: a create form makes a draft, the
 *   entry becomes `failed` with `meta.errors`, and the site admin is told; nothing is emitted. The
 *   submitter then sees `messages.confirm_failed` (a person will check it), not the thank-you.
 * - At most MAX_MAILS confirmation emails per address per MAIL_WINDOW, across forms.
 * - Confirmation pages are sent with no-cache headers, `noindex` and `Referrer-Policy: no-referrer`,
 *   so the token is neither cached nor leaked to other sites.
 */
final class Confirm {

	/**
	 * Query arg (GET link) and POST array (`entry`, `token`) of a confirmation.
	 */
	public const QUERY = 'pegwork_confirm';

	/**
	 * Query arg of the thank-you state after a confirmation (value: form key).
	 */
	public const DONE = 'pegwork_confirmed';

	/**
	 * Query arg added to DONE when the confirmation was received but the entry failed re-validation
	 * (value: 1). It only changes the message shown, so a hand-made URL reveals nothing.
	 */
	public const REVIEW = 'pegwork_review';

	/**
	 * Confirmation emails allowed per address in MAIL_WINDOW, across forms.
	 */
	public const MAX_MAILS = 3;

	/**
	 * Window of the confirmation email cap, in seconds.
	 */
	public const MAIL_WINDOW = DAY_IN_SECONDS;

	/**
	 * Outcome of this request's confirmation POST, for the renderer.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $state = null;

	/**
	 * Whether the_content already received the fallback form block in this request.
	 *
	 * @var bool
	 */
	private static bool $injected = false;

	/**
	 * Registers the request handler. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'template_redirect', array( self::class, 'handle' ) ) ) {
			add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
		}
	}

	/**
	 * Clears per-request state (tests).
	 */
	public static function reset(): void {
		self::$state    = null;
		self::$injected = false;
	}

	/**
	 * Whether another confirmation email to this address would exceed the cap.
	 *
	 * @param string $email Lowercased address.
	 * @param int    $now   Unix time.
	 */
	public static function cap_reached( string $email, int $now ): bool {
		return '' !== $email && EntryState::confirm_mails( $email, $now - self::MAIL_WINDOW ) >= self::MAX_MAILS;
	}

	/**
	 * Starts confirmation for a stored `unconfirmed` entry: stores the token hash and emails the link.
	 *
	 * @param array<string, mixed> $form     Canonical form with `confirm`.
	 * @param int                  $entry_id Entry ID.
	 * @param array<string, mixed> $values   Collected values.
	 * @param int                  $page_id  Page that held the form, 0 if none.
	 * @param int                  $now      Unix time.
	 * @param array<string, mixed> $meta     Entry meta so far.
	 * @return array<string, mixed> Meta with `confirm_expires` and `confirm_mail` (`sent`, `failed`, `skipped`).
	 */
	public static function start( array $form, int $entry_id, array $values, int $page_id, int $now, array $meta ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding of a random token.
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		EntryState::set( $entry_id, array( 'token_hash' => hash( 'sha256', $token ) ) );

		$hours                   = max( 1, (int) ( $form['confirm']['expires_hours'] ?? 72 ) );
		$meta['confirm_expires'] = $now + $hours * HOUR_IN_SECONDS;
		$meta['confirm_mail']    = Notifications::confirm(
			$form,
			array(
				'entry_id'    => $entry_id,
				'values'      => $values,
				'confirm_url' => self::url( $page_id, $entry_id, $token ),
			)
		);

		return $meta;
	}

	/**
	 * The confirmation link: the page that held the form when it is publicly viewable, else home.
	 *
	 * @param int    $page_id  Page ID, 0 if none.
	 * @param int    $entry_id Entry ID.
	 * @param string $token    Raw token.
	 */
	public static function url( int $page_id, int $entry_id, string $token ): string {
		$base = $page_id > 0 && is_post_publicly_viewable( $page_id ) ? (string) get_permalink( $page_id ) : home_url( '/' );

		return add_query_arg( self::QUERY, $entry_id . '.' . $token, $base );
	}

	/**
	 * Parses a link value `<entry id>.<token>`.
	 *
	 * @param mixed $value Query value.
	 * @return array{id: int, token: string}|null
	 */
	public static function parse( mixed $value ): ?array {
		if ( ! is_string( $value ) || ! preg_match( '/^([1-9][0-9]{0,19})\.([A-Za-z0-9_-]{43})$/', $value, $parts ) ) {
			return null;
		}
		return array(
			'id'    => (int) $parts[1],
			'token' => $parts[2],
		);
	}

	/**
	 * Where an entry stands for a token, without changing anything (the GET page).
	 *
	 * @param array<string, mixed>|null $entry Entry from Entries::get().
	 * @param string                    $token Raw token.
	 * @param int                       $now   Unix time.
	 * @return string `pending` (may confirm), `done` (already confirmed), `expired` or `invalid`.
	 */
	public static function state( ?array $entry, string $token, int $now ): string {
		if ( null === $entry || ! is_string( $entry['token_hash'] ?? null ) || ! hash_equals( $entry['token_hash'], hash( 'sha256', $token ) ) ) {
			return 'invalid';
		}
		if ( 'unconfirmed' !== $entry['status'] ) {
			return in_array( $entry['status'], array( 'processing', 'received', 'failed' ), true ) ? 'done' : 'invalid';
		}

		return $now > (int) ( $entry['meta']['confirm_expires'] ?? 0 ) ? 'expired' : 'pending';
	}

	/**
	 * Confirms an entry: checks the token and expiry, claims the entry atomically, then completes it.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $token    Raw token.
	 * @param int    $now      Unix time.
	 * @return array<string, mixed> `outcome` (`confirmed`, `done`, `expired`, `invalid`), `form`, `entry_id`, `record_id`.
	 */
	public static function confirm( int $entry_id, string $token, int $now ): array {
		$entry  = $entry_id > 0 ? Entries::get( $entry_id ) : null;
		$result = array(
			'form'      => is_array( $entry ) ? (string) $entry['form_key'] : '',
			'entry_id'  => $entry_id,
			'record_id' => null,
		);

		$state = self::state( $entry, $token, $now );
		if ( 'pending' !== $state ) {
			return array( 'outcome' => $state ) + $result;
		}
		if ( ! EntryState::claim( $entry_id, 'unconfirmed', 'processing' ) ) {
			return array( 'outcome' => 'done' ) + $result;
		}

		return array( 'outcome' => 'confirmed' ) + self::complete( $entry, $now ) + $result;
	}

	/**
	 * Completes a claimed (`processing`) entry: re-validates, writes the record, sets the final status,
	 * notifies and emits. Also the seam for the entries admin's "Create post anyway", after its own claim.
	 *
	 * @param array<string, mixed> $entry Entry from Entries::get() (before the claim).
	 * @param int                  $now   Unix time.
	 * @return array{record_id: int|null, status: string}
	 */
	public static function complete( array $entry, int $now ): array {
		$id        = (int) $entry['id'];
		$values    = is_array( $entry['values'] ?? null ) ? $entry['values'] : array();
		$meta      = is_array( $entry['meta'] ?? null ) ? $entry['meta'] : array();
		$user_id   = (int) ( $entry['user_id'] ?? 0 );
		$update_id = (int) ( $meta['record'] ?? 0 );
		$form      = pegwork_registry()->form( (string) $entry['form_key'] );
		$record_id = null;

		if ( null === $form ) {
			$errors = array( '' => __( 'The form no longer exists.', 'pegwork' ) );
			$form   = array(
				'key'   => (string) $entry['form_key'],
				'label' => (string) $entry['form_key'],
			);
		} elseif ( $update_id > 0 && ( 0 === $user_id || ! user_can( $user_id, 'edit_post', $update_id ) ) ) {
			// An update confirmed later still needs the submitter's right to edit that record.
			$errors = array( '' => __( 'The submitter can no longer edit this record.', 'pegwork' ) );
		} else {
			$errors = Submission::validate( $form, $values, $update_id );
			if ( array() === $errors && null !== Definition::target_type( $form ) ) {
				$written = RecordWriter::write( $form, $values, $user_id, $update_id );
				if ( is_wp_error( $written ) ) {
					$errors = Submission::error_messages( $written );
				} else {
					$record_id = $written;
				}
			}
			if ( array() !== $errors && null !== Definition::target_type( $form ) && ! Definition::updates( $form ) ) {
				$record_id = RecordWriter::draft( $form, $values, $user_id );
			}
		}

		$status  = array() === $errors ? 'received' : 'failed';
		$columns = array(
			'status'       => $status,
			'confirmed_at' => gmdate( 'Y-m-d H:i:s', $now ),
		);
		if ( null !== $record_id ) {
			$columns['post_id'] = $record_id;
		}
		EntryState::set( $id, $columns );

		if ( 'failed' === $status ) {
			$meta['errors']         = $errors;
			$meta['failure_notice'] = Notifications::failure( $form, $id, $record_id, $errors );
			Entries::update_meta( $id, $meta );
			return array(
				'record_id' => $record_id,
				'status'    => $status,
			);
		}

		$meta['notifications'] = array_merge(
			is_array( $meta['notifications'] ?? null ) ? $meta['notifications'] : array(),
			Notifications::send(
				$form,
				'confirmed',
				array(
					'entry_id' => $id,
					'values'   => $values,
					'post_id'  => $record_id,
				)
			)
		);
		Entries::update_meta( $id, $meta );

		Events::emit(
			'form.confirmed',
			array(
				'form'      => (string) $form['key'],
				'entry_id'  => $id,
				'record_id' => $record_id,
				'values'    => Uploads::without_paths( $values ),
			)
		);

		return array(
			'record_id' => $record_id,
			'status'    => $status,
		);
	}

	/**
	 * Handles requests carrying a confirmation: protective headers on every one, the confirmation POST,
	 * and the fallback that places the form block on a page that lacks it. Hooked to template_redirect, priority 1.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- The secret token is the request's proof; anonymous visitors have no nonce.
		$link = isset( $_GET[ self::QUERY ] );
		$done = isset( $_GET[ self::DONE ] );
		$post = isset( $_POST[ self::QUERY ] ) && is_array( $_POST[ self::QUERY ] );
		if ( ! $link && ! $done && ! $post ) {
			return;
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'the_content', array( self::class, 'inject' ), 8 );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method || ! $post ) {
			return;
		}

		$posted = wp_unslash( $_POST[ self::QUERY ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked below: an integer and a base64url token.
		// phpcs:enable WordPress.Security.NonceVerification
		$parsed = self::parse( ( is_scalar( $posted['entry'] ?? null ) ? (string) $posted['entry'] : '' ) . '.' . ( is_scalar( $posted['token'] ?? null ) ? (string) $posted['token'] : '' ) );
		$result = null === $parsed ? array(
			'outcome'  => 'invalid',
			'form'     => '',
			'entry_id' => 0,
		) : self::confirm( $parsed['id'], $parsed['token'], time() );

		if ( 'confirmed' === $result['outcome'] ) {
			$args = array( self::DONE => $result['form'] );
			if ( 'failed' === ( $result['status'] ?? '' ) ) {
				$args[ self::REVIEW ] = '1';
			}
			$target = add_query_arg( $args, Submission::current_url() );
			wp_safe_redirect( $target, 303 );
			exit;
		}
		self::$state = $result;
	}

	/**
	 * The confirmation markup for a form block, or null when this request is not a confirmation of this form.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @param string               $base Base ID of the block.
	 * @return array{html: string, replace: bool}|null `replace`: the markup stands in for the form;
	 *                                                 otherwise it is a notice shown above the form.
	 */
	public static function render( array $form, string $base ): ?array {
		$key = (string) $form['key'];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display state; the token is checked.
		if ( isset( $_GET[ self::DONE ] ) && sanitize_key( wp_unslash( $_GET[ self::DONE ] ) ) === $key ) {
			return array(
				'html'    => self::status( isset( $_GET[ self::REVIEW ] ) ? self::review_message( $form ) : __( 'Thank you. Your submission is confirmed.', 'pegwork' ) ),
				'replace' => true,
			);
		}

		$parsed = self::parse( isset( $_GET[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) : null );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$entry = null === $parsed ? null : Entries::get( $parsed['id'] );
		if ( null === $entry || $entry['form_key'] !== $key ) {
			return null;
		}

		$state = null !== self::$state && (int) self::$state['entry_id'] === $parsed['id'] ? (string) self::$state['outcome'] : self::state( $entry, $parsed['token'], time() );

		return match ( $state ) {
			'pending' => array(
				'html'    => self::page( $base, $form, $parsed ),
				'replace' => true,
			),
			'done'    => array(
				'html'    => self::status( 'failed' === $entry['status'] ? self::review_message( $form ) : __( 'This submission is already confirmed. There is nothing more to do.', 'pegwork' ) ),
				'replace' => true,
			),
			'expired' => array(
				'html'    => self::problem( __( 'This confirmation link has expired. Submit the form again below.', 'pegwork' ) ),
				'replace' => false,
			),
			default   => array(
				'html'    => self::problem( __( 'This confirmation link is not valid. Check that you used the whole link from the email, or submit the form again below.', 'pegwork' ) ),
				'replace' => false,
			),
		};
	}

	/**
	 * Places the form block at the top of the main content on a confirmation request when the content
	 * does not already hold it (a form shown by a template, or the home fallback). Hooked to the_content, priority 8.
	 *
	 * @param string $content Post content.
	 */
	public static function inject( $content ): string {
		$content = is_string( $content ) ? $content : '';
		if ( self::$injected || ! is_singular() || ! is_main_query() || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}

		$key = self::requested_form();
		if ( null === $key || self::contains_form( parse_blocks( $content ), $key ) ) {
			return $content;
		}

		self::$injected = true;
		return '<!-- wp:pegwork/form ' . wp_json_encode( array( 'form' => $key ) ) . " /-->\n" . $content;
	}

	/**
	 * Form key a confirmation request is about, or null.
	 */
	private static function requested_form(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only; the key is checked against the registry.
		if ( isset( $_GET[ self::DONE ] ) ) {
			$key = sanitize_key( wp_unslash( $_GET[ self::DONE ] ) );
		} else {
			$parsed = self::parse( isset( $_GET[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) : null );
			$entry  = null === $parsed ? null : Entries::get( $parsed['id'] );
			$key    = null === $entry ? '' : (string) $entry['form_key'];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '' !== $key && null !== pegwork_registry()->form( $key ) ? $key : null;
	}

	/**
	 * Whether parsed blocks hold a `pegwork/form` block for a key, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $key    Form key.
	 */
	private static function contains_form( array $blocks, string $key ): bool {
		foreach ( $blocks as $block ) {
			if ( Block::NAME === ( $block['blockName'] ?? null ) && ( $block['attrs']['form'] ?? null ) === $key ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && self::contains_form( $block['innerBlocks'], $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The page behind a link: what is being confirmed, and the button that POSTs.
	 *
	 * @param string                        $base   Base ID.
	 * @param array<string, mixed>          $form   Canonical form.
	 * @param array{id: int, token: string} $parsed Parsed link.
	 */
	private static function page( string $base, array $form, array $parsed ): string {
		$title  = $base . '-confirm-title';
		$action = add_query_arg( self::QUERY, $parsed['id'] . '.' . $parsed['token'], Submission::current_url() );

		return '<section class="pegwork-form__confirm" aria-labelledby="' . esc_attr( $title ) . '">'
			. '<h2 class="pegwork-form__confirm-title" id="' . esc_attr( $title ) . '">' . esc_html__( 'Confirm your submission', 'pegwork' ) . '</h2>'
			/* translators: %s: form label. */
			. '<p>' . esc_html( sprintf( __( 'Your "%s" submission is waiting. Press the button to confirm it.', 'pegwork' ), (string) $form['label'] ) ) . '</p>'
			. '<form class="pegwork-form__form" method="post" action="' . esc_url( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::QUERY ) . '[entry]" value="' . esc_attr( (string) $parsed['id'] ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::QUERY ) . '[token]" value="' . esc_attr( $parsed['token'] ) . '">'
			. '<div class="pegwork-form__actions"><button type="submit" class="pegwork-form__submit wp-element-button">' . esc_html__( 'Confirm my submission', 'pegwork' ) . '</button></div>'
			. '</form></section>';
	}

	/**
	 * What the submitter sees when their confirmation arrived but the entry failed re-validation (it is
	 * kept, as a draft for a create form, for a person to check): `messages.confirm_failed`, or the default.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function review_message( array $form ): string {
		$message = $form['messages']['confirm_failed'] ?? null;
		return is_string( $message ) && '' !== $message
			? $message
			: __( 'We got your confirmation, but something in your submission needs a person to check it. We will be in touch.', 'pegwork' );
	}

	/**
	 * A focused status message (success-like states).
	 *
	 * @param string $message Message.
	 */
	private static function status( string $message ): string {
		return '<div class="pegwork-form__status" role="status" tabindex="-1" autofocus><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * A focused problem notice shown above the form.
	 *
	 * @param string $message Message.
	 */
	private static function problem( string $message ): string {
		return '<div class="pegwork-form__notice pegwork-form__notice--problem" role="alert" tabindex="-1" autofocus><p>' . esc_html( $message ) . '</p></div>';
	}
}
