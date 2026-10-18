<?php
/**
 * Test fixture module: listens on pegwork_event_record_updated through the public API only.
 *
 * When a person's save (depth 0) changes a Project's website, it stamps last_verified through
 * Pegwork\Api\Records, the way an automation would.
 *
 * @package Pegwork
 */


return new class() implements \Pegwork\Api\Module {
	public function id(): string {
		return 'record-listener';
	}

	public function requires(): array {
		return array();
	}

	public function boot(): void {
		add_action(
			'pegwork_event_record_updated',
			static function ( array $payload ) {
				if ( 0 !== $payload['origin']['depth'] || ! in_array( 'website', $payload['changed'], true ) ) {
					return;
				}
				$result = \Pegwork\Api\Records::update(
					$payload['id'],
					array( 'last_verified' => '2026-10-18' ),
					array( 'automation' => 'fixture_stamp', 'run_id' => 7 )
				);
				do_action( 'pegwork_fixture_listener_result', $result );
			}
		);
		do_action( 'pegwork_fixture_booted', 'record-listener' );
	}
};
