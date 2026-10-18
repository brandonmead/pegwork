<?php
/**
 * Plugin Name: Pegwork E2E sifg package
 * Description: Registers the examples/sifg package as a file package for browser tests. Not shipped.
 *
 * @package Pegwork
 */

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'examples/sifg' );
		}
	}
);
