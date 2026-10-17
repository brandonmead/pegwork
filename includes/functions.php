<?php
/**
 * Public global functions.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The effective schema registry, read-only. Built on init at priority 5; empty before that.
 */
function pegwork_registry(): Pegwork\Api\Registry {
	return Pegwork\Plugin::registry();
}
