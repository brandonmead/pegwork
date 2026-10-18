const { defineConfig, devices } = require( '@playwright/test' );
const { baseURL, authFile } = require( './tests/e2e/utils/env' );

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	// Not *.spec.js: the Vitest unit run would pick those up.
	testMatch: '**/*.e2e.js',
	outputDir: 'test-results',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI
		? [
				[ 'list' ],
				[
					'html',
					{ open: 'never', outputFolder: 'playwright-report' },
				],
			]
		: [ [ 'list' ] ],
	use: {
		baseURL,
		storageState: authFile,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
