/**
 * QA fixes in the forms module, as a visitor with JavaScript on, each with axe at 1280 and 782 px:
 * a confirmation email that cannot be sent (visitor told, admin alerted, entry kept as failed), the same
 * confirm submission sent twice (no second email, says so), a POST over post_max_size (too large notice,
 * visitor stays on the form), and a send before the Turnstile token exists (blocked, polite message).
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (forms e2e_project, e2e_upload, e2e_turnstile; mail log
 * and failure switch; helper routes) and tests/e2e/mu-plugins/pegwork-e2e-sifg.php (the sifg_project type).
 * The Turnstile test needs a browser that can reach challenges.cloudflare.com.
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );
test.use( { storageState: { cookies: [], origins: [] } } );

// The server treats submissions faster than 3 seconds after render as bots.
const HUMAN_PAUSE_MS = 3200;
const AXE = { exclude: [ '.wp-block-navigation' ] };

let admin;
const pages = {};

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

const entries = ( form ) =>
	adminCall( 'GET', '/pegwork-e2e/v1/entries', undefined, { form } );
const mail = () => adminCall( 'GET', '/pegwork-e2e/v1/mail' );

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

async function createPage( key ) {
	const created = await adminCall( 'POST', '/wp/v2/pages', {
		status: 'publish',
		title: `QA fixes ${ key } ${ Date.now() }`,
		content: `<!-- wp:pegwork/form {"form":"${ key }"} /-->`,
	} );
	pages[ key ] = { id: created.id, url: created.link };
}

/**
 * Fills the three steps of e2e_project and stops before sending.
 *
 * @param {import('@playwright/test').Page} page
 */
async function fillProject( page ) {
	const description = Array( 12 )
		.fill( 'Volunteers test river water every week.' )
		.join( ' ' );
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'River Watch' );
	await page
		.getByRole( 'textbox', { name: /^Description/ } )
		.fill( description );
	await page
		.getByRole( 'radiogroup', { name: /^Type/ } )
		.getByRole( 'radio', { name: 'Nonprofit' } )
		.check();
	await page
		.getByRole( 'radiogroup', { name: /^Project status/ } )
		.getByRole( 'radio', { name: 'Active' } )
		.check();
	await page.getByRole( 'checkbox', { name: 'Life Below Water' } ).check();
	await page.getByRole( 'button', { name: 'Next' } ).click();
	await page
		.getByRole( 'textbox', { name: /^Website/ } )
		.fill( 'https://riverwatch.example.org' );
	await page.getByRole( 'button', { name: 'Next' } ).click();
	await page
		.getByRole( 'textbox', { name: /^Contact email/ } )
		.fill( 'ada@example.org' );
	await page
		.getByRole( 'checkbox', { name: /I have permission to share/ } )
		.check();
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	for ( const key of [ 'e2e_project', 'e2e_upload', 'e2e_turnstile' ] ) {
		await createPage( key );
	}
} );

