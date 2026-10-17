<?php
/**
 * Test fixture module: needs-sample.
 *
 * @package Pegwork
 */

$GLOBALS['pegwork_needs_sample_included'] = true;

return new class() implements \Pegwork\Api\Module {
	public function id(): string {
		return 'needs-sample';
	}

	public function requires(): array {
		return array( 'sample' );
	}

	public function boot(): void {
		do_action( 'pegwork_fixture_booted', 'needs-sample' );
	}
};
