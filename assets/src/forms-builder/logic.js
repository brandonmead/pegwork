/**
 * Pure builder logic: keys, settings JSON, error pointers, bound field resolution, rule summaries.
 * No WordPress imports, so it runs in unit tests as is.
 */

export const FIELD = 'pegwork/form-field';
export const STEP = 'pegwork/form-step';

/**
 * Unbound field types offered as block variations, in inserter order. `rich_text` is accepted
 * (it round-trips) but not offered: forms take plain text.
 */
export const FIELD_TYPES = [
	'text',
	'email',
	'textarea',
	'url',
	'number',
	'date',
	'boolean',
	'select',
	'file',
];

/**
 * A key from a label: lowercase ASCII letters, digits and underscores, starting with a letter, at
 * most 64 characters; '' when nothing usable remains. Same rule as PHP `Builder::slug()`.
 *
 * @param {unknown} label Label.
 * @return {string} Key.
 */
export function slugify( label ) {
	let slug = String( label ?? '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' );
	if ( slug !== '' && ! /^[a-z]/.test( slug ) ) {
		slug = `f_${ slug }`;
	}
	return slug.slice( 0, 64 ).replace( /_+$/, '' );
}

/**
 * The key to keep when a label changes: it follows the label while it still equals the key the
 * old label gave (or is empty); a key typed by hand stays.
 *
 * @param {?string} key      Current key.
 * @param {?string} oldLabel Label before the change.
 * @param {string}  newLabel Label after the change.
 * @return {string} Key.
 */
export function keyForLabel( key, oldLabel, newLabel ) {
	if ( ! key || key === slugify( oldLabel ) ) {
		return slugify( newLabel );
	}
	return key;
}

/**
 * Normalizes the inline config (window.pegworkFormBuilder).
 *
 * @param {unknown} raw Config from PHP.
 * @return {Object} Config.
 */
export function parseConfig( raw ) {
	const config = raw && typeof raw === 'object' ? raw : {};
	const types = Array.isArray( config.types ) ? config.types : [];
	return {
		types: types
			.filter( ( type ) => type && typeof type.key === 'string' )
			.map( ( type ) => ( {
				key: type.key,
				label: typeof type.label === 'string' ? type.label : type.key,
				fields: Array.isArray( type.fields ) ? type.fields : [],
				taxonomies: Array.isArray( type.taxonomies )
					? type.taxonomies
					: [],
			} ) ),
		turnstile: config.turnstile === true,
		lockedKey: typeof config.lockedKey === 'string' ? config.lockedKey : '',
		readOnly: config.readOnly === true,
		package: typeof config.package === 'string' ? config.package : '',
		duplicateUrl:
			typeof config.duplicateUrl === 'string' ? config.duplicateUrl : '',
		formsUrl: typeof config.formsUrl === 'string' ? config.formsUrl : '',
	};
}

/**
 * The settings object from its meta JSON.
 *
 * @param {unknown} json Meta value.
 * @return {Object} Settings ({} when empty or malformed).
 */
export function parseSettings( json ) {
	if ( typeof json !== 'string' || json === '' ) {
		return {};
	}
	try {
		const value = JSON.parse( json );
		return value && typeof value === 'object' && ! Array.isArray( value )
			? value
			: {};
	} catch {
		return {};
	}
}

/**
 * Drops undefined, null and '' properties, then objects left empty (lists are kept as they are, so a
 * half-filled notification still reaches the validator, which names what is missing).
 *
 * @param {unknown} value Value.
 * @return {unknown} Clean value, or undefined when nothing is left.
 */
export function clean( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( item ) => {
			const cleaned = clean( item );
			return cleaned === undefined ? {} : cleaned;
		} );
	}
	if ( value && typeof value === 'object' ) {
		const out = {};
		for ( const [ name, item ] of Object.entries( value ) ) {
			const cleaned = clean( item );
			if ( cleaned !== undefined ) {
				out[ name ] = cleaned;
			}
		}
		return Object.keys( out ).length ? out : undefined;
	}
	return value === undefined || value === null || value === ''
		? undefined
		: value;
}

