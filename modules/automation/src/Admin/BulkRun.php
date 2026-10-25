<?php
/**
 * Bulk "Run automation" on core list tables.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

use Pegwork\Api\Records;
use Pegwork\Api\Throttle;
use PegworkAutomation\Conditions;
use PegworkAutomation\Context;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;
use PegworkAutomation\StartRoute;
use WP_Error;

/**
 * Starts a `manual` automation on records selected in a content type's list table
 * (docs/automation-v4-design.md section 4). No JavaScript:
 *
 * 1. The list's Bulk actions menu offers "Run: <button>" for each bulk-eligible automation (enabled, manual,
 *    with a `content_type`, nothing that can trash: Manual::trashes()) the user may run: administrators see
 *    all, other users the `allow: editors` ones if they hold the type's `edit_posts`. Applying it (core's
 *    form and `bulk-posts` nonce) validates the IDs, at most 50, and redirects to the confirmation screen
 *    with a new random request ID, so reloading the screen, or Back to it, keeps the same request.
 * 2. The confirmation screen (hidden admin page `pegwork-automation-bulk`, the type's `edit_posts`) lists
 *    each record with "Will run", "Does not apply" (its `when`) or "You cannot run it on this record"
 *    (StartRoute::can_run()). Its GET changes nothing.
 * 3. The POST (admin-post.php, nonce `pegwork_automation_bulk_<key>`) takes one hit of the user's
 *    `automation|manual|<user>` throttle bucket for the whole request, re-checks every record and starts
 *    each with Manual::start() (source `bulk`, request ID `<request>-<record>`, so the idempotency key is
 *    `manual:<user>:<request>-<record>` and a second submit starts nothing new), then redirects to the list
 *    with a one-time notice.
 */
final class BulkRun {

	/**
	 * Slug of the hidden confirmation page.
	 */
	public const PAGE = 'pegwork-automation-bulk';

	/**
	 * The admin-post action of the confirmation form.
	 */
	public const POST = 'pegwork_automation_bulk';

	/**
	 * Nonce action prefix of the confirmation form (the automation key is appended).
	 */
	public const NONCE = 'pegwork_automation_bulk_';

	/**
	 * Prefix of the bulk action names in the list's menu (the automation key is appended).
	 */
	public const ACTION = 'pegwork_automation_run_';

	/**
	 * Most records per request.
	 */
	public const MAX = 50;

	/**
	 * A request ID: 8 to 64 letters, digits and hyphens (as the start route's).
	 */
	private const REQUEST = '/^[A-Za-z0-9-]{8,64}$/';

	/**
	 * Transient prefix of the one-time list notice; the user ID is appended.
	 */
	private const NOTICE = 'pegwork_automation_bulk_notice_';

