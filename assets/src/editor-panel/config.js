/**
 * Parses the localized editor panel config (window.pegworkEditor).
 *
 * The contract is documented in docs/editor-panel.md. Parsing is defensive:
 * malformed entries are dropped and missing keys get their documented defaults,
 * so the panel never throws on a bad config.
 */

const STORAGES = [ 'meta', 'post_title', 'post_content' ];

const isObject = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

const isKey = ( value ) => typeof value === 'string' && value !== '';

const toList = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value;
	}
	return isObject( value ) ? Object.values( value ) : [];
};

/**
 * Parses a `show_when` or `required_when` condition.
 *
 * @param {unknown} condition Raw condition.
 * @return {?{field: string, equals: unknown}} The condition, or null when absent or malformed.
 */
export function parseCondition( condition ) {
	if ( ! isObject( condition ) || ! isKey( condition.field ) ) {
		return null;
	}
	return { field: condition.field, equals: condition.equals };
}

/**
 * Parses one field entry.
 *
 * @param {unknown} field Raw field.
 * @return {?Object} The field, or null when it cannot be edited or counted.
 */
export function parseField( field ) {
	if ( ! isObject( field ) || ! isKey( field.key ) ) {
		return null;
	}

	const storage = STORAGES.includes( field.storage ) ? field.storage : 'meta';
	const metaKey = isKey( field.meta_key ) ? field.meta_key : null;

	if ( storage === 'meta' && metaKey === null ) {
		return null;
	}

	return {
		key: field.key,
		type: isKey( field.type ) ? field.type : 'text',
		label: isKey( field.label ) ? field.label : field.key,
		required: field.required === true,
		visibility: field.visibility === 'private' ? 'private' : 'public',
		meta_key: storage === 'meta' ? metaKey : null,
		storage,
		show_when: parseCondition( field.show_when ),
		required_when: parseCondition( field.required_when ),
		validate: isObject( field.validate ) ? field.validate : {},
		options: toList( field.options )
			.filter( ( option ) => isObject( option ) && 'value' in option )
			.map( ( option ) => ( {
				value: String( option.value ),
				label: isKey( option.label )
					? option.label
					: String( option.value ),
			} ) ),
		default: field.default === undefined ? null : field.default,
	};
}

/**
 * Parses one taxonomy entry.
 *
 * @param {unknown} taxonomy Raw taxonomy.
 * @return {?Object} The taxonomy, or null when malformed.
 */
export function parseTaxonomy( taxonomy ) {
	if ( ! isObject( taxonomy ) || ! isKey( taxonomy.key ) ) {
		return null;
	}
	return {
		key: taxonomy.key,
		rest_base: isKey( taxonomy.rest_base )
			? taxonomy.rest_base
			: taxonomy.key,
		select: taxonomy.select === 'single' ? 'single' : 'multi',
		required: taxonomy.required === true,
		label: isKey( taxonomy.label ) ? taxonomy.label : taxonomy.key,
	};
}

/**
 * Parses the whole config.
 *
 * @param {unknown} raw The localized value, usually window.pegworkEditor.
 * @return {{types: Object<string, {fields: Object[], taxonomies: Object[]}>}} Parsed config.
 */
export function parseConfig( raw ) {
	const types = {};
	const source = isObject( raw ) && isObject( raw.types ) ? raw.types : {};

	for ( const [ postType, type ] of Object.entries( source ) ) {
		if ( ! isObject( type ) ) {
			continue;
		}
		types[ postType ] = {
			fields: toList( type.fields ).map( parseField ).filter( Boolean ),
			taxonomies: toList( type.taxonomies )
				.map( parseTaxonomy )
				.filter( Boolean ),
		};
	}

	return { types };
}

/**
 * Config for one post type.
 *
 * @param {{types: Object}} config   Parsed config.
 * @param {?string}         postType Post type key.
 * @return {?{fields: Object[], taxonomies: Object[]}} The type config, or null when the panel does not apply.
 */
export function getTypeConfig( config, postType ) {
	if (
		! isKey( postType ) ||
		! Object.prototype.hasOwnProperty.call( config.types, postType )
	) {
		return null;
	}
	return config.types[ postType ];
}
