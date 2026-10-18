const { baseURL } = require( './env' );
const {
	createRecord,
	deleteRecord,
	restNonce,
	restUrl,
} = require( './records' );

/**
 * Helpers for the listings specs: module toggle, published Projects, pages, patterns.
 */

const DESCRIPTION = Array.from( { length: 60 }, ( _, i ) => `word${ i }` ).join(
	' '
);

/**
 * Enables a Pegwork module on the Modules screen when it is disabled.
 *
 * @param {import('@playwright/test').Page} page  Logged-in page.
 * @param {string}                          label Module label, e.g. "Listings".
 */
async function enableModule( page, label ) {
	await page.goto( '/wp-admin/admin.php?page=pegwork-modules' );
	const enable = page.getByRole( 'button', {
		name: `Enable ${ label }`,
		exact: true,
	} );
	if ( await enable.isVisible().catch( () => false ) ) {
		await Promise.all( [
			page.waitForURL( /pegwork-modules/ ),
			enable.click(),
		] );
	}
}

/**
 * Term IDs by slug.
 *
 * @param {import('@playwright/test').APIRequestContext} request  Logged-in request context.
 * @param {string}                                       taxonomy Taxonomy key (also its REST base).
 * @param {string[]}                                     slugs    Slugs.
 * @return {Promise<number[]>} IDs.
 */
async function termIds( request, taxonomy, slugs ) {
	const res = await request.get( restUrl( `/wp/v2/${ taxonomy }` ), {
		headers: { 'X-WP-Nonce': await restNonce( request ) },
		params: { slug: slugs.join( ',' ), per_page: '100' },
	} );
	if ( ! res.ok() ) {
		throw new Error( `termIds ${ taxonomy }: ${ res.status() }` );
	}
	return ( await res.json() ).map( ( term ) => term.id );
}

// Term IDs of the Type and Status every seeded Project gets, looked up once.
let terms = null;

/**
 * Data for a valid Project (passes publish validation).
 *
 * @param {import('@playwright/test').APIRequestContext} request Logged-in request context.
 * @param {string}                                       title   Title.
 * @param {Object}                                       extra   Extra post fields (status, date, meta).
 * @return {Promise<Object>} REST body.
 */
async function projectData( request, title, extra = {} ) {
	if ( ! terms ) {
		const [ type ] = await termIds( request, 'sifg_project_type', [
			'nonprofit',
		] );
		const [ status ] = await termIds( request, 'sifg_project_status', [
			'active',
		] );
		terms = { type, status };
	}
	const { type, status } = terms;
	return {
		title,
		status: 'publish',
		content: `<!-- wp:paragraph --><p>${ title } ${ DESCRIPTION }</p><!-- /wp:paragraph -->`,
		sifg_project_type: [ type ],
		sifg_project_status: [ status ],
		...extra,
		meta: {
			_sifg_project_contact_email: 'e2e-secret@example.org',
			...( extra.meta || {} ),
		},
	};
}

/**
 * Creates records and pages, and deletes them all at the end.
 *
 * @param {import('@playwright/test').APIRequestContext} request Logged-in request context.
 */
function recordTracker( request ) {
	const created = [];
	return {
		async project( title, extra = {} ) {
			const record = await createRecord(
				request,
				'projects',
				await projectData( request, title, extra )
			);
			created.push( [ 'projects', record.id ] );
			return record;
		},
		async page( title, content ) {
			const record = await createRecord( request, 'pages', {
				title,
				content,
				status: 'publish',
			} );
			created.push( [ 'pages', record.id ] );
			return record;
		},
		track( restBase, id ) {
			created.push( [ restBase, id ] );
		},
		async cleanup() {
			for ( const [ restBase, id ] of created.reverse() ) {
				await deleteRecord( request, restBase, id );
			}
		},
	};
}

/**
 * Creates `count` published Projects titled "<prefix> 01"..., dated in 2001 so they sort after
 * anything newer, and returns their titles.
 *
 * @param {Object} tracker From recordTracker().
 * @param {string} prefix  Title prefix.
 * @param {number} count   How many.
 * @return {Promise<string[]>} Titles.
 */
async function seedProjects( tracker, prefix, count ) {
	const titles = [];
	for ( let i = 1; i <= count; i++ ) {
		const title = `${ prefix } ${ String( i ).padStart( 2, '0' ) }`;
		await tracker.project( title, {
			date: `2001-01-${ String( i ).padStart( 2, '0' ) }T10:00:00`,
		} );
		titles.push( title );
	}
	return titles;
}

/**
 * Number of published Projects.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @return {Promise<number>} Total.
 */
async function publishedProjects( request ) {
	const res = await request.get( restUrl( '/wp/v2/projects' ), {
		params: { per_page: '1', status: 'publish' },
	} );
	return Number( res.headers()[ 'x-wp-total' ] );
}

/**
 * Content of a registered block pattern.
 *
 * @param {import('@playwright/test').APIRequestContext} request Logged-in request context.
 * @param {string}                                       name    Pattern name.
 * @return {Promise<string>} Block markup.
 */
async function patternContent( request, name ) {
	const res = await request.get(
		restUrl( '/wp/v2/block-patterns/patterns' ),
		{ headers: { 'X-WP-Nonce': await restNonce( request ) } }
	);
	const pattern = ( await res.json() ).find( ( p ) => p.name === name );
	if ( ! pattern ) {
		throw new Error( `Pattern ${ name } is not registered.` );
	}
	return pattern.content;
}

/**
 * Card titles in a rendered directory.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          type Content type key.
 * @return {Promise<string[]>} Titles.
 */
async function cardTitles( page, type = 'sifg_project' ) {
	return page
		.locator( `[data-pegwork-directory="${ type }"] .wp-block-post-title` )
		.allInnerTexts();
}

module.exports = {
	baseURL,
	cardTitles,
	enableModule,
	patternContent,
	projectData,
	publishedProjects,
	recordTracker,
	seedProjects,
	termIds,
};
