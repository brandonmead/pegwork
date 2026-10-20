const { test, expect } = require( '@playwright/test' );
const { authFile } = require( '../utils/env' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );
const { baseURL } = require( '../utils/listings' );
const { TOKEN, setUpDirectory, withParams } = require( '../utils/filters' );

// Phase 2 accessibility gate (docs/phase-2-design.md sections 11 and 12): every display view at 1280 and
// 782 px as a visitor: archive, detail, the directory pattern, no results, filters open. No serious or
// critical axe violations, and no horizontal scroll at 782 px.
test.use( { storageState: { cookies: [], origins: [] } } );
test.describe.configure( { mode: 'serial' } );

const SCREENSHOTS = 'docs/screenshots';
// The theme's header navigation lists every page the specs create; its page-list markup is core's.
const AXE = { exclude: [ '.wp-block-navigation' ] };

let context;
let tracker;
let directory;
let detail;

test.beforeAll( async ( { browser } ) => {
	context = await browser.newContext( { baseURL, storageState: authFile } );
	( { tracker, page: directory } = await setUpDirectory( context ) );
	detail = await tracker.project( 'E2E Display detail', {
		meta: {
			_sifg_project_needs_volunteers: true,
			_sifg_project_volunteer_link: 'https://example.org/volunteer',
		},
	} );
} );

test.afterAll( async () => {
	await tracker?.cleanup();
	await context?.close();
} );

async function noHorizontalScroll( page ) {
	const overflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth -
			document.documentElement.clientWidth
	);
	expect( overflow, 'horizontal overflow in px' ).toBeLessThanOrEqual( 0 );
}

for ( const width of [ 1280, 782 ] ) {
	test.describe( `at ${ width } px`, () => {
		test.beforeEach( async ( { page } ) => {
			await page.setViewportSize( { width, height: 900 } );
		} );

		const views = {
			archive: {
				url: () => '/projects/',
				ready: ( page ) =>
					expect(
						page.locator( '.wp-block-post-title' ).first()
					).toBeVisible(),
			},
			detail: {
				url: () => detail.link,
				ready: ( page ) =>
					expect(
						page.getByRole( 'heading', { level: 1 } )
					).toBeVisible(),
			},
			'directory pattern': {
				url: () => directory.link,
				ready: ( page ) =>
					expect(
						page.locator( '.pegwork-card' ).first()
					).toBeVisible(),
			},
			'no results': {
				url: () =>
					withParams( directory.link, { q: 'nothing-matches-zq' } ),
				ready: ( page ) =>
					expect(
						page.locator( '.pegwork-filters__count' )
					).toHaveText( 'No results' ),
			},
			'filters open': {
				url: () =>
					withParams( directory.link, {
						q: TOKEN,
						impact: 'sdg-04',
					} ),
				ready: async ( page ) => {
					await page.locator( 'summary' ).click();
					await expect( page.locator( 'details' ) ).toHaveAttribute(
						'open',
						''
					);
				},
			},
		};

		for ( const [ name, view ] of Object.entries( views ) ) {
			test( `${ name }: no serious axe violations${
				width === 782 ? ' and no horizontal scroll' : ''
			}`, async ( { page } ) => {
				await page.goto( view.url() );
				await view.ready( page );
				await expectNoSeriousA11yViolations( page, AXE );
				if ( width === 782 ) {
					await noHorizontalScroll( page );
				}
			} );
		}
	} );
}

test( 'the multi-value filter disclosure shows a marker, turns it when open, and has a focus style', async ( {
	page,
} ) => {
	await page.goto(
		withParams( directory.link, { impact: 'sdg-04,sdg-13' } )
	);
	const summary = page.locator( 'summary' ).first();
	await expect( summary ).toHaveText( 'Impact category, 2 selected' );

	const marker = () =>
		summary.evaluate( ( el ) => {
			const style = window.getComputedStyle( el, '::after' );
			return { content: style.content, transform: style.transform };
		} );
	const closed = await marker();
	expect( closed.content ).not.toBe( 'none' );
	await page
		.locator( 'details' )
		.first()
		.screenshot( {
			path: `${ SCREENSHOTS }/filters-disclosure-closed.png`,
		} );

	// Keyboard: Tab to the summary, a visible outline appears, Space opens it natively.
	await page.getByLabel( 'Search projects' ).focus();
	for ( let i = 0; i < 12; i++ ) {
		await page.keyboard.press( 'Tab' );
		if (
			await summary.evaluate(
				( el ) => el === el.ownerDocument.activeElement
			)
		) {
			break;
		}
	}
	await expect( summary ).toBeFocused();
	const outline = await summary.evaluate(
		( el ) => window.getComputedStyle( el ).outlineStyle
	);
	expect( outline ).not.toBe( 'none' );
	await page.keyboard.press( 'Space' );
	await expect( page.locator( 'details' ).first() ).toHaveAttribute(
		'open',
		''
	);
	// The chevron turns (a short transition, so wait for it to finish).
	await expect
		.poll( async () => ( await marker() ).transform )
		.not.toBe( closed.transform );
	await page.waitForTimeout( 300 );
	await page
		.locator( 'details' )
		.first()
		.screenshot( {
			path: `${ SCREENSHOTS }/filters-disclosure-open.png`,
		} );

	// With nothing selected the summary is just the label.
	await page.goto( directory.link );
	await expect( page.locator( 'summary' ).first() ).toHaveText(
		'Impact category'
	);
} );

test( 'horizontal layout: the Needs volunteers checkbox sits at the top of the row, not centered', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( directory.link );
	// Narrow the search box so every control shares one row, next to the tall radio lists.
	await page.addStyleTag( {
		content:
			'.wp-block-pegwork-filters .pegwork-filters__search { flex: 0 1 8rem; } .wp-block-pegwork-filters .pegwork-filters__details { display: none; }',
	} );
	const toggle = page.locator( '.pegwork-filters__toggle' );
	const search = page.getByLabel( 'Search projects' );
	const toggleBox = await toggle.getByRole( 'checkbox' ).boundingBox();
	const searchBox = await search.boundingBox();
	// On the search box's line (the other groups' controls start there), not centered against the lists.
	expect(
		Math.abs(
			toggleBox.y +
				toggleBox.height / 2 -
				searchBox.y -
				searchBox.height / 2
		)
	).toBeLessThan( 20 );
	await page.locator( '.wp-block-pegwork-filters' ).screenshot( {
		path: `${ SCREENSHOTS }/filters-layout-horizontal.png`,
	} );
} );
