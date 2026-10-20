/**
 * File uploads: a keyboard-only submission with a file, rejection and "Choose the file again",
 * the entry screen's download link, and downloads that need the forms capability and a nonce.
 * axe at 1280 and 782 px, no horizontal scroll at 782 px.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (form package with e2e_upload, mail log, helper routes).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );
// Visitors submit; the admin works through its own request context and pages.
test.use( { storageState: { cookies: [], origins: [] } } );

// The server treats submissions faster than 3 seconds after render as bots.
const HUMAN_PAUSE_MS = 3200;
const AXE = { exclude: [ '.wp-block-navigation' ] };

const PDF = Buffer.from(
	'%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n'
);

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
		form: 'e2e_upload',
	} );

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

/**
 * Chooses a file for the focused file input with the keyboard (Space opens the chooser).
 *
 * @param {import('@playwright/test').Page}                  page
 * @param {{name: string, mimeType: string, buffer: Buffer}} file
 */
async function chooseByKeyboard( page, file ) {
	const [ chooser ] = await Promise.all( [
		page.waitForEvent( 'filechooser' ),
		page.keyboard.press( 'Space' ),
	] );
	await chooser.setFiles( file );
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
		title: `Upload E2E ${ Date.now() }`,
		content: '<!-- wp:pegwork/form {"form":"e2e_upload"} /-->',
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

test( 'a visitor sends a file using only the keyboard', async ( { page } ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( pageUrl );
	const form = page.locator( 'form.pegwork-form__form' );
	await expect( form ).toHaveAttribute( 'enctype', 'multipart/form-data' );
	const input = page.getByLabel( /^Document/ );
	await expect( input ).toHaveAttribute( 'type', 'file' );
	await expect( input ).toHaveAttribute( 'accept', '.pdf,.txt' );
	await expect( input ).toHaveAccessibleDescription(
		'Your proposal. PDF or TXT, up to 1 MB.'
	);
	await checkLayout( page );

	await page.getByRole( 'textbox', { name: /^Name/ } ).focus();
	await page.keyboard.type( 'Ada Lovelace' );
	await page.keyboard.press( 'Tab' );
	await expect(
		page.getByRole( 'textbox', { name: /^Email/ } )
	).toBeFocused();
	await page.keyboard.type( 'ada@example.org' );
	await page.keyboard.press( 'Tab' );
	await expect( input ).toBeFocused();
	await chooseByKeyboard( page, {
		name: 'proposal.pdf',
		mimeType: 'application/pdf',
		buffer: PDF,
	} );
	await page.keyboard.press( 'Tab' );
	const submit = page.getByRole( 'button', { name: 'Send document' } );
	await expect( submit ).toBeFocused();
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await Promise.all( [
		page.waitForURL( /pegwork_sent=e2e_upload/ ),
		page.keyboard.press( 'Enter' ),
	] );

	const status = page.locator( '.pegwork-form__status' );
	await expect( status ).toHaveText( 'Thanks. We received your document.' );
	await expect( status ).toBeFocused();
	await checkLayout( page );

	const [ entry ] = await entries();
	expect( entry.status ).toBe( 'received' );
	expect( entry.values.document ).toMatchObject( {
		name: 'proposal.pdf',
		size: PDF.length,
		mime: 'application/pdf',
	} );
	expect( entry.values.document.path ).toMatch(
		/^pegwork-forms\/[0-9a-f]{32}\/proposal\.pdf$/
	);
	const mail = await adminCall( 'GET', '/pegwork-e2e/v1/mail' );
	expect( mail[ 0 ].subject ).toBe( 'E2E upload: proposal.pdf' );
	expect( mail[ 0 ].message ).toContain( 'Document: proposal.pdf' );
	expect( mail[ 0 ].message ).not.toContain( 'pegwork-forms' );
} );

test( 'a refused file shows its error; a problem elsewhere asks to choose the file again', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( pageUrl );
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada Lovelace' );
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'ada@example.org' );
	await page.getByLabel( /^Document/ ).setInputFiles( {
		name: 'proposal.php.pdf',
		mimeType: 'application/pdf',
		buffer: PDF,
	} );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Send document' } ).click();

	const summary = page.locator( '.pegwork-form__errors' );
	await expect( summary ).toBeFocused();
	await expect( summary ).toContainText(
		'The file for Document must be PDF or TXT, up to 1 MB.'
	);
	const input = page.getByLabel( /^Document/ );
	await expect( input ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page ).toHaveTitle( /^Error: / );
	await checkLayout( page );
	expect( await entries() ).toEqual( [] );

	// The summary link moves focus to the file input.
	await summary.getByRole( 'link' ).first().press( 'Enter' );
	await expect( input ).toBeFocused();

	// A valid file with a missing name: the file is discarded and the field asks for it again.
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( '' );
	await input.setInputFiles( {
		name: 'proposal.pdf',
		mimeType: 'application/pdf',
		buffer: PDF,
	} );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Send document' } ).click();
	await expect( page.locator( '.pegwork-form__errors' ) ).toContainText(
		'Name is required.'
	);
	const again = page.locator( '.pegwork-form__again' );
	await expect( again ).toHaveText(
		'Choose the file again. "proposal.pdf" was not kept.'
	);
	await expect( input ).toHaveAccessibleDescription(
		/Choose the file again\. "proposal\.pdf" was not kept\./
	);
	await expect( page.getByRole( 'textbox', { name: /^Email/ } ) ).toHaveValue(
		'ada@example.org'
	);
	await checkLayout( page );
	await page.screenshot( {
		path: 'test-results/forms-upload-again.png',
		fullPage: true,
	} );
	expect( await entries() ).toEqual( [] );
} );

