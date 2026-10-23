/**
 * Pure builder logic: config and settings, the block tree as a definition, JSON pointers, save
 * errors, step ids and durations. No WordPress imports, so it runs in unit tests as is.
 */

export const STEP = 'pegwork/automation-step';
export const DECISION = 'pegwork/automation-decision';
export const BRANCH = 'pegwork/automation-branch';

/**
 * Step types offered as variations, in inserter order.
 */
export const STEP_TYPES = [
	'send_email',
	'update_record',
	'create_record',
	'call_webhook',
	'wait',
	'condition',
];

/**
 * Base of generated step ids, by type.
 */
const ID_BASE = {
	send_email: 'email',
	update_record: 'update',
	create_record: 'create',
	call_webhook: 'webhook',
	wait: 'wait',
	condition: 'check',
	decision: 'decision',
};

/**
 * Decisions nest at most this many levels (AutomationRules::DECISION_MAX_LEVELS), with at most this
 * many branches each (DECISION_MAX_BRANCHES).
 */
export const MAX_LEVELS = 3;
export const MAX_BRANCHES = 10;

/**
 * Attributes the block editor adds; never part of the definition (Builder::EDITOR_ATTRIBUTES).
 */
const EDITOR_ATTRIBUTES = [ 'lock', 'metadata', 'className', 'otherwise' ];

const list = ( value ) => ( Array.isArray( value ) ? value : [] );
const text = ( value ) => ( typeof value === 'string' ? value : '' );

/**
 * Normalizes the inline config (window.pegworkAutomationBuilder).
 *
 * @param {unknown} raw Config from PHP.
 * @return {Object} Config.
 */
