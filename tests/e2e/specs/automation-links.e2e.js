/**
 * Signed links with JavaScript off, as a visitor: open the still-active link, see the confirm page,
 * press the button, see the thank-you page and the Project re-verified; the same link then says it was
 * already used; tampered and expired links get their own pages and change nothing; axe on every page.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-sifg.php (examples/sifg), pegwork-e2e-automation.php
 * (module switch) and pegwork-e2e-links.php (Project and link helpers; links are minted by the
 * same code as `wp pegwork automation link`).
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { noJs } = require( '../utils/no-js' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.use( noJs );
test.describe.configure( { mode: 'serial' } );

/**
 * axe needs script injection, which a JavaScript-off page refuses (as in forms-no-js.e2e.js): serve the
 * page's exact HTML at the same URL to a JavaScript-on page and run axe there.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {import('@playwright/test').Page}    page    JavaScript-off page in the state to check.
 */
async function expectAccessible( browser, page ) {
	const html = await page.content();
	const target = page.url().split( '#' )[ 0 ];
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
	await expectNoSeriousA11yViolations( copy );
	await context.close();
}

let admin;
let projectId;
let initialVerified;

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

const project = () =>
	adminCall( 'GET', `/pegwork-e2e/v1/project/${ projectId }` );

async function mint( offset ) {
	const minted = await adminCall( 'POST', '/pegwork-e2e/v1/link', {
		record: projectId,
		...( offset ? { offset } : {} ),
	} );
	return minted.url;
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	projectId = (
		await adminCall( 'POST', '/pegwork-e2e/v1/project', {
			name: `Link E2E ${ Date.now() }`,
		} )
	).id;
	initialVerified = ( await project() ).values.last_verified;
} );

test.afterAll( async () => {
	if ( projectId ) {
		await admin.delete( restUrl( `/wp/v2/projects/${ projectId }` ), {
			headers: { 'X-WP-Nonce': await restNonce( admin ) },
			params: { force: 'true' },
		} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'confirm page, button, thank-you page, record re-verified, then already done', async ( {
	page,
	browser,
} ) => {
	const url = await mint();
	expect( url ).toContain( '?pegwork_link=v1.' );

	const opened = await page.goto( url );
	expect( opened.status() ).toBe( 200 );
	expect( opened.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
	expect( opened.headers()[ 'cache-control' ] ).toContain( 'no-store' );
	expect( opened.headers()[ 'content-security-policy' ] ).toContain(
		"frame-ancestors 'none'"
	);
	expect( opened.headers()[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Please confirm' } )
	).toBeVisible();
	await expect( page.getByText( 'Link E2E' ) ).toBeVisible();
	await expect( page.locator( 'body' ) ).not.toContainText(
		'owner@example.org'
	);
	await expectAccessible( browser, page );
	expect( ( await project() ).values.last_verified ).toBe( initialVerified );

	await page
		.getByRole( 'button', { name: 'Yes, it is still active' } )
		.click();
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Thank you' } )
	).toBeVisible();
	await expect(
		page.getByText( 'Your listing stays live on' )
	).toBeVisible();
	await expectAccessible( browser, page );

	const after = await project();
	expect( after.values.last_verified ).not.toBe( initialVerified );
	expect( after.values.last_verified ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );
	expect( after.values.sifg_project_status ).toEqual( [ 'active' ] );

	await page.goto( url );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Already done' } )
	).toBeVisible();
	await expectAccessible( browser, page );
} );

test( 'a tampered link changes nothing', async ( { page, browser } ) => {
	const before = await project();
	const url = await mint();
	const [ base, signature ] = url.split( /\.(?=[^.]+$)/ );
	const tampered = `${ base }.${ signature.split( '' ).reverse().join( '' ) }`;

	const opened = await page.goto( tampered );
	expect( opened.status() ).toBe( 403 );
	await expect(
		page.getByRole( 'heading', {
			level: 1,
			name: 'This link does not work',
		} )
	).toBeVisible();
	await expect( page.getByRole( 'button' ) ).toHaveCount( 0 );
	await expectAccessible( browser, page );
	expect( await project() ).toEqual( before );
} );

test( 'an expired link changes nothing', async ( { page, browser } ) => {
	const before = await project();
	const url = await mint( '-400 days' );

	const opened = await page.goto( url );
	expect( opened.status() ).toBe( 410 );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'This link has expired' } )
	).toBeVisible();
	await expect( page.getByRole( 'button' ) ).toHaveCount( 0 );
	await expectAccessible( browser, page );
	expect( await project() ).toEqual( before );
} );
