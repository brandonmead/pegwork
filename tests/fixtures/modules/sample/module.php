<?php
/**
 * Test fixture module: sample.
 *
 * @package Pegwork
 */


return new class() implements \Pegwork\Api\Module {
	public function id(): string {
		return 'sample';
	}

	public function requires(): array {
		return array();
	}

	public function boot(): void {
		do_action( 'pegwork_fixture_booted', 'sample' );
	}
};
