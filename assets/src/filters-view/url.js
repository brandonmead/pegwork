/**
 * Pure URL state for the filters view module. Mirrors PegworkFilters\UrlState: `q` for the search,
 * `<key>=a,b` or `<key>[]=a&<key>[]=b` for filter values; this module writes the comma form.
 *
 * A filter is `{ key, kind, multiple }` where kind is `taxonomy`, `boolean` or `select`.
 * State is `{ q: string, values: { [key]: string[] } }`.
 */

export const SEARCH = 'q';
export const MAX_SEARCH = 100;
export const MAX_VALUES = 20;

// Paging parameters a filter change drops (core Query Loop pages, `paged`).
const PAGING = /^(query-\d+-page|query-page|paged)$/;

/**
 * Clean values of one filter: trimmed, unique, non-empty, `1` only for a boolean, capped.
 *
 * @param {Object}   filter Filter.
 * @param {string[]} raw    Raw values.
 * @return {string[]} Values.
 */
function clean( filter, raw ) {
	const values = [];
	for ( const value of raw ) {
		const one = String( value ).trim();
		if (
			one !== '' &&
			! values.includes( one ) &&
			( filter.kind !== 'boolean' || one === '1' )
		) {
			values.push( one );
		}
	}
	return values.slice( 0, filter.multiple ? MAX_VALUES : 1 );
}

/**
 * Plain search text, at most MAX_SEARCH characters.
 *
 * @param {string|undefined} value Raw value.
 * @return {string} Search.
 */
function cleanSearch( value ) {
	return typeof value === 'string' ? value.slice( 0, MAX_SEARCH ).trim() : '';
}

/**
 * State from `[ name, value ]` pairs, as a form submits them (FormData entries).
 *
 * @param {Iterable<[string, string]>} entries Name and value pairs.
 * @param {Object[]}                   filters Filters.
 * @param {boolean}                    search  Whether the search box exists.
 * @return {{q: string, values: Object<string, string[]>}} State.
 */
export function stateFromEntries( entries, filters, search ) {
	const list = Array.from( entries ).filter(
		( [ , value ] ) => typeof value === 'string'
	);
	const values = {};
	for ( const filter of filters ) {
		values[ filter.key ] = clean(
			filter,
			list
				.filter(
					( [ name ] ) =>
						name === filter.key || name === `${ filter.key }[]`
				)
				.flatMap( ( [ , value ] ) => value.split( ',' ) )
		);
	}
	const q = search
		? cleanSearch( list.find( ( [ name ] ) => name === SEARCH )?.[ 1 ] )
		: '';
	return { q, values };
}

/**
 * State from a query string (back and forward, shared links).
 *
 * @param {string}   query   `location.search`.
 * @param {Object[]} filters Filters.
 * @param {boolean}  search  Whether the search box exists.
 * @return {{q: string, values: Object<string, string[]>}} State.
 */
export function parseUrl( query, filters, search ) {
	return stateFromEntries(
		new URLSearchParams( query ).entries(),
		filters,
		search
	);
}

/**
 * Whether any filter or the search is set.
 *
 * @param {{q: string, values: Object<string, string[]>}} state State.
 * @return {boolean} Active.
 */
export function isActive( state ) {
	return (
		state.q !== '' ||
		Object.values( state.values ).some( ( values ) => values.length > 0 )
	);
}

/**
 * The URL for a state: the base URL (keeping its own parameters, such as `page_id`), without paging,
 * with `q` and each filter in the comma form, in filter order.
 *
 * @param {string}                                        base    Base URL (absolute).
 * @param {{q: string, values: Object<string, string[]>}} state   State.
 * @param {Object[]}                                      filters Filters.
 * @return {string} URL.
 */
export function buildUrl( base, state, filters ) {
	const url = new URL( base );
	const params = url.searchParams;
	const own = new Set( [ SEARCH ] );
	for ( const filter of filters ) {
		own.add( filter.key );
		own.add( `${ filter.key }[]` );
	}
	for ( const name of Array.from( params.keys() ) ) {
		if ( own.has( name ) || PAGING.test( name ) ) {
			params.delete( name );
		}
	}
	url.pathname = url.pathname.replace( /\/page\/\d+\/?$/, '/' );

	if ( state.q !== '' ) {
		params.set( SEARCH, state.q );
	}
	for ( const filter of filters ) {
		const values = state.values[ filter.key ] || [];
		if ( values.length > 0 ) {
			params.set( filter.key, values.join( ',' ) );
		}
	}
	url.hash = '';
	// Commas are valid in a query string; keep them readable.
	return url.toString().replace( /%2C/gi, ',' );
}

/**
 * How many controls are active: the search when set, plus each filter with a value.
 *
 * @param {{q: string, values: Object<string, string[]>}} state State (only the shown filters).
 * @return {number} Count.
 */
export function activeCount( state ) {
	return (
		( state.q !== '' ? 1 : 0 ) +
		Object.values( state.values ).filter( ( values ) => values.length > 0 )
			.length
	);
}

/**
 * Summary of the collapsed form: "Filters", or "Filters, 2 active".
 *
 * @param {{collapseActive: string, collapseNone: string}} strings Translated formats (`%s` count).
 * @param {number}                                         count   Active filters.
 * @return {string} Text.
 */
export function collapseText( strings, count ) {
	if ( ! count ) {
		return strings?.collapseNone || 'Filters';
	}
	return ( strings?.collapseActive || 'Filters, %s active' ).replace(
		'%s',
		String( count )
	);
}

/**
 * Summary of a multi-value filter: "Impact category, 2 selected", or the label alone.
 *
 * @param {{selectedOne: string, selectedOther: string}} strings Translated formats (`%1$s` label, `%2$s` count).
 * @param {string}                                       label   Filter label.
 * @param {number}                                       count   Selected options.
 * @return {string} Text.
 */
export function summaryText( strings, label, count ) {
	if ( ! count ) {
		return label;
	}
	const format =
		( count === 1 ? strings?.selectedOne : strings?.selectedOther ) ||
		'%1$s, %2$s selected';
	return format.replace( '%1$s', label ).replace( '%2$s', String( count ) );
}