	/**
	 * Registers the hooks. Safe to call more than once (add_action ignores duplicates).
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'load-edit.php', array( self::class, 'hook_list' ) );
		add_action( 'admin_post_' . self::POST, array( self::class, 'submit' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Whether an automation can be offered in bulk: enabled, manual, with a `content_type`, and nothing in it
	 * (or in what it starts) can move records to the trash (core's bulk Trash covers that).
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public static function eligible( string $key, array $definition ): bool {
		return 'manual' === ( $definition['trigger']['type'] ?? '' )
			&& isset( $definition['trigger']['content_type'] )
			&& Overrides::enabled( $definition )
			&& ! Manual::trashes( $key );
	}

	/**
	 * The bulk-eligible automations on a content type that the current user may run, in registry order.
	 *
	 * @param string $type Content type key.
	 * @return array<string, array<string, mixed>> Canonical automations by key.
	 */
	public static function automations( string $type ): array {
		$object = get_post_type_object( $type );
		if ( null === $object || ! is_user_logged_in() ) {
			return array();
		}
		$admin = current_user_can( Screen::CAPABILITY );
		$out   = array();
		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			$key = (string) $key;
			if ( ( $definition['trigger']['content_type'] ?? null ) !== $type || ! self::eligible( $key, $definition ) ) {
				continue;
			}
			if ( $admin || ( 'editors' === ( $definition['trigger']['allow'] ?? 'admins' ) && current_user_can( $object->cap->edit_posts ) ) ) {
				$out[ $key ] = $definition;
			}
		}
		return $out;
	}

	/**
	 * Adds the hidden confirmation page, with the capability of the requested automation's type
	 * (`edit_posts`; `manage_options` when the key is unknown). Hooked to admin_menu.
	 */
	public static function add_page(): void {
		$definition = pegwork_registry()->automation( self::requested_key() );
		$object     = get_post_type_object( (string) ( $definition['trigger']['content_type'] ?? '' ) );
		$hook       = add_submenu_page(
			'',
			__( 'Run an automation', 'pegwork' ),
			__( 'Run an automation', 'pegwork' ),
			null === $object ? Screen::CAPABILITY : $object->cap->edit_posts,
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( self::class, 'title' ) );
		}
	}

	/**
	 * Gives the hidden page its document title: core's get_admin_page_title() finds none for a page without
	 * a parent menu, and admin-header.php would pass null to strip_tags(). Hooked to load-<page hook>.
	 */
	public static function title(): void {
		global $title;
		$title = __( 'Run an automation', 'pegwork' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core reads this global for the document title.
	}

	/**
	 * Hooks the bulk menu and handler of every content type that has a bulk-eligible automation. Hooked to
	 * load-edit.php, which runs before core handles a bulk action.
	 */
	public static function hook_list(): void {
		$types = array();
		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			if ( self::eligible( (string) $key, $definition ) ) {
				$types[ (string) $definition['trigger']['content_type'] ] = true;
			}
		}
		foreach ( array_keys( $types ) as $type ) {
			add_filter( 'bulk_actions-edit-' . $type, fn( $actions ) => self::bulk_actions( (array) $actions, $type ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, fn( $redirect, $action, $ids ) => self::handle( (string) $redirect, (string) $action, (array) $ids, $type ), 10, 3 );
		}
	}

	/**
	 * Adds "Run: <button>" per automation the user may run on the type.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @param string                $type    Content type key.
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions, string $type ): array {
		foreach ( self::automations( $type ) as $key => $definition ) {
			/* translators: %s: button text of the automation, such as "Send receipt". */
			$actions[ self::ACTION . $key ] = sprintf( __( 'Run: %s', 'pegwork' ), (string) $definition['trigger']['button'] );
		}
		return $actions;
	}

	/**
	 * Step 1, after core verified its `bulk-posts` nonce: validates the IDs (integers, at most 50) and
	 * redirects to the confirmation screen; more than 50, or an automation the user may not run, goes back to
	 * the list with a notice. Other bulk actions pass through.
	 *
	 * @param string            $redirect Where core would redirect (the list with its filters).
	 * @param string            $action   Bulk action name.
	 * @param array<int, mixed> $ids      Selected post IDs.
	 * @param string            $type     Content type key.
	 */
	public static function handle( string $redirect, string $action, array $ids, string $type ): string {
		if ( ! str_starts_with( $action, self::ACTION ) ) {
			return $redirect;
		}
		$key = substr( $action, strlen( self::ACTION ) );
		if ( ! isset( self::automations( $type )[ $key ] ) ) {
			self::set_notice( 'error', __( 'Sorry, you are not allowed to run this automation.', 'pegwork' ) );
			return $redirect;
		}
		$ids = self::ids( $ids );
		if ( null === $ids ) {
			/* translators: %d: most records per request (50). */
			self::set_notice( 'error', sprintf( __( 'Select at most %d records to run an automation on. Nothing was started.', 'pegwork' ), self::MAX ) );
			return $redirect;
		}
		if ( array() === $ids ) {
			return $redirect;
		}
		return add_query_arg(
			array(
				'page'       => self::PAGE,
				'automation' => rawurlencode( $key ),
				'records'    => implode( ',', $ids ), // Not `ids`: core strips that from the address bar (wp_removable_query_args()), so Back would lose it.
				'request'    => wp_generate_uuid4(), // One per Apply, so a reload or Back to the same screen keeps it.
				'back'       => rawurlencode( $redirect ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Step 2, the confirmation screen. Menu callback; changes nothing.
	 */
	public static function render(): void {
		$key        = self::requested_key();
		$definition = pegwork_registry()->automation( $key );
		$type       = (string) ( $definition['trigger']['content_type'] ?? '' );
		if ( null === $definition || ! isset( self::automations( $type )[ $key ] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to run this automation.', 'pegwork' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen; the POST has its own nonce.
		$raw     = isset( $_GET['records'] ) ? sanitize_text_field( wp_unslash( $_GET['records'] ) ) : '';
		$request = isset( $_GET['request'] ) ? sanitize_text_field( wp_unslash( $_GET['request'] ) ) : '';
		$back    = self::back( isset( $_GET['back'] ) ? sanitize_url( wp_unslash( $_GET['back'] ) ) : '', $type );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$ids     = self::ids( explode( ',', $raw ) );
		$request = 1 === preg_match( self::REQUEST, $request ) ? $request : wp_generate_uuid4();

		$button   = (string) $definition['trigger']['button'];
		$labels   = (array) ( pegwork_registry()->content_type( $type )['labels'] ?? array() );
		$singular = (string) ( $labels['singular'] ?? $type );
		$plural   = (string) ( $labels['plural'] ?? $type );

		echo '<div class="wrap pegwork-automations pegwork-bulk-run">';
		if ( null === $ids || array() === $ids ) {
			echo '<h1 tabindex="-1" autofocus>' . esc_html__( 'Run an automation', 'pegwork' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html(
				null === $ids
					/* translators: %d: most records per request (50). */
					? sprintf( __( 'Select at most %d records to run an automation on. Nothing was started.', 'pegwork' ), self::MAX )
					: __( 'No records were selected.', 'pegwork' )
			) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'pegwork' ) . '</a></p></div>';
			return;
		}

		$rows = self::rows( $key, $definition, $ids );
		$will = count( array_filter( $rows, fn( $row ) => 'run' === $row['state'] ) );

		echo '<h1 tabindex="-1" autofocus>' . esc_html(
			sprintf(
				/* translators: 1: button text of the automation, 2: number of records, 3: content type label (singular for 1, else plural). */
				__( 'Run "%1$s" on %2$s %3$s?', 'pegwork' ),
				$button,
				number_format_i18n( count( $rows ) ),
				1 === count( $rows ) ? $singular : $plural
			)
		) . '</h1>';
		echo '<p>' . esc_html( (string) $definition['label'] ) . '. ' . esc_html__( 'Each record gets its own run, which starts in the background. Records it does not apply to, or that you cannot run it on, are skipped.', 'pegwork' ) . '</p>';

		echo '<table class="widefat striped pegwork-table">';
		echo '<caption class="screen-reader-text">' . esc_html(
			/* translators: %s: content type label (plural). */
			sprintf( __( 'Selected %s', 'pegwork' ), $plural )
		) . '</caption>';
		echo '<thead><tr><th scope="col">' . esc_html( $singular ) . '</th><th scope="col">' . esc_html__( 'Status', 'pegwork' ) . '</th><th scope="col">' . esc_html__( 'Result', 'pegwork' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row['title'] ) . '</th><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( self::state_label( $row['state'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pegwork-bulk-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::POST ) . '">';
		echo '<input type="hidden" name="automation" value="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="records" value="' . esc_attr( implode( ',', $ids ) ) . '">';
		echo '<input type="hidden" name="request" value="' . esc_attr( $request ) . '">';
		echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
		wp_nonce_field( self::NONCE . $key );
		echo '<p class="submit">';
		if ( $will > 0 ) {
			echo '<button type="submit" class="button button-primary">' . esc_html(
				sprintf(
					/* translators: 1: number of records, 2: content type label (singular for 1, else plural). */
					__( 'Run on %1$s %2$s', 'pegwork' ),
					number_format_i18n( $will ),
					1 === $will ? $singular : $plural
				)
			) . '</button> ';
		} else {
			echo '<span class="pegwork-meta">' . esc_html__( 'It runs on none of these records.', 'pegwork' ) . '</span> ';
		}
		echo '<a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'pegwork' ) . '</a>';
		echo '</p></form></div>';
	}

	/**
	 * Step 3, the POST: nonce, capability, one throttle hit, then a start per record that will run.
	 * Redirects to the list with a notice. Hooked to admin_post_pegwork_automation_bulk.
	 */
	public static function submit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce action includes the key, so it is read first and verified next.
		$key = isset( $_POST['automation'] ) ? sanitize_key( wp_unslash( $_POST['automation'] ) ) : '';
		check_admin_referer( self::NONCE . $key );

		$definition = pegwork_registry()->automation( $key );
		$type       = (string) ( $definition['trigger']['content_type'] ?? '' );
		if ( null === $definition || ! isset( self::automations( $type )[ $key ] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to run this automation.', 'pegwork' ), '', array( 'response' => 403 ) );
		}

		$back    = self::back( isset( $_POST['back'] ) ? sanitize_url( wp_unslash( $_POST['back'] ) ) : '', $type );
		$ids     = self::ids( explode( ',', isset( $_POST['records'] ) ? sanitize_text_field( wp_unslash( $_POST['records'] ) ) : '' ) );
		$request = isset( $_POST['request'] ) ? sanitize_text_field( wp_unslash( $_POST['request'] ) ) : '';
		if ( null === $ids || array() === $ids || 1 !== preg_match( self::REQUEST, $request ) ) {
			/* translators: %d: most records per request (50). */
			self::set_notice( 'error', sprintf( __( 'Select 1 to %d records to run an automation on. Nothing was started.', 'pegwork' ), self::MAX ) );
			self::redirect( $back );
		}

		$user = get_current_user_id();
		$wait = Throttle::take( 'automation|manual|' . $user, StartRoute::RATE, StartRoute::WINDOW, Engine::instance()->clock()->now()->getTimestamp() );
		if ( $wait > 0 ) {
			if ( ! headers_sent() ) {
				header( 'Retry-After: ' . $wait );
			}
			wp_die(
				esc_html(
					/* translators: %d: seconds to wait. */
					sprintf( _n( 'Too many automations started. Try again in %d second.', 'Too many automations started. Try again in %d seconds.', $wait, 'pegwork' ), $wait )
				),
				esc_html__( 'Too many requests', 'pegwork' ),
				array(
					'response'  => 429,
					'back_link' => true,
				)
			);
		}

		$counts = array(
			'started'   => 0,
			'duplicate' => 0,
			'skipped'   => 0,
			'cannot'    => 0,
		);
		$manual = new Manual();
		foreach ( $ids as $id ) {
			if ( 'post' !== self::of_type( $id, $type ) || ! StartRoute::can_run( $key, $id ) ) {
				++$counts['cannot'];
				continue;
			}
			$result = $manual->start( $key, $id, $user, 'bulk', $request . '-' . $id );
			if ( $result instanceof WP_Error ) {
				++$counts[ in_array( $result->get_error_code(), array( 'automation_not_applicable', 'pegwork_record_not_found' ), true ) ? 'skipped' : 'cannot' ];
			} else {
				++$counts[ $result['duplicate'] ? 'duplicate' : 'started' ];
			}
		}

		self::set_notice(
			'success',
			self::summary( $counts ),
			current_user_can( Screen::CAPABILITY ) && $counts['started'] > 0
				? Screen::url(
					array(
						'view'       => 'log',
						'automation' => $key,
					)
				)
				: ''
		);
		self::redirect( $back );
	}

	/**
	 * The notice text of a submitted request: "Started 10 runs. 2 did not apply."
	 *
	 * @param array{started: int, duplicate: int, skipped: int, cannot: int} $counts Counts.
	 */
	public static function summary( array $counts ): string {
		/* translators: %s: number of runs. */
		$parts = array( sprintf( _n( 'Started %s run.', 'Started %s runs.', $counts['started'], 'pegwork' ), number_format_i18n( $counts['started'] ) ) );
		if ( $counts['skipped'] > 0 ) {
			/* translators: %s: number of records. */
			$parts[] = sprintf( _n( '%s did not apply.', '%s did not apply.', $counts['skipped'], 'pegwork' ), number_format_i18n( $counts['skipped'] ) );
		}
		if ( $counts['cannot'] > 0 ) {
			/* translators: %s: number of records. */
			$parts[] = sprintf( _n( 'You cannot run it on %s record.', 'You cannot run it on %s records.', $counts['cannot'], 'pegwork' ), number_format_i18n( $counts['cannot'] ) );
		}
		if ( $counts['duplicate'] > 0 ) {
			/* translators: %s: number of records. */
			$parts[] = sprintf( _n( '%s was already started from this screen.', '%s were already started from this screen.', $counts['duplicate'], 'pegwork' ), number_format_i18n( $counts['duplicate'] ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Prints and clears the current user's notice on a list screen. Hooked to admin_notices.
	 */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'edit' !== $screen->base ) {
			return;
		}
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible pegwork-bulk-notice"><p>' . esc_html( (string) $notice['message'] );
		if ( '' !== (string) ( $notice['url'] ?? '' ) ) {
			echo ' <a href="' . esc_url( (string) $notice['url'] ) . '">' . esc_html__( 'View runs', 'pegwork' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Stores the current user's one-time list notice.
	 *
	 * @param string $type    success or error.
	 * @param string $message Plain text.
	 * @param string $url     "View runs" link, or ''.
	 */
	private static function set_notice( string $type, string $message, string $url = '' ): void {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'url'     => $url,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * One row per selected ID for the confirmation table: title (or "<Singular> <id>" without `read_post`),
	 * status label, and state `run`, `skip` (missing, in the trash, or its `when` is false) or `cannot`
	 * (another type, or StartRoute::can_run() refuses).
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param int[]                $ids        Record IDs.
	 * @return array<int, array{id: int, title: string, status: string, state: string}>
	 */
	private static function rows( string $key, array $definition, array $ids ): array {
		$type     = (string) $definition['trigger']['content_type'];
		$singular = (string) ( pegwork_registry()->content_type( $type )['labels']['singular'] ?? $type );
		$rows     = array();
		foreach ( $ids as $id ) {
			$of_type = self::of_type( $id, $type );
			$post    = 'missing' === $of_type ? null : get_post( $id );
			$status  = null === $post ? null : get_post_status_object( (string) $post->post_status );
			$rows[]  = array(
				'id'     => $id,
				'title'  => null !== $post && 'post' === $of_type && current_user_can( 'read_post', $id ) && '' !== $post->post_title
					? wp_specialchars_decode( $post->post_title, ENT_QUOTES )
					/* translators: 1: content type label (singular), 2: record ID. */
					: sprintf( __( '%1$s %2$d', 'pegwork' ), $singular, $id ),
				'status' => null === $status ? '' : (string) $status->label,
				'state'  => self::state( $key, $definition, $id, $of_type ),
			);
		}
		return $rows;
	}

	/**
	 * What starting the automation on one record would do (see rows()).
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param int                  $id         Record ID.
	 * @param string               $of_type    self::of_type() of the record.
	 */
	private static function state( string $key, array $definition, int $id, string $of_type ): string {
		if ( 'missing' === $of_type ) {
			return 'skip';
		}
		if ( 'post' !== $of_type || ! StartRoute::can_run( $key, $id ) ) {
			return 'cannot';
		}
		$data = Records::get( $id );
		if ( null === $data || in_array( (string) $data['status'], array( 'trash', 'auto-draft' ), true ) ) {
			return 'skip';
		}
		if ( ! isset( $definition['trigger']['when'] ) ) {
			return 'run';
		}
		// The context Triggers::staff() checks `when` against.
		$context = new Context(
			Engine::instance()->clock(),
			$data,
			array(
				'user_id' => get_current_user_id(),
				'source'  => 'bulk',
				'origin'  => array(
					'source' => 'manual',
					'depth'  => 0,
				),
			)
		);
		return Conditions::evaluate( (array) $definition['trigger']['when'], $context ) ? 'run' : 'skip';
	}

	/**
	 * The label of a row state.
	 *
	 * @param string $state `run`, `skip` or `cannot`.
	 */
	private static function state_label( string $state ): string {
		return match ( $state ) {
			'run'   => __( 'Will run', 'pegwork' ),
			'skip'  => __( 'Does not apply', 'pegwork' ),
			default => __( 'You cannot run it on this record', 'pegwork' ),
		};
	}

	/**
	 * Whether a post ID is a record of the type: `post`, `other` (another post type) or `missing`.
	 *
	 * @param int    $id   Post ID.
	 * @param string $type Content type key.
	 */
	private static function of_type( int $id, string $type ): string {
		$actual = get_post_type( $id );
		if ( false === $actual ) {
			return 'missing';
		}
		return $type === $actual ? 'post' : 'other';
	}

	/**
	 * Validated record IDs: positive integers, unique, in order; null when more than 50.
	 *
	 * @param array<int, mixed> $raw Raw IDs.
	 * @return int[]|null
	 */
	private static function ids( array $raw ): ?array {
		$ids = array();
		foreach ( $raw as $value ) {
			$value = is_int( $value ) ? (string) $value : trim( (string) $value );
			if ( 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $value ) ) {
				$ids[ (int) $value ] = (int) $value;
			}
		}
		return count( $ids ) > self::MAX ? null : array_values( $ids );
	}

	/**
	 * Where Cancel and the POST return: the given list URL when it is this site's list of the type, else
	 * the type's list.
	 *
	 * @param string $url  Requested URL.
	 * @param string $type Content type key.
	 */
	private static function back( string $url, string $type ): string {
		$list  = admin_url( 'edit.php?post_type=' . rawurlencode( $type ) );
		$parts = '' === $url ? false : wp_parse_url( $url );
		$own   = wp_parse_url( admin_url( 'edit.php' ) );
		$path  = is_array( $own ) ? (string) ( $own['path'] ?? '' ) : '';
		$host  = is_array( $own ) ? (string) ( $own['host'] ?? '' ) : '';
		// Core's referer may be a path ("/wp-admin/edit.php?..."): accept it on this host only.
		if ( ! is_array( $parts ) || ( $parts['path'] ?? '' ) !== $path || ( isset( $parts['host'] ) && $parts['host'] !== $host ) ) {
			return $list;
		}
		$query = array();
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		return ( $query['post_type'] ?? '' ) === $type ? admin_url( 'edit.php?' . $parts['query'] ) : $list;
	}

	/**
	 * The automation key the request names (GET on the screen, read before its capability is known).
	 */
	private static function requested_key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects which page capability applies; nothing changes.
		return isset( $_GET['automation'] ) ? sanitize_key( wp_unslash( $_GET['automation'] ) ) : '';
	}

	/**
	 * Redirects and ends the request.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
