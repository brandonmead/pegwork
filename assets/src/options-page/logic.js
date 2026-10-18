/**
 * Pure logic of the options page post picker (tested in test/logic.test.js).
 */

/**
 * Number of search results requested per query.
 */
export const PER_PAGE = 20;

/**
 * Reads the picker config from its data attribute.
 *
 * @param {?string} raw JSON from `data-pegwork-post-picker`.
 * @return {?{restBase: string, restNamespace: string, singular: string, current: ?{id: number, title: string}}} Config, or null when unusable.
 */
export function parseConfig( raw ) {
	let data;
	try {
		data = JSON.parse( raw || '' );
	} catch {
		return null;
	}
	if ( ! data || typeof data.restBase !== 'string' || data.restBase === '' ) {
		return null;
	}
	const current =
		data.current &&
		Number.isInteger( data.current.id ) &&
		data.current.id > 0
			? {
					id: data.current.id,
					title: String( data.current.title ?? '' ),
				}
			: null;
	return {
		restBase: data.restBase,
		restNamespace:
			typeof data.restNamespace === 'string' && data.restNamespace
				? data.restNamespace
				: 'wp/v2',
		singular: typeof data.singular === 'string' ? data.singular : '',
		current,
	};
}

/**
 * REST path for a search: published records only, view context, IDs and titles.
 * An empty search lists records by title.
 *
 * @param {{restBase: string, restNamespace: string}} config Picker config.
 * @param {string}                                    search Search text.
 * @return {string} Path for apiFetch.
 */
export function searchPath( config, search ) {
	const params = new URLSearchParams( {
		per_page: String( PER_PAGE ),
		status: 'publish',
		context: 'view',
		_fields: 'id,title',
	} );
	const text = ( search || '' ).trim();
	if ( text === '' ) {
		params.set( 'orderby', 'title' );
		params.set( 'order', 'asc' );
	} else {
		params.set( 'search', text );
	}
	return `/${ config.restNamespace }/${ config.restBase }?${ params.toString() }`;
}

/**
 * A combobox option from a REST record.
 *
 * @param {{id: number, title?: {rendered?: string}}} record   REST record.
 * @param {(text: string) => string}                  decode   Entity decoder.
 * @param {(id: number) => string}                    untitled Label for a record without a title.
 * @return {{value: string, label: string}} Option.
 */
export function toOption( record, decode, untitled ) {
	const title = decode( record?.title?.rendered ?? '' ).trim();
	return {
		value: String( record.id ),
		label: title !== '' ? title : untitled( record.id ),
	};
}

/**
 * Options to offer: the selected record first (so the combobox can show it), then results, each ID once.
 *
 * @param {?{value: string, label: string}}       selected Selected option, or null.
 * @param {Array<{value: string, label: string}>} results  Search results.
 * @return {Array<{value: string, label: string}>} Options.
 */
export function mergeOptions( selected, results ) {
	const seen = new Set();
	const options = [];
	[ ...( selected ? [ selected ] : [] ), ...results ].forEach( ( option ) => {
		if ( ! seen.has( option.value ) ) {
			seen.add( option.value );
			options.push( option );
		}
	} );
	return options;
}

/**
 * What the status line under the combobox says.
 *
 * @param {{search: ?string, isSearching: boolean, hasFailed: boolean, count: number}} state Search state.
 * @return {?('loading'|'error'|'no-matches')} Status, or null for none.
 */
export function searchStatus( { search, isSearching, hasFailed, count } ) {
	if ( search === null ) {
		return null;
	}
	if ( hasFailed ) {
		return 'error';
	}
	if ( isSearching ) {
		return 'loading';
	}
	if ( count === 0 && search.trim() !== '' ) {
		return 'no-matches';
	}
	return null;
}
