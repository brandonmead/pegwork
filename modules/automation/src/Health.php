<?php
/**
 * Scheduling health: Site Health test and admin notice.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use const Pegwork\Api\ADMIN_MENU;

/**
 * Tells site managers when automations are not running on time. WP-Cron only runs on page visits, so on a
 * quiet site (or with DISABLE_WP_CRON and no server cron) wake-ups can be hours or days late. Three checks:
 * runs overdue by more than OVERDUE_MINUTES, the daily maintenance job missing or not run for
 * MAINTENANCE_HOURS, and DISABLE_WP_CRON with no cron process seen for TICK_HOURS. Reported as one core
 * Site Health test and one notice on Pegwork screens for users who can manage options.
 *
 * Cron processes record their time in an autoload-off option at shutdown, at most once a minute (only
 * wp-cron.php and `wp cron event run` define DOING_CRON, so this costs nothing on other requests).
 */
final class Health {

	/**
	 * Site Health test ID.
	 */
	public const TEST = 'pegwork_automation_scheduling';

	/**
	 * Option holding the Unix time of the last cron process seen (autoload off).
	 */
	public const TICK_OPTION = 'pegwork_automation_last_tick';

	/**
	 * Minutes past its due time after which a queued or waiting run counts as overdue.
	 */
	public const OVERDUE_MINUTES = 15;

	/**
	 * Hours since its last daily slot after which the maintenance job counts as late (one day plus two hours).
	 */
	public const MAINTENANCE_HOURS = 26;

	/**
	 * Hours without a cron process after which, with DISABLE_WP_CRON set, the server cron counts as missing.
	 * Core schedules an hourly event, so a working server cron runs due events at least hourly.
	 */
	public const TICK_HOURS = 2;

