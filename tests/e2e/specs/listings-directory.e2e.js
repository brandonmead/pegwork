const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const { createRecord } = require( '../utils/records' );
const {
	baseURL,
	cardTitles,
	enableModule,
	patternContent,
	publishedProjects,
	recordTracker,
	seedProjects,
} = require( '../utils/listings' );

// Listings B (docs/display.md "Directories"): the "Projects directory" Query Loop variation, the
// generated patterns, the featured preset and the published-only rule.

let context;
let tracker;
let seeded;
const hidden = [];

test.describe.configure( { mode: 'serial' } );

test.beforeAll( async ( { browser } ) => {
	context = await browser.newContext( { baseURL, storageState: authFile } );
	await enableModule( await context.newPage(), 'Listings' );
	tracker = recordTracker( context.request );
	seeded = await seedProjects( tracker, 'E2E Dir', 13 );
	await tracker.project( 'E2E Featured newest' );
	// Newer than everything else, and never listed.
	for ( const status of [ 'draft', 'pending', 'private' ] ) {
		hidden.push( `E2E Hidden ${ status }` );
		await tracker.project( `E2E Hidden ${ status }`, { status } );
	}
} );

test.afterAll( async () => {
	await tracker?.cleanup();
	await context?.close();
} );

async function openEditor( page, id ) {
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);
}

// Names of invalid or missing blocks, and of blocks still holding a core/pattern.
function blockProblems( page ) {
	return page.evaluate( () => {
		const problems = [];
		const walk = ( blocks ) => {
			for ( const block of blocks ) {
				if ( ! block.isValid || block.name === 'core/missing' ) {
					problems.push( `invalid ${ block.name }` );
				}
				walk( block.innerBlocks );
			}
		};
		walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return problems;
	} );
}

async function publish( page ) {
	await page.evaluate( async () => {
		const editor = window.wp.data.dispatch( 'core/editor' );
		editor.editPost( { status: 'publish' } );
		await editor.savePost();
	} );
	await page.waitForFunction( () => {
		const editor = window.wp.data.select( 'core/editor' );
		return ! editor.isSavingPost() && ! editor.isEditedPostDirty();
	} );
	return page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getPermalink()
	);
}

test( 'inserting "Projects directory" in the editor renders a working grid with pagination', async ( {
	page,
} ) => {
	// Some content, so the starter patterns modal for empty pages stays closed.
	const draft = await createRecord( page.request, 'pages', {
		title: 'E2E Projects directory',
		content:
			'<!-- wp:paragraph --><p>All projects.</p><!-- /wp:paragraph -->',
	} );
	tracker.track( 'pages', draft.id );
	await openEditor( page, draft.id );

	await page
		.getByRole( 'button', {
			name: /^(Block Inserter|Toggle block inserter)$/,
		} )
		.first()
		.click();
	await page.getByRole( 'searchbox' ).first().fill( 'Projects directory' );
	await page
		.getByRole( 'option', { name: 'Projects directory', exact: true } )
		.click();

	// The variation's core/pattern resolves to the registered card.
	await page.waitForFunction( () => {
		const all = [];
		const walk = ( blocks ) =>
			blocks.forEach( ( b ) => {
				all.push( b );
				walk( b.innerBlocks );
			} );
		walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return (
			all.some( ( b ) => b.name === 'core/post-title' ) &&
			! all.some( ( b ) => b.name === 'core/pattern' )
		);
	} );
	const query = await page.evaluate( () =>
		window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.find( ( b ) => b.name === 'core/query' )
	);
	expect( query.attributes.pegwork ).toEqual( { filters: true } );
	expect( query.attributes.query.postType ).toBe( 'sifg_project' );
	expect( query.attributes.enhancedPagination ).toBe( true );
	expect( await blockProblems( page ) ).toEqual( [] );

	// The editor recognizes the block as the variation (block card, List View).
	expect(
		await page.evaluate(
			( attributes ) =>
				window.wp.data
					.select( 'core/blocks' )
					.getActiveBlockVariation( 'core/query', attributes )?.name,
			query.attributes
		)
	).toBe( 'pegwork/sifg_project-directory' );

	const link = await publish( page );

	// Reloading the editor keeps the attribute and every block valid.
	await openEditor( page, draft.id );
	expect( await blockProblems( page ) ).toEqual( [] );
	expect(
		await page.evaluate(
			() =>
				window.wp.data
					.select( 'core/block-editor' )
					.getBlocks()
					.find( ( b ) => b.name === 'core/query' ).attributes.pegwork
		)
	).toEqual( { filters: true } );

	await page.goto( link );
	const total = await publishedProjects( page.request );
	const first = await cardTitles( page );
	expect( first ).toHaveLength( 12 );
	expect( first ).not.toContain( 'E2E Hidden draft' );
	expect( first[ 0 ] ).toBe( 'E2E Featured newest' );
	await expect(
		page.locator( '[data-pegwork-directory="sifg_project"]' )
	).toHaveAttribute( 'data-wp-router-region', /query-/ );

	await page.locator( '.wp-block-query-pagination-next' ).click();
	await expect
		.poll( async () => ( await cardTitles( page ) )[ 0 ] )
		.not.toBe( first[ 0 ] );
	const second = await cardTitles( page );
	expect( second ).toHaveLength( Math.min( 12, total - 12 ) );
	expect( second.filter( ( t ) => first.includes( t ) ) ).toEqual( [] );
	if ( total <= 24 ) {
		expect( second ).toContain( seeded[ 0 ] );
	}
	await expect( page ).toHaveURL( /query-\d+-page=2/ );
} );

