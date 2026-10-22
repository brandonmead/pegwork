<?php
/**
 * `send_email` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;
use PegworkAutomation\Templates;
use WP_Error;

/**
 * Sends one email with wp_mail() from the site's default sender.
 *
 * - `to`: a template rendering to 1 to 5 addresses separated by commas; each part is an address (literal or
 *   from a field such as `{{record.contact_email}}`) or `{option:<page>.<field>}`, read with
 *   pegwork_get_option(). Every address must pass is_email().
 * - `subject` and `text` render as text (term names for taxonomies); `html`, when set, renders with every
 *   value escaped and is sent as text/html with `text` as the plain-text alternative (PHPMailer AltBody,
 *   set by a phpmailer_init callback scoped to this call). Without `html` the mail is text/plain.
 * - `reply_to`: one address.
 *
 * Private fields may appear in the email (it goes to its recipients by design) but nowhere else: the log
 * summary holds masked recipients and the subject with private fields as `[private]`; error messages
 * have the recipients masked. A wp_mail() failure is retryable (default policy: 2 retries 15 minutes
 * apart); recipient errors are not.
 */
final class SendEmail implements Step {

	/**
	 * Maximum recipients per email.
	 */
	public const MAX_RECIPIENTS = 5;

	/**
	 * One `{option:<page>.<field>}` recipient.
	 */
	private const OPTION_TAG = '/^\{option:([a-z][a-z0-9_]*)\.([a-z][a-z0-9_]*)\}$/';

	/**
	 * Reads an options page field: ( string $page, string $field ): mixed. Null uses pegwork_get_option().
	 *
	 * @var callable|null
	 */
	private $option_reader;

	/**
	 * Builds the step handler.
	 *
	 * @param callable|null $option_reader Options page reader (tests); default pegwork_get_option() when it exists.
	 */
	public function __construct( ?callable $option_reader = null ) {
		$this->option_reader = $option_reader;
	}

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$subject = self::one_line( Templates::render( (string) ( $step['subject'] ?? '' ), $context, Templates::TEXT ) );
		$summary = array(
			'subject' => self::one_line( Templates::render( (string) ( $step['subject'] ?? '' ), $context, Templates::LOG ) ),
		);

		$to = $this->recipients( (string) ( $step['to'] ?? '' ), $context );
		if ( $to instanceof WP_Error ) {
			return StepResult::fail( (string) $to->get_error_code(), $to->get_error_message(), $summary, false );
		}
		$summary = array( 'to' => array_map( array( self::class, 'mask' ), $to ) ) + $summary;

		$headers = array();
		if ( isset( $step['reply_to'] ) ) {
			$reply_to = trim( Templates::render( (string) $step['reply_to'], $context, Templates::TEXT ) );
			if ( ! is_email( $reply_to ) ) {
				return StepResult::fail( 'invalid_recipient', sprintf( 'Reply-To "%s" is not an email address.', self::mask( $reply_to ) ), $summary, false );
			}
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$text = Templates::render( (string) ( $step['text'] ?? '' ), $context, Templates::TEXT );
		$html = isset( $step['html'] ) ? Templates::render( (string) $step['html'], $context, Templates::HTML ) : null;
		if ( '' === trim( $html ?? $text ) ) {
			// PHPMailer refuses an empty body; no retry can fix it.
			return StepResult::fail( 'empty_body', 'The email body rendered empty.', $summary, false );
		}

		$headers[]       = 'Content-Type: ' . ( null === $html ? 'text/plain' : 'text/html' ) . '; charset=UTF-8';
		$summary['html'] = null !== $html;

		$error = null;
		$catch = static function ( $failure ) use ( &$error ): void {
			$error = $failure instanceof WP_Error ? $failure->get_error_message() : 'wp_mail failed.';
		};
		$alt   = static function ( $phpmailer ) use ( $text ): void {
			if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
				$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
			}
		};

