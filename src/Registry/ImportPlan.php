<?php
/**
 * Outcome of planning or running a schema import.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

/**
 * Validation errors, or the changes an import makes to the `pegwork_schema` option and the seed terms it creates.
 */
final class ImportPlan {

	/**
	 * Creates the plan.
	 *
	 * @param ValidationError[]                 $errors   Errors that stop the import; nothing is written when any exist.
	 * @param array<int, array<string, string>> $changes  Option changes: each has action (`add`, `update`, `remove`), kind and key.
	 *                                                    Kind `manifest` is the stored package manifest.
	 * @param array<string, mixed>              $option   New value of the `pegwork_schema` option.
	 * @param array<int, array<string, string>> $terms    Seed terms to create (dry run) or created: each has taxonomy and slug.
	 * @param array<int, array<string, string>> $shadowed DB entries a file package shadows after the import: each has kind, key and by.
	 */
	public function __construct(
		public readonly array $errors = array(),
		public readonly array $changes = array(),
		public readonly array $option = array(),
		public readonly array $terms = array(),
		public readonly array $shadowed = array()
	) {
	}

	/**
	 * Whether the import package passed validation.
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Total changes: option changes plus seed terms. Zero means the import is already in place.
	 */
	public function count(): int {
		return count( $this->changes ) + count( $this->terms );
	}

	/**
	 * Number of option changes with a given action.
	 *
	 * @param string $action `add`, `update` or `remove`.
	 */
	public function count_action( string $action ): int {
		return count( array_filter( $this->changes, fn( array $change ) => $action === $change['action'] ) );
	}

	/**
	 * Copy of this plan with the seed terms and shadowed entries filled in.
	 *
	 * @param array<int, array<string, string>> $terms    Seed terms.
	 * @param array<int, array<string, string>> $shadowed Shadowed DB entries.
	 */
	public function with( array $terms, array $shadowed ): self {
		return new self( $this->errors, $this->changes, $this->option, $terms, $shadowed );
	}
}