test( 'the entry links the file; downloads need the capability and a nonce', async ( {
	page,
	browser,
} ) => {
	await page.goto( pageUrl );
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada Lovelace' );
	await page
		.getByRole( 'textbox', { name: /^Email/ } )
		.fill( 'ada@example.org' );
	await page.getByLabel( /^Document/ ).setInputFiles( {
		name: 'proposal.pdf',
		mimeType: 'application/pdf',
		buffer: PDF,
	} );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await Promise.all( [
		page.waitForURL( /pegwork_sent=e2e_upload/ ),
		page.getByRole( 'button', { name: 'Send document' } ).click(),
	] );
	const [ entry ] = await entries();

	// The admin's entry screen.
	const adminContext = await browser.newContext( { storageState: authFile } );
	const adminPage = await adminContext.newPage();
	await adminPage.setViewportSize( { width: 1280, height: 900 } );
	await adminPage.goto(
		`${ baseURL }/wp-admin/admin.php?page=pegwork-entries&entry=${ entry.id }`
	);
	const link = adminPage.getByRole( 'link', { name: 'proposal.pdf' } );
	await expect( link ).toBeVisible();
	await expect(
		adminPage.locator( 'tr', { has: link } ).locator( '.description' )
	).toHaveText( `(${ PDF.length } B, application/pdf)` );
	await expect( adminPage.locator( '#wpbody-content' ) ).not.toContainText(
		'pegwork-forms/'
	);
	await checkLayout( adminPage );
	await adminPage.screenshot( {
		path: 'test-results/forms-upload-entry.png',
		fullPage: true,
	} );

	// Keyboard: the link is reachable and downloads the file.
	await link.focus();
	await expect( link ).toBeFocused();
	const [ download ] = await Promise.all( [
		adminPage.waitForEvent( 'download' ),
		adminPage.keyboard.press( 'Enter' ),
	] );
	expect( download.suggestedFilename() ).toBe( 'proposal.pdf' );

	const href = await link.getAttribute( 'href' );
	expect( href ).toContain( 'admin-post.php?action=pegwork_forms_file' );
	const ok = await adminContext.request.get( href );
	expect( ok.status() ).toBe( 200 );
	expect( ok.headers()[ 'content-disposition' ] ).toBe(
		`attachment; filename="proposal.pdf"; filename*=UTF-8''proposal.pdf`
	);
	expect( ok.headers()[ 'x-content-type-options' ] ).toBe( 'nosniff' );
	expect( ok.headers()[ 'content-type' ] ).toContain( 'application/pdf' );
	expect( ok.headers()[ 'cache-control' ] ).toContain( 'no-store' );
	expect( Buffer.compare( await ok.body(), PDF ) ).toBe( 0 );

	const badNonce = await adminContext.request.get(
		href.replace( /_wpnonce=[0-9a-f]+/, '_wpnonce=0123456789' )
	);
	expect( badNonce.status() ).toBe( 403 );

	// A subscriber, logged in, is refused.
	const password = `Sub-${ Date.now() }-pass!`;
	const subscriber = await adminCall( 'POST', '/wp/v2/users', {
		username: `e2e_sub_${ Date.now() }`,
		email: `sub${ Date.now() }@example.org`,
		password,
		roles: [ 'subscriber' ],
	} );
	const subContext = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	const subPage = await subContext.newPage();
	await subPage.goto( `${ baseURL }/wp-login.php` );
	await subPage.fill( '#user_login', subscriber.username );
	await subPage.fill( '#user_pass', password );
	await Promise.all( [
		subPage.waitForURL( /wp-admin|profile/ ),
		subPage.click( '#wp-submit' ),
	] );
	const refused = await subContext.request.get( href );
	expect( refused.status() ).toBe( 403 );
	expect( await refused.text() ).not.toContain( '%PDF' );
	await subContext.close();
	await admin.delete( restUrl( `/wp/v2/users/${ subscriber.id }` ), {
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		params: { force: 'true', reassign: '1' },
	} );

	// Logged out: admin-post.php has no handler for visitors.
	const anonymous = await requestFactory.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	const visitor = await anonymous.get( href );
	expect( visitor.status() ).toBe( 400 );
	expect( await visitor.text() ).not.toContain( '%PDF' );
	await anonymous.dispose();

	// Deleting the entry removes the file: the same link now finds nothing.
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	const gone = await adminContext.request.get( href );
	expect( gone.status() ).toBe( 404 );
	await adminContext.close();
} );
