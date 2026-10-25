<?php
/**
 * Scheduler wrapper for tests that end a request between a run's insert and its wake-up.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use PegworkAutomation\Engine;
use PegworkAutomation\Scheduler\Scheduler;
use RuntimeException;

/**
 * Fails the n-th run wake-up of one automation, after its run row was written, as a request that dies
 * between the insert and the wake-up would; everything else goes to the fake scheduler.
 */
final class FailingScheduler implements Scheduler {

	/**
	 * The fake scheduler.
	 *
	 * @var FakeScheduler
	 */
	private FakeScheduler $inner;

	/**
	 * Automation whose run wake-ups are counted.
	 *
	 * @var string
	 */
	public string $automation = '';

	/**
	 * Wake-ups of it to schedule before the failing one (0 fails the next one; -1 fails none).
	 *
	 * @var int
	 */
	public int $fail_after = -1;

	/**
	 * Wraps the fake scheduler.
	 *
	 * @param FakeScheduler $inner Fake scheduler.
	 */
	public function __construct( FakeScheduler $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Schedules, or throws once for the chosen wake-up.
	 *
	 * @param string $hook      Action name.
	 * @param array  $args      Arguments.
	 * @param int    $timestamp Unix time.
	 * @throws RuntimeException For the chosen wake-up.
	 */
	public function schedule( string $hook, array $args, int $timestamp ): bool {
		if ( Engine::RUN_HOOK === $hook && $this->fail_after >= 0 && ( Engine::instance()->runs()->get( (int) $args[0] )['automation'] ?? '' ) === $this->automation ) {
			if ( 0 === $this->fail_after-- ) {
				throw new RuntimeException( 'The request ended.' );
			}
		}
		return $this->inner->schedule( $hook, $args, $timestamp );
	}

	/**
	 * Cancels a pending firing.
	 *
	 * @param string $hook Action name.
	 * @param array  $args Arguments.
	 */
	public function unschedule( string $hook, array $args ): void {
		$this->inner->unschedule( $hook, $args );
	}

	/**
	 * Time of a pending firing.
	 *
	 * @param string $hook Action name.
	 * @param array  $args Arguments.
	 */
	public function next( string $hook, array $args ): ?int {
		return $this->inner->next( $hook, $args );
	}
}
