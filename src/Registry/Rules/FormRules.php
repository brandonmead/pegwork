<?php
/**
 * Validator rules for forms (`forms/<key>.json`, Phase 3).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry\Rules;

/**
 * A form definition: fields (bound to a content type's fields and taxonomies, or unbound), optional steps,
 * a target record, confirmation and notifications. Definitions are inert data; the forms module runs them.
 *
 * Used by Pegwork\Registry\Validator, whose helpers (props, type, key, add, ...) it calls.
 */
trait FormRules {

	/**
	 * Values of a form item's `display`.
	 */
	public const FORM_DISPLAYS = array( 'radio', 'checkboxes', 'select' );

	/**
	 * Extensions an upload field may never accept (server-executable or script-bearing files).
	 */
	public const DENIED_EXTENSIONS_PATTERN = '/^(php[a-z0-9]*|phtml|pht|phar|html?|xhtml|shtml|svgz?|m?js|exe)$/';

	/**
	 * File extensions in `accept`: lowercase letters and digits, without the dot.
	 */
	public const EXTENSION_PATTERN = '/^[a-z0-9]+$/';

	/**
	 * A literal email address in a notification recipient. Deliberately loose; the mailer validates for real.
	 */
	public const EMAIL_PATTERN = '/^[^\s@{}]+@[^\s@{}]+\.[^\s@{}]+$/';

	/**
	 * Recipient merge tags: `{admin_email}`, `{field:<key>}`, `{option:<page>.<field>}`.
	 */
	public const RECIPIENT_TAG_PATTERN = '/^\{(?:(admin_email)|field:([a-z][a-z0-9_]*)|option:([a-z][a-z0-9_]*)\.([a-z][a-z0-9_]*))\}$/';

	/**
	 * `{field:<key>}` merge tags in subjects and bodies.
	 */
	public const FIELD_TAG_PATTERN = '/\{field:([^}]*)\}/';

