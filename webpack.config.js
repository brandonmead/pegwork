/*
 * Webpack config: the `@wordpress/scripts` defaults plus the filters view script module.
 *
 * `wp-scripts build --experimental-modules` (verified in `@wordpress/scripts` 36.0.0) exports a script
 * config and a module config. Entries named on the command line (WP_ENTRY) go to the script config only,
 * and the module config finds entries only in block.json files under `src/`, so the module entry is set
 * here. The module build marks `@wordpress/interactivity` (static) and `@wordpress/interactivity-router`
 * (dynamic) as script module dependencies in `filters-view.asset.php`.
 */
const defaults = require( '@wordpress/scripts/config/webpack.config' );

const [ scripts, modules ] = Array.isArray( defaults )
	? defaults
	: [ defaults, null ];

module.exports = modules
	? [
			scripts,
			{
				...modules,
				entry: {
					'filters-view': './assets/src/filters-view/index.js',
				},
			},
		]
	: scripts;
