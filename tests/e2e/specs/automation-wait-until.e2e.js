/**
 * Run log and run detail of a wait until a date (Automation v4 PR 3): barn_task_due_reminder from
 * tests/fixtures/valid/v4 saved as an enabled site automation. Task A is due in 30 days (its run waits),
 * then moved to 2 days out (the listener wakes the run, which checks the date again and reminds); task B
 * has no due date (its run parks, "Waiting for Due on"). The log and the run detail are reached by
 * keyboard; axe on the page and no horizontal scroll at 1280 and 782 px. Saves screenshots to
 * docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-barn.php (barn types, sample tasks, task update, v4 automation,
 * reset) and pegwork-e2e-automation.php (module toggle, in-process cron, mail stub).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { adminCaller } = require( '../utils/builder' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LOG =
	'/wp-admin/admin.php?page=pegwork-automations&view=log&automation=barn_task_due_reminder';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };

let admin;
const adminCall = adminCaller( () => admin );

// A site date (the e2e sites run in UTC) some days from now.
const day = ( days ) =>
	new Date( Date.now() + days * 86_400_000 ).toISOString().slice( 0, 10 );

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
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/v4-automation', {
		key: 'barn_task_due_reminder',
	} );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'a date wait checks the date again; the log and run detail say so; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	const a = await adminCall( 'POST', '/pegwork-e2e/v1/barn/task', {
		name: 'E2E wait A',
		due_on: day( 30 ),
		assignee_email: 'staff@example.org',
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/task', {
		name: 'E2E wait B',
		assignee_email: 'staff@example.org',
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );

	// Due in two days: three days before has passed, so the woken run reminds at once.
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/task-update', {
		id: a.id,
		values: { due_on: day( 2 ) },
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );

	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LOG );
	const runs = page.getByRole( 'table', { name: /runs/i } );
	const rowA = runs.getByRole( 'row' ).filter( { hasText: 'E2E wait A' } );
	const rowB = runs.getByRole( 'row' ).filter( { hasText: 'E2E wait B' } );
	await expect( rowA ).toContainText( 'Completed' );
	await expect( rowB ).toContainText( 'Waiting' );
	await expect( rowB ).toContainText( 'Waiting for Due on' );
	await checkLayout( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-wait-until-log.png' ),
		fullPage: true,
	} );

	// Open task A's run by keyboard.
	const link = rowA.getByRole( 'link', { name: /^Run \d+$/ } );
	await link.focus();
	await Promise.all( [
		page.waitForURL( /view=run&run=\d+/ ),
		page.keyboard.press( 'Enter' ),
	] );
	const attempts = page.getByRole( 'table', {
		name: 'Step attempts, oldest first',
	} );
	const waits = attempts
		.getByRole( 'row' )
		.filter( { has: page.locator( 'code', { hasText: /^before_due$/ } ) } );
	await expect( waits ).toHaveCount( 2 );
	await expect( waits.nth( 0 ).locator( '.pegwork-wait-label' ) ).toHaveText(
		/^Waits until /
	);
	await expect( waits.nth( 1 ).locator( '.pegwork-wait-label' ) ).toHaveText(
		'Checked the date again: the time has come, so the run continues'
	);
	await expect(
		attempts.getByRole( 'row', { name: /^remind send_email/ } )
	).toContainText( 'OK' );
	await checkLayout( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-wait-until-run.png' ),
		fullPage: true,
	} );

	await page.setViewportSize( { width: 782, height: 1000 } );
	await page.reload();
	await checkLayout( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-wait-until-run-782.png' ),
		fullPage: true,
	} );
	await page.goto( LOG );
	await checkLayout( page );
} );
