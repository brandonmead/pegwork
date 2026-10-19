const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const { baseURL, cardTitles } = require( '../utils/listings' );
const {
	TOKEN,
	allProjects,
	countText,
	expected,
	setUpDirectory,
	withParams,
} = require( '../utils/filters' );

// Filters with JavaScript (docs/display.md "Filters"): the Interactivity store navigates with the router,
// which swaps only the Query Loop region. As a visitor.
test.use( { storageState: { cookies: [], origins: [] } } );
test.describe.configure( { mode: 'serial' } );

const PER_PAGE = 12;
const SCREENSHOTS = 'docs/screenshots';

let context;
let tracker;
let directory;
let records;

test.beforeAll( async ( { browser } ) => {
	context = await browser.newContext( { baseURL, storageState: authFile } );
	( { tracker, page: directory } = await setUpDirectory( context ) );
	records = await allProjects( context.request );
} );

test.afterAll( async () => {
	await tracker?.cleanup();
	await context?.close();
} );

const count = ( page ) => page.locator( '.pegwork-filters__count' );
const region = ( page ) =>
	page.locator( '[data-pegwork-directory="sifg_project"]' );

/**
 * Opens the directory, waits for the store to hydrate, and marks the document so a full load is detectable.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {Object<string, string>}          params URL parameters.
 */
async function open( page, params = {} ) {
	await page.goto( withParams( directory.link, params ) );
	await page.waitForFunction(
		() =>
			document.querySelector( 'script[type="importmap"]' ) &&
			window.performance
				.getEntriesByType( 'resource' )
				.some( ( r ) => r.name.includes( 'filters-view.js' ) )
	);
	// Hydration: the store sets no visible change, so wait for the router to be importable and idle.
	await page.waitForLoadState( 'networkidle' );
	await page.evaluate( () => {
		window.pegworkE2eMarker = 'same-document';
	} );
}

async function sameDocument( page ) {
	return page.evaluate( () => window.pegworkE2eMarker === 'same-document' );
}

test( 'changing a filter updates the results without a document load and announces the count', async ( {
	page,
} ) => {
	await open( page, { q: TOKEN } );
	await expect( count( page ) ).toHaveAttribute( 'role', 'status' );
	const loads = [];
	page.on( 'load', () => loads.push( page.url() ) );

	// Hold the router's fetch so the loading state is observable.
	let release;
	const held = new Promise( ( resolve ) => ( release = resolve ) );
	await page.route( /zqfiltertoken.*volunteers=1/, async ( route ) => {
		await held;
		await route.continue();
	} );
	await page.getByLabel( 'Needs volunteers' ).check();
	await expect( region( page ) ).toHaveAttribute( 'aria-busy', 'true' );
	await expect( region( page ) ).toHaveClass( /is-loading/ );
	release();

	const matches = expected( records, { q: TOKEN, volunteers: '1' } );
	await expect( count( page ) ).toHaveText( countText( matches.length ) );
	await expect( region( page ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	await expect( region( page ) ).not.toHaveClass( /is-loading/ );
	expect( await cardTitles( page ) ).toEqual(
		matches.slice( 0, PER_PAGE ).map( ( r ) => r.title )
	);
	expect( new URL( page.url() ).searchParams.get( 'volunteers' ) ).toBe(
		'1'
	);
	expect( await sameDocument( page ) ).toBe( true );
	expect( loads ).toEqual( [] );
	await page.unroute( /zqfiltertoken.*volunteers=1/ );

	// Focus stays on the control that changed.
	await expect( page.getByLabel( 'Needs volunteers' ) ).toBeFocused();

	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-directory-1280.png`,
		fullPage: false,
	} );
} );

test( 'search updates after typing, multi-select summaries update, Clear all resets and focuses search', async ( {
	page,
} ) => {
	await open( page );
	await page.getByLabel( 'Search projects' ).fill( TOKEN );
	// Debounced: the URL changes once typing pauses (the count may not change on a site with no other
	// Projects).
	await expect( page ).toHaveURL( /[?&]q=zqfiltertoken/ );
	await expect( count( page ) ).toHaveText(
		countText( expected( records, { q: TOKEN } ).length )
	);

	await page.locator( 'summary' ).click();
	await page.getByLabel( 'Climate Action' ).check();
	await page.getByLabel( 'Life on Land' ).check();
	await expect( page.locator( 'summary' ) ).toHaveText(
		'Impact category, 2 selected'
	);
	const matches = expected( records, { q: TOKEN, impact: 'sdg-13,sdg-15' } );
	await expect( count( page ) ).toHaveText( countText( matches.length ) );
	expect( page.url() ).toContain( 'impact=sdg-13,sdg-15' );
	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-impact-open.png`,
	} );

	const clear = page.getByRole( 'link', { name: 'Clear all' } );
	await expect( clear ).toBeVisible();
	await clear.click();
	await expect( page.getByLabel( 'Search projects' ) ).toBeFocused();
	await expect( page.getByLabel( 'Search projects' ) ).toHaveValue( '' );
	await expect( page.getByLabel( 'Climate Action' ) ).not.toBeChecked();
	await expect( count( page ) ).toHaveText( countText( records.length ) );
	await expect( clear ).toBeHidden();
	await expect( page ).toHaveURL( directory.link );
	expect( await sameDocument( page ) ).toBe( true );
} );

