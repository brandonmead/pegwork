const { restNonce, restUrl } = require( './records' );
const {
	enableModule,
	patternContent,
	recordTracker,
	termIds,
} = require( './listings' );

/**
 * Helpers for the filters specs: a fixture of Projects with known terms and Needs volunteers, a page with
 * the generated directory pattern (filters block plus Pegwork Query Loop), and an oracle that computes the
 * expected results of any filter combination from REST data.
 */

// Appears only in fixture records, so `q=TOKEN` scopes results to them on any site (500 seeded or none).
const TOKEN = 'zqfiltertoken';
const FIXTURE = 16;

const TYPES = [ 'nonprofit', 'corporation', 'individual', 'community-group' ];
const STATUSES = [ 'active', 'paused', 'building' ];
const DESCRIPTION = Array.from( { length: 60 }, ( _, i ) => `word${ i }` ).join(
	' '
);

/**
 * Fixture record `i` (1 based): terms and Needs volunteers by a fixed rule.
 *
 * @param {number} i Index.
 * @return {{title: string, type: string, status: string, impact: string[], volunteers: boolean}} Record.
 */
function fixtureRecord( i ) {
	const impact = [ i % 2 ? 'sdg-04' : 'sdg-13' ];
	if ( i % 5 === 0 ) {
		impact.push( 'sdg-15' );
	}
	return {
		title: `E2E Filter ${ String( i ).padStart( 2, '0' ) }`,
		type: TYPES[ i % TYPES.length ],
		status: STATUSES[ i % STATUSES.length ],
		impact,
		volunteers: i % 3 !== 0,
	};
}

/**
 * Enables Listings and Filters, creates the fixture records and the directory page.
 *
 * @param {import('@playwright/test').BrowserContext} context Logged-in context.
 * @return {Promise<{tracker: Object, page: Object}>} Tracker (cleanup) and the directory page record.
 */
async function setUpDirectory( context ) {
	const admin = await context.newPage();
	await enableModule( admin, 'Listings' );
	await enableModule( admin, 'Filters' );
	await admin.close();

	const { request } = context;
	const tracker = recordTracker( request );
	const ids = {};
	for ( const [ taxonomy, slugs ] of [
		[ 'sifg_project_type', TYPES ],
		[ 'sifg_project_status', STATUSES ],
		[ 'sifg_impact', [ 'sdg-04', 'sdg-13', 'sdg-15' ] ],
	] ) {
		for ( const slug of slugs ) {
			ids[ `${ taxonomy }:${ slug }` ] = (
				await termIds( request, taxonomy, [ slug ] )
			)[ 0 ];
		}
	}

	for ( let i = 1; i <= FIXTURE; i++ ) {
		const record = fixtureRecord( i );
		await tracker.project( record.title, {
			date: `2002-02-${ String( i ).padStart( 2, '0' ) }T10:00:00`,
			content: `<!-- wp:paragraph --><p>${ record.title } ${ TOKEN } ${ DESCRIPTION }</p><!-- /wp:paragraph -->`,
			sifg_project_type: [ ids[ `sifg_project_type:${ record.type }` ] ],
			sifg_project_status: [
				ids[ `sifg_project_status:${ record.status }` ],
			],
			sifg_impact: record.impact.map(
				( slug ) => ids[ `sifg_impact:${ slug }` ]
			),
			meta: {
				_sifg_project_needs_volunteers: record.volunteers,
				...( record.volunteers
					? {
							_sifg_project_volunteer_link:
								'https://example.org/volunteer',
						}
					: {} ),
			},
		} );
	}

	const page = await tracker.page(
		'E2E Filters directory',
		await patternContent( request, 'pegwork/sifg_project-directory' )
	);
	return { tracker, page };
}

/**
 * Every published Project with its terms (slugs), Needs volunteers and searchable text, newest first.
 *
 * @param {import('@playwright/test').APIRequestContext} request Logged-in request context.
 * @return {Promise<Object[]>} Records.
 */
