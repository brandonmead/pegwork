/**
 * Turnstile against Cloudflare's real widget with the published test keys: pass (keyboard only,
 * axe at 1280 and 782 px), server-side fail, blocked widget, siteverify unreachable (held as spam),
 * the no-JavaScript notice, and missing keys (form hidden, admin notice).
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (form e2e_turnstile, test keys per mode, helper
 * routes) and a site and browser that can reach challenges.cloudflare.com.
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );
test.use( { storageState: { cookies: [], origins: [] } } );

const HUMAN_PAUSE_MS = 3200;
const AXE = { exclude: [ '.wp-block-navigation' ] };
const TOKEN_FIELD = 'input[name="pegwork_form[captcha]"]';

let admin;
let pageId;
let pageUrl;

async function adminCall( method, route, data, params ) {
	const res = await admin.fetch( restUrl( route ), {
		method,
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		data,
		params,
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`${ method } ${ route } failed: ${ res.status() } ${ await res.text() }`
		);
	}
	return res.json();
}

const entries = () =>
	adminCall( 'GET', '/pegwork-e2e/v1/entries', undefined, {
		form: 'e2e_turnstile',
	} );
const mode = ( name ) =>
	adminCall( 'POST', '/pegwork-e2e/v1/turnstile', { mode: name } );

async function checkLayout( page ) {
	await expectNoSeriousA11yViolations( page, AXE );
	await page.setViewportSize( { width: 782, height: 900 } );
	await expectNoSeriousA11yViolations( page, AXE );
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - window.innerWidth
	);
	expect( overflow, 'horizontal page scroll at 782 px' ).toBeLessThanOrEqual(
		0
	);
	await page.setViewportSize( { width: 1280, height: 900 } );
}

async function fill( page ) {
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada Lovelace' );
	await page
		.getByRole( 'textbox', { name: /^Message/ } )
		.fill( 'Hello from the Turnstile spec.' );
	// The view script shows one step at a time; Email is on the last step.
	await page.getByRole( 'button', { name: 'Next' } ).click();
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'ada@example.org' );
}

async function send( page ) {
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page.getByRole( 'button', { name: 'Send message' } ).click(),
	] );
}

/**
 * Waits until the widget has written a token (the always-pass site key does this without a puzzle).
 *
 * @param {import('@playwright/test').Page} page
 */
async function waitForToken( page ) {
	await expect( page.locator( TOKEN_FIELD ) ).not.toHaveValue( '', {
		timeout: 30_000,
	} );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	const created = await adminCall( 'POST', '/wp/v2/pages', {
		status: 'publish',
		title: `Turnstile E2E ${ Date.now() }`,
		content: '<!-- wp:pegwork/form {"form":"e2e_turnstile"} /-->',
	} );
	pageId = created.id;
	pageUrl = created.link;
} );

