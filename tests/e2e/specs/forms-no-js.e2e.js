/**
 * Forms runtime with JavaScript off, as a visitor: submit, POST-redirect-GET, success message,
 * entry stored, notification logged; validation errors; honeypot and time trap; a field keyed
 * `name` does not 404; a disabled module renders and handles nothing; axe on every state.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (form package, mail log, helper routes).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { noJs } = require( '../utils/no-js' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.use( noJs );
test.describe.configure( { mode: 'serial' } );

// The server treats submissions faster than 3 seconds after render as bots.
const HUMAN_PAUSE_MS = 3200;

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
		form: 'e2e_contact',
	} );
const mail = () => adminCall( 'GET', '/pegwork-e2e/v1/mail' );

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
		title: `Contact E2E ${ Date.now() }`,
		content: '<!-- wp:pegwork/form {"form":"e2e_contact"} /-->',
	} );
	pageId = created.id;
	pageUrl = created.link;
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
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

/**
 * axe needs script injection, which a JavaScript-off page refuses. Serve this page's exact HTML
 * (same URL, so styles and fonts load) to a JavaScript-on visitor page and run axe there.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {import('@playwright/test').Page}    page    JavaScript-off page in the state to check.
 */
async function expectAccessible( browser, page ) {
	const html = await page.content();
	const target = page.url().split( '#' )[ 0 ];
	// The test runner applies `use` options (noJs) to new contexts too, so turn JavaScript back on.
	const context = await browser.newContext( {
		javaScriptEnabled: true,
		storageState: { cookies: [], origins: [] },
	} );
	const copy = await context.newPage();
	await copy.route(
		( url ) => url.href === target,
		( route ) =>
			route.fulfill( {
				status: 200,
				contentType: 'text/html; charset=utf-8',
				body: html,
			} ),
		{ times: 1 }
	);
	await copy.goto( target );
	// The theme's navigation (a core block listing pages) is not under test.
	await expectNoSeriousA11yViolations( copy, {
		exclude: [ '.wp-block-navigation' ],
	} );
	await context.close();
}

async function fillValid( page, message ) {
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada Lovelace' );
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'ada@example.org' );
	await page.getByRole( 'radio', { name: 'Getting help' } ).check();
	await page.getByRole( 'textbox', { name: /^Message/ } ).fill( message );
	await page.getByRole( 'checkbox', { name: /You may contact me/ } ).check();
}

test( 'contact form submits end to end without JavaScript', async ( {
	browser,
	page,
} ) => {
	const response = await page.goto( pageUrl );
	expect( response.status() ).toBe( 200 );
	const form = page.locator( 'form.pegwork-form__form' );
	await expect( form ).toBeVisible();
	await expect( form ).toHaveAttribute( 'method', 'post' );
	await expect( page.getByText( '(required)' ).first() ).toBeVisible();
	await expectAccessible( browser, page );

	const message = `Hello from E2E ${ Date.now() }`;
	await fillValid( page, message );
	await page.waitForTimeout( HUMAN_PAUSE_MS );

	const [ landed ] = await Promise.all( [
		page.waitForResponse( ( res ) =>
			res.url().includes( 'pegwork_sent=e2e_contact' )
		),
		page.getByRole( 'button', { name: 'Send message' } ).click(),
	] );
	// A field keyed `name` would collide with the WordPress query var and 404; prefixed names do not.
	const post = landed.request().redirectedFrom();
	expect( post.method() ).toBe( 'POST' );
	expect( ( await post.response() ).status() ).toBe( 303 );
	expect( landed.status() ).toBe( 200 );
	await page.waitForURL( /pegwork_sent=e2e_contact/ );

	const status = page.getByRole( 'status' );
	await expect( status ).toHaveText( 'Thanks. Your message is on its way.' );
	await expect( status ).toBeFocused();
	await expect( page.locator( 'form.pegwork-form__form' ) ).toHaveCount( 0 );
	await expectAccessible( browser, page );

	const stored = await entries();
	expect( stored ).toHaveLength( 1 );
	expect( stored[ 0 ].status ).toBe( 'received' );
	expect( stored[ 0 ].values ).toMatchObject( {
		name: 'Ada Lovelace',
		email: 'ada@example.org',
		topic: 'help',
		message,
		consent: true,
	} );
	expect( stored[ 0 ].meta.notifications ).toEqual( { admin: 'sent' } );

	const sent = await mail();
	expect( sent ).toHaveLength( 1 );
	expect( sent[ 0 ].subject ).toBe( 'E2E contact: Ada Lovelace' );
	expect( sent[ 0 ].message ).toContain( `Message: ${ message }` );
	expect( JSON.stringify( sent[ 0 ].headers ) ).toContain(
		'Reply-To: ada@example.org'
	);

	// Reloading the success URL shows the message again but stores nothing new.
	await page.reload();
	await expect( page.getByRole( 'status' ) ).toBeVisible();
	expect( await entries() ).toHaveLength( 1 );
} );