export function parseConfig( raw ) {
	const config = raw && typeof raw === 'object' ? raw : {};
	return {
		types: list( config.types ).filter(
			( type ) => type && typeof type.key === 'string'
		),
		forms: list( config.forms ).filter(
			( form ) => form && typeof form.key === 'string'
		),
		optionsPages: list( config.optionsPages ).filter(
			( page ) => page && typeof page.key === 'string'
		),
		rest:
			config.rest && typeof config.rest === 'object' ? config.rest : {},
		lockedKey: text( config.lockedKey ),
		readOnly: config.readOnly === true,
		package: text( config.package ),
		duplicateUrl: text( config.duplicateUrl ),
		listUrl: text( config.listUrl ),
		webhookUrl: text( config.webhookUrl ),
		override: text( config.override ),
		openRuns: Number.isInteger( config.openRuns ) ? config.openRuns : 0,
		testAvailable: config.testAvailable === true,
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
 * Settings as meta JSON. Undefined properties are dropped by JSON.stringify; everything else is kept
 * as it is, so `links` and `ui` survive untouched.
 *
 * @param {Object} settings Settings.
 * @return {string} JSON.
 */
export function serializeSettings( settings ) {
	return JSON.stringify( settings || {} );
}

/**
 * A copy of an object with one property set, or removed when the value is undefined, null or ''.
 *
 * @param {Object} object Object.
 * @param {string} name   Property.
 * @param {*}      value  Value.
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
 * A key from a label, the same rule as PHP `Builder::slug()`: lowercase ASCII letters, digits and
 * underscores, starting with a letter, at most 64 characters.
 *
 * @param {unknown} label Label.
 * @return {string} Key, or ''.
 */
export function slugify( label ) {
	let slug = String( label ?? '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' );
	if ( slug !== '' && ! /^[a-z]/.test( slug ) ) {
		slug = `a_${ slug }`;
	}
	return slug.slice( 0, 64 ).replace( /_+$/, '' );
}

/**
 * A block's definition attributes, as `Builder::attributes()` reads them: editor-only ones dropped,
 * undefined and null dropped.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} Definition properties.
 */
function own( attributes ) {
	const out = {};
	for ( const [ name, value ] of Object.entries( attributes || {} ) ) {
		if (
			! EDITOR_ATTRIBUTES.includes( name ) &&
			value !== undefined &&
			value !== null
		) {
			out[ name ] = value;
		}
	}
	return out;
}

/**
 * The step list a block list describes, as `Builder::compile()` builds it.
 *
 * @param {Array} blocks Blocks (with inner blocks).
 * @return {Array<Object>} Steps.
 */
export function compileBlocks( blocks ) {
	const steps = [];
	for ( const block of list( blocks ) ) {
		if ( block.name === STEP ) {
			steps.push( own( block.attributes ) );
		} else if ( block.name === DECISION ) {
			const { id, ...rest } = own( block.attributes );
			const decision = { id, type: 'decision', ...rest, branches: [] };
			for ( const branch of list( block.innerBlocks ) ) {
				if ( branch.name !== BRANCH ) {
					continue;
				}
				const inner = compileBlocks( branch.innerBlocks );
				if ( branch.attributes?.otherwise ) {
					if ( inner.length && ! decision.otherwise ) {
						decision.otherwise = inner;
					}
				} else {
					decision.branches.push( {
						...own( branch.attributes ),
						steps: inner,
					} );
				}
			}
			steps.push( decision );
		}
	}
	return steps;
}

/**
 * JSON pointer of every step, decision and branch block, as `Builder::compile()` numbers them: step
 * lists count only steps and decisions; branches count without the otherwise branch, which is
 * `<decision>/otherwise`.
 *
 * @param {Array}  blocks    Blocks.
 * @param {string} [pointer] Pointer of the list.
 * @param {Object} [map]     Map to fill.
 * @return {Object<string, string>} Pointer by client ID.
 */
export function pointerMap( blocks, pointer = '/steps', map = {} ) {
	let index = 0;
	for ( const block of list( blocks ) ) {
		if ( block.name !== STEP && block.name !== DECISION ) {
			continue;
		}
		const at = `${ pointer }/${ index++ }`;
		map[ block.clientId ] = at;
		if ( block.name !== DECISION ) {
			continue;
		}
		let branchIndex = 0;
		for ( const branch of list( block.innerBlocks ) ) {
			if ( branch.name !== BRANCH ) {
				continue;
			}
			if ( branch.attributes?.otherwise ) {
				map[ branch.clientId ] = `${ at }/otherwise`;
				pointerMap( branch.innerBlocks, `${ at }/otherwise`, map );
			} else {
				const branchAt = `${ at }/branches/${ branchIndex++ }`;
				map[ branch.clientId ] = branchAt;
				pointerMap( branch.innerBlocks, `${ branchAt }/steps`, map );
			}
		}
	}
	return map;
}

/**
 * Every block of the tree in document order.
 *
 * @param {Array} blocks Blocks.
 * @param {Array} [out]  List to fill.
 * @return {Array} Blocks.
 */
export function flatten( blocks, out = [] ) {
	for ( const block of list( blocks ) ) {
		out.push( block );
		flatten( block.innerBlocks, out );
	}
	return out;
}

const NO_ERRORS = Object.freeze( {} );

/**
 * Messages by pointer from a failed save.
 *
 * @param {unknown} error Last save error (REST error body), or null.
 * @return {Object<string, string>} Messages; empty unless the error is `pegwork_invalid_automation`.
 */
export function saveErrors( error ) {
	const fields = error?.data?.fields;
	if (
		! error ||
		error.code !== 'pegwork_invalid_automation' ||
		! fields ||
		typeof fields !== 'object' ||
		Array.isArray( fields )
	) {
		return NO_ERRORS;
	}
	return fields;
}

/**
 * Splits save errors by owner: each pointer goes to the block whose pointer is its longest prefix;
 * the rest are document settings (`/trigger/...`, `/key`) or general (`/`, `/steps`).
 *
 * @param {Object<string, string>} errors Messages by pointer.
 * @param {Object<string, string>} map    Pointer by client ID.
 * @return {{blocks: Object<string, Object<string, string>>, settings: Object<string, string>}} Errors.
 */
export function errorOwners( errors, map ) {
	const owners = { blocks: {}, settings: {} };
	const pointers = Object.entries( map );
	for ( const [ key, message ] of Object.entries( errors || {} ) ) {
		let owner = null;
		let length = -1;
		for ( const [ clientId, pointer ] of pointers ) {
			if (
				( key === pointer || key.startsWith( `${ pointer }/` ) ) &&
				pointer.length > length
			) {
				owner = clientId;
				length = pointer.length;
			}
		}
		if ( owner ) {
			owners.blocks[ owner ] = {
				...owners.blocks[ owner ],
				[ key ]: message,
			};
		} else {
			owners.settings[ key ] = message;
		}
	}
	return owners;
}

/**
 * Messages at a pointer, or at it and below.
 *
 * @param {Object<string, string>} errors  Messages by pointer.
 * @param {string}                 pointer Pointer.
 * @param {boolean}                [deep]  Include pointers below (default true).
 * @return {string[]} Messages.
 */
export function errorsAt( errors, pointer, deep = true ) {
	return Object.keys( errors || {} )
		.filter(
			( key ) =>
				key === pointer || ( deep && key.startsWith( `${ pointer }/` ) )
		)
		.map( ( key ) => errors[ key ] );
}

/**
 * A step id no other step uses: the type's base, else base_2, base_3 and so on.
 *
 * @param {string}      type  Step type.
 * @param {Set<string>} taken Ids in use.
 * @return {string} Id.
 */
export function uniqueId( type, taken ) {
	const base = ID_BASE[ type ] || 'step';
	let id = base;
	for ( let n = 2; taken.has( id ); n++ ) {
		id = `${ base }_${ n }`;
	}
	return id;
}

/**
 * Whether a block needs a new id: it has none, or an earlier block in document order has the same
 * one (a duplicated or pasted block).
 *
 * @param {Array}  blocks   Every block, in document order.
 * @param {string} clientId Block.
 * @param {string} id       Its id.
 * @return {boolean} Whether to replace it.
 */
export function needsId( blocks, clientId, id ) {
	if ( ! id ) {
		return true;
	}
	for ( const block of blocks ) {
		if ( block.clientId === clientId ) {
			return false;
		}
		if ( block.name !== BRANCH && block.attributes?.id === id ) {
			return true;
		}
	}
	return false;
}

/**
 * Nesting level of a block's list: 1 at the top, 2 inside one decision's branch, and so on.
 *
 * @param {?string} pointer Pointer of a step or decision.
 * @return {number} Level.
 */
export function levelOf( pointer ) {
	return ( String( pointer || '' ).match( /\/(branches\/\d+\/steps|otherwise)\//g ) || [] )
		.length + 1;
}

const UNITS = { Y: 'years', M: 'months', W: 'weeks', D: 'days', H: 'hours' };

/**
 * A single-unit ISO 8601 duration as amount and unit (`P30D` is 30 days), or null when it has more
 * than one unit or is not a duration.
 *
 * @param {unknown} value Duration.
 * @return {?{amount: number, unit: string, sign: string}} Parts.
 */
export function parseDuration( value ) {
	const match = /^([-+]?)P(?:(\d+)([YMWD])|T(\d+)H)$/.exec( text( value ) );
	if ( ! match ) {
		return null;
	}
	return match[ 2 ]
		? {
				sign: match[ 1 ],
				amount: Number( match[ 2 ] ),
				unit: UNITS[ match[ 3 ] ],
			}
		: { sign: match[ 1 ], amount: Number( match[ 4 ] ), unit: 'hours' };
}

/**
 * An ISO 8601 duration from amount and unit.
 *
 * @param {number} amount Amount (positive integer).
 * @param {string} unit   years, months, weeks, days or hours.
 * @param {string} [sign] '' or '-'.
 * @return {string} Duration, or '' when the amount is not a positive integer.
 */
export function formatDuration( amount, unit, sign = '' ) {
	if ( ! Number.isInteger( amount ) || amount < 1 ) {
		return '';
	}
	const letter = Object.keys( UNITS ).find( ( key ) => UNITS[ key ] === unit );
	return unit === 'hours'
		? `${ sign }PT${ amount }H`
		: `${ sign }P${ amount }${ letter || 'D' }`;
}

/**
 * A number from an input, or undefined when empty or not a number.
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