test.afterAll( async () => {
	if ( pageId ) {
		await admin.delete( restUrl( `/wp/v2/pages/${ pageId }` ), {
			headers: { 'X-WP-Nonce': await restNonce( admin ) },
			params: { force: 'true' },
		} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	await admin.dispose();
} );

test.beforeEach( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
} );

test( 'test keys pass: the real widget on the last step, keyboard only', async ( {
	page,
} ) => {
	await mode( 'pass' );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( pageUrl );

	const lastStep = page.locator( 'fieldset.pegwork-form__step' ).last();
	await expect( lastStep.locator( 'legend' ) ).toHaveText( 'Contact' );
	await expect( lastStep.locator( '.cf-turnstile' ) ).toHaveCount( 1 );
	await expect(
		page
			.locator( 'fieldset.pegwork-form__step' )
			.first()
			.locator( '.cf-turnstile' )
	).toHaveCount( 0 );
	await expect( page.locator( 'form.pegwork-form__form' ) ).toHaveAttribute(
		'enctype',
		'multipart/form-data'
	);
	await waitForToken( page );
	await checkLayout( page );

	// Keyboard only: fields in order, Next to the last step, the file input, past the widget, to the button.
	await page.getByRole( 'textbox', { name: /^Name/ } ).focus();
	await page.keyboard.type( 'Ada Lovelace' );
	await page.keyboard.press( 'Tab' );
	await expect(
		page.getByRole( 'textbox', { name: /^Message/ } )
	).toBeFocused();
	await page.keyboard.type( 'Hello from the keyboard.' );
	await page.keyboard.press( 'Tab' );
	await expect( page.getByRole( 'button', { name: 'Next' } ) ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect( lastStep.locator( 'h2' ) ).toBeFocused();
	await expect( page.locator( '.pegwork-form__progress' ) ).toHaveText(
		'Step 2 of 2: Contact'
	);
	await expect( lastStep.locator( '.cf-turnstile' ) ).toBeVisible();
	await checkLayout( page );
	// The flexible widget redraws after the viewport changes back. A full-page capture leaves
	// Cloudflare's iframe blank, so the form itself is captured.
	await page.waitForTimeout( 1500 );
	await page.locator( '.pegwork-form' ).screenshot( {
		path: 'test-results/forms-turnstile.png',
	} );
	await page.keyboard.press( 'Tab' );
	await expect(
		page.getByRole( 'textbox', { name: /^Email/ } )
	).toBeFocused();
	await page.keyboard.type( 'ada@example.org' );
	await page.keyboard.press( 'Tab' );
	await expect( page.getByLabel( /^Attachment/ ) ).toBeFocused();
	const submit = page.getByRole( 'button', { name: 'Send message' } );
	let presses = 0;
	do {
		await page.keyboard.press( 'Tab' );
		presses++;
	} while (
		presses < 6 &&
		! ( await submit.evaluate(
			( el ) => el === el.ownerDocument.activeElement
		) )
	);
	await expect( submit ).toBeFocused();
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await Promise.all( [
		page.waitForURL( /pegwork_sent=e2e_turnstile/ ),
		page.keyboard.press( 'Enter' ),
	] );
	await expect( page.locator( '.pegwork-form__status' ) ).toHaveText(
		'Thanks. Your message passed the spam check.'
	);

	const [ entry ] = await entries();
	expect( entry.status ).toBe( 'received' );
	expect( entry.values.message ).toBe( 'Hello from the keyboard.' );
	expect( entry.values ).not.toHaveProperty( 'captcha' );
} );

test( 'test secret fails: stored as spam, the form asks to send again', async ( {
	page,
} ) => {
	await mode( 'fail' );
	await page.goto( pageUrl );
	await fill( page );
	await waitForToken( page );
	await send( page );

	const notice = page.locator(
		'.pegwork-form__notice--problem[role="alert"]'
	);
	await expect( notice ).toHaveText(
		'The spam check did not pass. Wait for the check to finish, then send the form again.'
	);
	await expect( notice ).toBeFocused();
	await expect( page.getByRole( 'textbox', { name: /^Name/ } ) ).toHaveValue(
		'Ada Lovelace'
	);
	await checkLayout( page );
	const [ entry ] = await entries();
	expect( entry.status ).toBe( 'spam' );
	expect( entry.meta.spam ).toEqual( [ 'captcha_failed' ] );
	expect( await adminCall( 'GET', '/pegwork-e2e/v1/mail' ) ).toEqual( [] );
} );

test( 'the always-blocking site key gives no token: refused as spam', async ( {
	page,
} ) => {
	await mode( 'block' );
	await page.goto( pageUrl );
	await fill( page );
	// Give the widget time to run and fail; it never writes a token.
	await page.waitForTimeout( 4000 );
	await send( page );
	await expect(
		page.locator( '.pegwork-form__notice--problem[role="alert"]' )
	).toContainText( 'The spam check did not pass.' );
	const [ entry ] = await entries();
	expect( entry.meta.spam ).toEqual( [ 'captcha_failed' ] );
} );

test( 'siteverify unreachable: held as spam for review, the visitor is told', async ( {
	page,
} ) => {
	await mode( 'offline' );
	await page.goto( pageUrl );
	await fill( page );
	await page.getByLabel( /^Attachment/ ).setInputFiles( {
		name: 'notes.txt',
		mimeType: 'text/plain',
		buffer: Buffer.from( 'Plain notes\n' ),
	} );
	await waitForToken( page );
	await send( page );

	await expect( page ).toHaveURL( /pegwork_held=1/ );
	const status = page.locator( '.pegwork-form__status' );
	await expect( status ).toHaveText(
		'Your submission was saved, but our spam check could not be completed. The site team will review it, so there is no need to send it again.'
	);
	await expect( status ).toBeFocused();
	await checkLayout( page );
	const [ entry ] = await entries();
	expect( entry.status ).toBe( 'spam' );
	expect( entry.meta.spam ).toEqual( [ 'captcha_unverified' ] );
	expect( entry.values.attachment.path ).toMatch(
		/^pegwork-forms\/[0-9a-f]{32}\/notes\.txt$/
	);
	expect( await adminCall( 'GET', '/pegwork-e2e/v1/mail' ) ).toEqual( [] );
} );

test( 'without JavaScript the form says it needs JavaScript', async ( {
	browser,
} ) => {
	await mode( 'pass' );
	const context = await browser.newContext( {
		javaScriptEnabled: false,
		storageState: { cookies: [], origins: [] },
	} );
	const page = await context.newPage();
	await page.goto( pageUrl );
	// Playwright turns JavaScript off through the DevTools protocol, which leaves Chromium's parser
	// treating <noscript> as raw text, so the notice is checked in the markup, not as rendered text.
	const noscript = page.locator( '.pegwork-form > noscript' );
	await expect( noscript ).toHaveCount( 1 );
	expect( await noscript.evaluate( ( el ) => el.textContent ) ).toContain(
		'This form needs JavaScript for its spam check.'
	);
	// It comes before the form, so nobody fills in the form first.
	expect(
		await noscript.evaluate(
			( el ) => el.nextElementSibling?.tagName.toLowerCase() || ''
		)
	).toBe( 'form' );
	await context.close();
} );

test( 'missing keys: visitors see no form, managers get a notice', async ( {
	page,
	browser,
} ) => {
	await mode( 'none' );
	await page.goto( pageUrl );
	await expect( page.locator( 'form.pegwork-form__form' ) ).toHaveCount( 0 );
	await expect( page.locator( '.cf-turnstile' ) ).toHaveCount( 0 );

	const adminContext = await browser.newContext( { storageState: authFile } );
	const adminPage = await adminContext.newPage();
	await adminPage.setViewportSize( { width: 1280, height: 900 } );
	await adminPage.goto( `${ baseURL }/wp-admin/` );
	const notice = adminPage.locator( '.pegwork-turnstile-notice' );
	await expect( notice ).toContainText( '"E2E Turnstile"' );
	await expect( notice ).toContainText(
		'Define PEGWORK_TURNSTILE_SITE_KEY and PEGWORK_TURNSTILE_SECRET_KEY in wp-config.php.'
	);
	await notice.screenshot( {
		path: 'test-results/forms-turnstile-notice.png',
	} );
	await adminPage.goto( pageUrl );
	await expect( adminPage.locator( '.pegwork-form--notice' ) ).toContainText(
		'is not shown to visitors: it uses Cloudflare Turnstile'
	);
	await adminContext.close();
	await mode( 'pass' );
} );
