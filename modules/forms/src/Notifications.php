<?php
/**
 * Notification emails and merge tags.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Sends a form's notifications for one moment (`submitted` here; `confirmed` arrives with confirmation)
 * through wp_mail, as plain text, synchronously.
 *
 * Merge tags: `{field:key}`, `{all_fields}`, `{form:label}`, `{entry:id}`, `{entry:admin_url}`,
 * `{post:edit_url}`, `{confirm_url}`, `{admin_email}`, `{site:name}`, `{option:page.field}`. Unknown
 * tags render empty. CR and LF are stripped from subjects and addresses, so values cannot add headers.
 */
final class Notifications {

	/**
	 * Admin screen slug for entries (Forms > Entries); `{entry:admin_url}` links there.
	 */
	public const ENTRIES_PAGE = 'pegwork-entries';

	/**
	 * Sends every notification of a form for a moment.
	 *
	 * @param array<string, mixed> $form    Canonical form.
	 * @param string               $when    `submitted` or `confirmed`.
	 * @param array<string, mixed> $context `entry_id` (int), `values` (by item key), optional `post_id`, `confirm_url`.
	 * @return array<string, string> Result by notification key: `sent`, `failed`, or `skipped` (no valid recipient).
	 */
	public static function send( array $form, string $when, array $context ): array {
		$results = array();
		foreach ( $form['notifications'] ?? array() as $notification ) {
			if ( ( $notification['when'] ?? '' ) !== $when ) {
				continue;
			}

			$to = self::recipient( (string) ( $notification['to'] ?? '' ), $context['values'] ?? array() );
			if ( '' === $to ) {
				$results[ $notification['key'] ] = 'skipped';
				continue;
			}

			$headers  = array();
			$reply_to = self::recipient( (string) ( $notification['reply_to'] ?? '' ), $context['values'] ?? array(), false );
			if ( '' !== $reply_to ) {
				$headers[] = 'Reply-To: ' . $reply_to;
			}

			$subject = self::single_line( self::render( (string) ( $notification['subject'] ?? '' ), $form, $context ) );
			$body    = self::render( (string) ( $notification['body'] ?? '' ), $form, $context );
			if ( '' === trim( $body ) ) {
				// wp_mail refuses an empty body; send the values instead of failing.
				$body = self::render( '{all_fields}', $form, $context );
			}

			$results[ $notification['key'] ] = wp_mail( $to, $subject, $body, $headers ) ? 'sent' : 'failed';
		}

		return $results;
	}

	/**
	 * Resolves a `to` or `reply_to` value to one valid address, or ''.
	 *
	 * `{option:page.field}` reads the options page value through pegwork_get_option() when that exists;
	 * when it is empty or invalid, `to` falls back to the site admin email (so an unset moderator address
	 * still reaches someone). `{field:key}` must hold a valid address, else nothing is sent.
	 *
	 * @param string               $spec     Literal address or recipient tag.
	 * @param array<string, mixed> $values   Values by item key.
	 * @param bool                 $fallback Whether an unresolved option tag falls back to the admin email.
	 */
	public static function recipient( string $spec, array $values, bool $fallback = true ): string {
		$spec = trim( $spec );
		if ( '' === $spec ) {
			return '';
		}

		$admin = (string) get_option( 'admin_email' );
		if ( '{admin_email}' === $spec ) {
			$address = $admin;
		} elseif ( preg_match( '/^\{field:([a-z][a-z0-9_]*)\}$/', $spec, $match ) ) {
			$address = is_string( $values[ $match[1] ] ?? null ) ? $values[ $match[1] ] : '';
		} elseif ( preg_match( '/^\{option:([a-z][a-z0-9_]*)\.([a-z][a-z0-9_]*)\}$/', $spec, $match ) ) {
			$address = self::option( $match[1], $match[2] );
			if ( ! is_email( self::single_line( $address ) ) && $fallback ) {
				$address = $admin;
			}
		} else {
			$address = $spec;
		}

		$address = self::single_line( $address );

		return is_email( $address ) ? $address : '';
	}

