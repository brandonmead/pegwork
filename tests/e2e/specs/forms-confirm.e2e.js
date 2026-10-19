/**
 * Record mapping and email confirmation with JavaScript off, as a visitor: submit a project form bound
 * to sifg_project, read the confirmation link from the logged mail, open it (GET changes nothing),
 * press the button (POST), see the thank-you, and find the Pending project with the submitted values.
 * A second visit says "already confirmed" and creates nothing. axe on every confirmation state.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (form e2e_project, mail log, helper routes) and
 * tests/e2e/mu-plugins/pegwork-e2e-sifg.php (the sifg_project type).
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
const projects = [];

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
		form: 'e2e_project',
	} );
const mail = () => adminCall( 'GET', '/pegwork-e2e/v1/mail' );

/**
 * axe needs script injection, which a JavaScript-off page refuses. Serve this page's exact HTML
 * (same URL, so styles load) to a JavaScript-on page and run axe there.
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
	await expectNoSeriousA11yViolations( copy, {
		exclude: [ '.wp-block-navigation' ],
	} );
	await context.close();
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	const created = await adminCall( 'POST', '/wp/v2/pages', {
		status: 'publish',
		title: `Submit a project E2E ${ Date.now() }`,
		content: '<!-- wp:pegwork/form {"form":"e2e_project"} /-->',
	} );
	pageId = created.id;
	pageUrl = created.link;
} );

test.afterAll( async () => {
	const nonce = await restNonce( admin );
	if ( pageId ) {
		await admin.delete( restUrl( `/wp/v2/pages/${ pageId }` ), {
			headers: { 'X-WP-Nonce': nonce },
			params: { force: 'true' },
		} );
	}
	for ( const id of projects ) {
		await admin.delete( restUrl( `/wp/v2/projects/${ id }` ), {
			headers: { 'X-WP-Nonce': nonce },
			params: { force: 'true' },
		} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	await admin.dispose();
} );

test( 'submit a project, confirm by email, Pending project created', async ( {
	browser,
	page,
} ) => {
	const name = `River Watch ${ Date.now() }`;
	const description = Array( 12 )
		.fill( 'Volunteers test river water every week.' )
		.join( ' ' );

	await page.goto( pageUrl );
	await expect( page.locator( 'form.pegwork-form__form' ) ).toBeVisible();
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( name );
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
	await page
		.getByRole( 'checkbox', { name: 'Clean Water and Sanitation' } )
		.check();
	await page
		.getByRole( 'textbox', { name: /^Website/ } )
		.fill( 'https://riverwatch.example.org' );
	await page.getByRole( 'checkbox', { name: /^Needs volunteers/ } ).check();
	await page
		.getByRole( 'textbox', { name: /^Volunteer link/ } )
		.fill( 'https://riverwatch.example.org/join' );
	await page
		.getByRole( 'textbox', { name: /^Contact email/ } )
		.fill( 'ada@example.org' );
	await page
		.getByRole( 'checkbox', { name: /I have permission to share/ } )
		.check();
	await expectAccessible( browser, page );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Submit project' } ).click();

	await page.waitForURL( /pegwork_sent=e2e_project/ );
	const sent = page.getByRole( 'status' );
	await expect( sent ).toHaveText(
		'Check your email to confirm your submission. The link expires in 72 hours.'
	);
	await expect( sent ).toBeFocused();
	await expect( page.locator( 'body' ) ).not.toContainText(
		'ada@example.org'
	);

	let stored = await entries();
	expect( stored ).toHaveLength( 1 );
	expect( stored[ 0 ].status ).toBe( 'unconfirmed' );
	expect( stored[ 0 ].post_id ).toBeNull();

	// Read the link from the logged mail.
	const confirmMail = ( await mail() ).find( ( m ) =>
		String( m.message ).includes( 'pegwork_confirm=' )
	);
	expect( confirmMail.to ).toContain( 'ada@example.org' );
	expect( confirmMail.subject ).toBe( 'Confirm your E2E project' );
	const link = String( confirmMail.message ).match(
		/https?:\/\/\S*pegwork_confirm=[0-9]+\.[A-Za-z0-9_-]{43}/
	)[ 0 ];

	// GET shows a button and changes nothing (mail scanners prefetch links).
	const response = await page.goto( link );
	expect( response.headers()[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	expect( response.headers()[ 'cache-control' ] ).toContain( 'no-store' );
	await expect(
		page.getByRole( 'heading', { name: 'Confirm your submission' } )
	).toBeVisible();
	await expect( page.locator( 'meta[name="robots"]' ) ).toHaveAttribute(
		'content',
		/noindex/
	);
	await expectAccessible( browser, page );
	stored = await entries();
	expect( stored[ 0 ].status ).toBe( 'unconfirmed' );

	// Press the button: POST, then the thank-you state.
	await page.getByRole( 'button', { name: 'Confirm my submission' } ).click();
	await page.waitForURL( /pegwork_confirmed=e2e_project/ );
	expect( page.url() ).not.toContain( 'pegwork_confirm=' );
	const thanks = page.getByRole( 'status' );
	await expect( thanks ).toHaveText(
		'Thank you. Your submission is confirmed.'
	);
	await expect( thanks ).toBeFocused();
	await expectAccessible( browser, page );

	stored = await entries();
	expect( stored[ 0 ].status ).toBe( 'received' );
	const postId = Number( stored[ 0 ].post_id );
	expect( postId ).toBeGreaterThan( 0 );
	projects.push( postId );

	const project = await adminCall(
		'GET',
		`/wp/v2/projects/${ postId }`,
		undefined,
		{ context: 'edit' }
	);
	expect( project.status ).toBe( 'pending' );
	expect( project.title.raw ).toBe( name );
	expect( project.content.raw ).toBe(
		`<!-- wp:paragraph -->\n<p>${ description }</p>\n<!-- /wp:paragraph -->`
	);
	expect( project.meta ).toMatchObject( {
		_sifg_project_website: 'https://riverwatch.example.org',
		_sifg_project_needs_volunteers: true,
		_sifg_project_volunteer_link: 'https://riverwatch.example.org/join',
		_sifg_project_contact_email: 'ada@example.org',
	} );
	const terms = async ( taxonomy ) =>
		(
			await adminCall( 'GET', `/wp/v2/${ taxonomy }`, undefined, {
				post: postId,
			} )
		)
			.map( ( t ) => t.slug )
			.sort();
	expect( await terms( 'sifg_project_type' ) ).toEqual( [ 'nonprofit' ] );
	expect( await terms( 'sifg_project_status' ) ).toEqual( [ 'active' ] );
	expect( await terms( 'sifg_impact' ) ).toEqual( [ 'sdg-06', 'sdg-14' ] );

	const review = ( await mail() ).find( ( m ) =>
		String( m.subject ).startsWith( 'E2E project to review' )
	);
	expect( review.subject ).toBe( `E2E project to review: ${ name }` );

	// The link again: already confirmed, no second project.
	await page.goto( link );
	await expect( page.getByRole( 'status' ) ).toHaveText(
		'This submission is already confirmed. There is nothing more to do.'
	);
	await expectAccessible( browser, page );
	expect( ( await entries() ).map( ( e ) => e.status ) ).toEqual( [
		'received',
	] );
	const pending = await adminCall( 'GET', '/wp/v2/projects', undefined, {
		status: 'pending',
		search: name,
		context: 'edit',
	} );
	expect( pending.map( ( p ) => p.id ) ).toEqual( [ postId ] );

	// A tampered link: a notice, then the form to submit again.
	await page.goto(
		link.replace( /\.[A-Za-z0-9_-]{43}$/, '.' + 'A'.repeat( 43 ) )
	);
	await expect( page.getByRole( 'alert' ) ).toContainText(
		'This confirmation link is not valid.'
	);
	await expect( page.locator( 'form.pegwork-form__form' ) ).toBeVisible();
	await expectAccessible( browser, page );
} );

test( 'record rules validate the bound fields', async ( { page } ) => {
	await page.goto( pageUrl );
	await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Short' );
	await page
		.getByRole( 'textbox', { name: /^Description/ } )
		.fill( 'Too short.' );
	await page.waitForTimeout( HUMAN_PAUSE_MS );
	await page.getByRole( 'button', { name: 'Submit project' } ).click();
	await page.waitForLoadState();

	const links = page.locator( '.pegwork-form__errors' ).getByRole( 'link' );
	await expect( links ).toHaveText( [
		'Description must be at least 50 words (it has 2).',
		'Type is required.',
		'Project status is required.',
		'Contact email is required.',
		/^Check the box to confirm:/,
	] );
	await expect( links.nth( 1 ) ).toHaveAttribute(
		'href',
		'#pegwork-e2e_project-sifg_project_type-0'
	);
	expect(
		( await entries() ).filter( ( e ) => e.values.name === 'Short' )
	).toHaveLength( 0 );
} );
