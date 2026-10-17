<?php
/**
 * Public module contract.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

/**
 * An optional Pegwork module. Part of the semver-stable public API.
 *
 * A module's `module.php` returns an object implementing this interface.
 */
interface Module {

	/**
	 * The module slug, matching its manifest key.
	 */
	public function id(): string;

	/**
	 * Slugs of other modules that must be enabled and loaded before this one.
	 *
	 * @return string[]
	 */
	public function requires(): array;

	/**
	 * Starts the module. Called once per request on pegwork_loaded (the registry is available),
	 * only when the module is enabled and its requirements are met.
	 */
	public function boot(): void;
}
