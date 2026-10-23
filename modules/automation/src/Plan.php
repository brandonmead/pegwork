<?php
/**
 * Step tree of one run.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

/**
 * The positions of a step list and its decisions' branches, built once per wake-up from
 * Executor::step_list(). Step ids are unique across the tree (the registry checks it), so a run's
 * `current_step` alone fixes where it is: next() is the following sibling, or, after the last step of a
 * branch, next() of the enclosing decision, or null at the end of the tree. A flat Phase 4 list is a tree of
 * depth 0 only.
 *
 * Loop bodies are walked too: the last step of a body has next() = the loop's id, so the cursor returns to the
 * loop after each item, and the loop decides whether to start the body again (body_first()) or continue
 * after itself (next() of the loop). Loops do not nest (registry rule), so loop_of() names at most one loop.
 *
 * Shared by the executor, dry runs, the run detail and `wp pegwork automation log --run`.
 */
final class Plan {

	/**
	 * Steps by id, in document order: `step`, `depth` (0 for the top list), `next` (id or null), `loop` (the
	 * enclosing loop id or null), and for a step inside a branch `decision`, `branch` (index, -1 for
	 * otherwise) and `first` (whether it starts the branch).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $nodes = array();

	/**
	 * First step id of each loop body (null for an empty body), by loop id.
	 *
	 * @var array<string, string|null>
	 */
	private array $bodies = array();

	/**
	 * First step id of each branch of each decision (null for an empty branch), by decision id then branch
	 * index (-1 for otherwise; absent when the decision has no otherwise).
	 *
	 * @var array<string, array<int, string|null>>
	 */
	private array $branches = array();

	/**
	 * Branch labels by decision id then branch index (-1 is "otherwise").
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $labels = array();

	/**
	 * First step id of the tree, null for an empty list.
	 *
	 * @var string|null
	 */
	private ?string $first = null;

	/**
	 * Builds the plan of a step list.
	 *
	 * @param array<int, array<string, mixed>> $steps Canonical steps.
	 */
	public function __construct( array $steps ) {
		$this->first = $this->walk( array_values( $steps ), 0, null, null, null, null );
	}

	/**
	 * Walks one list; returns its first step id.
	 *
	 * @param array<int, array<string, mixed>> $steps    Steps of the list.
	 * @param int                              $depth    Depth of the list.
	 * @param string|null                      $after    Id that follows the list (next of the enclosing decision,
	 *                                                   or the loop id after a loop body).
	 * @param string|null                      $decision Enclosing decision id.
	 * @param int|null                         $branch   Branch index in the enclosing decision (-1 for otherwise).
	 * @param string|null                      $loop     Enclosing loop id.
	 */
	private function walk( array $steps, int $depth, ?string $after, ?string $decision, ?int $branch, ?string $loop ): ?string {
		// Ids are unique in a valid definition; should one repeat, its first position wins.
		$kept = array();
		foreach ( $steps as $step ) {
			if ( is_array( $step ) && isset( $step['id'] ) && ! isset( $this->nodes[ (string) $step['id'] ] ) && ! isset( $kept[ (string) $step['id'] ] ) ) {
				$kept[ (string) $step['id'] ] = $step;
			}
		}
		$steps = array_values( $kept );
		$count = count( $steps );

		foreach ( $steps as $index => $step ) {
			$id   = (string) $step['id'];
			$next = $index + 1 < $count ? (string) $steps[ $index + 1 ]['id'] : $after;

			$this->nodes[ $id ] = array(
				'step'     => $step,
				'depth'    => $depth,
				'next'     => $next,
				'decision' => $decision,
				'branch'   => $branch,
				'first'    => null !== $decision && 0 === $index,
				'loop'     => $loop,
			);

			if ( 'decision' === ( $step['type'] ?? null ) ) {
				$this->branches[ $id ] = array();
				$this->labels[ $id ]   = array();
				foreach ( array_values( (array) ( $step['branches'] ?? array() ) ) as $branch_index => $item ) {
					$this->labels[ $id ][ $branch_index ]   = (string) ( $item['label'] ?? '' );
					$this->branches[ $id ][ $branch_index ] = $this->walk( (array) ( $item['steps'] ?? array() ), $depth + 1, $next, $id, $branch_index, $loop );
				}
				if ( isset( $step['otherwise'] ) ) {
					$this->labels[ $id ][-1]   = 'otherwise';
					$this->branches[ $id ][-1] = $this->walk( (array) $step['otherwise'], $depth + 1, $next, $id, -1, $loop );
				}
			}
			if ( 'loop' === ( $step['type'] ?? null ) ) {
				// The body ends back at the loop, which decides between the next item and the step after it.
				$this->bodies[ $id ] = $this->walk( (array) ( $step['steps'] ?? array() ), $depth + 1, $id, null, null, $id );
			}
		}

		return 0 === $count ? null : (string) $steps[0]['id'];
	}

