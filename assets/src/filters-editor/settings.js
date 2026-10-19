/**
 * Pure helpers for the filters block settings.
 */

/**
 * The content type a block previews: its attribute, else the type of an `archive-<type>` template,
 * else the only listing type.
 *
 * @param {{types: Object[]}} config       Editor config.
 * @param {string}            postType     Block attribute.
 * @param {string}            templateSlug Slug of the template or post being edited.
 * @return {string} Type key, or '' when none applies.
 */
export function resolveType( config, postType, templateSlug ) {
	const keys = config.types.map( ( type ) => type.key );
	if ( postType ) {
		return keys.includes( postType ) ? postType : '';
	}
	const match = /^archive-(.+)$/.exec( templateSlug || '' );
	if ( match && keys.includes( match[ 1 ] ) ) {
		return match[ 1 ];
	}
	return keys.length === 1 ? keys[ 0 ] : '';
}

/**
 * Options of the content type select.
 *
 * @param {{types: Object[]}} config    Editor config.
 * @param {string}            automatic Label of the empty choice.
 * @return {{value: string, label: string}[]} Options.
 */
export function typeOptions( config, automatic ) {
	return [
		{ value: '', label: automatic },
		...config.types.map( ( type ) => ( {
			value: type.key,
			label: type.label,
		} ) ),
	];
}

/**
 * The `filters` attribute after switching one filter on or off. An empty list means all filters;
 * the result keeps the type's order, and the last shown filter cannot be switched off.
 *
 * @param {{filters: {key: string}[]}} definition Type config.
 * @param {string[]}                   current    Current attribute.
 * @param {string}                     key        Filter key.
 * @param {boolean}                    on         Whether it is shown.
 * @return {string[]} Attribute.
 */
export function toggleFilter( definition, current, key, on ) {
	const all = definition.filters.map( ( filter ) => filter.key );
	const shown = new Set( current.length === 0 ? all : current );
	if ( on ) {
		shown.add( key );
	} else {
		shown.delete( key );
	}
	const next = all.filter( ( one ) => shown.has( one ) );
	if ( next.length === 0 ) {
		// An empty attribute means all filters, so the last one stays.
		return current;
	}
	return next.length === all.length ? [] : next;
}
