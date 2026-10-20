<?php
/**
 * Static module manifest.
 *
 * Returns `slug => array( 'label' => string, 'description' => string, 'requires' => string[],
 * 'path' => string )`, where `path` is the absolute directory holding the module's `module.php`
 * (for example `PEGWORK_DIR . 'modules/listings'`). Filter `pegwork_module_manifest` to add or
 * change entries.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

// Plain strings: the manifest is read on plugins_loaded, before translations may load.
return array(
	'listings'   => array(
		'label'       => 'Listings',
		'description' => 'Field output on core blocks (the pegwork/field binding source), the Show if block, and directories (Pegwork Query Loops and patterns).',
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/listings',
	),
	'filters'    => array(
		'label'       => 'Filters',
		'description' => 'Search and filters for directories: the Filters block, URL-only state, live results without a page load.',
		'requires'    => array( 'listings' ),
		'path'        => PEGWORK_DIR . 'modules/filters',
	),
	'automation' => array(
		'label'       => 'Automation',
		'description' => 'Runs registry automations in the background (WP-Cron), with a run log and wp pegwork automation.',
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/automation',
	),
	'forms'      => array(
		'label'       => 'Forms',
		'description' => 'Registry forms on pages (the pegwork/form block): server-rendered, validated, spam-checked, stored as entries, with email notifications.',
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/forms',
	),
);