/**
 * Settings as meta JSON, cleaned.
 *
 * @param {Object} settings Settings.
 * @return {string} JSON.
 */
export function serializeSettings( settings ) {
	return JSON.stringify( clean( settings ) ?? {} );
}

/**
 * A copy of an object with one property set, or removed when the value is empty.
 *
 * @param {Object} object Object.
 * @param {string} name   Property.
 * @param {*}      value  Value; undefined, null or '' removes it.
 * @return {Object} New object.
 */
export function withValue( object, name, value ) {
	const next = { ...( object || {} ) };
	if ( value === undefined || value === null || value === '' ) {
		delete next[ name ];
	} else {
		next[ name ] = value;
	}
	return next;
}

/**
 * JSON pointer of every builder block, as the server compiles them: `/fields/i` without steps,
 * `/steps/i` and `/steps/i/fields/j` with steps. Fields beside steps (an error) get no pointer.
 *
 * @param {Array<{clientId: string, name: string, innerBlocks?: Array}>} blocks Top-level blocks.
 * @return {Object<string, string>} Pointer by client ID.
 */
export function pointerMap( blocks ) {
	const map = {};
	const top = ( blocks || [] ).filter(
		( block ) => block.name === FIELD || block.name === STEP
	);
	const hasSteps = top.some( ( block ) => block.name === STEP );
	if ( ! hasSteps ) {
		top.forEach( ( block, index ) => {
			map[ block.clientId ] = `/fields/${ index }`;
		} );
		return map;
	}
	const allSteps = top.every( ( block ) => block.name === STEP );
	top.forEach( ( block, index ) => {
		if ( block.name !== STEP || ! allSteps ) {
			return;
		}
		const pointer = `/steps/${ index }`;
		map[ block.clientId ] = pointer;
		( block.innerBlocks || [] ).forEach( ( child, position ) => {
			map[ child.clientId ] = `${ pointer }/fields/${ position }`;
		} );
	} );
	return map;
}

const NO_ERRORS = Object.freeze( {} );

/**
 * Messages by pointer from a failed save.
 *
 * @param {unknown} error Last save error (REST error body), or null.
 * @return {Object<string, string>} Messages; empty unless the error is `pegwork_invalid_form`.
 */
export function saveErrors( error ) {
	if (
		! error ||
		error.code !== 'pegwork_invalid_form' ||
		! error.data ||
		! error.data.fields ||
		typeof error.data.fields !== 'object'
	) {
		return NO_ERRORS;
	}
	return error.data.fields;
}

/**
 * Messages at a pointer or below it.
 *
 * @param {Object<string, string>} errors  Messages by pointer.
 * @param {?string}                pointer Pointer, or null.
 * @param {boolean}                [deep]  Include pointers below (default true).
 * @return {string[]} Messages.
 */
export function errorsAt( errors, pointer, deep = true ) {
	if ( ! pointer ) {
		return [];
	}
	return Object.keys( errors || {} )
		.filter(
			( key ) =>
				key === pointer || ( deep && key.startsWith( `${ pointer }/` ) )
		)
		.map( ( key ) => errors[ key ] );
}

/**
 * Messages the blocks and setting controls do not show: the form as a whole (`/`), `/fields`, `/steps`.
 *
 * @param {Object<string, string>} errors Messages by pointer.
 * @return {string[]} Messages.
 */
export function generalErrors( errors ) {
	return Object.keys( errors || {} )
		.filter( ( key ) => [ '/', '', '/fields', '/steps' ].includes( key ) )
		.map( ( key ) => errors[ key ] );
}

/**
 * A content type from the config.
 *
 * @param {Object}  config Parsed config.
 * @param {?string} key    Type key.
 * @return {?Object} Type, or null.
 */
export function findType( config, key ) {
	return config.types.find( ( type ) => type.key === key ) || null;
}

