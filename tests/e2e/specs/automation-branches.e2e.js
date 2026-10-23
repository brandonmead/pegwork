/**
 * Pegwork > Automations run detail with decisions: Run now on e2e_branch by keyboard, then the step
 * attempts indented by depth, a label row where each branch starts, and the decision summaries. Axe at
 * 1280 and 782 px, and no horizontal page scroll.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (automation package, helper routes).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const LABEL = 'E2E branching digest';

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

test( 'run detail shows the path through decisions; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );

	// Run now by keyboard, then open the run by keyboard.
	await page.goto( LIST );
	const runNow = page.getByRole( 'button', { name: `Run now ${ LABEL }` } );
	await runNow.focus();
	await Promise.all( [
		page.waitForURL( /view=log&automation=e2e_branch/ ),
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
	// Header, five attempts, two branch label rows.
	await expect( attempts.getByRole( 'row' ) ).toHaveCount( 8 );
	const labels = attempts.locator( '.pegwork-branch-row' );
	await expect( labels ).toHaveText( [
		'Branch Named site of route',
		'Branch Site URL set of has_url',
	] );
	await expect(
		attempts.getByRole( 'row', { name: /^route/ } )
	).toContainText( 'Took branch Named site' );
	await expect(
		attempts.getByRole( 'row', { name: /^has_url/ } )
	).toContainText( 'Took branch Site URL set' );

	// Depth: route and done at the top, named and has_url one level in, with_url two.
	const depth = ( id ) =>
		attempts
			.getByRole( 'rowheader' )
			.filter( { has: page.locator( 'code', { hasText: id } ) } );
	await expect( depth( 'route' ) ).not.toHaveClass( /pegwork-depth/ );
	await expect( depth( 'named' ) ).toHaveClass( 'pegwork-depth-1' );
	await expect( depth( 'has_url' ) ).toHaveClass( 'pegwork-depth-1' );
	await expect( depth( 'with_url' ) ).toHaveClass( 'pegwork-depth-2' );
	await expect( depth( 'done' ) ).not.toHaveClass( /pegwork-depth/ );
	const indent = async ( id ) =>
		depth( id ).evaluate( ( el ) =>
			parseFloat(
				el.ownerDocument.defaultView.getComputedStyle( el ).paddingLeft
			)
		);
	expect( await indent( 'named' ) ).toBeGreaterThan(
		await indent( 'route' )
	);
	expect( await indent( 'with_url' ) ).toBeGreaterThan(
		await indent( 'named' )
	);
	await expect( attempts ).not.toContainText( 'unnamed' );
	await expect( attempts ).not.toContainText( 'fallback' );

	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-branches.png',
		fullPage: true,
	} );

	await page.setViewportSize( { width: 782, height: 1000 } );
	await page.reload();
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/automation-run-branches-782.png',
		fullPage: true,
	} );
} );
