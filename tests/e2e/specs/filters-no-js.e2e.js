const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { noJs } = require( '../utils/no-js' );
const { baseURL, cardTitles } = require( '../utils/listings' );
const {
	TOKEN,
	allProjects,
	combinations,
	countText,
	expected,
	setUpDirectory,
	withParams,
} = require( '../utils/filters' );

// Filters with JavaScript off, as a visitor (docs/display.md "Filters"): a GET form, the URL is the only
// state. Expected results come from REST data (allProjects), so the spec works with or without the
// 500-record perf fixture.
test.use( noJs );
test.describe.configure( { mode: 'serial' } );

const PER_PAGE = 12;

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

test( 'every filter and search combination works by GET on the directory page', async ( {
	page,
} ) => {
	for ( const params of combinations() ) {
		const label = JSON.stringify( params );
		const response = await page.goto(
			withParams( directory.link, params )
		);
		expect( response.status(), label ).toBe( 200 );

		const matches = expected( records, params );
		await expect( count( page ), label ).toHaveText(
			countText( matches.length )
		);
		expect( await cardTitles( page ), label ).toEqual(
			matches.slice( 0, PER_PAGE ).map( ( r ) => r.title )
		);
	}
} );

test( 'every combination filters the archive main query by GET', async ( {
	page,
} ) => {
	for ( const params of combinations() ) {
		const label = JSON.stringify( params );
		const response = await page.goto(
			withParams( `${ baseURL }/projects/`, params )
		);
		const matches = expected( records, params );
		if ( matches.length === 0 ) {
			// No match: the archive template shows its no-results state.
			expect( await cardTitles( page ), label ).toEqual( [] );
			continue;
		}
		expect( response.status(), label ).toBe( 200 );
		const titles = await cardTitles( page );
		expect( titles.length, label ).toBeGreaterThan( 0 );
		expect( titles, label ).toEqual(
			matches.slice( 0, titles.length ).map( ( r ) => r.title )
		);
	}
} );

test( 'a filtered URL reloads to the same results and controls', async ( {
	page,
} ) => {
	const url = withParams( directory.link, {
		impact: 'sdg-04,sdg-15',
		volunteers: '1',
		q: TOKEN,
	} );
	await page.goto( url );
	const first = await cardTitles( page );
	const text = await count( page ).textContent();
	await page.reload();
	expect( await cardTitles( page ) ).toEqual( first );
	await expect( count( page ) ).toHaveText( text );
	await expect( page.getByLabel( 'Needs volunteers' ) ).toBeChecked();
	await expect( page.getByLabel( 'Search projects' ) ).toHaveValue( TOKEN );
	await expect( page.locator( 'summary' ) ).toHaveText(
		'Impact category, 2 selected'
	);
	await expect( page.getByLabel( 'Quality Education' ) ).toBeChecked();

	// A fresh visitor (shared link) sees the same.
	const other = await page.context().browser().newContext( noJs );
	const shared = await other.newPage();
	await shared.goto( url );
	expect( await cardTitles( shared ) ).toEqual( first );
	await other.close();
} );

test( 'the form submits GET and Clear all returns to the base URL', async ( {
	page,
} ) => {
	await page.goto( directory.link );
	await expect(
		page.getByRole( 'link', { name: 'Clear all' } )
	).toBeHidden();
	await page.getByLabel( 'Search projects' ).fill( TOKEN );
	await page
		.getByRole( 'group', { name: 'Type' } )
		.getByLabel( 'Nonprofit' )
		.check();
	await page.getByLabel( 'Needs volunteers' ).check();
	await Promise.all( [
		page.waitForURL( /project_type=nonprofit/ ),
		page.getByRole( 'button', { name: 'Apply filters' } ).click(),
	] );

	const url = new URL( page.url() );
	expect( url.searchParams.get( 'q' ) ).toBe( TOKEN );
	expect( url.searchParams.get( 'volunteers' ) ).toBe( '1' );
	const matches = expected( records, {
		project_type: 'nonprofit',
		volunteers: '1',
		q: TOKEN,
	} );
	await expect( count( page ) ).toHaveText( countText( matches.length ) );
	expect( await cardTitles( page ) ).toEqual(
		matches.map( ( r ) => r.title )
	);

	await Promise.all( [
		page.waitForURL( directory.link ),
		page.getByRole( 'link', { name: 'Clear all' } ).click(),
	] );
	await expect( count( page ) ).toHaveText( countText( records.length ) );
} );

test( 'bad and private parameters are ignored', async ( { page } ) => {
	await page.goto( directory.link );
	const all = await cardTitles( page );

	const response = await page.goto(
		`${ directory.link }?contact_email=e2e-secret%40example.org&_sifg_project_contact_email=x&meta_key=_sifg_project_contact_email&meta_value=x&impact=sdg-99,%3Cscript%3E&volunteers=2&project_type[]=nope&post_status=draft&sifg_project_type=corporation`
	);
	expect( response.status() ).toBe( 200 );
	expect( await cardTitles( page ) ).toEqual( all );
	await expect( count( page ) ).toHaveText( countText( records.length ) );
	await expect(
		page.getByRole( 'link', { name: 'Clear all' } )
	).toBeHidden();
	expect( await page.content() ).not.toContain( 'e2e-secret@example.org' );

	const archive = await page.goto(
		`${ baseURL }/projects/?contact_email=e2e-secret%40example.org&meta_key=_sifg_project_needs_volunteers`
	);
	expect( archive.status() ).toBe( 200 );
	expect( await page.content() ).not.toContain( 'e2e-secret@example.org' );
} );

test( 'pagination keeps the filters; a filter change starts again at page 1', async ( {
	page,
} ) => {
	await page.goto( withParams( directory.link, { q: TOKEN } ) );
	const next = page.locator( '.wp-block-query-pagination-next' );
	await Promise.all( [
		page.waitForURL( /query-\d+-page=2/ ),
		next.click(),
	] );
	const paged = new URL( page.url() );
	expect( paged.searchParams.get( 'q' ) ).toBe( TOKEN );
	const matches = expected( records, { q: TOKEN } );
	expect( await cardTitles( page ) ).toEqual(
		matches.slice( PER_PAGE, PER_PAGE * 2 ).map( ( r ) => r.title )
	);
	await expect( page.getByLabel( 'Search projects' ) ).toHaveValue( TOKEN );

	await page.getByLabel( 'Needs volunteers' ).check();
	await Promise.all( [
		page.waitForURL( /volunteers=1/ ),
		page.getByRole( 'button', { name: 'Apply filters' } ).click(),
	] );
	expect( page.url() ).not.toMatch( /query-\d+-page/ );
	const filtered = expected( records, { q: TOKEN, volunteers: '1' } );
	expect( await cardTitles( page ) ).toEqual(
		filtered.slice( 0, PER_PAGE ).map( ( r ) => r.title )
	);
} );
