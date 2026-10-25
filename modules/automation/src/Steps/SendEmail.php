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
use PegworkAutomation\TemplateTooLarge;
use WP_Error;

/**
 * Sends one email with wp_mail() from the site's default sender.
 *
 * - `to`: a template rendering to 1 to 5 addresses separated by commas; each part is an address (literal or
 *   from a field such as `{{record.contact_email}}`) or `{option:<page>.<field>}`, read with
 *   pegwork_get_option(), or `{role:<slug>}`, every member of a staff role (one with `edit_posts`, or a
 *   slug the `pegwork_automation_email_roles` filter allows), resolved at send time, at most 25 per email
 *   and sent in Bcc. Every address must pass is_email().
 * - `subject` and `text` render as text (term names for taxonomies); `html`, when set, renders with every
 *   value escaped and is sent as text/html with `text` as the plain-text alternative (PHPMailer AltBody,
 *   set by a phpmailer_init callback scoped to this call). Without `html` the mail is text/plain.
 * - `reply_to`: one address.
 *
 * Private fields may appear in the email (it goes to its recipients by design) but nowhere else: the log
 * summary holds masked recipients and the subject with private fields as `[private]`; error messages
 * have the recipients masked. Role members never appear anywhere but the Bcc header: the summary shows
 * `role:<slug> (N)` and errors name the role. A role with no member that has an email address fails
 * `no_recipient` and one with more than 25 members fails `too_many_recipients`, even when other parts
 * have addresses (as an empty options field does), so a partial send never hides a misconfiguration. A body with an `{{#each}}` block that renders past 64 KB fails
 * `template_too_large`. A wp_mail() failure is retryable (default policy: 2 retries 15 minutes
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
	 * One `{role:<slug>}` recipient (the registry's slug rule).
	 */
	public const ROLE_TAG = '/^\{role:([a-z0-9_-]{1,64})\}$/';

	/**
	 * Maximum role members per email (all roles together, after deduplication).
	 */
	public const MAX_ROLE_MEMBERS = 25;

	/**
	 * Filter: role slugs that may receive automation email without `edit_posts` (an allow-list; it cannot
	 * narrow the staff roles).
	 */
	public const ROLES_FILTER = 'pegwork_automation_email_roles';

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
		try {
			return $this->send( $step, $context );
		} catch ( TemplateTooLarge $e ) {
			return StepResult::fail( 'template_too_large', $e->getMessage(), array(), false );
		}
	}

	/**
	 * Renders and sends the email.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 */
	private function send( array $step, Context $context ): StepResult {
		$subject = self::one_line( Templates::render( (string) ( $step['subject'] ?? '' ), $context, Templates::TEXT ) );
		$summary = array(
			'subject' => self::one_line( Templates::render( (string) ( $step['subject'] ?? '' ), $context, Templates::LOG ) ),
		);

		$resolved = $this->resolve( (string) ( $step['to'] ?? '' ), $context );
		if ( $resolved instanceof WP_Error ) {
			return StepResult::fail( (string) $resolved->get_error_code(), $resolved->get_error_message(), $summary, false );
		}
		$to      = $resolved['to'];
		$bcc     = $resolved['bcc'];
		$summary = array( 'to' => self::shown( $resolved ) ) + $summary;

		$headers = array();
		foreach ( $bcc as $member ) {
			$headers[] = 'Bcc: ' . $member;
		}
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

		$type            = null === $html ? 'text/plain' : 'text/html';
		$headers[]       = 'Content-Type: ' . $type . '; charset=UTF-8';
		$summary['html'] = null !== $html;
		// The body was escaped for this type only, so a site-wide wp_mail_content_type filter cannot change it.
		$keep = static fn() => $type;

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
		add_filter( 'wp_mail_content_type', $keep, PHP_INT_MAX );
		if ( null !== $html ) {
			add_action( 'phpmailer_init', $alt );
		}
		try {
			// One call for To and Bcc together, so a retry resends one unit. With only role recipients To is
			// empty, which PHPMailer accepts when there is a Bcc.
			$sent = wp_mail( $to, $subject, $html ?? $text, $headers );
		} finally {
			remove_action( 'wp_mail_failed', $catch );
			remove_filter( 'wp_mail_content_type', $keep, PHP_INT_MAX );
			remove_action( 'phpmailer_init', $alt );
		}

		if ( ! $sent ) {
			$message = $error ?? 'wp_mail() returned false.';
			foreach ( $bcc as $member ) {
				$message = str_ireplace( $member, '[role member]', $message );
			}
			$message = self::mask_in( $message, $to );
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
		$resolved = $this->resolve( $template, $context );
		return $resolved instanceof WP_Error ? $resolved : array_merge( $resolved['to'], $resolved['bcc'] );
	}

	/**
	 * The recipients of a `to` template, split into explicit addresses and role members.
	 *
	 * @param string  $template `to` template.
	 * @param Context $context  Run context.
	 * @return array{to: string[], bcc: string[], roles: array<string, int>}|WP_Error Explicit addresses (To),
	 *         role members not already in To (Bcc), and the member count of each role; or an error naming no
	 *         private value and no member.
	 */
	public function resolve( string $template, Context $context ): array|WP_Error {
		$addresses = array();
		$roles     = array();
		// Option and role tags are read from the template, never from rendered values, so record data cannot name one.
		foreach ( self::split( $template, '/\{\{.*?\}\}(*SKIP)(*FAIL)|[,;]/' ) as $part ) {
			if ( preg_match( self::ROLE_TAG, $part, $m ) ) {
				$roles[ $m[1] ] = true;
				continue;
			}
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

		if ( array() === $unique && array() === $roles ) {
			return new WP_Error( 'no_recipient', 'The recipient list rendered empty.' );
		}
		if ( count( $unique ) > self::MAX_RECIPIENTS ) {
			return new WP_Error( 'too_many_recipients', sprintf( '%1$d recipients; at most %2$d per email.', count( $unique ), self::MAX_RECIPIENTS ) );
		}

		$bcc    = array();
		$counts = array();
		foreach ( array_keys( $roles ) as $slug ) {
			$members = self::role_members( (string) $slug );
			if ( $members instanceof WP_Error ) {
				return $members;
			}
			$counts[ (string) $slug ] = count( $members );
			foreach ( $members as $member ) {
				$key = strtolower( $member );
				if ( ! isset( $unique[ $key ] ) ) {
					$bcc[ $key ] = $member;
				}
			}
		}
		if ( count( $bcc ) > self::MAX_ROLE_MEMBERS ) {
			return new WP_Error( 'too_many_recipients', sprintf( '%1$d role members; at most %2$d per email.', count( $bcc ), self::MAX_ROLE_MEMBERS ) );
		}

		return array(
			'to'    => array_values( $unique ),
			'bcc'   => array_values( $bcc ),
			'roles' => $counts,
		);
	}

	/**
	 * The email addresses of a role's members on the current site.
	 *
	 * @param string $slug Role slug.
	 * @return string[]|WP_Error Unique addresses, or `unknown_role`, `role_not_allowed`, `too_many_recipients`
	 *                           (more than 25 members) or `no_recipient` (no member with an address).
	 */
	public static function role_members( string $slug ): array|WP_Error {
		if ( null === get_role( $slug ) ) {
			return new WP_Error( 'unknown_role', sprintf( 'Role "%s" does not exist on this site.', $slug ) );
		}
		if ( ! self::eligible( $slug ) ) {
			return new WP_Error( 'role_not_allowed', sprintf( 'Role "%1$s" is not a staff role (it lacks edit_posts), so automations cannot email it; the %2$s filter can allow it.', $slug, self::ROLES_FILTER ) );
		}

		$users = get_users(
			array(
				'role'        => $slug,
				'fields'      => array( 'user_email' ),
				'number'      => self::MAX_ROLE_MEMBERS + 1,
				'orderby'     => 'ID',
				'count_total' => false,
			)
		);
		if ( count( $users ) > self::MAX_ROLE_MEMBERS ) {
			return new WP_Error( 'too_many_recipients', sprintf( 'Role "%1$s" has more than %2$d members; at most %2$d per email.', $slug, self::MAX_ROLE_MEMBERS ) );
		}

		$members = array();
		foreach ( $users as $user ) {
			$address = trim( (string) ( is_object( $user ) ? ( $user->user_email ?? '' ) : '' ) );
			if ( is_email( $address ) ) {
				$members[ strtolower( $address ) ] = $address;
			}
		}
		if ( array() === $members ) {
			return new WP_Error( 'no_recipient', sprintf( 'Role "%s" has no member with an email address.', $slug ) );
		}
		return array_values( $members );
	}

	/**
	 * Whether a role may receive automation email: it holds `edit_posts`, or the filter allows its slug.
	 *
	 * @param string $slug Role slug.
	 */
	public static function eligible( string $slug ): bool {
		$role = get_role( $slug );
		if ( null === $role ) {
			return false;
		}
		if ( $role->has_cap( 'edit_posts' ) ) {
			return true;
		}
		/**
		 * Role slugs that may receive automation email although they lack `edit_posts`.
		 *
		 * @param string[] $slugs Allowed slugs (default none).
		 */
		$allowed = apply_filters( 'pegwork_automation_email_roles', array() );
		return in_array( $slug, array_map( 'strval', (array) $allowed ), true );
	}

	/**
	 * The roles that may receive automation email, for the builder: slug and translated name, never members.
	 *
	 * @return list<array{slug: string, name: string}>
	 */
	public static function eligible_roles(): array {
		$roles = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( self::eligible( (string) $slug ) ) {
				$roles[] = array(
					'slug' => (string) $slug,
					'name' => translate_user_role( (string) $name ),
				);
			}
		}
		return $roles;
	}

	/**
	 * The recipients as logs, previews and dry runs show them: masked explicit addresses, then
	 * `role:<slug> (N)` per role. Never a member address.
	 *
	 * @param array{to: string[], bcc: string[], roles: array<string, int>} $resolved Result of resolve().
	 * @return string[]
	 */
	public static function shown( array $resolved ): array {
		$shown = array_map( array( self::class, 'mask' ), $resolved['to'] );
		foreach ( $resolved['roles'] as $slug => $count ) {
			$shown[] = sprintf( 'role:%1$s (%2$d)', $slug, $count );
		}
		return $shown;
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
