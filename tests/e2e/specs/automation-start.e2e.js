/**
 * Pegwork > Automations run detail for start_automation (Automation v4 PR 5): Run now on e2e_start_parent
 * by keyboard, the `note` step row links the run it started, and that run's "Started by" row links back.
 * Keyboard only; axe at 1280 and 782 px on both runs, and no horizontal page scroll.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (module toggle, cron) and pegwork-e2e-start.php.
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };

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
 * Axe, plus no horizontal page scroll.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function checkLayout( page ) {
	await expectNoSeriousA11yViolations( page, AXE );
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - window.innerWidth
	);
	expect( overflow, 'horizontal page scroll' ).toBeLessThanOrEqual( 0 );
}

/**
 * Runs due wake-ups until the run shown is completed.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function waitCompleted( page ) {
	await expect( async () => {
		await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );
		await page.reload();
		await expect(
			page.locator( '.pegwork-run .pegwork-status' ).first()
		).toHaveText( 'Completed', { timeout: 1000 } );
	} ).toPass( { timeout: 20_000 } );
}

/**
 * Focuses a control and presses Enter, waiting for the navigation.
 *
 * @param {import('@playwright/test').Page}    page    Page.
 * @param {import('@playwright/test').Locator} locator Control.
 * @param {RegExp}                             url     URL after the navigation.
 */
async function press( page, locator, url ) {
	await locator.focus();
	await Promise.all( [
		page.waitForURL( url ),
		page.keyboard.press( 'Enter' ),
	] );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/start/reset' );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/start/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'parent and child runs link each other; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press(
		page,
		page.getByRole( 'button', { name: 'Run now E2E start the note' } ),
		/view=run&run=\d+/
	);
	const parent = Number( new URL( page.url() ).searchParams.get( 'run' ) );
	await waitCompleted( page );

	const attempts = page.getByRole( 'table', {
		name: 'Step attempts, oldest first',
	} );
	const note = attempts.getByRole( 'row', { name: /^note/ } );
	await expect( note ).toContainText( 'start_automation' );
	await expect( note ).toContainText( 'automation e2e_start_note' );
	await expect( note ).toContainText( 'started yes' );
	await expect(
		attempts.getByRole( 'row', { name: /^done/ } )
	).toContainText( 'Note started: 1' );
	const childLink = note.getByRole( 'link', { name: /^Run \d+$/ } );
	await expect( childLink ).toHaveCount( 1 );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-start-parent.png',
		fullPage: true,
	} );

	// Follow the link to the child by keyboard.
	const child = Number(
		( await childLink.textContent() ).replace( 'Run ', '' )
	);
	expect( child ).toBeGreaterThan( parent );
	await press( page, childLink, new RegExp( `view=run&run=${ child }$` ) );
	await waitCompleted( page );
	const startedBy = page.getByRole( 'row', { name: /^Started by/ } );
	await expect( startedBy.getByRole( 'cell' ) ).toHaveText(
		`Run ${ parent } of E2E start the note (step note)`
	);
	await expect(
		page.getByRole( 'row', { name: /^Loop depth/ } )
	).toContainText( '1' );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-started-by.png',
		fullPage: true,
	} );

	await page.setViewportSize( { width: 782, height: 1000 } );
	await page.reload();
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-started-by-782.png',
		fullPage: true,
	} );

	// And back to the parent by keyboard.
	await press(
		page,
		startedBy.getByRole( 'link', { name: `Run ${ parent }` } ),
		new RegExp( `view=run&run=${ parent }$` )
	);
	await checkLayout( page );
} );
