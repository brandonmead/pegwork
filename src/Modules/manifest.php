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
	'listings' => array(
		'label'       => 'Listings',
		'description' => 'Field output on core blocks (the pegwork/field binding source) and the Show if block.',
		'requires'    => array(),
		'path'        => PEGWORK_DIR . 'modules/listings',
	),
	'filters'  => array(
		'label'       => 'Filters',
		'description' => 'Search and filters for listings (skeleton; built in later Phase 2 PRs).',
		'requires'    => array( 'listings' ),
		'path'        => PEGWORK_DIR . 'modules/filters',
	),
);
