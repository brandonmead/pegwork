<?php
/**
 * Outcome of loading the registry.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use Pegwork\Api\Registry;

/**
 * The effective registry plus what the loader reported while building it.
 */
final class Result {

	/**
	 * Creates the result.
	 *
	 * @param Registry                             $registry Effective registry.
	 * @param array<string, array<string, string>> $sources  Source of each object: [kind][key] => package id or Loader::DB_SOURCE.
	 * @param ValidationError[]                    $errors   Errors from rejected packages and DB entries.
	 * @param array<int, array<string, string>>    $shadowed DB entries hidden by a file package: each has kind, key and by (the package id).
	 */
	public function __construct(
		public readonly Registry $registry,
		public readonly array $sources = array(),
		public readonly array $errors = array(),
		public readonly array $shadowed = array()
	) {
	}

	/**
	 * Whether loading found no errors.
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}
}
