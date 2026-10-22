const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const { baseURL, patternContent } = require( '../utils/listings' );
const { TOKEN, setUpDirectory, withParams } = require( '../utils/filters' );

// "Collapse on small screens" (docs/display.md "Filters"): the whole filter form in a native details,
// closed by default, open when a filter is active, under 600 px. Off by default. As a visitor.
test.use( { storageState: { cookies: [], origins: [] } } );
test.describe.configure( { mode: 'serial' } );

const SCREENSHOTS = 'docs/screenshots';
const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1280, height: 900 };

let context;
let tracker;
let plain;
let collapsed;

test.beforeAll( async ( { browser } ) => {
	context = await browser.newContext( { baseURL, storageState: authFile } );
	let directory;
	( { tracker, page: directory } = await setUpDirectory( context ) );
	plain = directory.link;
	// The same directory with the block option switched on.
	const content = await patternContent(
		context.request,
		'pegwork/sifg_project-directory'
	);
	expect( content, 'directory markup has a filters block' ).toContain(
		'wp:pegwork/filters'
	);
	const page = await tracker.page(
		'E2E Filters directory collapsed',
		content.replace(
			/<!-- wp:pegwork\/filters \{/,
			'<!-- wp:pegwork/filters {"collapseOnSmall":true,'
		)
	);
	collapsed = page.link;
} );

test.afterAll( async () => {
	await tracker?.cleanup();
	await context?.close();
} );

const summary = ( page ) =>
	page.locator( '.pegwork-filters__collapse-summary' );
const details = ( page ) => page.locator( '.pegwork-filters__collapse' );
const search = ( page ) => page.getByRole( 'searchbox' );

async function open( page, link, params = {} ) {
	await page.goto( withParams( link, params ) );
	await page.waitForLoadState( 'networkidle' );
}

test( 'off by default: no disclosure at 390 px', async ( { page } ) => {
	await page.setViewportSize( PHONE );
	await open( page, plain );
	await expect( details( page ) ).toHaveCount( 0 );
	await expect( search( page ) ).toBeVisible();
	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-collapse-off-390.png`,
	} );
} );

test( 'closed at 390 px, open when a filter is active, count in the summary', async ( {
	page,
} ) => {
	await page.setViewportSize( PHONE );
	await open( page, collapsed );
	await expect( summary( page ) ).toHaveText( 'Filters' );
	await expect( details( page ) ).not.toHaveAttribute( 'open', '' );
	await expect( search( page ) ).toBeHidden();
	await expect( page.locator( '.pegwork-filters__count' ) ).toBeVisible();
	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-collapse-closed-390.png`,
	} );
	await expectNoSeriousA11yViolations( page, {
		exclude: [ '#wpadminbar', '.wp-block-navigation' ],
	} );

	await open( page, collapsed, { q: TOKEN, volunteers: '1' } );
	await expect( details( page ) ).toHaveAttribute( 'open', '' );
	await expect( summary( page ) ).toHaveText( 'Filters, 2 active' );
	await expect( search( page ) ).toBeVisible();
	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-collapse-active-390.png`,
	} );
	await expectNoSeriousA11yViolations( page, {
		exclude: [ '#wpadminbar', '.wp-block-navigation' ],
	} );
} );

test( 'keyboard: the summary toggles with Enter and Space, has a visible focus style, and the summary follows changes', async ( {
	page,
} ) => {
	await page.setViewportSize( PHONE );
	await open( page, collapsed );
	await summary( page ).focus();
	await expect( summary( page ) ).toBeFocused();
	const outline = await summary( page ).evaluate(
		( element ) => window.getComputedStyle( element ).outlineStyle
	);
	expect( outline, 'visible focus outline' ).not.toBe( 'none' );

	await page.keyboard.press( 'Enter' );
	await expect( details( page ) ).toHaveAttribute( 'open', '' );
	await page.keyboard.press( 'Tab' );
	await expect( search( page ) ).toBeFocused();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Space' );
	await expect( details( page ) ).not.toHaveAttribute( 'open', '' );
	await page.keyboard.press( 'Enter' );
	await expect( details( page ) ).toHaveAttribute( 'open', '' );
	await expectNoSeriousA11yViolations( page, {
		exclude: [ '#wpadminbar', '.wp-block-navigation' ],
	} );

	// With the script, a change updates the summary and keeps the disclosure open.
	await page.getByLabel( 'Needs volunteers' ).check();
	await expect( summary( page ) ).toHaveText( 'Filters, 1 active' );
	await expect( details( page ) ).toHaveAttribute( 'open', '' );
	await search( page ).fill( TOKEN );
	await expect( summary( page ) ).toHaveText( 'Filters, 2 active' );
	await page.getByRole( 'link', { name: 'Clear all' } ).click();
	await expect( summary( page ) ).toHaveText( 'Filters' );
	await expect( details( page ) ).toHaveAttribute( 'open', '' );
} );

test( 'without JavaScript: closed at 390 px, opens natively, submits', async ( {
	browser,
} ) => {
	const bare = await browser.newContext( {
		baseURL,
		javaScriptEnabled: false,
		viewport: PHONE,
	} );
	const page = await bare.newPage();
	await open( page, collapsed );
	await expect( search( page ) ).toBeHidden();
	await summary( page ).click();
	await expect( search( page ) ).toBeVisible();
	await search( page ).fill( TOKEN );
	await page.getByRole( 'button', { name: 'Apply filters' } ).click();
	await expect( page ).toHaveURL( new RegExp( `q=${ TOKEN }` ) );
	await expect( details( page ) ).toHaveAttribute( 'open', '' );
	await expect( summary( page ) ).toHaveText( 'Filters, 1 active' );
	await bare.close();
} );

test( 'at 1280 px the controls show plainly, with no summary', async ( {
	page,
} ) => {
	await page.setViewportSize( DESKTOP );
	await open( page, collapsed );
	await expect( summary( page ) ).toBeHidden();
	await expect( search( page ) ).toBeVisible();
	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-collapse-1280.png`,
	} );
	await expectNoSeriousA11yViolations( page, {
		exclude: [ '#wpadminbar', '.wp-block-navigation' ],
	} );
} );
