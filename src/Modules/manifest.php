<?php
/**
 * Static module manifest.
 *
 * Returns `slug => array( 'label' => string|callable, 'description' => string|callable,
 * 'requires' => string[], 'path' => string )`, where `path` is the absolute directory holding the
 * module's `module.php` (for example `PEGWORK_DIR . 'modules/listings'`). Filter
 * `pegwork_module_manifest` to add or change entries; it always receives strings.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Labels and descriptions are callables: the manifest is read on plugins_loaded, before translations
// load, so Loader::manifest() calls them only once init has fired (render time).
return array(
	'listings'   => array(
		'label'       => static fn(): string => __( 'Listings', 'pegwork' ),
		'description' => static fn(): string => __( 'Field output on core blocks (the pegwork/field binding source), the Show if block, and directories (Pegwork Query Loops and patterns).', 'pegwork' ),
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/listings',
	),
	'filters'    => array(
		'label'       => static fn(): string => __( 'Filters', 'pegwork' ),
		'description' => static fn(): string => __( 'Search and filters for directories: the Filters block, URL-only state, live results without a page load.', 'pegwork' ),
		'requires'    => array( 'listings' ),
		'path'        => PEGWORK_DIR . 'modules/filters',
	),
	'automation' => array(
		'label'       => static fn(): string => __( 'Automation', 'pegwork' ),
		'description' => static fn(): string => __( 'Runs registry automations in the background (WP-Cron), with a run log and wp pegwork automation.', 'pegwork' ),
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/automation',
	),
	'forms'      => array(
		'label'       => static fn(): string => __( 'Forms', 'pegwork' ),
		'description' => static fn(): string => __( 'Registry forms on pages (the pegwork/form block): server-rendered, validated, spam-checked, stored as entries, with email notifications.', 'pegwork' ),
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/forms',
	),
);