test( 'generated patterns are valid blocks in the editor', async ( {
	page,
} ) => {
	const names = [ 'card', 'details', 'directory', 'featured', 'latest' ].map(
		( kind ) => `pegwork/sifg_project-${ kind }`
	);
	const content = (
		await Promise.all(
			names.map( ( name ) => patternContent( page.request, name ) )
		)
	).join( '\n' );
	const draft = await createRecord( page.request, 'pages', {
		title: 'E2E pattern validity',
		content,
	} );
	tracker.track( 'pages', draft.id );

	await openEditor( page, draft.id );
	await page.waitForFunction(
		() => window.wp.data.select( 'core/block-editor' ).getBlockCount() > 0
	);
	expect( await blockProblems( page ) ).toEqual( [] );
} );

test( 'featured preset shows one record: the newest published when none is chosen', async ( {
	page,
} ) => {
	const featured = await tracker.page(
		'E2E Featured',
		await patternContent( page.request, 'pegwork/sifg_project-featured' )
	);
	// sifg_settings.featured_project is empty here, so the newest published record shows; a newer draft never does.
	await page.goto( featured.link );
	expect( await cardTitles( page ) ).toEqual( [ 'E2E Featured newest' ] );
	await expect( page.locator( '.wp-block-query-pagination' ) ).toHaveCount(
		0
	);
} );

test( 'drafts, pending and private records are never listed, for visitors or administrators', async ( {
	page,
	browser,
} ) => {
	const directory = await tracker.page(
		'E2E Hidden check',
		await patternContent( page.request, 'pegwork/sifg_project-directory' )
	);
	const visitor = await browser.newContext( { baseURL } );
	const anonymous = await visitor.newPage();

	for ( const viewer of [ page, anonymous ] ) {
		for ( const url of [ directory.link, '/projects/' ] ) {
			await viewer.goto( url );
			const text = await viewer.locator( 'main' ).innerText();
			for ( const title of hidden ) {
				expect( text, `${ url }` ).not.toContain( title );
			}
			expect( text ).toContain( 'E2E Featured newest' );
			expect( await viewer.content() ).not.toContain(
				'e2e-secret@example.org'
			);
		}
	}
	await visitor.close();
} );

test( 'the archive and a directory page have no serious axe violations', async ( {
	browser,
} ) => {
	const visitor = await browser.newContext( { baseURL } );
	const anonymous = await visitor.newPage();
	// The theme's header navigation lists every page the specs create; its page-list markup is core's.
	const options = { exclude: [ '.wp-block-navigation' ] };

	await anonymous.goto( '/projects/' );
	await expect(
		anonymous.locator( '[data-pegwork-directory="sifg_project"]' )
	).toHaveCount( 1 );
	await expectNoSeriousA11yViolations( anonymous, options );

	const directory = await tracker.page(
		'E2E Axe directory',
		await patternContent(
			context.request,
			'pegwork/sifg_project-directory'
		)
	);
	await anonymous.goto( directory.link );
	await expect( anonymous.locator( '.pegwork-card' ).first() ).toBeVisible();
	await expectNoSeriousA11yViolations( anonymous, options );
	await visitor.close();
} );
