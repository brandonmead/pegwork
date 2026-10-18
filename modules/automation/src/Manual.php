<?php
/**
 * Manual runs: what `wp pegwork automation run|tick|retry` do.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use DateTimeImmutable;
use Exception;
use Pegwork\Api\Records;
use PegworkAutomation\Clock\Clock;
use PegworkAutomation\Clock\FrozenClock;
use PegworkAutomation\Clock\SystemClock;
use PegworkAutomation\Scheduler\InlineScheduler;
use WP_Error;

/**
 * Starts, resumes and retries runs on demand. With a clock (`--now`) or `sync` (`--sync`) the engine is
 * swapped for this process: the given clock (or the current one) and an InlineScheduler, so runs and their
 * follow-ups execute in process on finish(); wake-ups not yet due go to the site's scheduler. Time travel
 * is refused in production. A manual run never moves an automation's regular schedule.
 */
final class Manual {

	/**
	 * Engine before the swap, restored by finish().
	 *
	 * @var Engine
	 */
	private Engine $previous;

	/**
	 * In-process scheduler, when swapped.
	 *
	 * @var InlineScheduler|null
	 */
	private ?InlineScheduler $inline = null;

	/**
	 * Prepares the engine for this process.
	 *
	 * @param Clock|null $clock Clock for this process (`--now`), or null for the current one.
	 * @param bool       $sync  Execute in process (implied by a clock).
	 */
	public function __construct( ?Clock $clock = null, bool $sync = false ) {
		$this->previous = Engine::instance();
		if ( null !== $clock || $sync ) {
			$this->inline = new InlineScheduler( $this->previous->scheduler(), array( Engine::RUN_HOOK, Engine::SWEEP_HOOK ) );
			Engine::use( $clock ?? $this->previous->clock(), $this->inline );
		}
	}

	/**
	 * A frozen clock for `--now`: a datetime in site time (`2027-11-02 09:00`), or an offset from the real
	 * time (`+12months`, `+13 months`, `+P30D`, `-PT1H`). Refused when wp_get_environment_type() is production.
	 *
	 * @param string      $now         Value of --now.
	 * @param string|null $environment Environment type (default wp_get_environment_type()).
	 */
	public static function clock( string $now, ?string $environment = null ): FrozenClock|WP_Error {
		$environment = $environment ?? wp_get_environment_type();
		if ( 'production' === $environment ) {
			return new WP_Error( 'pegwork_now_refused', 'Time travel (--now) is refused on production sites (wp_get_environment_type() is "production"). Set WP_ENVIRONMENT_TYPE to local, development or staging on a test site.' );
		}

		$now   = trim( $now );
		$error = new WP_Error( 'pegwork_invalid_now', sprintf( 'Cannot read --now "%s": use a site-time datetime such as "2027-11-02 09:00" or an offset such as "+12months" or "+P30D".', $now ) );
		try {
			if ( preg_match( '/^([+-])(P[0-9YMWDTHS]+)$/', $now, $m ) ) {
				$clock = new FrozenClock( ( new SystemClock() )->now() );
				$clock->advance( $m[1] . $m[2] );
				return $clock;
			}
			if ( preg_match( '/^[+-]\s*\d/', $now ) ) {
				$moved = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( $now );
				return false === $moved ? $error : new FrozenClock( $moved );
			}
			return new FrozenClock( $now );
		} catch ( Exception $e ) {
			return $error;
		}
	}

