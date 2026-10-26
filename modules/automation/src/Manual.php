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
	 * automation starts one run against `$record` (its `when` is not evaluated), a manual automation starts
	 * one run as start() does with source `cli` (its `when` is checked).
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
		if ( 'manual' === $trigger['type'] ) {
			$result = $this->start( $key, $record, get_current_user_id(), 'cli' );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			return array(
				'runs'       => $result['duplicate'] ? array() : array( $result['run'] ),
				'matched'    => 1,
				'duplicates' => $result['duplicate'] ? 1 : 0,
				'capped'     => false,
				'continued'  => false,
			);
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
		$type = $trigger['content_type'] ?? null;
		if ( null === $type && Triggers::binds_form( $trigger ) ) {
			$type = pegwork_registry()->form( (string) $trigger['form'] )['target']['content_type'] ?? null; // A form-bound automation runs on its form's target type.
		}
		if ( null !== $type && $type !== $data['type'] ) {
			return new WP_Error( 'pegwork_record_type', sprintf( 'Record %1$d is a %2$s; automation %3$s runs on %4$s.', $record, $data['type'], $key, $type ) );
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
	 * Starts a run of a `manual` automation, shared by the record editor's REST route, the list's Run now
	 * and the CLI. Callers authorize the user. Refuses unknown, non-manual and disabled automations, a
	 * missing, unknown or other-type record when the trigger has a `content_type`, a record when it has
	 * none, and a false `when` (`automation_not_applicable`).
	 *
	 * @param string      $key     Automation key.
	 * @param int|null    $record  Record ID.
	 * @param int         $user    User who started it.
	 * @param string      $source  `editor`, `list`, `bulk` or `cli`.
	 * @param string|null $request Request ID for double-submit deduplication, or null.
	 * @return array{run: int, duplicate: bool}|WP_Error
	 */
	public function start( string $key, ?int $record, int $user, string $source, ?string $request = null ): array|WP_Error {
		$target = self::target( $key, $record );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$result = Engine::instance()->triggers()->staff( $key, $target['definition'], $target['record'], $user, $source, $request );
		if ( null === $result ) {
			return new WP_Error( 'automation_not_applicable', __( 'This automation does not apply to this record.', 'pegwork' ), array( 'status' => 409 ) );
		}
		return $result;
	}

	/**
	 * Starts a run of a `manual` automation from a start_automation step of another run, with start()'s
	 * checks (unknown or non-manual, disabled, the record), as Triggers::chained() describes: origin depth
	 * of the parent plus 1 (a skipped run at the depth cap), idempotency key `start:<run>-<step>[-<iteration>]`.
	 * The target's `allow` is not consulted: the system starts it.
	 *
	 * @param string               $key       Automation key.
	 * @param int|null             $record    Record ID, or null when the target has no content type.
	 * @param array<string, mixed> $starter   Parent run row (`id`, `automation`, `depth`, `context`).
	 * @param string               $step      Step ID in the parent.
	 * @param int|null             $iteration 1-based loop item number, or null outside loop bodies.
	 * @return array{run: int, started: bool, skipped: string|null, duplicate: bool}|WP_Error
	 */
	public static function chain( string $key, ?int $record, array $starter, string $step, ?int $iteration = null ): array|WP_Error {
		$target = self::target( $key, $record );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		return Engine::instance()->triggers()->chained( $key, $target['definition'], $target['record'], $starter, $step, $iteration );
	}

	/**
	 * The checks start(), chain() and the dry run share: the automation exists, has a manual trigger and is
	 * enabled, and the record exists (not trashed) and has the trigger's content type, or is absent when it
	 * has none.
	 *
	 * @param string   $key    Automation key.
	 * @param int|null $record Record ID.
	 * @return array{definition: array<string, mixed>, record: array<string, mixed>|null}|WP_Error
	 */
	public static function target( string $key, ?int $record ): array|WP_Error {
		$definition = pegwork_registry()->automation( $key );
		if ( null === $definition || 'manual' !== $definition['trigger']['type'] ) {
			/* translators: %s: automation key. */
			return new WP_Error( 'pegwork_unknown_automation', sprintf( __( 'No automation %s can be started by staff.', 'pegwork' ), $key ), array( 'status' => 404 ) );
		}
		if ( ! Overrides::enabled( $definition ) ) {
			/* translators: %s: automation label. */
			return new WP_Error( 'automation_disabled', sprintf( __( 'Automation %s is disabled on this site; enable it first.', 'pegwork' ), $definition['label'] ), array( 'status' => 409 ) );
		}

		$type = $definition['trigger']['content_type'] ?? null;
		$data = null;
		if ( null === $type ) {
			if ( null !== $record && $record > 0 ) {
				/* translators: %s: automation key. */
				return new WP_Error( 'pegwork_no_record', sprintf( __( 'Automation %s has no content type, so it runs without a record.', 'pegwork' ), $key ), array( 'status' => 400 ) );
			}
		} else {
			if ( null === $record || $record <= 0 ) {
				/* translators: %s: automation key. */
				return new WP_Error( 'pegwork_no_record', sprintf( __( 'Automation %s runs on one record; name it.', 'pegwork' ), $key ), array( 'status' => 400 ) );
			}
			$data = Records::get( $record );
			if ( null === $data || 'trash' === $data['status'] || 'auto-draft' === $data['status'] ) {
				/* translators: %d: record ID. */
				return new WP_Error( 'pegwork_record_not_found', sprintf( __( 'No record with ID %d.', 'pegwork' ), $record ), array( 'status' => 404 ) );
			}
			if ( $type !== $data['type'] ) {
				/* translators: 1: record ID, 2: its content type, 3: automation key, 4: the automation's content type. */
				return new WP_Error( 'pegwork_record_type', sprintf( __( 'Record %1$d is a %2$s; automation %3$s runs on %4$s.', 'pegwork' ), $record, $data['type'], $key, $type ), array( 'status' => 400 ) );
			}
		}

		return array(
			'definition' => $definition,
			'record'     => $data,
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
			$clock         = Engine::instance()->clock(); // A frozen clock (--now) returns the same time on every read.
			$out['fired']  = $this->inline->drain( $clock->now()->getTimestamp(), 10000, fn() => $clock->now()->getTimestamp() );
			$out['handed'] = $this->inline->flush();
			Engine::use( $this->previous->clock(), $this->previous->scheduler() );
			$this->inline = null;
		}
		return $out;
	}

	/**
	 * Whether starting an automation can move records to the trash: one of its steps (in branches, loop
	 * bodies and links too) is a trash_record, or one of the automations it starts (start_automation,
	 * followed transitively) has one. A static closure over the definitions; cycles and unknown keys stop
	 * the walk. Editors need `delete_post` on the record to start such an automation (StartRoute::can_run()).
	 *
	 * @param string                                   $key         Automation key.
	 * @param array<string, array<string, mixed>>|null $automations Canonical automations by key (default: the registry's).
	 */
	public static function trashes( string $key, ?array $automations = null ): bool {
		$automations = $automations ?? pegwork_registry()->automations();
		$seen        = array();
		$queue       = array( $key );
		while ( array() !== $queue ) {
			$current = (string) array_shift( $queue );
			if ( isset( $seen[ $current ] ) || ! isset( $automations[ $current ] ) ) {
				continue;
			}
			$seen[ $current ] = true;
			$definition       = (array) $automations[ $current ];
			$lists            = array( (array) ( $definition['steps'] ?? array() ) );
			foreach ( (array) ( $definition['links'] ?? array() ) as $link ) {
				$lists[] = (array) ( ( (array) $link )['steps'] ?? array() );
			}
			while ( array() !== $lists ) {
				foreach ( (array) array_shift( $lists ) as $step ) {
					$step = (array) $step;
					$type = (string) ( $step['type'] ?? '' );
					if ( 'trash_record' === $type ) {
						return true;
					}
					if ( 'start_automation' === $type && is_string( $step['automation'] ?? null ) ) {
						$queue[] = $step['automation'];
					}
					foreach ( (array) ( $step['branches'] ?? array() ) as $branch ) {
						$lists[] = (array) ( ( (array) $branch )['steps'] ?? array() );
					}
					$lists[] = (array) ( $step['otherwise'] ?? array() );
					$lists[] = (array) ( $step['steps'] ?? array() );
				}
			}
		}
		return false;
	}
}