	/**
	 * First step id, null for an empty list.
	 */
	public function first(): ?string {
		return $this->first;
	}

	/**
	 * Whether the tree holds a step id.
	 *
	 * @param string $id Step id.
	 */
	public function has( string $id ): bool {
		return isset( $this->nodes[ $id ] );
	}

	/**
	 * Step definition of an id.
	 *
	 * @param string $id Step id.
	 * @return array<string, mixed>|null
	 */
	public function step( string $id ): ?array {
		return $this->nodes[ $id ]['step'] ?? null;
	}

	/**
	 * Depth of a step: 0 for the top list, 1 inside a top-level decision's branch, and so on; null when unknown.
	 *
	 * @param string $id Step id.
	 */
	public function depth( string $id ): ?int {
		return $this->nodes[ $id ]['depth'] ?? null;
	}

	/**
	 * The step after an id when it does not branch: the next sibling, else next() of the enclosing decision,
	 * else null (end of the tree, or an unknown id).
	 *
	 * @param string $id Step id.
	 */
	public function next( string $id ): ?string {
		return $this->nodes[ $id ]['next'] ?? null;
	}

	/**
	 * The step after a decision took a branch: that branch's first step, or next() of the decision when the
	 * branch is empty, the index is not a branch, or nothing matched (null index).
	 *
	 * @param string   $decision Decision id.
	 * @param int|null $index    Branch index, -1 for otherwise, null for no match.
	 */
	public function after_branch( string $decision, ?int $index ): ?string {
		$first = null === $index ? null : ( $this->branches[ $decision ][ $index ] ?? null );
		return $first ?? $this->next( $decision );
	}

	/**
	 * First step id of a branch, null for an empty or unknown branch.
	 *
	 * @param string $decision Decision id.
	 * @param int    $index    Branch index, -1 for otherwise.
	 */
	public function branch_first( string $decision, int $index ): ?string {
		return $this->branches[ $decision ][ $index ] ?? null;
	}

	/**
	 * Where a step sits when it is inside a branch: the enclosing decision id, the branch index (-1 for
	 * otherwise), its label ("otherwise" for otherwise) and whether the step starts the branch. Null for a
	 * top-level or unknown step.
	 *
	 * @param string $id Step id.
	 * @return array{decision: string, branch: int, label: string, first: bool}|null
	 */
	public function location( string $id ): ?array {
		$node = $this->nodes[ $id ] ?? null;
		if ( null === $node || null === $node['decision'] ) {
			return null;
		}
		return array(
			'decision' => (string) $node['decision'],
			'branch'   => (int) $node['branch'],
			'label'    => $this->labels[ $node['decision'] ][ $node['branch'] ] ?? '',
			'first'    => (bool) $node['first'],
		);
	}

	/**
	 * First step id of a loop's body, null for an empty body or an id that is not a loop.
	 *
	 * @param string $loop Loop id.
	 */
	public function body_first( string $loop ): ?string {
		return $this->bodies[ $loop ] ?? null;
	}

	/**
	 * The loop whose body holds a step (at any depth inside it), or null for a step outside every loop, a
	 * loop itself, or an unknown id.
	 *
	 * @param string $id Step id.
	 */
	public function loop_of( string $id ): ?string {
		return $this->nodes[ $id ]['loop'] ?? null;
	}

	/**
	 * Whether an id is a loop step.
	 *
	 * @param string $id Step id.
	 */
	public function is_loop( string $id ): bool {
		return array_key_exists( $id, $this->bodies );
	}

	/**
	 * The step id whose `records` a loop iterates (`over` is `steps.<id>.records`), or null.
	 *
	 * @param string $loop Loop id.
	 */
	public function over( string $loop ): ?string {
		$over  = (string) ( $this->step( $loop )['over'] ?? '' );
		$parts = explode( '.', $over );
		return 3 === count( $parts ) && 'steps' === $parts[0] && 'records' === $parts[2] ? $parts[1] : null;
	}

	/**
	 * Step ids in document order.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_map( 'strval', array_keys( $this->nodes ) );
	}

	/**
	 * The plan of the steps a run walks under a definition (the current one, for the run detail and the CLI
	 * log), or null when the automation or the run's link is no longer defined.
	 *
	 * @param array<string, mixed>      $run        Run row.
	 * @param array<string, mixed>|null $definition Canonical automation, null when it was removed.
	 */
	public static function for_run( array $run, ?array $definition ): ?self {
		$steps = null === $definition ? null : Executor::step_list( $definition, $run );
		return null === $steps ? null : new self( $steps );
	}

	/**
	 * Every step type used in a step list, branches included.
	 *
	 * @param array<int, array<string, mixed>> $steps Canonical steps.
	 * @return string[]
	 */
	public static function types( array $steps ): array {
		$plan = new self( $steps );
		return array_values( array_unique( array_map( fn( string $id ) => (string) ( $plan->step( $id )['type'] ?? '' ), $plan->ids() ) ) );
	}
}
