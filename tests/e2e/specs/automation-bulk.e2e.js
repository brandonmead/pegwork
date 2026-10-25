/**
 * Bulk Run (Automation v4 PR 7), all by keyboard: on the BarnCRM Gifts list an editor and an administrator
 * select three gifts, choose "Run: Resend thank-you" in Bulk actions, Apply, land on the confirmation screen
 * (focus on its heading; two "Will run", one "Does not apply" because its amount is 0), Run, and get the
 * notice back on the list; the administrator follows "View runs", and a resubmit starts nothing new. Axe at
 * 1280 and 782 px on the list and the confirmation screen, and no horizontal page scroll. Screenshots in
 * docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-manual.php (package tests/e2e/packages/manual, runs, reset),
 * pegwork-e2e-barn.php (the gift type, sample gifts) and pegwork-e2e-automation.php (module switch).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const GIFTS = '/wp-admin/edit.php?post_type=barn_gift';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const ACTION = 'pegwork_automation_run_e2e_manual_thanks';

let admin;
let editor;
let gifts = [];
const password = `E2e-${ Date.now() }-pw!`;

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
 * Axe at both widths, plus no horizontal page scroll; ends at 1280 px.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function checkLayout( page ) {
	for ( const width of [ 1280, 782 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await expectNoSeriousA11yViolations( page, AXE );
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - window.innerWidth
		);
		expect(
			overflow,
			`horizontal scroll at ${ width }px`
		).toBeLessThanOrEqual( 0 );
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
}

/**
 * Focuses a control and presses a key on it.
 *
 * @param {import('@playwright/test').Locator} locator Control.
 * @param {string}                             key     Key.
 */
async function press( locator, key = 'Enter' ) {
	await locator.focus();
	await expect( locator ).toBeFocused();
	await locator.press( key );
}

/**
 * On the Gifts list: selects the three gifts by keyboard, chooses the bulk action and applies it; returns
 * once the confirmation screen shows, with focus on its heading.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string|null}                     shot Screenshot name for the confirmation screen, or null.
 */
async function bulkRun( page, shot ) {
	await page.goto( `${ baseURL }${ GIFTS }` );
	await checkLayout( page );
	for ( const gift of gifts ) {
		const box = page.locator( `#cb-select-${ gift.id }` );
		await press( box, 'Space' );
		await expect( box ).toBeChecked();
	}
	const bulk = page.locator( '#bulk-action-selector-top' );
	await expect( bulk.locator( `option[value="${ ACTION }"]` ) ).toHaveText(
		'Run: Resend thank-you'
	);
	await bulk.focus();
	await bulk.selectOption( ACTION );
	await Promise.all( [
		page.waitForURL( /page=pegwork-automation-bulk/ ),
		press( page.locator( '#doaction' ) ),
	] );

	const heading = page.getByRole( 'heading', {
		level: 1,
		name: 'Run "Resend thank-you" on 3 Gifts?',
	} );
	await expect( heading ).toBeFocused();
	const table = page.getByRole( 'table', { name: 'Selected Gifts' } );
	for ( const gift of gifts ) {
		await expect(
			table.getByRole( 'row', { name: new RegExp( gift.title ) } )
		).toContainText( gift.amount > 0 ? 'Will run' : 'Does not apply' );
	}
	await checkLayout( page );
	if ( shot ) {
		await page.screenshot( {
			path: path.join( SHOTS, `${ shot }.png` ),
			fullPage: true,
		} );
	}
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/manual/reset' );
	gifts = [];
	for ( const amount of [ 120, 80, 0 ] ) {
		const gift = await adminCall( 'POST', '/pegwork-e2e/v1/barn/gift', {
			amount,
		} );
		gifts.push( { ...gift, amount } );
	}
	editor = await adminCall( 'POST', '/wp/v2/users', {
		username: `e2e_bulk_editor_${ Date.now() }`,
		email: `e2e-bulk-editor-${ Date.now() }@example.org`,
		password,
		roles: [ 'editor' ],
	} );
} );