test( 'back and forward restore the controls and the results', async ( {
	page,
} ) => {
	await open( page, { q: TOKEN } );
	const all = await cardTitles( page );

	await page
		.getByRole( 'group', { name: 'Type' } )
		.getByLabel( 'Corporation' )
		.check();
	const byType = expected( records, {
		q: TOKEN,
		project_type: 'corporation',
	} );
	await expect( count( page ) ).toHaveText( countText( byType.length ) );

	await page.getByLabel( 'Needs volunteers' ).check();
	const both = expected( records, {
		q: TOKEN,
		project_type: 'corporation',
		volunteers: '1',
	} );
	await expect( count( page ) ).toHaveText( countText( both.length ) );

	await page.goBack();
	await expect( page.getByLabel( 'Needs volunteers' ) ).not.toBeChecked();
	await expect(
		page.getByRole( 'group', { name: 'Type' } ).getByLabel( 'Corporation' )
	).toBeChecked();
	await expect
		.poll( () => cardTitles( page ) )
		.toEqual( byType.map( ( r ) => r.title ) );
	await expect( count( page ) ).toHaveText( countText( byType.length ) );

	await page.goBack();
	await expect(
		page.getByRole( 'group', { name: 'Type' } ).getByLabel( 'Any' )
	).toBeChecked();
	await expect.poll( () => cardTitles( page ) ).toEqual( all );

	await page.goForward();
	await expect(
		page.getByRole( 'group', { name: 'Type' } ).getByLabel( 'Corporation' )
	).toBeChecked();
	await expect
		.poll( () => cardTitles( page ) )
		.toEqual( byType.map( ( r ) => r.title ) );
	expect( await sameDocument( page ) ).toBe( true );
} );

test( 'enhanced pagination stays client-side and keeps the filters', async ( {
	page,
} ) => {
	await open( page, { q: TOKEN } );
	const matches = expected( records, { q: TOKEN } );
	await region( page ).locator( '.wp-block-query-pagination-next' ).click();
	await expect
		.poll( () => cardTitles( page ) )
		.toEqual(
			matches.slice( PER_PAGE, PER_PAGE * 2 ).map( ( r ) => r.title )
		);
	const url = new URL( page.url() );
	expect( url.searchParams.get( 'q' ) ).toBe( TOKEN );
	expect( [ ...url.searchParams.keys() ].join( ',' ) ).toMatch(
		/query-\d+-page/
	);
	expect( await sameDocument( page ) ).toBe( true );

	// A filter change from page 2 starts again at page 1.
	await page.getByLabel( 'Needs volunteers' ).check();
	const filtered = expected( records, { q: TOKEN, volunteers: '1' } );
	await expect( count( page ) ).toHaveText( countText( filtered.length ) );
	expect( page.url() ).not.toMatch( /query-\d+-page/ );
	expect( await cardTitles( page ) ).toEqual(
		filtered.slice( 0, PER_PAGE ).map( ( r ) => r.title )
	);
} );

test( 'keyboard only: search, radios, checkbox and Apply', async ( {
	page,
} ) => {
	await open( page );
	await page.getByLabel( 'Search projects' ).focus();
	await page.keyboard.type( TOKEN );
	await expect( page ).toHaveURL( /[?&]q=zqfiltertoken/ );
	await expect( count( page ) ).toHaveText(
		countText( expected( records, { q: TOKEN } ).length )
	);

	// Tab into the Type radios ("Any" is checked), arrow to the next option.
	await page.keyboard.press( 'Tab' );
	await expect(
		page.getByRole( 'group', { name: 'Type' } ).getByLabel( 'Any' )
	).toBeFocused();
	await page.keyboard.press( 'ArrowDown' );
	const second = page
		.getByRole( 'group', { name: 'Type' } )
		.getByRole( 'radio' )
		.nth( 1 );
	await expect( second ).toBeChecked();
	const slug = await second.getAttribute( 'value' );
	await expect( page ).toHaveURL( new RegExp( `project_type=${ slug }` ) );
	await expect( count( page ) ).toHaveText(
		countText(
			expected( records, { q: TOKEN, project_type: slug } ).length
		)
	);

	// Tab past Status into the impact disclosure, open it with Enter, check with Space.
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Tab' );
	await expect( page.locator( 'summary' ) ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Space' );
	await expect( page.locator( 'summary' ) ).toHaveText(
		'Impact category, 1 selected'
	);
	await expect( count( page ) ).toHaveText(
		countText(
			expected( records, {
				q: TOKEN,
				project_type: slug,
				impact: 'sdg-01',
			} ).length
		)
	);

	// Enter in the search box submits in place.
	await page.getByLabel( 'Search projects' ).focus();
	await page.keyboard.press( 'Enter' );
	expect( await sameDocument( page ) ).toBe( true );
} );

for ( const width of [ 1280, 782 ] ) {
	test( `no serious axe violations at ${ width } px`, async ( { page } ) => {
		await page.setViewportSize( { width, height: 900 } );
		await open( page, { q: TOKEN, impact: 'sdg-04' } );
		await page.locator( 'summary' ).click();
		await expectNoSeriousA11yViolations( page, {
			exclude: [ '.wp-block-navigation' ],
		} );
		await page.screenshot( {
			path: `${ SCREENSHOTS }/filters-directory-${ width }-axe.png`,
			fullPage: true,
		} );
	} );
}

test( 'the no-results message offers Clear all filters', async ( { page } ) => {
	await open( page, { q: 'nothing-matches-zq' } );
	await expect( count( page ) ).toHaveText( 'No results' );
	const link = region( page ).getByRole( 'link', {
		name: 'Clear all filters',
	} );
	await expect( link ).toHaveAttribute( 'href', directory.link );
	await page.screenshot( {
		path: `${ SCREENSHOTS }/filters-no-results.png`,
	} );
} );
