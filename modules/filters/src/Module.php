<?php
/**
 * Filters module entry point.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkFilters;

use Pegwork\Api\Module as ModuleContract;

/**
 * Registers the URL constraint hook, the directory pattern addition and the pegwork/filters block.
 */
final class Module implements ModuleContract {

	/**
	 * The manifest slug.
	 */
	public function id(): string {
		return 'filters';
	}

	/**
	 * Filters need the listings module.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array( 'listings' );
	}

	/**
	 * Starts the module (on pegwork_loaded, init priority 5). Safe to call more than once.
	 */
	public function boot(): void {
		Hooks::register();
		FiltersBlock::register();
	}
}