		add_action( 'wp_mail_failed', $catch );
		if ( null !== $html ) {
			add_action( 'phpmailer_init', $alt );
		}
		try {
			$sent = wp_mail( $to, $subject, $html ?? $text, $headers );
		} finally {
			remove_action( 'wp_mail_failed', $catch );
			remove_action( 'phpmailer_init', $alt );
		}

		if ( ! $sent ) {
			$message = self::mask_in( $error ?? 'wp_mail() returned false.', $to );
			return StepResult::fail( 'mail_failed', $message, $summary );
		}
		return StepResult::ok( array(), $summary );
	}

	/**
	 * The recipient addresses of a `to` template.
	 *
	 * @param string  $template `to` template.
	 * @param Context $context  Run context.
	 * @return string[]|WP_Error Unique addresses, or an error naming no private value.
	 */
	public function recipients( string $template, Context $context ): array|WP_Error {
		$addresses = array();
		// Option tags are read from the template, never from rendered values, so record data cannot name one.
		foreach ( self::split( $template, '/\{\{.*?\}\}(*SKIP)(*FAIL)|[,;]/' ) as $part ) {
			if ( preg_match( self::OPTION_TAG, $part, $m ) ) {
				$reader = $this->option_reader ?? ( function_exists( 'pegwork_get_option' ) ? 'pegwork_get_option' : null );
				if ( null === $reader ) {
					return new WP_Error( 'options_unavailable', sprintf( 'Recipient "%s" needs options pages (pegwork_get_option), which this site does not have.', $part ) );
				}
				$value = call_user_func( $reader, $m[1], $m[2] );
				$found = self::split( is_scalar( $value ) ? (string) $value : '' );
				if ( array() === $found ) {
					return new WP_Error( 'no_recipient', sprintf( 'Options field "%1$s.%2$s" is empty.', $m[1], $m[2] ) );
				}
				array_push( $addresses, ...$found );
				continue;
			}
			// One part, one recipient: a tag's value is never split, so a submitted text such as
			// "a@example.org, b@example.org" is one invalid address, not two recipients.
			$address = trim( Templates::render( $part, $context, Templates::TEXT ) );
			if ( '' !== $address ) {
				$addresses[] = $address;
			}
		}

		$unique = array();
		foreach ( $addresses as $address ) {
			if ( ! is_email( $address ) ) {
				return new WP_Error( 'invalid_recipient', sprintf( '"%s" is not an email address.', self::mask( $address ) ) );
			}
			$unique[ strtolower( $address ) ] = $address;
		}

		if ( array() === $unique ) {
			return new WP_Error( 'no_recipient', 'The recipient list rendered empty.' );
		}
		if ( count( $unique ) > self::MAX_RECIPIENTS ) {
			return new WP_Error( 'too_many_recipients', sprintf( '%1$d recipients; at most %2$d per email.', count( $unique ), self::MAX_RECIPIENTS ) );
		}
		return array_values( $unique );
	}

	/**
	 * An address as the log shows it: first character, then `***@domain` (j***@example.org).
	 *
	 * @param string $address Address.
	 */
	public static function mask( string $address ): string {
		$at = strrpos( $address, '@' );
		if ( false === $at || 0 === $at ) {
			return '***';
		}
		return substr( $address, 0, 1 ) . '***' . substr( $address, $at );
	}

	/**
	 * A message with every recipient address masked.
	 *
	 * @param string   $message   Message.
	 * @param string[] $addresses Addresses.
	 */
	private static function mask_in( string $message, array $addresses ): string {
		foreach ( $addresses as $address ) {
			$message = str_ireplace( $address, self::mask( $address ), $message );
		}
		return $message;
	}

	/**
	 * Comma- or semicolon-separated parts, trimmed, empty ones dropped.
	 *
	 * @param string $text      List.
	 * @param string $separator Separator pattern.
	 * @return string[]
	 */
	private static function split( string $text, string $separator = '/[,;]/' ): array {
		return array_values( array_filter( array_map( 'trim', (array) preg_split( $separator, $text ) ), 'strlen' ) );
	}

	/**
	 * Text on one line (no header injection through the subject).
	 *
	 * @param string $text Text.
	 */
	private static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $text ) );
	}
}
