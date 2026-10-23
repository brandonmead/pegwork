/**
 * Pegwork > Automations run detail with a loop: Run now on e2e_loop by keyboard over three draft
 * projects (the second has no website, so its condition skips it), then a heading row per item with
 * the record title, the item's attempts indented under it, and the titles the find_records step read.
 * Axe at 1280 and 782 px, and no horizontal page scroll.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (automation package, helper routes).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const {
	createRecord,
	deleteRecord,
	restNonce,
	restUrl,
} = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const LABEL = 'E2E loop digest';
const TITLES = [ 'E2E Loop Alder', 'E2E Loop Birch', 'E2E Loop Cedar' ];

let admin;
const projects = [];

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

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/reset' );
	for ( const [ i, title ] of TITLES.entries() ) {
		projects.push(
			await createRecord( admin, 'projects', {
				title,
				meta: {
					_sifg_project_who_for: 'E2E loop',
					_sifg_project_website:
						1 === i ? '' : `https://example.org/${ i }`,
				},
			} )
		);
	}
} );

test.afterAll( async () => {
	for ( const project of projects ) {
		await deleteRecord( admin, 'projects', project.id );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'run detail groups loop attempts by item; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );

	// Run now by keyboard, then open the run by keyboard.
	await page.goto( LIST );
	const runNow = page.getByRole( 'button', { name: `Run now ${ LABEL }` } );
	await runNow.focus();
	await Promise.all( [
		page.waitForURL( /view=log&automation=e2e_loop/ ),
		page.keyboard.press( 'Enter' ),
	] );
	const runLink = page.getByRole( 'link', { name: /^Run \d+$/ } ).first();
	await runLink.focus();
	await Promise.all( [
		page.waitForURL( /view=run&run=\d+/ ),
		page.keyboard.press( 'Enter' ),
	] );
	await expect( async () => {
		await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );
		await page.reload();
		await expect(
			page.locator( '.pegwork-run .pegwork-status' ).first()
		).toHaveText( 'Completed', { timeout: 1000 } );
	} ).toPass( { timeout: 20_000 } );

	const attempts = page.getByRole( 'table', {
		name: 'Step attempts, oldest first',
	} );
	await expect( attempts.locator( '.pegwork-item-row' ) ).toHaveText(
		projects.map(
			( project, i ) =>
				`Item ${ i + 1 } of 3: Project #${ project.id } (${ TITLES[ i ] })`
		)
	);

	// The find_records row names the records it read.
	await expect(
		attempts.getByRole( 'row', { name: /^drafts/ } )
	).toContainText(
		`Read: Project #${ projects[ 0 ].id } (${ TITLES[ 0 ] })`
	);

	// Items 1 and 3 sent mail; item 2 stopped at its condition.
	const rowsOf = ( id ) =>
		attempts.getByRole( 'row' ).filter( {
			has: page.getByRole( 'rowheader' ).filter( {
				has: page.locator( 'code', {
					hasText: new RegExp( `^${ id }$` ),
				} ),
			} ),
		} );
	await expect( rowsOf( 'mail' ) ).toHaveCount( 2 );
	await expect( rowsOf( 'has_site' ) ).toHaveCount( 3 );

	// The body steps sit one level in from the loop.
	const header = ( id ) => rowsOf( id ).first().getByRole( 'rowheader' );
	await expect( header( 'each' ) ).not.toHaveClass( /pegwork-depth/ );
	await expect( header( 'mail' ) ).toHaveClass( 'pegwork-depth-1' );
	await expect( header( 'done' ) ).not.toHaveClass( /pegwork-depth/ );

	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-loop.png',
		fullPage: true,
	} );

	await page.setViewportSize( { width: 782, height: 1000 } );
	await page.reload();
	await checkLayout( page );
	// Step ids stay on one line at 782 px (has_site wrapped before PR 7).
	const ids = header( 'has_site' ).locator( 'code' );
	const box = await ids.boundingBox();
	const line = await ids.evaluate(
		( el ) =>
			parseFloat(
				el.ownerDocument.defaultView.getComputedStyle( el ).lineHeight
			) || 20
	);
	expect( box.height ).toBeLessThan( line * 1.5 );
	await page.screenshot( {
		path: 'test-results/automation-run-loop-782.png',
		fullPage: true,
	} );
} );