	/**
	 * Validates a form.
	 *
	 * @param mixed  $data    Decoded form.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function form( mixed $data, string $pointer ): void {
		$string    = fn( $v, $p ) => $this->type( $v, $p, 'string' );
		$is_object = $this->props(
			$data,
			$pointer,
			array(
				'format'         => fn( $v, $p ) => $this->format( $v, $p ),
				'key'            => fn( $v, $p ) => $this->bounded_key( $v, $p ),
				'label'          => $string,
				'target'         => fn( $v, $p ) => $this->props(
					$v,
					$p,
					array(
						'content_type' => fn( $w, $q ) => $this->key( $w, $q ),
						'action'       => fn( $w, $q ) => $this->enum( $w, $q, array( 'create', 'update' ) ),
						'status'       => fn( $w, $q ) => $this->enum( $w, $q, array( 'draft', 'pending', 'publish' ) ),
					),
					array( 'content_type' )
				),
				'access'         => fn( $v, $p ) => $this->form_access( $v, $p ),
				'confirm'        => fn( $v, $p ) => $this->props(
					$v,
					$p,
					array(
						'field'         => fn( $w, $q ) => $this->key( $w, $q ),
						'subject'       => $string,
						'body'          => function ( $w, $q ) {
							if ( $this->type( $w, $q, 'string' ) && ! str_contains( $w, '{confirm_url}' ) ) {
								$this->add( 'form_confirm_url_missing', $q, __( 'The confirmation body must contain "{confirm_url}", or the submitter cannot confirm.', 'pegwork' ) );
							}
						},
						'expires_hours' => fn( $w, $q ) => $this->positive_count( $w, $q ),
					),
					array( 'field', 'subject', 'body' )
				),
				'captcha'        => fn( $v, $p ) => $this->enum( $v, $p, array( 'turnstile' ) ),
				'fields'         => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->form_item( $item, $q ) ),
				'steps'          => fn( $v, $p ) => $this->list_of(
					$v,
					$p,
					fn( $step, $q ) => $this->props(
						$step,
						$q,
						array(
							'key'         => fn( $w, $r ) => $this->key( $w, $r ),
							'label'       => $string,
							'description' => $string,
							'fields'      => fn( $w, $r ) => $this->list_of( $w, $r, fn( $item, $s ) => $this->form_item( $item, $s ) ),
						),
						array( 'key', 'label', 'fields' )
					)
				),
				'notifications'  => fn( $v, $p ) => $this->list_of(
					$v,
					$p,
					fn( $item, $q ) => $this->props(
						$item,
						$q,
						array(
							'key'      => fn( $w, $r ) => $this->key( $w, $r ),
							'when'     => fn( $w, $r ) => $this->enum( $w, $r, array( 'submitted', 'confirmed' ) ),
							'to'       => fn( $w, $r ) => $this->recipient( $w, $r ),
							'reply_to' => fn( $w, $r ) => $this->recipient( $w, $r ),
							'subject'  => $string,
							'body'     => $string,
						),
						array( 'key', 'when', 'to', 'subject', 'body' )
					)
				),
				'messages'       => fn( $v, $p ) => $this->props(
					$v,
					$p,
					array(
						'success'        => $string,
						'submit_label'   => $string,
						'confirm_failed' => $string,
						'confirmed'      => $string,
					)
				),
				'retention_days' => fn( $v, $p ) => $this->positive_count( $v, $p ),
			),
			array( 'format', 'key', 'label' )
		);

		if ( ! $is_object ) {
			return;
		}

		if ( array_key_exists( 'fields', $data ) === array_key_exists( 'steps', $data ) ) {
			$this->add( 'form_fields_or_steps', $pointer, __( 'A form needs exactly one of "fields" or "steps".', 'pegwork' ) );
			return;
		}

		$items = $this->form_items( $data, $pointer );
		$keys  = array();
		foreach ( $items as $entry ) {
			if ( null !== $entry['key'] ) {
				$keys[ $entry['key'] ] = true;
			}
		}

		$this->form_unique_keys( $data, $pointer, $items );
		$has_target = is_array( $data['target'] ?? null );

		foreach ( $items as $entry ) {
			if ( null !== $entry['bound'] && ! $has_target ) {
				$this->add( 'form_bind_without_target', $entry['pointer'] . '/bind', __( 'Bound fields need a form "target" content type.', 'pegwork' ) );
			}
			if ( null === $entry['bound'] ) {
				foreach ( array( 'required_when', 'show_when' ) as $condition ) {
					$target = is_array( $entry['item'][ $condition ] ?? null ) ? ( $entry['item'][ $condition ]['field'] ?? null ) : null;
					if ( is_string( $target ) && ! isset( $keys[ $target ] ) ) {
						$this->add(
							'unknown_field',
							$entry['pointer'] . '/' . $condition . '/field',
							/* translators: %s: field key. */
							sprintf( __( 'Field "%s" is not a field of this form.', 'pegwork' ), $target )
						);
					}
				}
			}
		}

		$target = $has_target ? $data['target'] : array();
		if ( ( 'publish' === ( $target['status'] ?? null ) || 'update' === ( $target['action'] ?? null ) ) && ! is_array( $data['access'] ?? null ) ) {
			$this->add( 'form_access_required', $pointer . '/target', __( 'Forms that publish or update records need "access": { "capability": ... }, so anonymous visitors cannot use them.', 'pegwork' ) );
		}

		$confirm = is_array( $data['confirm'] ?? null ) ? $data['confirm'] : null;
		$texts   = array();
		if ( null !== $confirm ) {
			$texts['/confirm/subject'] = $confirm['subject'] ?? null;
			$texts['/confirm/body']    = $confirm['body'] ?? null;
		}
		foreach ( is_array( $data['notifications'] ?? null ) ? $data['notifications'] : array() as $index => $notification ) {
			if ( ! is_array( $notification ) ) {
				continue;
			}
			$texts[ '/notifications/' . $index . '/subject' ] = $notification['subject'] ?? null;
			$texts[ '/notifications/' . $index . '/body' ]    = $notification['body'] ?? null;
			if ( null !== $confirm && 'submitted' === ( $notification['when'] ?? null ) && is_string( $notification['to'] ?? null ) && str_starts_with( $notification['to'], '{field:' ) ) {
				$this->add(
					'form_premature_mail',
					$pointer . '/notifications/' . $index . '/to',
					__( 'With "confirm", a "submitted" notification cannot go to a submitted address: the confirmation email is the only mail before confirmation. Use "when": "confirmed".', 'pegwork' )
				);
			}
		}
		$this->unique( $data['notifications'] ?? null, $pointer . '/notifications', 'key' );

		foreach ( $texts as $at => $text ) {
			if ( ! is_string( $text ) || ! preg_match_all( self::FIELD_TAG_PATTERN, $text, $matches ) ) {
				continue;
			}
			foreach ( array_unique( $matches[1] ) as $name ) {
				if ( ! isset( $keys[ $name ] ) ) {
					/* translators: %s: field key. */
					$this->add( 'unknown_field', $pointer . $at, sprintf( __( 'Merge tag "{field:%s}" names no field of this form.', 'pegwork' ), $name ) );
				}
			}
		}
	}

	/**
	 * Validates one form item: a bound item (`bind`) or an unbound field.
	 *
	 * @param mixed  $item    Decoded item.
	 * @param string $pointer JSON pointer of the item.
	 */
	private function form_item( mixed $item, string $pointer ): void {
		if ( ! is_array( $item ) || ! array_key_exists( 'bind', $item ) ) {
			$this->field( $item, $pointer, 'form' );
			return;
		}

		$this->props(
			$item,
			$pointer,
			array(
				'bind'  => function ( $v, $p ) {
					$is_object = $this->props(
						$v,
						$p,
						array(
							'field'    => fn( $w, $q ) => $this->key( $w, $q ),
							'taxonomy' => fn( $w, $q ) => $this->key( $w, $q ),
						)
					);
					if ( $is_object && array_key_exists( 'field', $v ) === array_key_exists( 'taxonomy', $v ) ) {
						$this->add( 'form_bind_target', $p, __( '"bind" needs exactly one of "field" or "taxonomy".', 'pegwork' ) );
					}
				},
				'label' => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
			) + $this->form_presentation_checks(),
			array( 'bind' )
		);
	}

	/**
	 * Checks for presentational item properties, shared by bound and unbound items.
	 *
	 * @return array<string, callable>
	 */
	private function form_presentation_checks(): array {
		$string = fn( $v, $p ) => $this->type( $v, $p, 'string' );

		return array(
			'help'         => $string,
			'placeholder'  => $string,
			'autocomplete' => $string,
			'display'      => fn( $v, $p ) => $this->enum( $v, $p, self::FORM_DISPLAYS ),
		);
	}

	/**
	 * Checks `display` against the control's cardinality.
	 *
	 * @param mixed       $display     Display value (only checked when it is a known display).
	 * @param string|null $cardinality `single` (select field, single taxonomy), `multi` (multi taxonomy), null (no choices).
	 * @param string      $pointer     JSON pointer of the display property.
	 */
	private function form_display( mixed $display, ?string $cardinality, string $pointer ): void {
		if ( ! in_array( $display, self::FORM_DISPLAYS, true ) ) {
			return;
		}
		$allowed = match ( $cardinality ) {
			'single' => array( 'radio', 'select' ),
			'multi'  => array( 'checkboxes', 'select' ),
			default  => array(),
		};
		if ( ! in_array( $display, $allowed, true ) ) {
			$this->add(
				'form_display',
				$pointer,
				array() === $allowed
					? __( '"display" applies only to select fields and taxonomies.', 'pegwork' )
					/* translators: %s: comma-separated allowed values. */
					: sprintf( __( 'This choice allows "display": %s.', 'pegwork' ), implode( ', ', $allowed ) )
			);
		}
	}

	/**
	 * Checks `access`: "anyone" or { "capability": ... }.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function form_access( mixed $value, string $pointer ): void {
		if ( is_string( $value ) ) {
			if ( 'anyone' !== $value ) {
				$this->add( 'invalid_value', $pointer, __( '"access" is "anyone" or { "capability": "<capability>" }.', 'pegwork' ) );
			}
			return;
		}
		$this->props( $value, $pointer, array( 'capability' => fn( $v, $p ) => $this->capability( $v, $p ) ), array( 'capability' ) );
	}

	/**
	 * Checks a notification recipient: an address or a recipient merge tag.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function recipient( mixed $value, string $pointer ): void {
		if ( ! $this->type( $value, $pointer, 'string' ) ) {
			return;
		}
		if ( preg_match( '/^\{\{\s*option:([^}]*?)\s*\}\}$/', $value, $match ) ) {
			/* translators: %s: options page and field, such as sifg_settings.moderator_email. */
			$this->add( 'invalid_recipient', $pointer, sprintf( __( 'Form merge tags use single braces: write "{option:%s}".', 'pegwork' ), $match[1] ) );
			return;
		}
		if ( ! preg_match( self::EMAIL_PATTERN, $value ) && ! preg_match( self::RECIPIENT_TAG_PATTERN, $value ) ) {
			$this->add( 'invalid_recipient', $pointer, __( 'Expected an email address, "{admin_email}", "{field:<email field>}" or "{option:<page>.<email field>}".', 'pegwork' ) );
		}
	}

	/**
	 * Checks one `accept` extension.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function extension( mixed $value, string $pointer ): void {
		if ( ! $this->type( $value, $pointer, 'string' ) ) {
			return;
		}
		if ( ! preg_match( self::EXTENSION_PATTERN, $value ) ) {
			$this->add( 'invalid_value', $pointer, __( 'Extensions use lowercase letters and digits, without the dot, such as "pdf".', 'pegwork' ) );
			return;
		}
		if ( preg_match( self::DENIED_EXTENSIONS_PATTERN, $value ) ) {
			/* translators: %s: file extension. */
			$this->add( 'form_accept_denied', $pointer, sprintf( __( 'Uploads can never accept "%s" files (executable or script-bearing).', 'pegwork' ), $value ) );
		}
	}

	/**
	 * Checks an integer of at least 1.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function positive_count( mixed $value, string $pointer ): void {
		if ( $this->type( $value, $pointer, 'count' ) && $value < 1 ) {
			$this->add( 'invalid_value', $pointer, __( 'Expected an integer of at least 1.', 'pegwork' ) );
		}
	}

	/**
	 * Flattens a form's items (from `fields` or every step) with their form keys.
	 *
	 * @param array<string, mixed> $form    Decoded form.
	 * @param string               $pointer JSON pointer of the form.
	 * @return list<array{pointer: string, item: array<string, mixed>, key: string|null, key_pointer: string, bound: string|null}>
	 *         `bound` is `field`, `taxonomy` or null (unbound); `key` is the bound key or the item key.
	 */
	private function form_items( array $form, string $pointer ): array {
		$lists = array();
		if ( isset( $form['steps'] ) && is_array( $form['steps'] ) && array_is_list( $form['steps'] ) ) {
			foreach ( $form['steps'] as $index => $step ) {
				if ( is_array( $step ) && is_array( $step['fields'] ?? null ) && array_is_list( $step['fields'] ) ) {
					$lists[ $pointer . '/steps/' . $index . '/fields' ] = $step['fields'];
				}
			}
		} elseif ( isset( $form['fields'] ) && is_array( $form['fields'] ) && array_is_list( $form['fields'] ) ) {
			$lists[ $pointer . '/fields' ] = $form['fields'];
		}

		$items = array();
		foreach ( $lists as $list_pointer => $list ) {
			foreach ( $list as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$at    = $list_pointer . '/' . $index;
				$bind  = is_array( $item['bind'] ?? null ) ? $item['bind'] : null;
				$bound = null;
				$key   = is_string( $item['key'] ?? null ) ? $item['key'] : null;
				$where = $at . '/key';
				if ( array_key_exists( 'bind', $item ) ) {
					$bound = null !== $bind && array_key_exists( 'taxonomy', $bind ) ? 'taxonomy' : 'field';
					$key   = null !== $bind && is_string( $bind[ $bound ] ?? null ) ? $bind[ $bound ] : null;
					$where = $at . '/bind/' . $bound;
				}
				$items[] = array(
					'pointer'     => $at,
					'item'        => $item,
					'key'         => $key,
					'key_pointer' => $where,
					'bound'       => $bound,
				);
			}
		}

		return $items;
	}

	/**
	 * Reports form keys used twice (across all steps) and repeated step keys.
	 *
	 * @param array<string, mixed>       $form    Decoded form.
	 * @param string                     $pointer JSON pointer of the form.
	 * @param list<array<string, mixed>> $items   Output of form_items().
	 */
	private function form_unique_keys( array $form, string $pointer, array $items ): void {
		$seen = array();
		foreach ( $items as $entry ) {
			if ( null === $entry['key'] ) {
				continue;
			}
			if ( isset( $seen[ $entry['key'] ] ) ) {
				/* translators: %s: field key. */
				$this->add( 'duplicate_key', $entry['key_pointer'], sprintf( __( 'Field "%s" appears more than once in this form.', 'pegwork' ), $entry['key'] ) );
			}
			$seen[ $entry['key'] ] = true;
		}
		$this->unique( $form['steps'] ?? null, $pointer . '/steps', 'key' );
	}

	/**
	 * Checks a form against the registry: target type, bound fields and taxonomies, required coverage,
	 * conditions, confirmation field and recipients.
	 *
	 * @param array<string, mixed>                $form    Valid form.
	 * @param string                              $pointer JSON pointer of the form.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 */
	private function form_references( array $form, string $pointer, array $known ): void {
		$items  = $this->form_items( $form, $pointer );
		$type   = null;
		$target = $form['target'] ?? null;

		if ( null !== $target ) {
			if ( ! isset( $known['content_type'][ $target['content_type'] ] ) ) {
				$this->add(
					'unknown_content_type',
					$pointer . '/target/content_type',
					/* translators: %s: content type key. */
					sprintf( __( 'Content type "%s" is not defined in the registry (forms cannot target core post types).', 'pegwork' ), $target['content_type'] )
				);
				return;
			}
			$type = is_array( $known['content_type'][ $target['content_type'] ] ) ? $known['content_type'][ $target['content_type'] ] : null;
		}

		$by_key = array();
		foreach ( $items as $entry ) {
			if ( null !== $entry['key'] ) {
				$by_key[ $entry['key'] ] = $entry;
			}
		}

		if ( null !== $type ) {
			$this->form_bound_items( $form, $pointer, $items, $by_key, $type );
		}

		if ( isset( $form['confirm'] ) ) {
			$this->form_email_field( $form['confirm']['field'], $pointer . '/confirm/field', $by_key, $type, true, 'form_confirm_field' );
		}

		foreach ( $form['notifications'] ?? array() as $index => $notification ) {
			foreach ( array( 'to', 'reply_to' ) as $name ) {
				if ( ! isset( $notification[ $name ] ) || ! preg_match( self::RECIPIENT_TAG_PATTERN, $notification[ $name ], $match ) ) {
					continue;
				}
				$at = $pointer . '/notifications/' . $index . '/' . $name;
				if ( '' !== ( $match[2] ?? '' ) ) {
					$this->form_email_field( $match[2], $at, $by_key, $type, false, 'form_recipient_not_email' );
				}
				if ( '' !== ( $match[3] ?? '' ) ) {
					$this->form_option_recipient( $match[3], $match[4], $at, $known );
				}
			}
		}
	}

	/**
	 * Checks bound items against the target content type.
	 *
	 * @param array<string, mixed>                $form    Valid form.
	 * @param string                              $pointer JSON pointer of the form.
	 * @param list<array<string, mixed>>          $items   Output of form_items().
	 * @param array<string, array<string, mixed>> $by_key  Items by form key.
	 * @param array<string, mixed>                $type    Canonical target content type.
	 */
	private function form_bound_items( array $form, string $pointer, array $items, array $by_key, array $type ): void {
		$fields = array_column( $type['fields'] ?? array(), null, 'key' );
		$usages = array_column( $type['taxonomies'] ?? array(), null, 'key' );

		foreach ( $items as $entry ) {
			if ( 'field' === $entry['bound'] ) {
				$field = $fields[ $entry['key'] ] ?? null;
				if ( null === $field ) {
					/* translators: 1: field key, 2: content type key. */
					$this->add( 'form_unknown_bind', $entry['key_pointer'], sprintf( __( 'Field "%1$s" is not defined on content type "%2$s".', 'pegwork' ), $entry['key'], $type['key'] ) );
					continue;
				}
				if ( isset( $entry['item']['display'] ) ) {
					$this->form_display( $entry['item']['display'], 'select' === $field['type'] ? 'single' : null, $entry['pointer'] . '/display' );
				}
				foreach ( array( 'required_when', 'show_when' ) as $condition ) {
					$other = $field[ $condition ]['field'] ?? null;
					if ( is_string( $other ) && ! isset( $by_key[ $other ] ) ) {
						$this->add(
							'form_condition_not_bound',
							$entry['key_pointer'],
							/* translators: 1: field key, 2: condition name, 3: field key. */
							sprintf( __( 'Field "%1$s" has a "%2$s" condition on "%3$s"; bind "%3$s" in this form too.', 'pegwork' ), $entry['key'], $condition, $other )
						);
					}
				}
			}
			if ( 'taxonomy' === $entry['bound'] ) {
				$usage = $usages[ $entry['key'] ] ?? null;
				if ( null === $usage ) {
					/* translators: 1: taxonomy key, 2: content type key. */
					$this->add( 'form_unknown_bind', $entry['key_pointer'], sprintf( __( 'Taxonomy "%1$s" is not used by content type "%2$s".', 'pegwork' ), $entry['key'], $type['key'] ) );
					continue;
				}
				if ( isset( $entry['item']['display'] ) ) {
					$this->form_display( $entry['item']['display'], $usage['select'] ?? 'multi', $entry['pointer'] . '/display' );
				}
			}
		}

		if ( 'update' === ( $form['target']['action'] ?? 'create' ) ) {
			return;
		}
		$missing = array();
		foreach ( $fields as $key => $field ) {
			if ( true === ( $field['required'] ?? false ) && 'field' !== ( $by_key[ $key ]['bound'] ?? null ) ) {
				$missing[] = $key;
			}
		}
		foreach ( $usages as $key => $usage ) {
			if ( true === ( $usage['required'] ?? false ) && 'taxonomy' !== ( $by_key[ $key ]['bound'] ?? null ) ) {
				$missing[] = $key;
			}
		}
		if ( $missing ) {
			$this->add(
				'form_missing_required',
				$pointer . '/target/content_type',
				/* translators: 1: content type key, 2: comma-separated field and taxonomy keys. */
				sprintf( __( 'This form creates "%1$s" records but does not bind its required field(s) or taxonomy(ies): %2$s. Every submission would fail.', 'pegwork' ), $type['key'], implode( ', ', $missing ) )
			);
		}
	}

	/**
	 * Checks that a form key names an email field (bound or unbound), optionally always required.
	 *
	 * @param string                              $name     Form key.
	 * @param string                              $pointer  JSON pointer of the reference.
	 * @param array<string, array<string, mixed>> $by_key   Items by form key.
	 * @param array<string, mixed>|null           $type     Canonical target content type, null when unknown.
	 * @param bool                                $required Whether the field must also be always required.
	 * @param string                              $code     Error code when the field does not qualify.
	 */
	private function form_email_field( string $name, string $pointer, array $by_key, ?array $type, bool $required, string $code ): void {
		$entry = $by_key[ $name ] ?? null;
		if ( null === $entry ) {
			/* translators: %s: field key. */
			$this->add( 'unknown_field', $pointer, sprintf( __( 'Field "%s" is not a field of this form.', 'pegwork' ), $name ) );
			return;
		}

		$field = $entry['item'];
		if ( 'taxonomy' === $entry['bound'] ) {
			$field = array( 'type' => 'taxonomy' );
		} elseif ( 'field' === $entry['bound'] ) {
			if ( null === $type ) {
				return;
			}
			$field = array_column( $type['fields'] ?? array(), null, 'key' )[ $name ] ?? null;
			if ( null === $field ) {
				return; // Reported as form_unknown_bind.
			}
		}

		if ( 'email' !== ( $field['type'] ?? null ) || ( $required && true !== ( $field['required'] ?? false ) ) ) {
			$this->add(
				$code,
				$pointer,
				$required
					/* translators: %s: field key. */
					? sprintf( __( 'Field "%s" must be an email field that is always required.', 'pegwork' ), $name )
					/* translators: %s: field key. */
					: sprintf( __( 'Field "%s" is not an email field.', 'pegwork' ), $name )
			);
		}
	}

	/**
	 * Checks an `{option:<page>.<field>}` recipient: the page exists and the field is an email field.
	 *
	 * @param string                              $page    Options page key.
	 * @param string                              $name    Field key.
	 * @param string                              $pointer JSON pointer of the recipient.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 */
	private function form_option_recipient( string $page, string $name, string $pointer, array $known ): void {
		if ( ! isset( $known['options_page'][ $page ] ) ) {
			/* translators: %s: options page key. */
			$this->add( 'unknown_options_page', $pointer, sprintf( __( 'Options page "%s" is not defined.', 'pegwork' ), $page ) );
			return;
		}
		if ( ! is_array( $known['options_page'][ $page ] ) ) {
			return;
		}
		$field = array_column( $known['options_page'][ $page ]['fields'] ?? array(), null, 'key' )[ $name ] ?? null;
		if ( null === $field ) {
			/* translators: 1: field key, 2: options page key. */
			$this->add( 'unknown_field', $pointer, sprintf( __( 'Field "%1$s" is not defined on options page "%2$s".', 'pegwork' ), $name, $page ) );
			return;
		}
		if ( 'email' !== $field['type'] ) {
			/* translators: 1: options page key, 2: field key. */
			$this->add( 'form_recipient_not_email', $pointer, sprintf( __( 'Options field "%1$s.%2$s" is not an email field.', 'pegwork' ), $page, $name ) );
		}
	}
}