	/**
	 * Runs an automation now: a schedule fires once (sweeping its records, or only `$record`), an event
	 * automation starts one run against `$record` (its `when` is not evaluated).
	 *
	 * @param string   $key    Automation key.
	 * @param int|null $record Record ID.
	 * @return array{runs: int[], matched: int, duplicates: int, capped: bool, continued: bool}|WP_Error
	 */
	public function run( string $key, ?int $record = null ): array|WP_Error {
		$definition = pegwork_registry()->automation( $key );
		if ( null === $definition ) {
			return new WP_Error( 'pegwork_unknown_automation', sprintf( 'Unknown automation: %s.', $key ) );
		}
		if ( ! Overrides::enabled( $definition ) ) {
			return new WP_Error( 'automation_disabled', sprintf( 'Automation %s is disabled on this site; enable it first.', $key ) );
		}

		$engine  = Engine::instance();
		$trigger = $definition['trigger'];
		if ( 'schedule' === $trigger['type'] ) {
			if ( null !== $record && ! isset( $trigger['for_each'] ) ) {
				return new WP_Error( 'pegwork_no_record', sprintf( 'Automation %s has no for_each, so it binds no record; run it without --record.', $key ) );
			}
			return $engine->schedules()->start( $key, $definition, $record );
		}
		if ( 'event' !== $trigger['type'] ) {
			return new WP_Error( 'pegwork_unsupported_trigger', sprintf( 'Automation %1$s has a %2$s trigger, which this version cannot run on demand.', $key, $trigger['type'] ) );
		}
		if ( null === $record ) {
			return new WP_Error( 'pegwork_no_record', sprintf( 'Automation %s is event-triggered; name the record with --record=<id>.', $key ) );
		}

		$data = Records::get( $record );
		if ( null === $data ) {
			return new WP_Error( 'pegwork_record_not_found', sprintf( 'No record with ID %d.', $record ) );
		}
		if ( isset( $trigger['content_type'] ) && $trigger['content_type'] !== $data['type'] ) {
			return new WP_Error( 'pegwork_record_type', sprintf( 'Record %1$d is a %2$s; automation %3$s runs on %4$s.', $record, $data['type'], $key, $trigger['content_type'] ) );
		}

		$id = $engine->triggers()->manual( $key, $definition, $data );
		return array(
			'runs'       => $id > 0 ? array( $id ) : array(),
			'matched'    => 1,
			'duplicates' => $id > 0 ? 0 : 1,
			'capped'     => false,
			'continued'  => false,
		);
	}

	/**
	 * Resumes due waits (and queued runs idle for 10 minutes), replacing any pending wake-up.
	 *
	 * @return int[] IDs of the runs resumed.
	 */
	public function tick(): array {
		return Engine::instance()->maintenance()->resume( true );
	}

	/**
	 * Resumes a failed run from its failed step, with that step's retry policy counted afresh.
	 *
	 * @param int $id Run ID.
	 * @return true|WP_Error
	 */
	public function retry( int $id ): bool|WP_Error {
		$engine = Engine::instance();
		$run    = $engine->runs()->get( $id );
		if ( null === $run ) {
			return new WP_Error( 'pegwork_unknown_run', sprintf( 'No run with ID %d.', $id ) );
		}
		if ( 'failed' !== $run['status'] ) {
			return new WP_Error( 'pegwork_not_failed', sprintf( 'Run %1$d is %2$s; only failed runs can be retried.', $id, $run['status'] ) );
		}

		$context = $run['context'];
		unset( $context['retries'][ (string) $run['current_step'] ] );
		if ( isset( $context['retries'] ) && array() === $context['retries'] ) {
			unset( $context['retries'] );
		}
		if ( ! $engine->runs()->reopen( $id, $context ) ) {
			return new WP_Error( 'pegwork_not_failed', sprintf( 'Run %d changed meanwhile; it was not retried.', $id ) );
		}
		$engine->scheduler()->schedule( Engine::RUN_HOOK, array( $id ), $engine->clock()->now()->getTimestamp() );
		return true;
	}

	/**
	 * Executes what is due in process (when swapped), hands later wake-ups to the site's scheduler, and
	 * restores the engine.
	 *
	 * @return array{fired: int, handed: int} Wake-ups fired in process and handed to the site's scheduler.
	 */
	public function finish(): array {
		$out = array(
			'fired'  => 0,
			'handed' => 0,
		);
		if ( null !== $this->inline ) {
			$out['fired']  = $this->inline->drain( Engine::instance()->clock()->now()->getTimestamp() );
			$out['handed'] = $this->inline->flush();
			Engine::use( $this->previous->clock(), $this->previous->scheduler() );
			$this->inline = null;
		}
		return $out;
	}
}
