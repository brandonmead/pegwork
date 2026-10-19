/**
 * Pegwork > Automations, all by keyboard: the list, Disable and Enable, Run now (a run that fails),
 * the run log, a run's step attempts, and Retry (the run completes). Axe at 1280 and 782 px, and no
 * horizontal page scroll at 782 px.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (automation package, mail switch, helper routes).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const LABEL = 'E2E daily digest';

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
 * Focuses a control and activates it with Enter, waiting for the navigation it causes.
 *
 * @param {import('@playwright/test').Page}    page    Page.
 * @param {import('@playwright/test').Locator} control Button or link.
 * @param {RegExp}                             url     URL after the action.
 */
async function press( page, control, url ) {
	await control.focus();
	await expect( control ).toBeFocused();
	await Promise.all( [
		page.waitForURL( url ),
		page.keyboard.press( 'Enter' ),
	] );
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
 * The run row of the digest in the log, waiting for the background wake-up to finish it.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {string}                          status Expected status label.
 */
async function waitForStatus( page, status ) {
	await expect( async () => {
		await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );
		await page.reload();
		await expect(
			page.locator( '.pegwork-run .pegwork-status' ).first()
		).toHaveText( status, { timeout: 1000 } );
	} ).toPass( { timeout: 20_000 } );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/reset' );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'list, toggle, Run now, run detail and Retry by keyboard; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );

	// The list.
	await page.goto( LIST );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Automations' } )
	).toBeVisible();
	const row = page.getByRole( 'row', { name: new RegExp( LABEL ) } );
	await expect( row ).toContainText( 'e2e_digest' );
	await expect( row ).toContainText( 'Every day at 03:00' );
	await expect( row ).toContainText( 'File package e2e_automation' );
	await expect( row ).toContainText( 'JSON default on, no site override' );
	await expect( row ).toContainText( 'Never run' );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-list.png',
		fullPage: true,
	} );

	// Disable, then Enable, by keyboard; Run now is blocked with a reason while disabled.
	await press(
		page,
		page.getByRole( 'button', { name: `Disable ${ LABEL }` } ),
		/page=pegwork-automations$/
	);
	await expect( page.locator( '.notice-success' ) ).toContainText(
		`Automation ${ LABEL } disabled for this site.`
	);
	await expect( row ).toContainText( 'JSON default on, site override off' );
	const blocked = page.getByRole( 'button', { name: `Run now ${ LABEL }` } );
	await expect( blocked ).toHaveAttribute( 'aria-disabled', 'true' );
	await expect( blocked ).toHaveAccessibleDescription(
		'Enable it to run it now.'
	);
	await press(
		page,
		page.getByRole( 'button', { name: `Enable ${ LABEL }` } ),
		/page=pegwork-automations$/
	);
	await expect( row ).toContainText( 'JSON default on, site override on' );

	// Run now while mail fails: the run is logged and fails.
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/mail-fail', {
		fail: true,
	} );
	await press(
		page,
		page.getByRole( 'button', { name: `Run now ${ LABEL }` } ),
		/view=log&automation=e2e_digest/
	);
	await expect( page.locator( '.notice-success' ) ).toContainText(
		`${ LABEL }: matched 1, created 1 run.`
	);
	const runLink = page.getByRole( 'link', { name: /^Run \d+$/ } ).first();
	await expect( runLink ).toBeVisible();
	await checkLayout( page );

	// Open the run by keyboard.
	await press( page, runLink, /view=run&run=\d+/ );
	await waitForStatus( page, 'Failed' );
	await expect( page.getByRole( 'row', { name: /^Error/ } ) ).toContainText(
		'mail_failed'
	);
	const attempts = page.getByRole( 'table', {
		name: 'Step attempts, oldest first',
	} );
	await expect( attempts.getByRole( 'row' ) ).toHaveCount( 2 );
	await expect( attempts ).toContainText( 'send_email' );
	await expect( attempts ).toContainText( 'e***@example.org' );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-failed.png',
		fullPage: true,
	} );

	// Retry by keyboard once mail works: the run completes.
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/mail-fail', {
		fail: false,
	} );
	await press(
		page,
		page.getByRole( 'button', { name: 'Retry' } ),
		/view=run&run=\d+/
	);
	await expect( page.locator( '.notice-success' ) ).toContainText(
		'queued again from the step that failed'
	);
	await waitForStatus( page, 'Completed' );
	await expect( page.getByRole( 'button', { name: 'Retry' } ) ).toHaveCount(
		0
	);
	await expect( attempts.getByRole( 'row' ) ).toHaveCount( 3 );

	// The log, filtered by status by keyboard.
	await page.goto( `${ LIST }&view=log` );
	await page
		.getByRole( 'combobox', { name: 'Status' } )
		.selectOption( 'completed' );
	await press(
		page,
		page.getByRole( 'button', { name: 'Filter' } ),
		/status=completed/
	);
	await expect(
		page.getByRole( 'table', { name: 'Runs, newest first' } )
	).toContainText( 'e2e_digest' );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-log.png',
		fullPage: true,
	} );

	// 782 px: every view.
	await page.setViewportSize( { width: 782, height: 1000 } );
	await page.goto( LIST );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-list-782.png',
		fullPage: true,
	} );
	await page.goto( `${ LIST }&view=log` );
	await checkLayout( page );
	await page
		.getByRole( 'link', { name: /^Run \d+$/ } )
		.first()
		.click();
	await expect( page ).toHaveURL( /view=run/ );
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-782.png',
		fullPage: true,
	} );
} );

test( 'editors are refused the screen', async ( { browser } ) => {
	// Create an editor through REST, log in as them, and expect a 403 page.
	const password = `E2e-${ Date.now() }-pw!`;
	const user = await adminCall( 'POST', '/wp/v2/users', {
		username: `e2e_editor_${ Date.now() }`,
		email: `e2e-editor-${ Date.now() }@example.org`,
		password,
		roles: [ 'editor' ],
	} );
	const context = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	try {
		const page = await context.newPage();
		await page.goto( `${ baseURL }/wp-login.php` );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( user.username );
		await page.getByLabel( 'Password', { exact: true } ).fill( password );
		await Promise.all( [
			page.waitForURL( /wp-admin/ ),
			page.getByRole( 'button', { name: 'Log In' } ).click(),
		] );
		const res = await page.goto( `${ baseURL }${ LIST }` );
		expect( res.status() ).toBe( 403 );
	} finally {
		await context.close();
		await adminCall( 'DELETE', `/wp/v2/users/${ user.id }`, {
			force: true,
			reassign: 1,
		} );
	}
} );
