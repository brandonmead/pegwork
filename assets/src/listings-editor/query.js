/**
 * Pegwork directories on core/query: the `pegwork` attribute, the "<Plural> directory"
 * variations and `where` preset helpers. Pure functions; the editor wiring is in
 * query-controls.js and index.js.
 *
 * Attribute shape (mirrors PegworkListings\QueryHooks on the server):
 * `{ filters?: boolean, where?: { [fieldKey]: boolean|string|string[], post_in_option?: "<page>.<field>",
 * related?: "<relationship key>" } }`.
 */

export const ATTRIBUTE = 'pegwork';
export const OPTION_KEY = 'post_in_option';
export const RELATED_KEY = 'related';
export const PER_PAGE = 12;
// Narrowest card column; columns wrap below it (PegworkListings\Patterns::MIN_COLUMN).
export const MIN_COLUMN = '16rem';

const isObject = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

/**
 * blocks.registerBlockType filter: adds the `pegwork` object attribute to core/query, so
 * the editor keeps it when the block is saved.
 *
 * @param {Object} settings Block settings.
 * @param {string} name     Block name.
 * @return {Object} Settings.
 */
export function addPegworkAttribute( settings, name ) {
	if ( name !== 'core/query' || ! isObject( settings ) ) {
		return settings;
	}
	return {
		...settings,
		attributes: {
			...( settings.attributes ?? {} ),
			[ ATTRIBUTE ]: { type: 'object' },
		},
	};
}

/**
 * Whether Query Loop attributes make a Pegwork directory.
 *
 * @param {Object} attributes core/query attributes.
 * @return {boolean} True when the `pegwork` attribute is set.
 */
export function isPegworkQuery( attributes ) {
	return isObject( attributes?.[ ATTRIBUTE ] );
}

/**
 * Public fields a `where` preset may use: boolean and select fields stored in meta.
 *
 * @param {?Object} type Parsed type config.
 * @return {Object[]} Fields.
 */
export function presetFields( type ) {
	return ( type?.fields ?? [] ).filter(
		( field ) =>
			( field.type === 'boolean' || field.type === 'select' ) &&
			field.storage === 'meta'
	);
}

/**
 * Choices for the `related` preset: one per relationship with the loop's type at one end, naming
 * the type at the other end (the type of the post being viewed).
 *
 * @param {?Object}                                   type  Parsed type config of the loop.
 * @param {(singular: string, key: string) => string} label Choice label.
 * @return {{value: string, label: string}[]} Choices.
 */
export function relatedChoices( type, label ) {
	return ( type?.relationships ?? [] ).map( ( relationship ) => ( {
		value: relationship.key,
		label: label( relationship.label, relationship.key ),
	} ) );
}

/**
 * A preset's value as a SelectControl value: '' (any), '1' / '0' for booleans, the option value
 * for selects.
 *
 * @param {Object} pegwork The attribute.
 * @param {Object} field   Field config.
 * @return {string} Control value.
 */
export function whereValue( pegwork, field ) {
	const value = isObject( pegwork?.where )
		? pegwork.where[ field.key ]
		: undefined;
	if ( field.type === 'boolean' ) {
		if ( value === true ) {
			return '1';
		}
		return value === false ? '0' : '';
	}
	return typeof value === 'string' ? value : '';
}

/**
 * The attribute with one preset set from a control value, or removed when the value is ''.
 *
 * @param {Object} pegwork The attribute.
 * @param {string} key     Field key, or OPTION_KEY.
 * @param {string} value   Control value ('' removes; '1' / '0' for boolean fields).
 * @param {string} type    Field type (`boolean`, `select`) or `option`.
 * @return {Object} New attribute (`where` dropped when empty).
 */
export function setWhere( pegwork, key, value, type ) {
	const base = isObject( pegwork ) ? pegwork : {};
	const where = { ...( isObject( base.where ) ? base.where : {} ) };
	if ( value === '' || value === undefined || value === null ) {
		delete where[ key ];
	} else if ( type === 'boolean' ) {
		where[ key ] = value === '1';
	} else {
		where[ key ] = String( value );
	}

	const next = { ...base, where };
	if ( Object.keys( where ).length === 0 ) {
		delete next.where;
	}
	return next;
}

/**
 * The "<Plural> directory" Query Loop variations, one per content type with a `listing`.
 *
 * Inner blocks: a grid post template holding the type's card pattern (core/pattern, which the
 * editor replaces with the registered pattern, so a theme's card override applies), pagination
 * and a no-results message.
 *
 * @param {Object}                     config              Parsed config.
 * @param {Object}                     strings             Translated strings.
 * @param {(plural: string) => string} strings.title       Variation title.
 * @param {(plural: string) => string} strings.description Variation description.
 * @return {Object[]} Variations for registerBlockVariation( 'core/query', ... ).
 */
export function directoryVariations( config, strings ) {
	return Object.entries( config?.types ?? {} )
		.filter( ( [ , type ] ) => type.listing && type.cardPattern )
		.map( ( [ postType, type ] ) => ( {
			name: `pegwork/${ postType }-directory`,
			title: strings.title( type.plural ),
			description: strings.description( type.plural ),
			category: 'pegwork',
			icon: 'grid-view',
			keywords: [ 'pegwork', 'directory', type.plural ],
			scope: [ 'inserter', 'block' ],
			attributes: {
				query: {
					perPage: PER_PAGE,
					pages: 0,
					offset: 0,
					postType,
					order: 'desc',
					orderBy: 'date',
					author: '',
					search: '',
					exclude: [],
					sticky: '',
					inherit: false,
				},
				enhancedPagination: true,
				align: 'wide',
				[ ATTRIBUTE ]: { filters: true },
			},
			innerBlocks: [
				[
					'core/post-template',
					{
						layout: {
							type: 'grid',
							minimumColumnWidth: MIN_COLUMN,
						},
					},
					[ [ 'core/pattern', { slug: type.cardPattern } ] ],
				],
				[
					'core/query-pagination',
					{ layout: { type: 'flex', justifyContent: 'center' } },
					[
						[ 'core/query-pagination-previous' ],
						[ 'core/query-pagination-numbers' ],
						[ 'core/query-pagination-next' ],
					],
				],
				[
					'core/query-no-results',
					{},
					[ [ 'core/paragraph', { content: type.noResults } ] ],
				],
			],
			isActive: ( attributes ) =>
				isPegworkQuery( attributes ) &&
				attributes.query?.postType === postType,
		} ) );
}