	/**
	 * Seconds between tick writes.
	 */
	private const TICK_EVERY = 60;

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Uses the engine's clock, runs and scheduler.
	 *
	 * @param Engine $engine Engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Registers the Site Health test, the notice and the tick. Safe to call more than once.
	 */
	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'shutdown', array( self::class, 'on_shutdown' ) );
	}

	/**
	 * Adds the direct test. Hooked to site_status_tests.
	 *
	 * @param mixed $tests Tests by type.
	 * @return mixed
	 */
	public static function add_test( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct'][ self::TEST ] = array(
				'label' => __( 'Pegwork automation scheduling', 'pegwork' ),
				'test'  => array( self::class, 'run_test' ),
			);
		}
		return $tests;
	}

	/**
	 * Site Health test callback.
	 *
	 * @return array<string, mixed>
	 */
	public static function run_test(): array {
		return ( new self( Engine::instance() ) )->result();
	}

	/**
	 * Records the tick in cron processes. Hooked to shutdown.
	 */
	public static function on_shutdown(): void {
		if ( wp_doing_cron() ) {
			( new self( Engine::instance() ) )->record_tick();
		}
	}

	/**
	 * Prints the notice on Pegwork screens when scheduling is unhealthy. Hooked to admin_notices.
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::on_pegwork_screen() ) {
			return;
		}
		$problems = ( new self( Engine::instance() ) )->problems();
		if ( array() === $problems ) {
			return;
		}

		$critical = in_array( 'critical', array_column( $problems, 'status' ), true );
		echo '<div class="notice ' . ( $critical ? 'notice-error' : 'notice-warning' ) . ' pegwork-health-notice"><p><strong>'
			. esc_html__( 'Pegwork automations are not running on time.', 'pegwork' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem['message'] ) . '</li>';
		}
		echo '</ul><p>';
		if ( current_user_can( 'view_site_health_checks' ) ) {
			printf(
				/* translators: %s: link to the Site Health screen. */
				esc_html__( 'See %s for what to do.', 'pegwork' ),
				'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'pegwork' ) . '</a>'
			);
		} else {
			echo esc_html( self::fix_text() );
		}
		echo '</p></div>';
	}

	/**
	 * Writes the tick option unless it was written in the last minute.
	 *
	 * @return bool Whether it was written.
	 */
	public function record_tick(): bool {
		$now  = $this->engine->clock()->now()->getTimestamp();
		$last = (int) get_option( self::TICK_OPTION, 0 );
		if ( abs( $now - $last ) < self::TICK_EVERY ) {
			return false;
		}
		return update_option( self::TICK_OPTION, $now, false );
	}

	/**
	 * What is wrong, by code (`overdue`, `maintenance_missing`, `maintenance_late`, `no_cron`).
	 *
	 * @return array<string, array{status: string, message: string}> Status `critical` or `recommended`.
	 */
	public function problems(): array {
		$now      = $this->engine->clock()->now();
		$time     = $now->getTimestamp();
		$runs     = $this->engine->runs();
		$problems = array();

		$overdue = $runs->overdue( $runs->stamp( $now->modify( '-' . self::OVERDUE_MINUTES . ' minutes' ) ) );
		if ( $overdue['count'] > 0 ) {
			$oldest              = null === $overdue['oldest'] ? $time : (int) strtotime( $overdue['oldest'] . ' UTC' );
			$problems['overdue'] = array(
				'status'  => 'critical',
				'message' => sprintf(
					/* translators: 1: number of runs, 2: time span such as "3 hours". */
					_n(
						'%1$d automation run is overdue; the oldest was due %2$s ago.',
						'%1$d automation runs are overdue; the oldest was due %2$s ago.',
						$overdue['count'],
						'pegwork'
					),
					$overdue['count'],
					human_time_diff( $oldest, $time )
				),
			);
		}

		$next = $this->engine->scheduler()->next( Engine::MAINTENANCE_HOOK, array() );
		if ( null === $next ) {
			$problems['maintenance_missing'] = array(
				'status'  => 'recommended',
				'message' => __( 'The daily maintenance job is not scheduled, so runs whose wake-up was lost are not recovered.', 'pegwork' ),
			);
		} elseif ( $time - $next > ( self::MAINTENANCE_HOURS - 24 ) * HOUR_IN_SECONDS ) {
			$problems['maintenance_late'] = array(
				'status'  => 'recommended',
				'message' => sprintf(
					/* translators: 1: hours, 2: time span such as "5 hours". */
					__( 'The daily maintenance job has not run for over %1$d hours; it was due %2$s ago.', 'pegwork' ),
					self::MAINTENANCE_HOURS,
					human_time_diff( $next, $time )
				),
			);
		}

		$last = (int) get_option( self::TICK_OPTION, 0 );
		if ( self::wp_cron_disabled() && ( 0 === $last || $time - $last > self::TICK_HOURS * HOUR_IN_SECONDS ) ) {
			$problems['no_cron'] = array(
				'status'  => 'critical',
				'message' => 0 === $last
					? __( 'DISABLE_WP_CRON is set, but no cron run has been seen, so nothing seems to run a server cron.', 'pegwork' )
					: sprintf(
						/* translators: %s: time span such as "3 hours". */
						__( 'DISABLE_WP_CRON is set, but the last cron run was %s ago, so the server cron seems to have stopped.', 'pegwork' ),
						human_time_diff( $last, $time )
					),
			);
		}

		return $problems;
	}

	/**
	 * The Site Health test result.
	 *
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
	 */
	public function result(): array {
		$problems = $this->problems();
		$statuses = array_column( $problems, 'status' );
		$status   = in_array( 'critical', $statuses, true ) ? 'critical' : ( array() === $statuses ? 'good' : 'recommended' );
		$badge    = array(
			'label' => __( 'Pegwork', 'pegwork' ),
			'color' => 'blue',
		);

		if ( 'good' === $status ) {
			$last = (int) get_option( self::TICK_OPTION, 0 );
			$how  = self::wp_cron_disabled()
				? sprintf(
					/* translators: %s: time span such as "4 minutes". */
					__( 'A server cron runs WP-Cron; the last run was %s ago.', 'pegwork' ),
					human_time_diff( $last, $this->engine->clock()->now()->getTimestamp() )
				)
				: __( 'WP-Cron runs when someone visits the site, so on a quiet site runs can be late. A server cron keeps them on time.', 'pegwork' );
			return array(
				'label'       => __( 'Pegwork automations are running on time', 'pegwork' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'No automation run is overdue and the daily maintenance job is on schedule.', 'pegwork' ) . '</p><p>' . esc_html( $how ) . '</p>',
				'actions'     => '',
				'test'        => self::TEST,
			);
		}

		$items = '';
		foreach ( $problems as $problem ) {
			$items .= '<li>' . esc_html( $problem['message'] ) . '</li>';
		}
		$actions = '<p>' . esc_html( self::fix_text() ) . '</p><p><code>define( \'DISABLE_WP_CRON\', true );</code></p><p><code>*/5 * * * * cd /path/to/wordpress &amp;&amp; wp cron event run --due-now --quiet</code></p>';
		if ( isset( $problems['overdue'] ) ) {
			$actions .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Admin\Screen::SLUG . '&view=log' ) ) . '">' . esc_html__( 'View the automation run log', 'pegwork' ) . '</a></p>';
		}

		return array(
			'label'       => __( 'Pegwork automations are not running on time', 'pegwork' ),
			'status'      => $status,
			'badge'       => $badge,
			'description' => '<p>' . esc_html__( 'Pegwork runs automations (emails, waits, retries, schedules) from WP-Cron, which only runs when someone visits the site or a server cron calls it. Without a reliable cron, runs are late or never happen.', 'pegwork' ) . '</p><ul>' . $items . '</ul>',
			'actions'     => $actions,
			'test'        => self::TEST,
		);
	}

	/**
	 * What to do, in one sentence.
	 */
	private static function fix_text(): string {
		return __( 'Run WP-Cron from a server cron every 5 minutes: set DISABLE_WP_CRON in wp-config.php and schedule "wp cron event run --due-now" in the hosting panel or crontab.', 'pegwork' );
	}

	/**
	 * Whether WP-Cron's page-load spawn is turned off.
	 */
	private static function wp_cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Whether the current admin page is a Pegwork submenu page (Overview, Modules, Automations, ...).
	 */
	private static function on_pegwork_screen(): bool {
		global $plugin_page, $submenu;
		if ( ! is_string( $plugin_page ) || '' === $plugin_page ) {
			return false;
		}
		return in_array( $plugin_page, array_column( (array) ( $submenu[ ADMIN_MENU ] ?? array() ), 2 ), true );
	}
}