test( 'validation errors show a summary and linked field messages', async ( {
	browser,
	page,
} ) => {
	await page.goto( pageUrl );
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'not-an-email' );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Send message' } ).click();
	await page.waitForLoadState();

	await expect( page ).toHaveTitle( /^Error: / );
	const summary = page.locator( '.pegwork-form__errors' );
	await expect(
		summary.getByRole( 'heading', { name: 'There is a problem' } )
	).toBeVisible();
	await expect( summary ).toBeFocused();
	const links = summary.getByRole( 'link' );
	await expect( links ).toHaveText( [
		'Name is required.',
		'Enter Email as an email address, like name@example.com.',
		'Message is required.',
		'Check the box to confirm: You may contact me about this message',
	] );
	await expect( links.first() ).toHaveAttribute(
		'href',
		'#pegwork-e2e_contact-name'
	);

	const email = page.getByRole( 'textbox', { name: /^Email/ } );
	await expect( email ).toHaveValue( 'not-an-email' );
	await expect( email ).toHaveAttribute( 'aria-invalid', 'true' );
	const describedBy = await email.getAttribute( 'aria-describedby' );
	expect( describedBy ).toContain( 'pegwork-e2e_contact-email-error' );
	await expect(
		page.locator( '#pegwork-e2e_contact-email-error' )
	).toHaveText( /Error: Enter Email as an email address/ );
	await expect( email ).toHaveAccessibleDescription(
		/Enter Email as an email address/
	);

	const message = page.getByRole( 'textbox', { name: /^Message/ } );
	await expect( message ).toHaveAccessibleDescription(
		/A few sentences\. Error: Message is required\./
	);
	await expectAccessible( browser, page );
	expect( await entries() ).toHaveLength( 0 );
	expect( await mail() ).toHaveLength( 0 );

	// Fixing the errors and sending again succeeds.
	await fillValid( page, `Fixed ${ Date.now() }` );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Send message' } ).click();
	await page.waitForURL( /pegwork_sent=e2e_contact/ );
	await expect( page.getByRole( 'status' ) ).toBeVisible();
	const stored = await entries();
	expect( stored.map( ( e ) => e.status ) ).toEqual( [ 'received' ] );
	expect( await mail() ).toHaveLength( 1 );
} );

test( 'honeypot and too-fast submissions are rejected quietly', async ( {
	page,
} ) => {
	await page.goto( pageUrl );
	await fillValid( page, `Bot one ${ Date.now() }` );
	await page
		.locator( 'input[name="pegwork_form[url]"]' )
		.fill( 'https://spam.example', { force: true } );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Send message' } ).click();
	await page.waitForURL( /pegwork_sent=e2e_contact/ );
	await expect( page.getByRole( 'status' ) ).toHaveText(
		'Thanks. Your message is on its way.'
	);

	await page.goto( pageUrl );
	await fillValid( page, `Bot two ${ Date.now() }` );
	await page.getByRole( 'button', { name: 'Send message' } ).click();
	await page.waitForURL( /pegwork_sent=e2e_contact/ );
	await expect( page.getByRole( 'status' ) ).toBeVisible();

	const stored = await entries();
	expect( stored.map( ( e ) => e.status ) ).toEqual( [ 'spam', 'spam' ] );
	expect( stored.map( ( e ) => e.meta.spam ) ).toEqual( [
		[ 'too_fast' ],
		[ 'honeypot' ],
	] );
	expect( await mail() ).toHaveLength( 0 );
} );

test( 'a disabled forms module renders and handles nothing', async ( {
	browser,
	page,
} ) => {
	await page.goto( pageUrl );
	const token = await page
		.locator( 'input[name="pegwork_form[token]"]' )
		.getAttribute( 'value' );

	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: false,
	} );
	const response = await page.goto( pageUrl );
	expect( response.status() ).toBe( 200 );
	await expect( page.locator( '.pegwork-form' ) ).toHaveCount( 0 );
	await expectAccessible( browser, page );

	await page.waitForTimeout( HUMAN_PAUSE_MS );
	const post = await page.request.post( pageUrl, {
		maxRedirects: 0,
		form: {
			'pegwork_form[key]': 'e2e_contact',
			'pegwork_form[token]': token,
			'pegwork_form[url]': '',
			'pegwork[name]': 'Ada Lovelace',
			'pegwork[email]': 'ada@example.org',
			'pegwork[message]': 'Should be ignored',
			'pegwork[consent]': '1',
		},
	} );
	expect( post.status() ).toBe( 200 );
	expect( await entries() ).toHaveLength( 0 );
	expect( await mail() ).toHaveLength( 0 );

	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	await page.goto( pageUrl );
	await expect( page.locator( 'form.pegwork-form__form' ) ).toBeVisible();
} );