test.afterAll( async () => {
	const nonce = await restNonce( admin );
	for ( const { id } of Object.values( pages ) ) {
		await admin.delete( restUrl( `/wp/v2/pages/${ id }` ), {
			headers: { 'X-WP-Nonce': nonce },
			params: { force: 'true' },
		} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	await admin.dispose();
} );

test.beforeEach( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
} );

test( 'F1: a confirmation email that cannot be sent is said so, and the admin is told', async ( {
	page,
} ) => {
	await adminCall( 'POST', '/pegwork-e2e/v1/mail-fail', { on: true } );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( pages.e2e_project.url );
	await fillProject( page );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Submit project' } ).click();

	const notice = page.locator(
		'.pegwork-form__notice--problem[role="alert"]'
	);
	await expect( notice ).toContainText(
		'we could not send the confirmation email'
	);
	await expect( notice ).toBeFocused();
	await expect( page.locator( '.pegwork-form__status' ) ).toHaveCount( 0 );
	await checkLayout( page );

	const [ entry ] = await entries( 'e2e_project' );
	expect( entry.status ).toBe( 'failed' );
	const sent = await mail();
	expect( sent ).toHaveLength( 1 );
	expect( sent[ 0 ].subject ).toContain( 'Confirmation email failed' );
	expect( String( sent[ 0 ].to ) ).not.toContain( 'ada@example.org' );
} );

test( 'F2: the same confirm submission twice sends one email and says so', async ( {
	page,
} ) => {
	await page.goto( pages.e2e_project.url );
	await fillProject( page );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Submit project' } ).click();
	await page.waitForURL( /pegwork_sent=e2e_project/ );
	await expect( page.locator( '.pegwork-form__status' ) ).toContainText(
		'Check your email'
	);

	await page.goto( pages.e2e_project.url );
	await fillProject( page );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Submit project' } ).click();
	await page.waitForURL( /pegwork_again=1/ );
	const status = page.getByRole( 'status' );
	await expect( status ).toContainText( 'We already have this submission' );
	await expect( status ).toBeFocused();
	await checkLayout( page );

	const confirmMails = ( await mail() ).filter( ( m ) =>
		String( m.message ).includes( 'pegwork_confirm=' )
	);
	expect( confirmMails ).toHaveLength( 1 );
	expect( await entries( 'e2e_project' ) ).toHaveLength( 1 );
} );

test( 'F3: files over post_max_size give a too large notice and keep the form', async ( {
	page,
} ) => {
	const limit = await adminCall( 'GET', '/pegwork-e2e/v1/post-max' );
	test.skip( limit === 0 || limit > 256 * 1024 * 1024, 'No usable limit.' );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( pages.e2e_upload.url );
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada Lovelace' );
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'ada@example.org' );
	// Just over the server's post_max_size: PHP drops the whole body.
	await page.getByLabel( /^Document/ ).setInputFiles( {
		name: 'huge.txt',
		mimeType: 'text/plain',
		buffer: Buffer.alloc( limit + 1024 * 1024, 'a' ),
	} );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page.getByRole( 'button', { name: 'Send document' } ).click(),
	] );

	const notice = page.locator(
		'.pegwork-form__notice--problem[role="alert"]'
	);
	await expect( notice ).toContainText( 'too large to send together' );
	await expect( notice ).toContainText( 'nothing was sent' );
	await expect( notice ).toBeFocused();
	await expect( page.getByLabel( /^Document/ ) ).toBeVisible();
	await expect( page ).not.toHaveURL( /pegwork_sent/ );
	await checkLayout( page );
	expect( await entries( 'e2e_upload' ) ).toHaveLength( 0 );
} );

test( 'F5: sending before the Turnstile token exists waits with a polite message', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	// The always-blocking test site key never gives a token, so the form must not send.
	await adminCall( 'POST', '/pegwork-e2e/v1/turnstile', { mode: 'block' } );
	await page.goto( pages.e2e_turnstile.url );
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada Lovelace' );
	await page
		.getByRole( 'textbox', { name: /^Message/ } )
		.fill( 'Hello from the Turnstile spec.' );
	await page.getByRole( 'button', { name: 'Next' } ).click();
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'ada@example.org' );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Send message' } ).click();

	const status = page.locator( '.pegwork-form__captcha-status' );
	await expect( status ).toHaveAttribute( 'role', 'status' );
	await expect( status ).toHaveText(
		'The spam check is still running. Wait a moment, then send the form again.'
	);
	await expect( page ).not.toHaveURL( /pegwork_sent|pegwork_held/ );
	await expect(
		page.getByRole( 'button', { name: 'Send message' } )
	).not.toHaveAttribute( 'aria-disabled', 'true' );
	await checkLayout( page );
	expect( await entries( 'e2e_turnstile' ) ).toHaveLength( 0 );
} );
