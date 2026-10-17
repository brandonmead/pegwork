<?php
/**
 * Test fixture module: tripwire.
 *
 * @package Pegwork
 */

$GLOBALS['pegwork_tripwire_included'] = true;

return new class() implements \Pegwork\Api\Module {
	public function id(): string {
		return 'tripwire';
	}

	public function requires(): array {
		return array();
	}

	public function boot(): void {
		do_action( 'pegwork_fixture_booted', 'tripwire' );
	}
};
