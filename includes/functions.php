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

/**
 * Validates a record against its content type, as publishing would.
 *
 * @param string               $type   Content type key.
 * @param array<string, mixed> $values Field values by field key (title and content included); term ID lists by taxonomy key.
 * @return true|WP_Error WP_Error (status 400) with per-field messages in its data `fields`.
 */
function pegwork_validate_record( string $type, array $values ): bool|WP_Error {
	return Pegwork\Content\RecordValidator::validate( pegwork_registry(), $type, $values );
}
