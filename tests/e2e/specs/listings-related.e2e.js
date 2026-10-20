const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const { createRecord } = require( '../utils/records' );
const {
	baseURL,
	enableModule,
	patternContent,
	projectData,
	recordTracker,
} = require( '../utils/listings' );

// Related records (docs/display.md "Related records"): the `where.related` preset, the "Show if"
// relationship condition and the generated "<Type> related <other type>" patterns, both directions
// of `story_projects` (Story to Project).

// The theme's header navigation lists every page the specs create; its page-list markup is core's.
const AXE = { exclude: [ '.wp-block-navigation' ] };

let context;
let tracker;
const records = {};

test.describe.configure( { mode: 'serial' } );

const paragraph = ( text ) =>
	`<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;

// The section's card titles, in order.
const relatedTitles = ( page ) =>
	page.locator( '.pegwork-related .wp-block-post-title' ).allInnerTexts();

test.beforeAll( async ( { browser } ) => {
	context = await browser.newContext( { baseURL, storageState: authFile } );
	const { request } = context;
	await enableModule( await context.newPage(), 'Listings' );
	tracker = recordTracker( request );

	const onProject = await patternContent(
		request,
		'pegwork/sifg_project-related-story_projects'
	);
	const onStory = await patternContent(
		request,
		'pegwork/sifg_story-related-story_projects'
	);

	// Projects carry the "Related stories" section in their content, so it renders on their single page.
	for ( const [ key, title ] of [
		[ 'linked', 'E2E Rel linked project' ],
		[ 'lonely', 'E2E Rel lonely project' ],
	] ) {
		const data = await projectData( request, title );
		data.content += onProject;
		records[ key ] = await createRecord( request, 'projects', data );
		tracker.track( 'projects', records[ key ].id );
	}

	const story = async ( key, title, extra ) => {
		records[ key ] = await createRecord( request, 'stories', {
			title,
			status: 'publish',
			content: paragraph( `${ title } told.` ) + onStory,
			meta: { _rel_story_projects: [ records.linked.id ] },
			...extra,
		} );
		tracker.track( 'stories', records[ key ].id );
	};
	await story( 'older', 'E2E Rel older story', {
		date: '2002-01-01T10:00:00',
	} );
	await story( 'newer', 'E2E Rel newer story', {
		date: '2002-02-01T10:00:00',
	} );
	await story( 'draft', 'E2E Rel draft story', { status: 'draft' } );
} );

test.afterAll( async () => {
	await tracker?.cleanup();
	await context?.close();
} );

test( 'a Project lists its published Stories; a Story lists its Projects; nothing related hides the section', async ( {
	browser,
} ) => {
	const visitor = await browser.newContext( { baseURL } );
	const page = await visitor.newPage();

	await page.goto( records.linked.link );
	await expect(
		page.getByRole( 'heading', { name: 'Related stories', level: 2 } )
	).toBeVisible();
	expect( await relatedTitles( page ) ).toEqual( [
		'E2E Rel newer story',
		'E2E Rel older story',
	] );
	expect( await page.content() ).not.toContain( 'E2E Rel draft story' );
	expect( await page.content() ).not.toContain( 'e2e-secret@example.org' );

	await page.goto( records.lonely.link );
	await expect( page.locator( '.pegwork-related' ) ).toHaveCount( 0 );
	expect( await page.content() ).not.toContain( 'Related stories' );

	await page.goto( records.newer.link );
	await expect(
		page.getByRole( 'heading', { name: 'Related projects', level: 2 } )
	).toBeVisible();
	expect( await relatedTitles( page ) ).toEqual( [
		'E2E Rel linked project',
	] );

	// On the archive (the theme shows each Project's content), each section follows its own Project:
	// never another Project's Stories, never every Story.
	await page.goto( '/projects/' );
	const allowed = [ 'E2E Rel newer story', 'E2E Rel older story' ];
	expect(
		( await relatedTitles( page ) ).filter(
			( title ) => ! allowed.includes( title )
		)
	).toEqual( [] );
	await visitor.close();
} );

test( 'keyboard only: Tab reaches a related Story and Enter opens it', async ( {
	browser,
} ) => {
	const visitor = await browser.newContext( { baseURL } );
	const page = await visitor.newPage();
	await page.goto( records.linked.link );

	const inSection = async () =>
		( await page.locator( '.pegwork-related a:focus' ).count() ) > 0;
	for ( let i = 0; i < 80 && ! ( await inSection() ); i++ ) {
		await page.keyboard.press( 'Tab' );
	}
	expect( await inSection() ).toBe( true );
	await expect( page.locator( ':focus' ) ).toHaveText(
		'E2E Rel newer story'
	);
	await page.keyboard.press( 'Enter' );
	await expect( page ).toHaveURL( records.newer.link );
	await expect(
		page.getByRole( 'heading', { name: 'Related projects', level: 2 } )
	).toBeVisible();
	await visitor.close();
} );

test( 'related sections have no serious axe violations at 1280 and 782 px and no horizontal scroll at 782 px', async ( {
	browser,
} ) => {
	const visitor = await browser.newContext( { baseURL } );
	const page = await visitor.newPage();
	for ( const width of [ 1280, 782 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		for ( const key of [ 'linked', 'newer' ] ) {
			await page.goto( records[ key ].link );
			await expect( page.locator( '.pegwork-related' ) ).toBeVisible();
			await expectNoSeriousA11yViolations( page, AXE );
			if ( width === 782 ) {
				expect(
					await page.evaluate(
						() =>
							document.documentElement.scrollWidth <=
							document.documentElement.clientWidth
					)
				).toBe( true );
			}
		}
		await page.goto( records.linked.link );
		await page.locator( '.pegwork-related' ).screenshot( {
			path: `test-results/listings-related-front-${ width }.png`,
		} );
	}
	await visitor.close();
} );

test( 'editor: the related pattern is valid, the Query Loop panel sets the preset, Show if offers the relationship', async ( {
	page,
} ) => {
	const draft = await createRecord( page.request, 'pages', {
		title: 'E2E Related editor',
		content: paragraph( 'Related records.' ),
	} );
	tracker.track( 'pages', draft.id );
	await page.goto( `/wp-admin/post.php?post=${ draft.id }&action=edit` );
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);

	// Insert the generated pattern as the editor would, then check every block parses as valid.
	const content = await patternContent(
		page.request,
		'pegwork/sifg_project-related-story_projects'
	);
	const problems = await page.evaluate( ( markup ) => {
		const { parse } = window.wp.blocks;
		window.wp.data
			.dispatch( 'core/block-editor' )
			.insertBlocks( parse( markup ) );
		const found = [];
		const walk = ( blocks ) =>
			blocks.forEach( ( block ) => {
				if ( ! block.isValid || block.name === 'core/missing' ) {
					found.push( block.name );
				}
				walk( block.innerBlocks );
			} );
		walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return found;
	}, content );
	expect( problems ).toEqual( [] );

	const clientIdOf = ( name ) =>
		page.evaluate( ( blockName ) => {
			const all = [];
			const walk = ( blocks ) =>
				blocks.forEach( ( block ) => {
					all.push( block );
					walk( block.innerBlocks );
				} );
			walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
			return all.find( ( block ) => block.name === blockName ).clientId;
		}, name );
	const select = async ( name ) => {
		const clientId = await clientIdOf( name );
		await page.evaluate( ( id ) => {
			window.wp.data.dispatch( 'core/block-editor' ).selectBlock( id );
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar( 'edit-post/block' );
		}, clientId );
		return clientId;
	};
	const sidebar = page.locator( '.interface-complementary-area' );

	// The pattern's loop: the preset shows as chosen; clear it and choose it again from the panel.
	const queryId = await select( 'core/query' );
	const preset = sidebar.getByLabel( 'Only records linked to' );
	await expect( preset ).toHaveValue( 'story_projects' );
	await preset.selectOption( '' );
	const attribute = () =>
		page.evaluate(
			( id ) =>
				window.wp.data
					.select( 'core/block-editor' )
					.getBlockAttributes( id ).pegwork,
			queryId
		);
	expect( ( await attribute() ).where ).toBeUndefined();
	await preset.focus();
	await preset.selectOption( {
		label: 'The Project being viewed (story_projects)',
	} );
	expect( await attribute() ).toEqual( {
		where: { related: 'story_projects' },
	} );
	await preset.scrollIntoViewIfNeeded();
	await sidebar.screenshot( {
		path: 'test-results/listings-related-editor-query.png',
	} );

	// The Show if block around the section names the relationship condition.
	await select( 'pegwork/conditional' );
	// On a page the block cannot tell the record's type; pick it to list its conditions.
	const typePicker = sidebar.getByLabel( 'Content type' );
	if ( await typePicker.isVisible().catch( () => false ) ) {
		await typePicker.selectOption( 'sifg_project' );
	}
	await expect( sidebar.getByLabel( 'Condition 1' ) ).toHaveValue(
		'relationship:story_projects'
	);
	await expect(
		sidebar.getByLabel( 'Condition 1' ).locator( 'option:checked' )
	).toHaveText( 'Related Stories' );
	await expect(
		page
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.getByText( 'Show if: Related Stories is not empty' )
			.or( page.getByText( 'Show if: Related Stories is not empty' ) )
	).toBeVisible();
	await sidebar.screenshot( {
		path: 'test-results/listings-related-editor-condition.png',
	} );
} );
