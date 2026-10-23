/**
 * Pure builder logic: config and settings, the block tree as a definition, JSON pointers, save
 * errors, step ids and durations. No WordPress imports, so it runs in unit tests as is.
 */

export const STEP = 'pegwork/automation-step';
export const DECISION = 'pegwork/automation-decision';
export const BRANCH = 'pegwork/automation-branch';
export const LOOP = 'pegwork/automation-loop';

/**
 * A loop body holds at most this many steps, counting the steps inside decisions.
 */
export const MAX_BODY = 10;

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
	'get_record',
	'find_records',
	'relate',
];

/**
 * Step types that take `"on_error": "continue"` (AutomationRules::ON_ERROR_TYPES).
 */
export const ON_ERROR_TYPES = [
	'update_record',
	'create_record',
	'send_email',
	'call_webhook',
	'relate',
	'get_record',
	'find_records',
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
	loop: 'each',
	get_record: 'get',
	find_records: 'find',
	relate: 'link',
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
		relationships: list( config.relationships ).filter(
			( relationship ) =>
				relationship && typeof relationship.key === 'string'
		),
		rest: config.rest && typeof config.rest === 'object' ? config.rest : {},
		lockedKey: text( config.lockedKey ),
		readOnly: config.readOnly === true,
		package: text( config.package ),
		duplicateUrl: text( config.duplicateUrl ),
		listUrl: text( config.listUrl ),
		webhookUrl: text( config.webhookUrl ),
		override: text( config.override ),
		openRuns: Number.isInteger( config.openRuns ) ? config.openRuns : 0,
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
		} else if ( block.name === LOOP ) {
			const { id, ...rest } = own( block.attributes );
			steps.push( {
				id,
				type: 'loop',
				...rest,
				steps: compileBlocks( block.innerBlocks ),
			} );
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
 * lists count only steps, decisions and loops; branches count without the otherwise branch, which
 * is `<decision>/otherwise`; a loop's body is `<loop>/steps`.
 *
 * @param {Array}  blocks    Blocks.
 * @param {string} [pointer] Pointer of the list.
 * @param {Object} [map]     Map to fill.
 * @return {Object<string, string>} Pointer by client ID.
 */
export function pointerMap( blocks, pointer = '/steps', map = {} ) {
	let index = 0;
	for ( const block of list( blocks ) ) {
		if ( ! [ STEP, DECISION, LOOP ].includes( block.name ) ) {
			continue;
		}
		const at = `${ pointer }/${ index++ }`;
		map[ block.clientId ] = at;
		if ( block.name === LOOP ) {
			pointerMap( block.innerBlocks, `${ at }/steps`, map );
		}
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
 * The id a mounting step or decision should take, or null to keep its own. Reads the block's id
 * from `blocks` (the store), not from the props captured at mount: React's StrictMode runs a mount
 * effect twice in development builds (SCRIPT_DEBUG), and the second run must see the id the first
 * one gave, or a new block skips to `decision_2`.
 *
 * @param {Array}  blocks   Every block, in document order.
 * @param {string} clientId Block.
 * @param {string} id       Its id when it mounted (used when the block is not in `blocks`).
 * @param {string} type     Step type (or `decision`).
 * @return {?string} New id, or null.
 */
export function assignId( blocks, clientId, id, type ) {
	const self = blocks.find( ( block ) => block.clientId === clientId );
	if ( ! needsId( blocks, clientId, self ? self.attributes?.id : id ) ) {
		return null;
	}
	const taken = new Set(
		blocks.map( ( block ) => block.attributes?.id ).filter( Boolean )
	);
	return uniqueId( type, taken );
}

/**
 * Nesting level of a block's list: 1 at the top, 2 inside one decision's branch or a loop's body,
 * and so on.
 *
 * @param {?string} pointer Pointer of a step, decision or loop.
 * @return {number} Level.
 */
export function levelOf( pointer ) {
	return Math.max(
		1,
		(
			String( pointer || '' ).match(
				/\/(branches\/\d+\/)?(steps|otherwise)\/\d+/g
			) || []
		).length
	);
}

/**
 * The blocks one level inside a decision (its branches' steps) or a loop (its body).
 *
 * @param {Object} block Block.
 * @return {Array} Blocks.
 */
const children = ( block ) =>
	block?.name === DECISION
		? list( block.innerBlocks ).flatMap( ( branch ) =>
				list( branch.innerBlocks )
			)
		: list( block?.innerBlocks );

/**
 * Nesting levels in a block: 0 for a step, 1 plus the deepest inner one for a decision or a loop.
 *
 * @param {Object} block Block.
 * @return {number} Height.
 */
export function heightOf( block ) {
	if ( block?.name !== DECISION && block?.name !== LOOP ) {
		return 0;
	}
	return 1 + Math.max( 0, ...children( block ).map( heightOf ) );
}

/**
 * Every step list a block can move to ("Move to"): the top level, each branch and each loop body
 * outside the block itself. Omits lists that would break the nesting limit, and loop bodies for a
 * block that is or holds a wait or a loop (`loop_wait`, `loop_nested`).
 *
 * @param {Array}  blocks Top-level blocks.
 * @param {Object} block  The block being moved.
 * @return {Array<Object>} Targets: `rootClientId`, `count` (its blocks), `level`, and `loop` (a loop
 *                         body's loop id), or `decision` and `branch` (the branch's attributes).
 */
export function moveTargets( blocks, block ) {
	const targets = [ { rootClientId: '', count: blocks.length, level: 1 } ];
	const walk = ( items, level, inLoop ) => {
		for ( const item of items ) {
			if ( item.clientId === block.clientId ) {
				continue;
			}
			if ( item.name === LOOP ) {
				targets.push( {
					rootClientId: item.clientId,
					count: item.innerBlocks.length,
					level: level + 1,
					inLoop: true,
					loop: item.attributes.id,
				} );
				walk( item.innerBlocks, level + 1, true );
			}
			for ( const branch of item.name === DECISION
				? item.innerBlocks
				: [] ) {
				targets.push( {
					rootClientId: branch.clientId,
					count: branch.innerBlocks.length,
					level: level + 1,
					inLoop,
					decision: item.attributes.id,
					branch: branch.attributes,
				} );
				walk( branch.innerBlocks, level + 1, inLoop );
			}
		}
	};
	walk( blocks, 1, false );
	const height = heightOf( block );
	const loopless = ! flatten( [ block ] ).some(
		( item ) => item.name === LOOP || item.attributes?.type === 'wait'
	);
	return targets.filter(
		( target ) =>
			target.level + height - 1 <= MAX_LEVELS &&
			( loopless || ! target.inLoop )
	);
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
	const letter = Object.keys( UNITS ).find(
		( key ) => UNITS[ key ] === unit
	);
	return unit === 'hours'
		? `${ sign }PT${ amount }H`
		: `${ sign }P${ amount }${ letter || 'D' }`;
}

/**
 * Test run report steps as nested lists: a new level opens at each branch heading ("If High",
 * "Otherwise") and at each loop item (`heading` is then the entry's `item`). A loop's own lines
 * inside its body (a skipped item, "and 3 more items") close the item before them.
 *
 * @param {Array} steps Report steps (`depth`, `branch`, `item`, `type`, `message`, `result`).
 * @return {Array} Tree of `{ entry }` and `{ heading, children }`.
 */
export function reportTree( steps ) {
	const root = { children: [] };
	const stack = [ root ];
	for ( const entry of list( steps ) ) {
		const depth = Math.max( 0, Number( entry.depth ) || 0 );
		while ( stack.length - 1 > depth ) {
			stack.pop();
		}
		const heading = entry.item || entry.branch;
		if (
			entry.type === 'loop' &&
			( entry.detail?.more || entry.detail?.skipped_item )
		) {
			while ( stack.length > depth ) {
				stack.pop();
			}
		}
		if ( heading !== null && heading !== undefined && depth > 0 ) {
			while ( stack.length > depth ) {
				stack.pop();
			}
			const node = { heading, children: [] };
			stack[ stack.length - 1 ].children.push( node );
			stack.push( node );
		}
		while ( stack.length - 1 < depth ) {
			const node = { heading: null, children: [] };
			stack[ stack.length - 1 ].children.push( node );
			stack.push( node );
		}
		stack[ stack.length - 1 ].children.push( { entry } );
	}
	return root.children;
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
