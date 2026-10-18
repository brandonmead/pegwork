<?php
/**
 * Filters module (skeleton): the pegwork/filters block arrives in later Phase 2 PRs.
 *
 * Talks to listings only through hooks, never its classes.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return new class() implements Pegwork\Api\Module {

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
	 * Nothing to start yet.
	 */
	public function boot(): void {
	}
};