/**
 * A field block's item as the form sees it: bound items get type, label, rules and options from the
 * target type (as the server's Definition::resolve() does), unbound items are taken as they are.
 *
 * @param {Object}  attributes Block attributes.
 * @param {?Object} type       Target type from the config, or null.
 * @return {?Object} Item with `key`, `label`, `type`, `bound` (`field`, `taxonomy` or null), or null
 *                   when a bound item does not resolve.
 */
export function resolveItem( attributes, type ) {
	const bind = attributes.bind;
	if ( ! bind ) {
		return {
			...attributes,
			key: attributes.key || slugify( attributes.label ),
			type: attributes.type || 'text',
			label: attributes.label || '',
			bound: null,
		};
	}
	if ( ! type ) {
		return null;
	}
	if ( bind.field ) {
		const field = type.fields.find(
			( candidate ) => candidate.key === bind.field
		);
		if ( ! field ) {
			return null;
		}
		return {
			...field,
			type: field.type === 'rich_text' ? 'textarea' : field.type,
			registryLabel: field.label,
			label: attributes.label || field.label,
			help: attributes.help,
			placeholder: attributes.placeholder,
			autocomplete: attributes.autocomplete,
			display: attributes.display,
			bound: 'field',
		};
	}
	const taxonomy = type.taxonomies.find(
		( candidate ) => candidate.key === bind.taxonomy
	);
	if ( ! taxonomy ) {
		return null;
	}
	return {
		key: taxonomy.key,
		type: 'select',
		multiple: taxonomy.multiple,
		required: taxonomy.required,
		options: taxonomy.options || [],
		registryLabel: taxonomy.label,
		label: attributes.label || taxonomy.label,
		help: attributes.help,
		placeholder: attributes.placeholder,
		autocomplete: attributes.autocomplete,
		display:
			attributes.display ||
			( taxonomy.multiple ? 'checkboxes' : 'select' ),
		bound: 'taxonomy',
	};
}

/**
 * Every field block in order, flattened from steps.
 *
 * @param {Array} blocks Top-level blocks.
 * @return {Array} Field blocks.
 */
export function fieldBlocks( blocks ) {
	const out = [];
	for ( const block of blocks || [] ) {
		if ( block.name === FIELD ) {
			out.push( block );
		} else if ( block.name === STEP ) {
			out.push( ...fieldBlocks( block.innerBlocks ) );
		}
	}
	return out;
}

/**
 * Items of the form (resolved), each with its block's client ID.
 *
 * @param {Array}   blocks Top-level blocks.
 * @param {?Object} type   Target type from the config, or null.
 * @return {Array<Object>} Items.
 */
export function formItems( blocks, type ) {
	return fieldBlocks( blocks )
		.map( ( block ) => {
			const item = resolveItem( block.attributes || {}, type );
			return item ? { ...item, clientId: block.clientId } : null;
		} )
		.filter( Boolean );
}

/**
 * Keys of email items (for the confirmation field and `{field:...}` recipients).
 *
 * @param {Array<Object>} items Resolved items.
 * @return {Array<{key: string, label: string, required: boolean}>} Email items.
 */
export function emailItems( items ) {
	return items
		.filter( ( item ) => item.type === 'email' && item.key )
		.map( ( item ) => ( {
			key: item.key,
			label: item.label || item.key,
			required: item.required === true,
		} ) );
}

/**
 * Bound items of a type that the form does not hold yet ("Add all fields from <type>").
 *
 * @param {?Object} type   Target type from the config.
 * @param {Array}   blocks Top-level blocks.
 * @return {Array<{bind: Object}>} Attributes for new field blocks, fields first, then taxonomies.
 */
