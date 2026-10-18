const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { noJs } = require( '../utils/no-js' );
const {
	baseURL,
	cardTitles,
	enableModule,
	patternContent,
	publishedProjects,
	recordTracker,
	seedProjects,
} = require( '../utils/listings' );

// A Pegwork directory with JavaScript off, as a visitor: the grid and its pagination are plain links.
test.use( noJs );
test.describe.configure( { mode: 'serial' } );

let context;
let tracker;
let directory;

test.beforeAll( async ( { browser } ) => {
	context = await browser.newContext( { baseURL, storageState: authFile } );
	await enableModule( await context.newPage(), 'Listings' );
	tracker = recordTracker( context.request );
	await seedProjects( tracker, 'E2E NoJS', 13 );
	directory = await tracker.page(
		'E2E No-JS directory',
		await patternContent(
			context.request,
			'pegwork/sifg_project-directory'
		)
	);
} );

test.afterAll( async () => {
	await tracker?.cleanup();
	await context?.close();
} );

test( 'the directory paginates by links with JavaScript off', async ( {
	page,
} ) => {
	const total = await publishedProjects( page.request );
	const response = await page.goto( directory.link );
	expect( response.status() ).toBe( 200 );

	const first = await cardTitles( page );
	expect( first ).toHaveLength( 12 );

	const next = page.locator( '.wp-block-query-pagination-next' );
	await expect( next ).toHaveAttribute( 'href', /query-\d+-page=2/ );
	await Promise.all( [
		page.waitForURL( /query-\d+-page=2/ ),
		next.click(),
	] );

	const second = await cardTitles( page );
	expect( second ).toHaveLength( Math.min( 12, total - 12 ) );
	expect( second.filter( ( title ) => first.includes( title ) ) ).toEqual(
		[]
	);
	await expect(
		page.locator( '.wp-block-query-pagination-previous' )
	).toBeVisible();
} );

test( 'the archive lists Projects and pages with JavaScript off', async ( {
	page,
} ) => {
	await page.goto( '/projects/' );
	const directoryRegion = page.locator(
		'[data-pegwork-directory="sifg_project"]'
	);
	await expect( directoryRegion ).toHaveCount( 1 );
	const first = await directoryRegion
		.locator( '.wp-block-post-title' )
		.allInnerTexts();
	expect( first.length ).toBeGreaterThan( 0 );

	const response = await page.goto( '/projects/page/2/' );
	expect( response.status() ).toBe( 200 );
	const second = await directoryRegion
		.locator( '.wp-block-post-title' )
		.allInnerTexts();
	expect( second.length ).toBeGreaterThan( 0 );
	expect( second.filter( ( title ) => first.includes( title ) ) ).toEqual(
		[]
	);
} );