async function allProjects( request ) {
	const nonce = await restNonce( request );
	const slugs = {};
	for ( const taxonomy of [
		'sifg_project_type',
		'sifg_project_status',
		'sifg_impact',
	] ) {
		const res = await request.get( restUrl( `/wp/v2/${ taxonomy }` ), {
			headers: { 'X-WP-Nonce': nonce },
			params: { per_page: '100', _fields: 'id,slug' },
		} );
		for ( const term of await res.json() ) {
			slugs[ term.id ] = term.slug;
		}
	}

	const records = [];
	for ( let page = 1; ; page++ ) {
		const res = await request.get( restUrl( '/wp/v2/projects' ), {
			headers: { 'X-WP-Nonce': nonce },
			params: {
				per_page: '100',
				page: String( page ),
				status: 'publish',
				context: 'edit',
				orderby: 'date',
				order: 'desc',
				_fields:
					'id,date,title,content,sifg_project_type,sifg_project_status,sifg_impact,meta',
			},
		} );
		const list = await res.json();
		for ( const item of list ) {
			records.push( {
				title: item.title.raw,
				type: item.sifg_project_type.map( ( id ) => slugs[ id ] ),
				status: item.sifg_project_status.map( ( id ) => slugs[ id ] ),
				impact: item.sifg_impact.map( ( id ) => slugs[ id ] ),
				volunteers: !! item.meta?._sifg_project_needs_volunteers,
				text: `${ item.title.raw } ${ item.content.raw }`,
			} );
		}
		if ( page >= Number( res.headers()[ 'x-wp-totalpages' ] || 1 ) ) {
			break;
		}
	}
	return records;
}

/**
 * Records matching URL parameters: Type and Status by slug, impact any of a comma list, Needs volunteers,
 * search as a substring (the fixture token is unique).
 *
 * @param {Object[]}               records From allProjects().
 * @param {Object<string, string>} params  Parameters.
 * @return {Object[]} Matches, newest first.
 */
function expected( records, params ) {
	return records.filter(
		( record ) =>
			( ! params.project_type ||
				record.type.includes( params.project_type ) ) &&
			( ! params.project_status ||
				record.status.includes( params.project_status ) ) &&
			( ! params.impact ||
				params.impact
					.split( ',' )
					.some( ( slug ) => record.impact.includes( slug ) ) ) &&
			( ! params.volunteers || record.volunteers ) &&
			( ! params.q || record.text.includes( params.q ) )
	);
}

/**
 * Every subset of the scenario parameters (32 combinations).
 *
 * @return {Object<string, string>[]} Parameter sets.
 */
function combinations() {
	const all = {
		project_type: 'nonprofit',
		project_status: 'active',
		impact: 'sdg-04,sdg-15',
		volunteers: '1',
		q: TOKEN,
	};
	// Subsets in binary counting order: start empty, and for each key add a copy of every subset so far with it.
	let list = [ {} ];
	for ( const key of Object.keys( all ) ) {
		list = [
			...list,
			...list.map( ( params ) => ( { ...params, [ key ]: all[ key ] } ) ),
		];
	}
	return list;
}

/**
 * The count text the filters block shows for a number of results.
 *
 * @param {number} count Results.
 * @return {string} Text.
 */
function countText( count ) {
	if ( count === 0 ) {
		return 'No results';
	}
	return `${ count.toLocaleString( 'en-US' ) } ${
		count === 1 ? 'result' : 'results'
	}`;
}

/**
 * A URL with parameters (commas kept readable, as the block writes them).
 *
 * @param {string}                 base   Base URL.
 * @param {Object<string, string>} params Parameters.
 * @return {string} URL.
 */
function withParams( base, params ) {
	const url = new URL( base );
	for ( const [ key, value ] of Object.entries( params ) ) {
		url.searchParams.set( key, value );
	}
	return url.toString().replace( /%2C/gi, ',' );
}

module.exports = {
	FIXTURE,
	TOKEN,
	allProjects,
	combinations,
	countText,
	expected,
	fixtureRecord,
	setUpDirectory,
	withParams,
};
