<?php
/**
 * WP-CLI automation commands.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Cli;

use Pegwork\Api\Records;
use PegworkAutomation\Duration;
use PegworkAutomation\Engine;
use PegworkAutomation\Links\Key;
use PegworkAutomation\Links\Links;
use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;
use PegworkAutomation\Runs;
use WP_CLI;

/**
 * Lists automations, shows the run log, runs, resumes and retries runs on demand (with optional time
 * travel off production), and switches automations on or off for this site.
 *
 * Output never includes run contexts (trigger payloads may hold private field values) or field values:
 * only statuses, step summaries (keys, IDs, masked recipients, subjects with private fields hidden) and
 * error messages.
 */
final class AutomationCommand {

	/**
	 * Lists the automations with their trigger, enabled state, recent runs and next schedule firing.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv, yaml, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$engine  = Engine::instance();
		$summary = $engine->runs()->summary( $engine->runs()->stamp( $engine->clock()->now()->modify( '-7 days' ) ) );
		$items   = array();

		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			$stats   = $summary[ $key ] ?? array();
			$next    = 'schedule' === $definition['trigger']['type'] ? $engine->scheduler()->next( Engine::SCHEDULE_HOOK, array( (string) $key ) ) : null;
			$items[] = array(
				'key'       => $key,
				'label'     => $definition['label'],
				'trigger'   => self::trigger( $definition['trigger'] ),
				'json'      => ( $definition['enabled'] ?? true ) ? 'on' : 'off',
				'override'  => Overrides::get( (string) $key ) ?? '',
				'enabled'   => Overrides::enabled( $definition ) ? 'yes' : 'no',
				'last_run'  => isset( $stats['last_status'] ) ? $stats['last_status'] . ' ' . $stats['last_at'] . ' UTC' : '',
				'open'      => ( $stats['queued'] ?? 0 ) + ( $stats['running'] ?? 0 ) + ( $stats['waiting'] ?? 0 ),
				'failed_7d' => $stats['failed'] ?? 0,
				'next'      => null === $next ? '' : wp_date( 'Y-m-d H:i T', $next ),
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $items, array( 'key', 'label', 'trigger', 'json', 'override', 'enabled', 'last_run', 'open', 'failed_7d', 'next' ) );
	}

	/**
	 * Shows the run log, newest first, or one run with its step attempts.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Only runs of this automation.
	 *
	 * [--run=<id>]
	 * : Show one run and its step attempts.
	 *
	 * [--status=<status>]
	 * : Only runs with this status: queued, running, waiting, completed, failed, cancelled, skipped.
	 *
	 * [--limit=<n>]
	 * : Maximum runs.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv, yaml, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$runs   = Engine::instance()->runs();
		$format = $assoc_args['format'] ?? 'table';
		$fields = array( 'id', 'automation', 'trigger', 'record', 'status', 'depth', 'step', 'outcome', 'created_at', 'finished_at' );

		if ( isset( $assoc_args['run'] ) ) {
			$run = $runs->get( (int) $assoc_args['run'] );
			if ( null === $run ) {
				WP_CLI::error( sprintf( 'No run with ID %d.', (int) $assoc_args['run'] ) );
			}
			WP_CLI\Utils\format_items( $format, array( self::row( $run ) ), $fields );

			$steps = array_map(
				fn( array $step ) => array(
					'step'        => $step['step_id'],
					'type'        => $step['step_type'],
					'attempt'     => (int) $step['attempt'],
					'status'      => $step['status'],
					'started_at'  => $step['started_at'],
					'duration_ms' => (int) $step['duration_ms'],
					'summary'     => array() === $step['summary'] ? '' : (string) wp_json_encode( $step['summary'] ),
					'error'       => (string) $step['error'],
				),
				$runs->steps( $run['id'] )
			);
			if ( 'table' === $format ) {
				WP_CLI::log( '' );
			}
			WP_CLI\Utils\format_items( $format, $steps, array( 'step', 'type', 'attempt', 'status', 'started_at', 'duration_ms', 'summary', 'error' ) );
			return;
		}

		$status = $assoc_args['status'] ?? null;
		if ( null !== $status && ! in_array( $status, Runs::STATUSES, true ) ) {
			WP_CLI::error( sprintf( 'Unknown status "%s". Use one of: %s.', $status, implode( ', ', Runs::STATUSES ) ) );
		}

		$rows = array_map( array( self::class, 'row' ), $runs->find( $args[0] ?? null, $status, (int) ( $assoc_args['limit'] ?? 20 ) ) );
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Runs an automation now: a schedule fires once (without moving its regular schedule), sweeping its
	 * records or only --record; an event automation starts one run against --record (its `when` is not
	 * evaluated). Idempotency keys still apply, so a repeat creates nothing.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Automation key.
	 *
	 * [--record=<id>]
	 * : Record to run against (required for event automations).
	 *
	 * [--now=<datetime|offset>]
	 * : Pretend it is this time, for this process only: a site-time datetime ("2027-11-02 09:00") or an
	 * offset from now ("+12months", "+P30D"). Implies --sync. Refused when the environment type is production.
	 *
	 * [--sync]
	 * : Execute the runs (and the runs they trigger) in this process instead of on WP-Cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork automation run sifg_staleness_check --now=+12months
	 *     wp pegwork automation run sifg_project_approved --record=24 --sync
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$manual = $this->manual( $assoc_args );
		$result = $manual->run( $args[0], isset( $assoc_args['record'] ) ? (int) $assoc_args['record'] : null );
		if ( is_wp_error( $result ) ) {
			$manual->finish();
			WP_CLI::error( $result->get_error_message() );
		}

		$done = $manual->finish();
		WP_CLI::log(
			sprintf(
				'Matched %1$d, created %2$d run(s), %3$d already had a run for their idempotency key.%4$s%5$s',
				$result['matched'],
				count( $result['runs'] ),
				$result['duplicates'],
				$result['capped'] ? ' Stopped at max_runs; the rest are checked next sweep.' : '',
				$result['continued'] ? ' The sweep continues in a chained wake-up.' : ''
			)
		);
		$this->report( $result['runs'], $done );
	}

	/**
	 * Resumes waits whose time has come (and queued runs idle for 10 minutes). The daily maintenance does
	 * this too; tick is for system cron setups and for --now. It never prunes or interrupts runs.
	 *
	 * ## OPTIONS
	 *
	 * [--now=<datetime|offset>]
	 * : Pretend it is this time, for this process only (see `run`). Implies --sync. Refused in production.
	 *
	 * [--sync]
	 * : Execute the resumed runs in this process instead of on WP-Cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork automation tick --now=+13months
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function tick( array $args, array $assoc_args ): void {
		$manual = $this->manual( $assoc_args );
		$ids    = $manual->tick();
		$done   = $manual->finish();
		WP_CLI::log( sprintf( 'Resumed %d run(s).', count( $ids ) ) );
		$this->report( $ids, $done );
	}

	/**
	 * Resumes a failed run from the step that failed, with that step's retry policy counted afresh. Steps
	 * before it are not repeated.
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Run ID, as shown by `wp pegwork automation log`.
	 *
	 * [--now=<datetime|offset>]
	 * : Pretend it is this time, for this process only (see `run`). Implies --sync. Refused in production.
	 *
	 * [--sync]
	 * : Execute the run in this process instead of on WP-Cron.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function retry( array $args, array $assoc_args ): void {
		$manual = $this->manual( $assoc_args );
		$result = $manual->retry( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			$manual->finish();
			WP_CLI::error( $result->get_error_message() );
		}
		$done = $manual->finish();
		$this->report( array( (int) $args[0] ), $done );
	}

	/**
	 * Turns an automation on for this site, overriding its JSON `enabled`.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Automation key, as shown by `wp pegwork automation list`.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function enable( array $args ): void {
		$this->toggle( $args[0], 'on' );
	}

	/**
	 * Turns an automation off for this site: no new runs start, and queued runs are cancelled when they wake.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Automation key.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function disable( array $args ): void {
		$this->toggle( $args[0], 'off' );
	}

	/**
	 * Shows where the signed link key comes from (never the key itself), or rotates it.
	 *
	 * The key is the PEGWORK_LINK_SECRET constant when wp-config.php defines it, else the option
	 * pegwork_link_secret, generated the first time a link is minted. Rotating replaces the option, so every
	 * link sent so far stops working (they show the "does not work" page).
	 *
	 * ## OPTIONS
	 *
	 * [--rotate]
	 * : Replace the stored key with a new random one.
	 *
	 * [--yes]
	 * : Rotate without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork automation link-key
	 *     wp pegwork automation link-key --rotate --yes
	 *
	 * @subcommand link-key
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function link_key( array $args, array $assoc_args ): void {
		$source = Key::source();
		if ( isset( $assoc_args['rotate'] ) ) {
			if ( 'constant' === $source ) {
				WP_CLI::error( sprintf( 'The link key comes from %s in wp-config.php; change it there to rotate it.', Key::CONSTANT ) );
			}
			WP_CLI::confirm( 'Rotate the link key? Every link sent so far stops working.', $assoc_args );
			if ( ! Key::rotate() ) {
				WP_CLI::error( 'Could not store the new link key.' );
			}
			WP_CLI::success( sprintf( 'Rotated the link key (option %s). Links sent before now no longer work.', Key::OPTION ) );
			return;
		}

		switch ( $source ) {
			case 'constant':
				WP_CLI::log( sprintf( 'Link key: constant %s (wp-config.php).', Key::CONSTANT ) );
				if ( (int) Key::constant_length() < Key::MIN_CONSTANT_LENGTH ) {
					WP_CLI::warning( sprintf( '%1$s is shorter than %2$d characters; use a long random value.', Key::CONSTANT, Key::MIN_CONSTANT_LENGTH ) );
				}
				return;
			case 'option':
				WP_CLI::log( sprintf( 'Link key: option %s (generated, autoload off).', Key::OPTION ) );
				return;
		}
		WP_CLI::log( sprintf( 'No link key yet: one is generated in option %s the first time a link is minted.', Key::OPTION ) );
	}

	/**
	 * Prints a signed URL for one of an automation's links on a record, as an email would carry it. For
	 * testing: anyone with the URL can use it.
	 *
	 * ## OPTIONS
	 *
	 * <automation>
	 * : Automation key.
	 *
	 * <link>
	 * : Link name, from the automation's "links".
	 *
	 * <record>
	 * : Record ID.
	 *
	 * [--now=<datetime|offset>]
	 * : Mint as if it were this time (the link expires its "expires" after it), for example "-400days" for an
	 * expired link. Refused when the environment type is production.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork automation link sifg_staleness_check still_active 42
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function link( array $args, array $assoc_args ): void {
		[ $key, $name, $record ] = array( (string) $args[0], (string) $args[1], (int) $args[2] );
		$definition              = pegwork_registry()->automation( $key );
		if ( null === $definition ) {
			WP_CLI::error( sprintf( 'Unknown automation: %s.', $key ) );
		}
		if ( ! isset( $definition['links'][ $name ] ) ) {
			$names = array_keys( $definition['links'] ?? array() );
			WP_CLI::error( sprintf( 'Automation %1$s has no link "%2$s"%3$s.', $key, $name, array() === $names ? '' : ' (links: ' . implode( ', ', $names ) . ')' ) );
		}
		$data = Records::get( $record );
		$type = Links::bound_type( $definition );
		if ( null === $data || ( null !== $type && $type !== $data['type'] ) ) {
			WP_CLI::error( sprintf( 'No %1$s record with ID %2$d.', $type ?? 'registry', $record ) );
		}

		$clock = Engine::instance()->clock();
		if ( isset( $assoc_args['now'] ) ) {
			$clock = Manual::clock( (string) $assoc_args['now'] );
			if ( is_wp_error( $clock ) ) {
				WP_CLI::error( $clock->get_error_message() );
			}
		}
		$url = Links::url( $key, $name, $record, $clock->now() );
		if ( null === $url ) {
			WP_CLI::error( sprintf( 'Could not mint link "%1$s" of %2$s.', $name, $key ) );
		}
		$expires = Duration::add( $clock->now(), (string) $definition['links'][ $name ]['expires'] );
		WP_CLI::log( $url );
		WP_CLI::log( sprintf( 'Expires %s (site time).', null === $expires ? '?' : wp_date( 'Y-m-d H:i T', $expires->getTimestamp() ) ) );
	}

	/**
	 * Sets an override.
	 *
	 * @param string $key   Automation key.
	 * @param string $value on or off.
	 */
	private function toggle( string $key, string $value ): void {
		$result = Overrides::toggle( $key, $value );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( '%s automation %s for this site.', 'on' === $value ? 'Enabled' : 'Disabled', $key ) );
	}

	/**
	 * The manual-run helper for --now and --sync; exits with an error when --now is refused or unreadable.
	 *
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	private function manual( array $assoc_args ): Manual {
		$clock = null;
		if ( isset( $assoc_args['now'] ) ) {
			$clock = Manual::clock( (string) $assoc_args['now'] );
			if ( is_wp_error( $clock ) ) {
				WP_CLI::error( $clock->get_error_message() );
			}
			WP_CLI::log( sprintf( 'Clock for this process: %s (site time).', $clock->now()->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s T' ) ) );
		}
		return new Manual( $clock, isset( $assoc_args['sync'] ) );
	}

	/**
	 * Prints the runs' statuses after a command.
	 *
	 * @param int[]                          $ids  Run IDs.
	 * @param array{fired: int, handed: int} $done What finish() did.
	 */
	private function report( array $ids, array $done ): void {
		$runs = Engine::instance()->runs();
		$rows = array();
		foreach ( $ids as $id ) {
			$run = $runs->get( $id );
			if ( null !== $run ) {
				$rows[] = self::row( $run );
			}
		}
		if ( array() !== $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'automation', 'trigger', 'record', 'status', 'step', 'outcome', 'created_at', 'finished_at' ) );
		}
		if ( $done['fired'] > 0 || $done['handed'] > 0 ) {
			WP_CLI::log( sprintf( 'Executed %1$d wake-up(s) in this process; %2$d later wake-up(s) left to WP-Cron.', $done['fired'], $done['handed'] ) );
		} elseif ( array() !== $ids ) {
			WP_CLI::log( 'Queued for WP-Cron (add --sync to run them now).' );
		}
		WP_CLI::success( 'Done.' );
	}

	/**
	 * A run as a log row.
	 *
	 * @param array<string, mixed> $run Run.
	 * @return array<string, mixed>
	 */
	private static function row( array $run ): array {
		$outcome = $run['context']['outcome'] ?? (string) $run['error'];
		return array(
			'id'          => $run['id'],
			'automation'  => $run['automation'],
			'trigger'     => $run['trigger_type'] . ( isset( $run['context']['event'] ) ? ' ' . $run['context']['event'] : '' ),
			'record'      => $run['record_id'] ?? '',
			'status'      => $run['status'],
			'depth'       => $run['depth'],
			'step'        => $run['current_step'],
			'outcome'     => $outcome,
			'created_at'  => $run['created_at'],
			'finished_at' => (string) $run['finished_at'],
		);
	}

	/**
	 * One-line trigger summary.
	 *
	 * @param array<string, mixed> $trigger Canonical trigger.
	 */
	private static function trigger( array $trigger ): string {
		switch ( $trigger['type'] ) {
			case 'event':
				$filter = $trigger['content_type'] ?? ( $trigger['form'] ?? '' );
				return $trigger['event'] . ( '' !== $filter ? ' (' . $filter . ')' : '' );
			case 'schedule':
				return 'every ' . $trigger['every'] . ( isset( $trigger['at'] ) ? ' at ' . $trigger['at'] : '' ) . ( isset( $trigger['for_each'] ) ? ' for each ' . $trigger['for_each']['content_type'] : '' );
		}
		return $trigger['type'] . ' (not supported yet)';
	}
}
