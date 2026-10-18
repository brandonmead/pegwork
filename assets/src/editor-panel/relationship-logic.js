/**
 * Pure relationship picker logic: linked ID lists, queries, suggestions and
 * picker state. No WordPress imports, so it runs in unit tests as is.
 */

/**
 * Statuses a link may point to. Matches what RecordValidator accepts (any
 * existing record of the "to" type), minus trash for suggestions.
 */
export const SEARCH_STATUSES = 'publish,future,draft,pending,private';
export const LINKED_STATUSES = `${ SEARCH_STATUSES },trash`;

export const SEARCH_PER_PAGE = 20;
export const LINKED_PER_PAGE = 100;

const FIELDS = 'id,title,status';

/**
 * Normalizes a stored `_rel_{key}` value to positive integer IDs, unique, in stored order.
 *
 * @param {unknown} value Meta value: an array of IDs, or anything else for none.
 * @return {number[]} IDs.
 */
export function linkedIds( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	const ids = [];
	for ( const raw of value ) {
		const id = Number( raw );
		if ( Number.isInteger( id ) && id > 0 && ! ids.includes( id ) ) {
			ids.push( id );
		}
	}
	return ids;
}

/**
 * Adds a link at the end, unless it is already there.
 *
 * @param {number[]} ids Linked IDs.
 * @param {number}   id  ID to add.
 * @return {number[]} New list (the same list when nothing changes).
 */
export function addLink( ids, id ) {
	if ( ! Number.isInteger( id ) || id <= 0 || ids.includes( id ) ) {
		return ids;
	}
	return [ ...ids, id ];
}

/**
 * Removes a link.
 *
 * @param {number[]} ids Linked IDs.
 * @param {number}   id  ID to remove.
 * @return {number[]} New list.
 */
export function removeLink( ids, id ) {
	return ids.filter( ( candidate ) => candidate !== id );
}

/**
 * Where focus goes after removing the row at `index`: the next row, else the combobox.
 *
 * @param {number[]} remaining IDs left after the removal.
 * @param {number}   index     Index the removed row had.
 * @return {?number} ID whose Remove button takes focus, or null for the combobox.
 */
export function focusAfterRemove( remaining, index ) {
	return index < remaining.length ? remaining[ index ] : null;
}

/**
 * `getEntityRecords` query for suggestions. An empty search lists the most recent records.
 *
 * @param {string} search Search text.
 * @return {Object} Query.
 */
export function searchQuery( search ) {
	const query = {
		per_page: SEARCH_PER_PAGE,
		status: SEARCH_STATUSES,
		_fields: FIELDS,
		context: 'view',
	};
	const text = typeof search === 'string' ? search.trim() : '';
	if ( text !== '' ) {
		query.search = text;
	}
	return query;
}

/**
 * `getEntityRecords` query resolving linked records' titles in one request.
 *
 * @param {number[]} ids Linked IDs.
 * @return {Object} Query. IDs are sorted so reordering does not refetch.
 */
export function linkedQuery( ids ) {
	return {
		include: [ ...ids ].sort( ( a, b ) => a - b ),
		per_page: LINKED_PER_PAGE,
		status: LINKED_STATUSES,
		_fields: FIELDS,
		context: 'view',
	};
}

/**
 * Converts a REST record to a picker item.
 *
 * @param {Object}                   record   REST record with `id`, `title`, `status`.
 * @param {(text: string) => string} [decode] Decodes HTML entities in the rendered title.
 * @return {{id: number, title: string, status: string}} Item. The title may be '' (untitled).
 */
export function toItem( record, decode = ( text ) => text ) {
	const title = record && record.title;
	let text = '';
	if ( typeof title === 'string' ) {
		text = title;
	} else if ( title && typeof title.rendered === 'string' ) {
		text = title.rendered;
	}
	return {
		id: Number( record.id ),
		title: decode( text ).trim(),
		status: typeof record.status === 'string' ? record.status : 'publish',
	};
}

/**
 * Lower-cases and strips accents, as ComboboxControl does when matching labels.
 *
 * @param {string} text Text.
 * @return {string} Normalized text.
 */
export function normalize( text ) {
	return String( text )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLocaleLowerCase();
}

/**
 * Suggestion items: search results that are not linked yet, and (once the user
 * typed) whose label contains the search text, since ComboboxControl hides the rest.
 *
 * @param {Object[]}                  items  Picker items from the search.
 * @param {number[]}                  ids    Linked IDs.
 * @param {string}                    search Current input text.
 * @param {(item: Object) => string } label  Option label of an item.
 * @return {Object[]} Items to offer.
 */
export function suggestionItems( items, ids, search, label ) {
	const match = normalize( typeof search === 'string' ? search.trim() : '' );
	return items.filter(
		( item ) =>
			! ids.includes( item.id ) &&
			( match === '' || normalize( label( item ) ).includes( match ) )
	);
}

/**
 * What the status line under the combobox shows.
 *
 * @param {Object}  state             State.
 * @param {?string} state.search      Current input text, null before the first focus.
 * @param {?string} state.debounced   Search text the last query used, null before the first query.
 * @param {boolean} state.isSearching Whether that query is in flight.
 * @param {boolean} state.hasFailed   Whether that query failed.
 * @param {number}  state.count       Suggestions on offer.
 * @return {'idle'|'loading'|'error'|'no-matches'|'ready'} Status.
 */
export function searchStatus( {
	search,
	debounced,
	isSearching,
	hasFailed,
	count,
} ) {
	if ( search === null || debounced === null ) {
		return 'idle';
	}
	if ( hasFailed ) {
		return 'error';
	}
	if ( isSearching || search.trim() !== debounced.trim() ) {
		return 'loading';
	}
	if ( count === 0 && search.trim() !== '' ) {
		return 'no-matches';
	}
	return 'ready';
}
