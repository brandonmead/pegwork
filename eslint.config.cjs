/**
 * ESLint config: the `@wordpress/scripts` default, plus the WordPress script
 * packages the editor panel imports. WordPress provides them at runtime (the
 * build externalizes them), so they are not installed as npm packages.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/components',
				'@wordpress/core-data',
				'@wordpress/data',
				'@wordpress/editor',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/plugins',
			],
		},
	},
];