	/**
	 * Replaces merge tags in a subject or body. Values are plain text (the email is text/plain).
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $form     Canonical form.
	 * @param array<string, mixed> $context  See send().
	 */
	public static function render( string $template, array $form, array $context ): string {
		$by_key = Definition::items_by_key( $form );
		$values = is_array( $context['values'] ?? null ) ? $context['values'] : array();

		return (string) preg_replace_callback(
			'/\{([a-z_]+)(?::([^{}\s]*))?\}/',
			function ( array $found ) use ( $form, $context, $by_key, $values ): string {
				$tag = $found[1];
				$arg = $found[2] ?? '';

				switch ( $tag ) {
					case 'field':
						return isset( $by_key[ $arg ] ) ? self::display( $by_key[ $arg ], $values[ $arg ] ?? null ) : '';
					case 'all_fields':
						return self::all_fields( $by_key, $values );
					case 'form':
						return 'label' === $arg ? (string) $form['label'] : ( 'key' === $arg ? (string) $form['key'] : '' );
					case 'entry':
						$id = (int) ( $context['entry_id'] ?? 0 );
						if ( 'id' === $arg ) {
							return $id > 0 ? (string) $id : '';
						}
						return 'admin_url' === $arg && $id > 0 ? admin_url( 'admin.php?page=' . self::ENTRIES_PAGE . '&entry=' . $id ) : '';
					case 'post':
						$post_id = (int) ( $context['post_id'] ?? 0 );
						return 'edit_url' === $arg && $post_id > 0 ? admin_url( 'post.php?post=' . $post_id . '&action=edit' ) : '';
					case 'confirm_url':
						return (string) ( $context['confirm_url'] ?? '' );
					case 'admin_email':
						return (string) get_option( 'admin_email' );
					case 'site':
						return 'name' === $arg ? wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) : '';
					case 'option':
						$parts = explode( '.', $arg, 2 );
						return 2 === count( $parts ) ? self::option( $parts[0], $parts[1] ) : '';
				}
				return '';
			},
			$template
		);
	}

	/**
	 * A value as text: Yes/No for booleans, the option label for selects.
	 *
	 * @param array<string, mixed> $item  Item.
	 * @param mixed                $value Sanitized value.
	 */
	public static function display( array $item, mixed $value ): string {
		$type = $item['type'] ?? 'text';
		if ( 'boolean' === $type ) {
			return $value ? __( 'Yes', 'pegwork' ) : __( 'No', 'pegwork' );
		}
		if ( null === $value || '' === $value || ! is_scalar( $value ) ) {
			return '';
		}
		if ( 'select' === $type ) {
			foreach ( $item['options'] ?? array() as $option ) {
				if ( (string) $option['value'] === (string) $value ) {
					return (string) $option['label'];
				}
			}
		}
		return (string) $value;
	}

	/**
	 * `Label: value` per collected item, in form order; multi-line values start on the next line.
	 *
	 * @param array<string, array<string, mixed>> $by_key Items by key.
	 * @param array<string, mixed>                $values Values by key.
	 */
	private static function all_fields( array $by_key, array $values ): string {
		$lines = array();
		foreach ( $by_key as $key => $item ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$text    = self::display( $item, $values[ $key ] );
			$lines[] = str_contains( $text, "\n" ) ? $item['label'] . ":\n" . $text : $item['label'] . ': ' . $text;
		}
		return implode( "\n\n", $lines );
	}

	/**
	 * An options page value as a string, through pegwork_get_option() when it exists.
	 *
	 * @param string $page  Options page key.
	 * @param string $field Field key.
	 */
	private static function option( string $page, string $field ): string {
		if ( ! function_exists( 'pegwork_get_option' ) ) {
			return '';
		}
		$value = call_user_func( 'pegwork_get_option', $page, $field );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Strips CR, LF and other control characters (header injection), then trims.
	 *
	 * @param string $text Text.
	 */
	private static function single_line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
	}
}