export function missingBound( type, blocks ) {
	if ( ! type ) {
		return [];
	}
	const bound = new Set(
		fieldBlocks( blocks )
			.map( ( block ) => block.attributes?.bind )
			.filter( Boolean )
			.map( ( bind ) =>
				bind.field
					? `field:${ bind.field }`
					: `taxonomy:${ bind.taxonomy }`
			)
	);
	return [
		...type.fields
			.filter( ( field ) => ! bound.has( `field:${ field.key }` ) )
			.map( ( field ) => ( { bind: { field: field.key } } ) ),
		...type.taxonomies
			.filter(
				( taxonomy ) => ! bound.has( `taxonomy:${ taxonomy.key }` )
			)
			.map( ( taxonomy ) => ( { bind: { taxonomy: taxonomy.key } } ) ),
	];
}

/**
 * The registry rules of a bound item as parts to word ("From Project: required, 50 to 300 words").
 *
 * @param {Object} item Resolved bound item.
 * @return {Array<Object>} Parts: `{rule: 'required'}`, `{rule: 'required_when', field, equals}`,
 *                         `{rule: 'show_when', field, equals}`, `{rule: 'length'|'words'|'range', min, max}`,
 *                         `{rule: 'pattern'}`, `{rule: 'terms', multiple}`, `{rule: 'private'}`.
 */
export function ruleParts( item ) {
	const parts = [];
	if ( item.required ) {
		parts.push( { rule: 'required' } );
	}
	if ( item.required_when ) {
		parts.push( { rule: 'required_when', ...item.required_when } );
	}
	if ( item.show_when ) {
		parts.push( { rule: 'show_when', ...item.show_when } );
	}
	const rules = item.validate || {};
	const pairs = [
		[ 'length', rules.min_length, rules.max_length ],
		[ 'words', rules.min_words, rules.max_words ],
		[ 'range', rules.min, rules.max ],
	];
	for ( const [ rule, min, max ] of pairs ) {
		if ( min !== undefined || max !== undefined ) {
			parts.push( { rule, min: min ?? null, max: max ?? null } );
		}
	}
	if ( rules.pattern ) {
		parts.push( { rule: 'pattern' } );
	}
	if ( item.bound === 'taxonomy' ) {
		parts.push( { rule: 'terms', multiple: item.multiple === true } );
	}
	if ( item.private ) {
		parts.push( { rule: 'private' } );
	}
	return parts;
}

/**
 * Values a `{field, equals}` condition can test for another item: booleans checked or not, select
 * options, else free text (numbers for number items).
 *
 * @param {?Object} item Resolved item the condition names.
 * @return {{kind: 'boolean'|'options'|'number'|'text', options: Array<{value: string, label: string}>}} Picker.
 */
export function conditionValues( item ) {
	if ( ! item ) {
		return { kind: 'text', options: [] };
	}
	if ( item.type === 'boolean' ) {
		return { kind: 'boolean', options: [] };
	}
	if ( item.type === 'select' ) {
		return { kind: 'options', options: item.options || [] };
	}
	return { kind: item.type === 'number' ? 'number' : 'text', options: [] };
}

/**
 * Integer from a number input, or undefined when empty or not a number.
 *
 * @param {string} value Input value.
 * @return {number|undefined} Integer.
 */
export function toInt( value ) {
	if ( value === '' || value === null || value === undefined ) {
		return undefined;
	}
	const number = Number( value );
	return Number.isFinite( number ) ? Math.trunc( number ) : undefined;
}

/**
 * Number from a number input, or undefined when empty or not a number.
 *
 * @param {string} value Input value.
 * @return {number|undefined} Number.
 */
export function toNumber( value ) {
	if ( value === '' || value === null || value === undefined ) {
		return undefined;
	}
	const number = Number( value );
	return Number.isFinite( number ) ? number : undefined;
}

/**
 * Extensions from a comma or space separated list: lowercase, without dots, unique.
 *
 * @param {string} value Input.
 * @return {string[]} Extensions.
 */
export function parseExtensions( value ) {
	return [
		...new Set(
			String( value || '' )
				.toLowerCase()
				.split( /[\s,]+/ )
				.map( ( ext ) => ext.replace( /^\.+/, '' ) )
				.filter( Boolean )
		),
	];
}
