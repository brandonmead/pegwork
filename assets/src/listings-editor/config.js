/**
 * Parses the localized listings editor config (window.pegworkListings).
 *
 * Built in PHP by PegworkListings\Editor::config(): public fields only. Parsing is
 * defensive so a malformed config never throws in the editor.
 */

const isObject = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

const isKey = ( value ) => typeof value === 'string' && value !== '';

const toList = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value;
	}
	return isObject( value ) ? Object.values( value ) : [];
};

function parseField( field ) {
	if ( ! isObject( field ) || ! isKey( field.key ) ) {
		return null;
	}
	const storage = isKey( field.storage ) ? field.storage : 'meta';
	const metaKey = isKey( field.metaKey ) ? field.metaKey : null;
	if ( storage === 'meta' && metaKey === null ) {
		return null;
	}
	return {
		key: field.key,
		type: isKey( field.type ) ? field.type : 'text',
		label: isKey( field.label ) ? field.label : field.key,
		storage,
		metaKey,
		options: toList( field.options )
			.filter( ( option ) => isObject( option ) && 'value' in option )
			.map( ( option ) => ( {
				value: String( option.value ),
				label: isKey( option.label )
					? option.label
					: String( option.value ),
			} ) ),
		showWhen:
			isObject( field.showWhen ) && isKey( field.showWhen.field )
				? { field: field.showWhen.field, equals: field.showWhen.equals }
				: null,
	};
}

function parseTaxonomy( taxonomy ) {
	if ( ! isObject( taxonomy ) || ! isKey( taxonomy.key ) ) {
		return null;
	}
	return {
		key: taxonomy.key,
		label: isKey( taxonomy.label ) ? taxonomy.label : taxonomy.key,
		terms: toList( taxonomy.terms )
			.filter( ( term ) => isObject( term ) && isKey( term.slug ) )
			.map( ( term ) => ( {
				slug: term.slug,
				name: isKey( term.name ) ? term.name : term.slug,
			} ) ),
	};
}

function parseRelationship( relationship ) {
	if (
		! isObject( relationship ) ||
		! isKey( relationship.key ) ||
		! isKey( relationship.type )
	) {
		return null;
	}
	return {
		key: relationship.key,
		type: relationship.type,
		label: isKey( relationship.label )
			? relationship.label
			: relationship.type,
		plural: isKey( relationship.plural )
			? relationship.plural
			: relationship.type,
	};
}

/**
 * Parses the whole config.
 *
 * @param {unknown} raw Usually window.pegworkListings.
 * @return {{dateFormat: string, types: Object}} Parsed config.
 */
export function parseConfig( raw ) {
	const source = isObject( raw ) ? raw : {};
	const types = {};

	for ( const [ postType, type ] of Object.entries(
		isObject( source.types ) ? source.types : {}
	) ) {
		if ( ! isObject( type ) ) {
			continue;
		}
		types[ postType ] = {
			label: isKey( type.label ) ? type.label : postType,
			plural: isKey( type.plural ) ? type.plural : postType,
			listing: type.listing === true,
			cardPattern: isKey( type.cardPattern ) ? type.cardPattern : null,
			noResults: isKey( type.noResults ) ? type.noResults : '',
			optionPosts: toList( type.optionPosts )
				.filter(
					( option ) =>
						isObject( option ) &&
						isKey( option.value ) &&
						option.value.includes( '.' )
				)
				.map( ( option ) => ( {
					value: option.value,
					label: isKey( option.label ) ? option.label : option.value,
				} ) ),
			fields: toList( type.fields ).map( parseField ).filter( Boolean ),
			taxonomies: toList( type.taxonomies )
				.map( parseTaxonomy )
				.filter( Boolean ),
			relationships: toList( type.relationships )
				.map( parseRelationship )
				.filter( Boolean ),
		};
	}

	return {
		dateFormat: isKey( source.dateFormat ) ? source.dateFormat : 'F j, Y',
		types,
	};
}

/**
 * A public field of a type.
 *
 * @param {?Object} type Parsed type config.
 * @param {string}  key  Field key.
 * @return {?Object} The field, or null.
 */
export function findField( type, key ) {
	return type?.fields.find( ( field ) => field.key === key ) ?? null;
}

/**
 * A taxonomy used by a type.
 *
 * @param {?Object} type Parsed type config.
 * @param {string}  key  Taxonomy key.
 * @return {?Object} The taxonomy, or null.
 */
export function findTaxonomy( type, key ) {
	const found = type?.taxonomies.find( ( taxonomy ) => taxonomy.key === key );
	return found ?? null;
}

/**
 * A relationship with the type at one end, with the type at the other end.
 *
 * @param {?Object} type Parsed type config.
 * @param {string}  key  Relationship key.
 * @return {?Object} `{ key, type, label, plural }` (the other end), or null.
 */
export function findRelationship( type, key ) {
	const found = ( type?.relationships ?? [] ).find(
		( relationship ) => relationship.key === key
	);
	return found ?? null;
}
