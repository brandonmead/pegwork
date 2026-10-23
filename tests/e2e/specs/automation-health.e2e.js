/**
 * Scheduling health: Tools > Site Health lists the Pegwork test (passed, then critical with an overdue
 * run), and the Automations screen shows one notice while it is unhealthy. Axe on Site Health at 1280
 * and 782 px.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (module toggle, overdue run route).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const HEALTH = '/wp-admin/site-health.php';
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const SCREENSHOTS = 'docs/screenshots';

let admin;

async function adminCall( method, route, data ) {
	const res = await admin.fetch( restUrl( route ), {
		method,
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		data,
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`${ method } ${ route } failed: ${ res.status() } ${ await res.text() }`
		);
	}
	return res.json();
}

/**
 * The Pegwork test's accordion button on the Site Health Status tab, once the direct tests have rendered.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
function pegworkTest( page ) {
	return page.locator(
		'button[aria-controls="health-check-accordion-block-pegwork_automation_scheduling"]'
	);
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/overdue', {
		on: false,
	} );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/overdue', {
		on: false,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'Site Health shows the Pegwork test passing, and no notice on Pegwork screens', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( HEALTH );
	const button = pegworkTest( page );
	await expect( button ).toHaveCount( 1 );
	await expect( button ).toContainText(
		'Pegwork automations are running on time'
	);
	await expect( button ).toContainText( 'Pegwork' );
	await expect(
		page.locator( '#health-check-issues-good' ).locator( button )
	).toHaveCount( 1 );

	await page.goto( LIST );
	await expect( page.locator( '.pegwork-health-notice' ) ).toHaveCount( 0 );
} );

test( 'an overdue run makes it critical, with one notice on the Automations screen; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/overdue', {
		on: true,
	} );

	for ( const width of [ 1280, 782 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await page.goto( HEALTH );
		const button = pegworkTest( page );
		await expect( button ).toContainText(
			'Pegwork automations are not running on time'
		);
		await expect(
			page.locator( '#health-check-issues-critical' ).locator( button )
		).toHaveCount( 1 );

		// Open it by keyboard and read the explanation and the action.
		await button.focus();
		await page.keyboard.press( 'Enter' );
		await expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
		const panel = page.locator(
			'#health-check-accordion-block-pegwork_automation_scheduling'
		);
		await expect( panel ).toContainText(
			'1 automation run is overdue; the oldest was due 1 hour ago.'
		);
		await expect( panel ).toContainText( 'wp cron event run --due-now' );
		await expect(
			panel.getByRole( 'link', { name: 'View the automation run log' } )
		).toHaveAttribute( 'href', /page=pegwork-automations&view=log/ );

		await expectNoSeriousA11yViolations( page, AXE );
		if ( 1280 === width ) {
			await panel.scrollIntoViewIfNeeded();
			await page.screenshot( {
				path: `${ SCREENSHOTS }/automation-site-health.png`,
			} );
		}
	}

	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	const notice = page.locator( '.pegwork-health-notice' );
	await expect( notice ).toHaveCount( 1 );
	await expect( notice ).toContainText(
		'Pegwork automations are not running on time.'
	);
	await expect( notice ).toContainText( '1 automation run is overdue' );
	await expect(
		notice.getByRole( 'link', { name: 'Site Health' } )
	).toHaveAttribute( 'href', /site-health\.php$/ );
	await expectNoSeriousA11yViolations( page, AXE );
	await page.screenshot( {
		path: `${ SCREENSHOTS }/automation-health-notice.png`,
		clip: { x: 0, y: 0, width: 1280, height: 420 },
	} );

	// Not on other admin screens.
	await page.goto( '/wp-admin/index.php' );
	await expect( page.locator( '.pegwork-health-notice' ) ).toHaveCount( 0 );
} );
