/**
 * Manual trigger, all by keyboard: an editor and an administrator run "Resend thank-you" from a BarnCRM
 * gift (the "Run an automation" section of the Details panel, a confirmation dialog, the announced
 * notice), and an administrator runs a no-record automation with Run now on Pegwork > Automations. Axe at
 * 1280 and 782 px, and no horizontal page scroll at 782 px. Screenshots in docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-manual.php (package tests/e2e/packages/manual, runs, reset),
 * pegwork-e2e-barn.php (the gift type, a sample gift) and pegwork-e2e-automation.php (module switch, cron).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const BUTTON = 'Resend thank-you';

let admin;
let gift;
let editor;
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
 * Waits for an open modal to finish fading in (axe measures colours mid-animation otherwise).
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function waitForModal( page ) {
	await page.waitForFunction( () =>
		(
			document
				.querySelector( '.components-modal__screen-overlay' )
				?.getAnimations( { subtree: true } ) || []
		).every( ( animation ) => animation.playState !== 'running' )
	);
}

/**
 * Axe, plus no horizontal page scroll.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function checkLayout( page ) {
	await waitForModal( page );
	await expectNoSeriousA11yViolations( page, AXE );
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - window.innerWidth
	);
	expect( overflow ).toBeLessThanOrEqual( 0 );
}

/**
 * Opens the gift in the block editor with the Details panel expanded; returns the section.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openGift( page ) {
	await page.goto(
		`${ baseURL }/wp-admin/post.php?post=${ gift.id }&action=edit`
	);
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	// A fresh user gets the welcome guide, which covers the editor.
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);
	const panel = page.getByRole( 'button', { name: 'Details', exact: true } );
	await expect( panel ).toBeVisible( { timeout: 30_000 } );
	if ( ( await panel.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await panel.click();
	}
	const section = page.locator( '.pegwork-editor-panel__actions' );
	await expect(
		section.getByRole( 'heading', { name: 'Run an automation' } )
	).toBeVisible();
	return section;
}

/**
 * Runs the automation from the open gift by keyboard: Enter on the button, the dialog's question, Enter on
 * its confirm button. Returns the dialog-free page once the notice shows.
 *
 * @param {import('@playwright/test').Page}    page    Page.
 * @param {import('@playwright/test').Locator} section The section.
 * @param {string|null}                        shot    Screenshot file names prefix, or null.
 */
async function runFromGift( page, section, shot ) {
	const button = section.getByRole( 'button', { name: BUTTON } );
	await button.focus();
	await expect( button ).toBeFocused();
	await page.keyboard.press( 'Enter' );

	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toContainText( `${ BUTTON } for ${ gift.title }?` );
	if ( shot ) {
		await waitForModal( page );
		await page.screenshot( {
			path: path.join( SHOTS, `${ shot }-confirm.png` ),
		} );
	}
	// Axe opens a helper page, and the dialog closes when the window loses focus; so after axe, Escape
	// (if it is still open) cancels it, focus returns to the button, and Enter opens it again.
	for ( const width of [ 1280, 782 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await checkLayout( page );
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
	if ( await dialog.isVisible() ) {
		await dialog.getByRole( 'button', { name: 'Cancel' } ).focus();
		await page.keyboard.press( 'Escape' );
	}
	await expect( dialog ).toHaveCount( 0 );
	await button.focus();
	await page.keyboard.press( 'Enter' );
	await expect( dialog ).toBeVisible();

	const confirm = dialog.getByRole( 'button', { name: BUTTON } );
	await confirm.focus();
	await page.keyboard.press( 'Enter' );
	await expect( dialog ).toHaveCount( 0 );

	const message = `${ BUTTON } started. It runs in the background.`;
	await expect( page.locator( '#a11y-speak-polite' ) ).toHaveText( message );
	await expect(
		page.locator( '.components-notice' ).filter( { hasText: message } )
	).toBeVisible();
	// Focus returns to the button, so the next action is one Tab away.
	await expect( button ).toBeFocused();
	return message;
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
	gift = await adminCall( 'POST', '/pegwork-e2e/v1/barn/gift', {
		amount: 120,
	} );
	editor = await adminCall( 'POST', '/wp/v2/users', {
		username: `e2e_manual_editor_${ Date.now() }`,
		email: `e2e-manual-editor-${ Date.now() }@example.org`,
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

test( 'an editor resends the thank-you from a gift by keyboard', async ( {
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

		const section = await openGift( page );
		await expect( section.getByRole( 'button' ) ).toHaveCount( 1 ); // Editors see only "editors" automations.
		await runFromGift( page, section, null );
		await expect(
			page.getByRole( 'link', { name: 'View run' } )
		).toHaveCount( 0 );

		const runs = await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' );
		expect( runs ).toHaveLength( 1 );
		expect( runs[ 0 ] ).toMatchObject( {
			automation: 'e2e_manual_thanks',
			record_id: gift.id,
			user_id: editor.id,
			source: 'editor',
		} );
		await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );
		const done = await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' );
		expect( done[ 0 ].status ).toBe( 'completed' );
	} finally {
		await context.close();
	}
} );

test( 'an administrator resends it and follows the link to the run', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	// A second gift: the editor still holds the post lock on the first.
	gift = await adminCall( 'POST', '/pegwork-e2e/v1/barn/gift', {
		amount: 80,
	} );
	const section = await openGift( page );
	await expect( section.getByRole( 'button' ) ).toHaveCount( 1 ); // The package has one automation on gifts.
	await runFromGift( page, section, 'automation-record-actions' );
	// The section and the notice, with its link to the run.
	await section.scrollIntoViewIfNeeded();
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-record-actions.png' ),
	} );

	const link = page.getByRole( 'link', { name: 'View run' } );
	await link.focus();
	await Promise.all( [
		page.waitForURL( /view=run/ ),
		page.keyboard.press( 'Enter' ),
	] );
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText(
		'Run'
	);
	const runs = await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' );
	expect( runs ).toHaveLength( 2 );
	expect( runs[ 1 ].source ).toBe( 'editor' );
	expect( page.url() ).toContain( `run=${ runs[ 1 ].id }` );
} );

test( 'Run now starts a no-record automation from the list', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( `${ baseURL }${ LIST }` );
	const row = page
		.getByRole( 'row' )
		.filter( { hasText: 'E2E send the giving report now' } );
	await expect( row ).toContainText(
		'Started by staff: "Send giving report" (Run now)'
	);
	await expect( row ).toContainText( 'When staff start it' );
	await expect(
		page
			.getByRole( 'row' )
			.filter( { hasText: 'E2E resend the thank-you for a gift' } )
	).toContainText( 'Editors can run' );
	for ( const width of [ 1280, 782 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await checkLayout( page );
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
	await row.scrollIntoViewIfNeeded();
	await row.screenshot( {
		path: path.join( SHOTS, 'automation-run-now-manual.png' ),
	} );

	const button = row.getByRole( 'button', {
		name: 'Run now E2E send the giving report now',
	} );
	await button.focus();
	await expect( button ).toBeFocused();
	await Promise.all( [
		page.waitForURL( /view=run/ ),
		page.keyboard.press( 'Enter' ),
	] );
	await expect( page.locator( '.notice-success' ) ).toContainText(
		'E2E send the giving report now started.'
	);
	const runs = await adminCall( 'GET', '/pegwork-e2e/v1/manual/runs' );
	const report = runs.filter(
		( run ) => run.automation === 'e2e_manual_report'
	);
	expect( report ).toHaveLength( 1 );
	expect( report[ 0 ] ).toMatchObject( { record_id: null, source: 'list' } );
	await checkLayout( page );
} );
