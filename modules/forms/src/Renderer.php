<?php
/**
 * Server-rendered form markup.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Renders a form that works without JavaScript: `<form method="post" novalidate>` posting to the same
 * URL, inputs named `pegwork[<key>]`, a `<label>` for every control, `fieldset`/`legend` for radio groups
 * and steps, "(required)" as text plus `aria-required`, `autocomplete` tokens, an error summary that is
 * focused on load and links to each field, and per-field errors linked by `aria-describedby`.
 */
final class Renderer {

	/**
	 * How many times each form key rendered in this request, so repeated forms get unique IDs.
	 *
	 * @var array<string, int>
	 */
	private static array $instances = array();

	/**
	 * Nonce action for a form (logged-in users only).
	 *
	 * @param string $key Form key.
	 */
	public static function nonce_action( string $key ): string {
		return 'pegwork_form_' . $key;
	}

	/**
	 * Renders a form by key: the form, its success state, or nothing.
	 *
	 * Unknown and unsupported forms render nothing for visitors and a short notice for users who can
	 * manage forms.
	 *
	 * @param string $key Form key.
	 * @param int    $now Unix time, for the time token.
	 */
	public static function render( string $key, int $now ): string {
		$form = '' === $key ? null : pegwork_registry()->form( $key );
		if ( null === $form ) {
			/* translators: %s: form key. */
			return self::manager_notice( '' === $key ? __( 'Choose a form for this block.', 'pegwork' ) : sprintf( __( 'Pegwork form "%s" is not defined.', 'pegwork' ), $key ) );
		}
		$reason = Definition::unsupported( $form );
		if ( null !== $reason ) {
			/* translators: 1: form label, 2: reason. */
			return self::manager_notice( sprintf( __( 'Form "%1$s" is not shown to visitors: %2$s.', 'pegwork' ), $form['label'], $reason ) );
		}

		self::$instances[ $key ] = ( self::$instances[ $key ] ?? 0 ) + 1;
		$base                    = 'pegwork-' . $key . ( self::$instances[ $key ] > 1 ? '-' . self::$instances[ $key ] : '' );

		$html = '<div class="wp-block-pegwork-form pegwork-form" id="' . esc_attr( $base ) . '">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state after the redirect.
		$sent = isset( $_GET[ Submission::SENT ] ) && sanitize_key( wp_unslash( $_GET[ Submission::SENT ] ) ) === $key;
		if ( $sent && 1 === self::$instances[ $key ] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state after the redirect.
			$held    = isset( $_GET[ Submission::HELD ] );
			$message = $held
				? __( 'Your submission was saved, but our spam check could not be completed. The site team will review it, so there is no need to send it again.', 'pegwork' )
				: ( $form['messages']['success'] ?? self::default_success( $form ) );
			return $html . '<div class="pegwork-form__status" role="status" tabindex="-1" autofocus><p>' . esc_html( $message ) . '</p></div></div>';
		}

		// A confirmation link or its thank-you state (any visitor: the token is the proof).
		$confirm = 1 === self::$instances[ $key ] ? Confirm::render( $form, $base ) : null;
		if ( null !== $confirm && $confirm['replace'] ) {
			return $html . $confirm['html'] . '</div>';
		}

		if ( ! Access::allowed( $form ) ) {
			if ( ! is_user_logged_in() ) {
				$html .= '<p class="pegwork-form__notice">' . sprintf(
					/* translators: %s: login link. */
					esc_html__( 'Please %s to use this form.', 'pegwork' ),
					'<a href="' . esc_url( wp_login_url( Submission::current_url() ) ) . '">' . esc_html__( 'log in', 'pegwork' ) . '</a>'
				) . '</p>';
			} else {
				$html .= '<p class="pegwork-form__notice">' . esc_html__( 'You do not have permission to use this form.', 'pegwork' ) . '</p>';
			}
			return $html . '</div>';
		}

		$record_id = 0;
		if ( Definition::updates( $form ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; edit_post is checked.
			$record_id = isset( $_GET['record'] ) ? absint( wp_unslash( $_GET['record'] ) ) : 0;
			if ( ! RecordWriter::editable( $form, $record_id ) ) {
				return $html . '<p class="pegwork-form__notice">' . esc_html__( 'There is no record here that you can edit with this form.', 'pegwork' ) . '</p></div>';
			}
		}

		$state  = 1 === self::$instances[ $key ] ? Submission::state( $key ) : null;
		$errors = is_array( $state['errors'] ?? null ) ? $state['errors'] : array();
		$files  = is_array( $state['files'] ?? null ) ? $state['files'] : array();
		$values = is_array( $state['values'] ?? null ) ? $state['values'] : ( $record_id > 0 ? RecordWriter::prefill( $form, $record_id ) : array() );
		$notice = self::outcome_notice( is_array( $state ) ? $state : array() );
		// A re-render keeps the posted token's render time (Submission::process() `issued`).
		$issued = is_int( $state['issued'] ?? null ) ? $state['issued'] : $now;

		if ( null !== $confirm ) {
			$html .= $confirm['html'];
		} elseif ( '' !== $notice ) {
			$html .= '<div class="pegwork-form__notice pegwork-form__notice--problem" role="alert" tabindex="-1" autofocus><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( $errors ) {
			$html .= self::summary( $base, $form, $errors );
		}

		// No #fragment in the action, so the re-render's `autofocus` on the error summary takes effect.
		$turnstile = Turnstile::enabled( $form );
		if ( $turnstile ) {
			$html .= Turnstile::noscript();
		}
		// The view script (enqueued only where a form renders) reads its rules from data-pegwork-config.
		View::enqueue();
		$html .= '<form class="pegwork-form__form" method="post" action="' . esc_url( Submission::current_url() ) . '"'
			. ( Definition::has_files( $form ) ? ' enctype="multipart/form-data"' : '' ) . ' novalidate data-pegwork-config="' . esc_attr( (string) wp_json_encode( View::config( $form, $base ) ) ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( Submission::CONTROL ) . '[key]" value="' . esc_attr( $key ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( Submission::CONTROL ) . '[token]" value="' . esc_attr( Token::create( $key, $issued ) ) . '">';
		if ( is_user_logged_in() ) {
			$html .= '<input type="hidden" name="' . esc_attr( Submission::CONTROL ) . '[nonce]" value="' . esc_attr( wp_create_nonce( self::nonce_action( $key ) ) ) . '">';
		}
		if ( $record_id > 0 ) {
			$html .= '<input type="hidden" name="' . esc_attr( Submission::CONTROL ) . '[record]" value="' . esc_attr( (string) $record_id ) . '">';
		}
		// Honeypot: hidden from people (off screen, out of the tab order, hidden from assistive tech); bots fill it.
		$html .= '<div class="pegwork-form__hp" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">'
			. '<label for="' . esc_attr( $base ) . '-hp">' . esc_html__( 'Leave this empty', 'pegwork' ) . '</label>'
			. '<input type="text" id="' . esc_attr( $base ) . '-hp" name="' . esc_attr( Submission::CONTROL ) . '[url]" value="" tabindex="-1" autocomplete="off">'
			. '</div>';

		$by_key   = Definition::items_by_key( $form );
		$sections = Definition::sections( $form );
		$last     = count( $sections ) - 1;
		foreach ( $sections as $index => $section ) {
			if ( $section['step'] ) {
				// A heading in the legend: heading navigation without JavaScript, a focus target for the view script.
				$html .= '<fieldset class="pegwork-form__step"><legend class="pegwork-form__step-title"><h2 class="pegwork-form__step-heading">' . esc_html( $section['label'] ) . '</h2></legend>';
				if ( '' !== $section['description'] ) {
					$html .= '<p class="pegwork-form__step-description">' . esc_html( $section['description'] ) . '</p>';
				}
			}
			foreach ( $section['fields'] as $item ) {
				$html .= self::field( $base, $item, $by_key, $values, $errors, $files );
			}
			// The Turnstile widget sits at the end of the last step, so it shows with the submit button.
			if ( $turnstile && $index === $last ) {
				$html .= Turnstile::widget( $base, Turnstile::action( $form ) );
			}
			if ( $section['step'] ) {
				$html .= '</fieldset>';
			}
		}

		$submit = $form['messages']['submit_label'] ?? __( 'Send', 'pegwork' );
		// Turnstile forms: the button starts disabled and a one-line script enables it, so without JavaScript it cannot be sent.
		$html .= '<div class="pegwork-form__actions"><button type="submit" class="pegwork-form__submit wp-element-button"' . ( $turnstile ? ' disabled' : '' ) . '>' . esc_html( $submit ) . '</button>' . ( $turnstile ? Turnstile::enable_submit() : '' ) . '</div>';

		return $html . '</form></div>';
	}

	/**
	 * Resets per-request instance counters (tests).
	 */
	public static function reset(): void {
		self::$instances = array();
	}

	/**
	 * The error summary: heading "There is a problem" and a link to each field, focused on load.
	 *
	 * @param string                $base   Base ID.
	 * @param array<string, mixed>  $form   Form.
	 * @param array<string, string> $errors Errors by item key.
	 */
	private static function summary( string $base, array $form, array $errors ): string {
		$by_key = Definition::items_by_key( $form );
		$html   = '<div class="pegwork-form__errors" tabindex="-1" autofocus aria-labelledby="' . esc_attr( $base ) . '-errors-title">';
		$html  .= '<h2 class="pegwork-form__errors-title" id="' . esc_attr( $base ) . '-errors-title">' . esc_html__( 'There is a problem', 'pegwork' ) . '</h2><ul>';
		foreach ( $errors as $key => $message ) {
			if ( ! isset( $by_key[ $key ] ) ) {
				// A record rule on a field this form does not show: nothing to link to.
				$html .= '<li>' . esc_html( $message ) . '</li>';
				continue;
			}
			$id    = self::control_id( $base, $by_key[ $key ] );
			$html .= '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( $message ) . '</a></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * One item's markup.
	 *
	 * @param string                              $base   Base ID.
	 * @param array<string, mixed>                $item   Item.
	 * @param array<string, array<string, mixed>> $by_key Items by key.
	 * @param array<string, string|string[]>      $values Posted values for a re-render (lists for multi-choice items).
	 * @param array<string, string>               $errors Errors by item key.
	 * @param array<string, string>               $files  Names of files chosen in the request being re-rendered, by item key.
	 */
	private static function field( string $base, array $item, array $by_key, array $values, array $errors, array $files = array() ): string {
		if ( ! empty( $item['multiple'] ) ) {
			return self::choices( $base, $item, $by_key, $values, $errors );
		}
		$key      = (string) $item['key'];
		$id       = $base . '-' . $key;
		$type     = (string) $item['type'];
		$error    = $errors[ $key ] ?? '';
		$required = ! empty( $item['required'] );
		$value    = array_key_exists( $key, $values ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : ( isset( $item['default'] ) && is_scalar( $item['default'] ) ? self::default_text( $item['default'] ) : '' );

		$help = array_values( array_filter( array( (string) ( $item['help'] ?? '' ), 'file' === $type ? Uploads::hint( $item ) : '', Definition::condition_text( $item, $by_key ) ) ) );

		$described = array();
		$extra     = '';
		if ( $help ) {
			$described[] = $id . '-help';
			$extra      .= '<p class="pegwork-form__help" id="' . esc_attr( $id ) . '-help">' . esc_html( implode( ' ', $help ) ) . '</p>';
		}
		if ( 'file' === $type && '' !== ( $files[ $key ] ?? '' ) ) {
			// Browsers cannot refill a file input, and the server discards files of a submission with problems.
			$described[] = $id . '-again';
			$extra      .= '<p class="pegwork-form__help pegwork-form__again" id="' . esc_attr( $id ) . '-again"><strong>' . esc_html(
				/* translators: %s: file name. */
				sprintf( __( 'Choose the file again. "%s" was not kept.', 'pegwork' ), $files[ $key ] )
			) . '</strong></p>';
		}
		if ( '' !== $error ) {
			$described[] = $id . '-error';
			$extra      .= '<p class="pegwork-form__error" id="' . esc_attr( $id ) . '-error"><span class="pegwork-form__sr">' . esc_html__( 'Error:', 'pegwork' ) . ' </span>' . esc_html( $error ) . '</p>';
		}
		$label = esc_html( $item['label'] ) . self::required_marker( $item, $by_key );
		$aria  = ( $required ? ' aria-required="true"' : '' )
			. ( '' !== $error ? ' aria-invalid="true"' : '' )
			. ( $described ? ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' : '' );
		$class = 'pegwork-form__field pegwork-form__field--' . $type . ( '' !== $error ? ' pegwork-form__field--error' : '' );
		$name  = Submission::VALUES . '[' . $key . ']';
		$auto  = isset( $item['autocomplete'] ) ? ' autocomplete="' . esc_attr( $item['autocomplete'] ) . '"' : '';
		$hint  = isset( $item['placeholder'] ) ? ' placeholder="' . esc_attr( $item['placeholder'] ) . '"' : '';

		if ( 'boolean' === $type ) {
			$checked = '' !== $value && '0' !== $value && 'false' !== $value;
			return '<div class="' . esc_attr( $class ) . '">' . $extra
				. '<input type="checkbox" class="pegwork-form__checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked' : '' ) . $aria . '>'
				. ' <label class="pegwork-form__label" for="' . esc_attr( $id ) . '">' . $label . '</label></div>';
		}

		if ( 'select' === $type && 'radio' === ( $item['display'] ?? 'select' ) ) {
			$group = ( $required ? ' aria-required="true"' : '' ) . ( '' !== $error ? ' aria-invalid="true"' : '' )
				. ( $described ? ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' : '' );
			$html  = '<fieldset class="' . esc_attr( $class ) . '" role="radiogroup"' . $group . '><legend class="pegwork-form__label">' . $label . '</legend>' . $extra;
			foreach ( array_values( $item['options'] ?? array() ) as $index => $option ) {
				$option_id = $id . '-' . $index;
				$html     .= '<div class="pegwork-form__option"><input type="radio" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $option['value'] ) . '"'
					. ( (string) $option['value'] === $value ? ' checked' : '' ) . '> <label for="' . esc_attr( $option_id ) . '">' . esc_html( $option['label'] ) . '</label></div>';
			}
			return $html . '</fieldset>';
		}

		$html = '<div class="' . esc_attr( $class ) . '"><label class="pegwork-form__label" for="' . esc_attr( $id ) . '">' . $label . '</label>' . $extra;

		if ( 'select' === $type ) {
			$html .= '<select class="pegwork-form__select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . $auto . '>';
			$html .= '<option value="">' . esc_html__( 'Choose an option', 'pegwork' ) . '</option>';
			foreach ( $item['options'] ?? array() as $option ) {
				$html .= '<option value="' . esc_attr( (string) $option['value'] ) . '"' . ( (string) $option['value'] === $value ? ' selected' : '' ) . '>' . esc_html( $option['label'] ) . '</option>';
			}
			return $html . '</select></div>';
		}

		if ( 'file' === $type ) {
			$accept = implode( ',', array_map( fn( string $ext ) => '.' . $ext, Uploads::extensions( $item ) ) );
			return $html . '<input class="pegwork-form__file" type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"'
				. ( '' !== $accept ? ' accept="' . esc_attr( $accept ) . '"' : '' ) . $aria . '></div>';
		}

		if ( in_array( $type, array( 'textarea', 'rich_text' ), true ) ) {
			return $html . '<textarea class="pegwork-form__textarea" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="6"' . $aria . $auto . $hint . '>' . esc_textarea( $value ) . '</textarea></div>';
		}

		$input = match ( $type ) {
			'email'  => 'type="email" spellcheck="false"',
			'url'    => 'type="url" spellcheck="false"',
			'date'   => 'type="date"',
			'number' => 'type="text" inputmode="decimal"',
			default  => 'type="text"',
		};

		return $html . '<input class="pegwork-form__input" ' . $input . ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $aria . $auto . $hint . '></div>';
	}

	/**
	 * A multi-choice item (a multi-select taxonomy): a fieldset of checkboxes named `pegwork[<key>][]`,
	 * or with `display: select` a multiple select.
	 *
	 * @param string                              $base   Base ID.
	 * @param array<string, mixed>                $item   Item with `options`.
	 * @param array<string, array<string, mixed>> $by_key Items by key.
	 * @param array<string, string|string[]>      $values Posted or prefilled values.
	 * @param array<string, string>               $errors Errors by item key.
	 */
	private static function choices( string $base, array $item, array $by_key, array $values, array $errors ): string {
		$key      = (string) $item['key'];
		$id       = $base . '-' . $key;
		$error    = $errors[ $key ] ?? '';
		$required = ! empty( $item['required'] );
		$chosen   = is_array( $values[ $key ] ?? null ) ? array_map( 'strval', $values[ $key ] ) : array();
		$name     = Submission::VALUES . '[' . $key . '][]';
		$help     = array_values( array_filter( array( (string) ( $item['help'] ?? '' ), Definition::condition_text( $item, $by_key ) ) ) );

		$described = array();
		$extra     = '';
		if ( $help ) {
			$described[] = $id . '-help';
			$extra      .= '<p class="pegwork-form__help" id="' . esc_attr( $id ) . '-help">' . esc_html( implode( ' ', $help ) ) . '</p>';
		}
		if ( '' !== $error ) {
			$described[] = $id . '-error';
			$extra      .= '<p class="pegwork-form__error" id="' . esc_attr( $id ) . '-error"><span class="pegwork-form__sr">' . esc_html__( 'Error:', 'pegwork' ) . ' </span>' . esc_html( $error ) . '</p>';
		}
		$label = esc_html( $item['label'] ) . self::required_marker( $item, $by_key );
		$aria  = ( '' !== $error ? ' aria-invalid="true"' : '' ) . ( $described ? ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"' : '' );
		$class = 'pegwork-form__field pegwork-form__field--choices' . ( '' !== $error ? ' pegwork-form__field--error' : '' );

		if ( 'select' === ( $item['display'] ?? 'checkboxes' ) ) {
			$html = '<div class="' . esc_attr( $class ) . '"><label class="pegwork-form__label" for="' . esc_attr( $id ) . '">' . $label . '</label>' . $extra
				. '<select class="pegwork-form__select" multiple id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' aria-required="true"' : '' ) . $aria . '>';
			foreach ( $item['options'] ?? array() as $option ) {
				$html .= '<option value="' . esc_attr( (string) $option['value'] ) . '"' . ( in_array( (string) $option['value'], $chosen, true ) ? ' selected' : '' ) . '>' . esc_html( $option['label'] ) . '</option>';
			}
			return $html . '</select></div>';
		}

		// Checkbox groups take no aria-required (not allowed on a group); "(required)" is in the legend.
		$html = '<fieldset class="' . esc_attr( $class ) . '"' . $aria . '><legend class="pegwork-form__label">' . $label . '</legend>' . $extra;
		foreach ( array_values( $item['options'] ?? array() ) as $index => $option ) {
			$option_id = $id . '-' . $index;
			$html     .= '<div class="pegwork-form__option"><input type="checkbox" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $option['value'] ) . '"'
				. ( in_array( (string) $option['value'], $chosen, true ) ? ' checked' : '' ) . '> <label for="' . esc_attr( $option_id ) . '">' . esc_html( $option['label'] ) . '</label></div>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * The label's required marker: "(required)", or for an item required only when its `required_when`
	 * holds "(required if ...)" with `data-pegwork-required-when`. The view script turns that into
	 * "(required)" plus `aria-required` while the condition holds, and hides it otherwise. Without
	 * JavaScript it stays as text (no `aria-required`, which would be wrong half the time).
	 *
	 * @param array<string, mixed>                $item   Item.
	 * @param array<string, array<string, mixed>> $by_key Items by key.
	 */
	private static function required_marker( array $item, array $by_key ): string {
		if ( ! empty( $item['required'] ) ) {
			return ' <span class="pegwork-form__required">' . esc_html__( '(required)', 'pegwork' ) . '</span>';
		}
		$when = Definition::required_text( $item, $by_key );

		return '' === $when ? '' : ' <span class="pegwork-form__required" data-pegwork-required-when>(' . esc_html( $when ) . ')</span>';
	}

	/**
	 * ID of the control an error summary link targets (the first radio or checkbox of a group).
	 *
	 * @param string               $base Base ID.
	 * @param array<string, mixed> $item Item.
	 */
	public static function control_id( string $base, array $item ): string {
		$id = $base . '-' . ( $item['key'] ?? '' );

		return self::is_group( $item ) ? $id . '-0' : $id;
	}

	/**
	 * Whether an item renders as a fieldset of radios or checkboxes (the fieldset carries its
	 * `aria-invalid` and `aria-describedby`).
	 *
	 * @param array<string, mixed> $item Item.
	 */
	public static function is_group( array $item ): bool {
		if ( ! empty( $item['multiple'] ) ) {
			return 'select' !== ( $item['display'] ?? 'checkboxes' );
		}

		return 'select' === ( $item['type'] ?? '' ) && 'radio' === ( $item['display'] ?? 'select' );
	}

	/**
	 * The success message when the form sets none.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	private static function default_success( array $form ): string {
		if ( isset( $form['confirm'] ) ) {
			$hours = (int) ( $form['confirm']['expires_hours'] ?? 72 );
			return sprintf(
				/* translators: %d: number of hours. */
				_n( 'Check your email for a link to confirm your submission. The link expires in %d hour.', 'Check your email for a link to confirm your submission. The link expires in %d hours.', $hours, 'pegwork' ),
				$hours
			);
		}
		return __( 'Thank you. Your message has been sent.', 'pegwork' );
	}

	/**
	 * A default value as form text.
	 *
	 * @param bool|int|float|string $value Default.
	 */
	private static function default_text( bool|int|float|string $value ): string {
		return is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value;
	}

	/**
	 * The message for a re-render outcome, or ''.
	 *
	 * @param array<string, mixed> $state Submission::state(): `outcome`, plus `limit` and `retry_after` when rate limited.
	 */
	private static function outcome_notice( array $state ): string {
		return match ( (string) ( $state['outcome'] ?? '' ) ) {
			'rate_limited' => self::rate_limit_notice( (string) ( $state['limit'] ?? '' ), (int) ( $state['retry_after'] ?? 0 ) ),
			'expired'      => __( 'This page was open too long. Check your answers and send again.', 'pegwork' ),
			'denied'       => __( 'You do not have permission to use this form.', 'pegwork' ),
			'failed'       => __( 'Your submission could not be saved. Try again later.', 'pegwork' ),
			'captcha_failed' => __( 'The spam check did not pass. Wait for the check to finish, then send the form again.', 'pegwork' ),
			'captcha_missing' => __( 'This form needs JavaScript for its spam check. Turn on JavaScript in your browser to send it.', 'pegwork' ),
			'record_failed' => __( 'Your submission was received, but it could not be processed. The site team has been told and will follow up.', 'pegwork' ),
			default        => '',
		};
	}

	/**
	 * The rate-limit notice: which limit, and when to try again ("in about 7 minutes", or hours past 90 minutes).
	 *
	 * @param string $limit       `ip`, `email` or `confirm`.
	 * @param int    $retry_after Seconds until a submission would pass.
	 */
	public static function rate_limit_notice( string $limit, int $retry_after ): string {
		$what = match ( $limit ) {
			'email'   => __( 'Too many submissions with this email address.', 'pegwork' ),
			'confirm' => __( 'Too many confirmation emails to this address.', 'pegwork' ),
			default   => __( 'Too many submissions from your connection.', 'pegwork' ),
		};
		if ( $retry_after <= 0 ) {
			return $what . ' ' . __( 'Try again later.', 'pegwork' );
		}

		$minutes = (int) ceil( $retry_after / MINUTE_IN_SECONDS );
		if ( $minutes > 90 ) {
			$hours = (int) ceil( $retry_after / HOUR_IN_SECONDS );
			/* translators: %d: number of hours. */
			return $what . ' ' . sprintf( _n( 'Try again in about %d hour.', 'Try again in about %d hours.', $hours, 'pegwork' ), $hours );
		}

		/* translators: %d: number of minutes. */
		return $what . ' ' . sprintf( _n( 'Try again in about %d minute.', 'Try again in about %d minutes.', $minutes, 'pegwork' ), $minutes );
	}

	/**
	 * A notice for users who can manage forms; nothing for anyone else.
	 *
	 * @param string $message Message.
	 */
	private static function manager_notice( string $message ): string {
		if ( ! current_user_can( Module::capability() ) ) {
			return '';
		}

		return '<div class="wp-block-pegwork-form pegwork-form pegwork-form--notice"><p>' . esc_html( $message ) . '</p></div>';
	}
}