test.afterAll( async () => {
	if ( editor ) {
		await adminCall( 'DELETE', `/wp/v2/users/${ editor.id }`, {
			force: true,
			reassign: 1,
		} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/manual/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'an editor runs it on three gifts from the list', async ( {
	browser,
} ) => {
	const context = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	try {
		const page = await context.newPage();
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( `${ baseURL }/wp-login.php` );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( editor.username );
		await page.getByLabel( 'Password', { exact: true } ).fill( password );
		await Promise.all( [
			page.waitForURL( /wp-admin/ ),
			page.getByRole( 'button', { name: 'Log In' } ).click(),
		] );

		await bulkRun( page, null );
		await Promise.all( [
			page.waitForURL( /edit\.php\?post_type=barn_gift/ ),
			press( page.getByRole( 'button', { name: 'Run on 2 Gifts' } ) ),
		] );
		const notice = page.locator( '.pegwork-bulk-notice' );
		await expect( notice ).toContainText(
			'Started 2 runs. 1 did not apply.'
		);
		await expect( notice.getByRole( 'link' ) ).toHaveCount( 0 ); // Editors get no run links.
		await checkLayout( page );

		const runs = await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' );
		expect( runs ).toHaveLength( 2 );
		for ( const run of runs ) {
			expect( run ).toMatchObject( {
				automation: 'e2e_manual_thanks',
				user_id: editor.id,
				source: 'bulk',
			} );
		}
		expect( runs.map( ( run ) => run.record_id ).sort() ).toEqual(
			[ gifts[ 0 ].id, gifts[ 1 ].id ].sort()
		);
	} finally {
		await context.close();
	}
} );

test( 'an administrator runs it, resubmits, and follows View runs', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto(
		`${ baseURL }/wp-admin/admin.php?page=pegwork-automations`
	);
	await expect(
		page
			.getByRole( 'row' )
			.filter( { hasText: 'E2E resend the thank-you for a gift' } )
	).toContainText( 'Staff can also run it on selected Gifts' );

	await bulkRun( page, 'automation-bulk-confirm' );
	await Promise.all( [
		page.waitForURL( /edit\.php\?post_type=barn_gift/ ),
		press( page.getByRole( 'button', { name: 'Run on 2 Gifts' } ) ),
	] );
	const notice = page.locator( '.pegwork-bulk-notice' );
	await expect( notice ).toContainText( 'Started 2 runs. 1 did not apply.' );
	await checkLayout( page );
	await notice.screenshot( {
		path: path.join( SHOTS, 'automation-bulk-notice.png' ),
	} );
	expect(
		await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' )
	).toHaveLength( 4 );

	// Back to the same confirmation screen (same request ID): submitting it again starts nothing new.
	await page.goBack();
	await expect(
		page.getByRole( 'heading', { level: 1, name: /on 3 Gifts\?/ } )
	).toBeVisible();
	await Promise.all( [
		page.waitForURL( /edit\.php\?post_type=barn_gift/ ),
		press( page.getByRole( 'button', { name: 'Run on 2 Gifts' } ) ),
	] );
	await expect( page.locator( '.pegwork-bulk-notice' ) ).toContainText(
		'Started 0 runs. 1 did not apply. 2 were already started from this screen.'
	);
	expect(
		await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' )
	).toHaveLength( 4 );

	// A fresh screen is a new request, so View runs comes with the notice of new runs.
	await bulkRun( page, null );
	await Promise.all( [
		page.waitForURL( /edit\.php\?post_type=barn_gift/ ),
		press( page.getByRole( 'button', { name: 'Run on 2 Gifts' } ) ),
	] );
	const link = page
		.locator( '.pegwork-bulk-notice' )
		.getByRole( 'link', { name: 'View runs' } );
	await Promise.all( [ page.waitForURL( /view=log/ ), press( link ) ] );
	expect( page.url() ).toContain( 'automation=e2e_manual_thanks' );
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toBeVisible();
} );

test( 'Cancel returns to the list and starts nothing', async ( { page } ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	const before = ( await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' ) )
		.length;
	await bulkRun( page, null );
	await Promise.all( [
		page.waitForURL( /edit\.php\?post_type=barn_gift/ ),
		press( page.getByRole( 'link', { name: 'Cancel' } ) ),
	] );
	expect(
		await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' )
	).toHaveLength( before );
} );
