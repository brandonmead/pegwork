<?php
/**
 * WP-CLI automation commands.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Cli;

use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Runs;
use WP_CLI;

/**
 * Lists automations, shows the run log and switches automations on or off for this site.
 *
 * Output never includes run contexts (trigger payloads may hold private field values) or field values:
 * only statuses, step summaries (keys, IDs) and error messages.
 */
final class AutomationCommand {

	/**
	 * Lists the automations with their trigger, enabled state and recent runs.
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
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $items, array( 'key', 'label', 'trigger', 'json', 'override', 'enabled', 'last_run', 'open', 'failed_7d' ) );
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
	 * Sets an override.
	 *
	 * @param string $key   Automation key.
	 * @param string $value on or off.
	 */
	private function toggle( string $key, string $value ): void {
		if ( null === pegwork_registry()->automation( $key ) ) {
			WP_CLI::error( sprintf( 'Unknown automation: %s.', $key ) );
		}
		Overrides::set( $key, $value );
		WP_CLI::success( sprintf( '%s automation %s for this site.', 'on' === $value ? 'Enabled' : 'Disabled', $key ) );
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
				return 'every ' . $trigger['every'] . ( isset( $trigger['at'] ) ? ' at ' . $trigger['at'] : '' ) . ( isset( $trigger['for_each'] ) ? ' for each ' . $trigger['for_each']['content_type'] : '' ) . ' (not supported yet)';
		}
		return $trigger['type'] . ' (not supported yet)';
	}
}
