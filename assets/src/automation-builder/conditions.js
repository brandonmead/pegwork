/**
 * The condition builder's model, its JSON (the registry condition grammar) and the operator rules.
 * Pure: no WordPress imports.
 *
 * Model nodes map one to one onto the grammar, so every valid tree round-trips byte for byte:
 * `{ kind: 'all'|'any', items }`, `{ kind: 'not', item }` and
 * `{ kind: 'leaf', var, op, value, has }` (`has`: whether the leaf carries a `value`).
 */

/**
 * Operators (AutomationRules::CONDITION_OPS), and those that take no value or a list.
 */
export const OPS = [
	'eq',
	'ne',
	'in',
	'not_in',
	'lt',
	'lte',
	'gt',
	'gte',
	'empty',
	'not_empty',
	'contains',
	'changed',
];
export const UNARY_OPS = [ 'empty', 'not_empty', 'changed' ];
export const LIST_OPS = [ 'in', 'not_in' ];
const ORDER_OPS = [ 'lt', 'lte', 'gt', 'gte' ];

/**
 * Limits of one condition tree (CONDITION_MAX_DEPTH, CONDITION_MAX_NODES).
 */
export const MAX_DEPTH = 5;
export const MAX_NODES = 50;

/**
 * The model of a condition.
 *
 * @param {unknown} node Condition JSON.
 * @return {?Object} Model, or null when there is no condition.
 */
export function toModel( node ) {
	if ( ! node || typeof node !== 'object' || Array.isArray( node ) ) {
		return null;
	}
	for ( const kind of [ 'all', 'any' ] ) {
		if ( Array.isArray( node[ kind ] ) ) {
			return { kind, items: node[ kind ].map( toModel ) };
		}
	}
	if ( 'not' in node ) {
		return { kind: 'not', item: toModel( node.not ) };
	}
	return {
		kind: 'leaf',
		var: node.var ?? '',
		op: node.op ?? 'eq',
		value: node.value,
		has: 'value' in node,
	};
}

/**
 * The condition JSON of a model.
 *
 * @param {?Object} model Model.
 * @return {Object|undefined} Condition, or undefined for none.
 */
export function toJSON( model ) {
	if ( ! model ) {
		return undefined;
	}
	if ( model.kind === 'all' || model.kind === 'any' ) {
		return { [ model.kind ]: model.items.map( toJSON ) };
	}
	if ( model.kind === 'not' ) {
		return { not: toJSON( model.item ) };
	}
	const leaf = { var: model.var, op: model.op };
	if ( model.has ) {
		leaf.value = model.value;
	}
	return leaf;
}

/**
 * Depth and node count of a model, as the validator counts them (the root is depth 1).
 *
 * @param {?Object} model   Model.
 * @param {number}  [level] Level of this node.
 * @return {{depth: number, nodes: number}} Size.
 */
export function size( model, level = 1 ) {
	if ( ! model ) {
		return { depth: 0, nodes: 0 };
	}
	const children = model.kind === 'not' ? [ model.item ] : model.items || [];
	let depth = level;
	let nodes = 1;
	for ( const child of children ) {
		const inner = size( child, level + 1 );
		depth = Math.max( depth, inner.depth );
		nodes += inner.nodes;
	}
	return { depth, nodes };
}

/**
 * Operators a var may use, with the validator's rules (check_leaf and scan_condition_node):
 * order operators for number and date values, `contains` for multi-select taxonomies, `changed`
 * only for `record.*` on a record.updated trigger. A var of unknown type (payloads, step outputs)
 * may use every operator except a misplaced `changed`.
 *
 * @param {?Object} field Field of the var (`type`, `multiple`), or null when its type is unknown.
 * @param {string}  path  Var path.
 * @param {?string} event Trigger event, or null.
 * @return {string[]} Operators.
 */
export function opsFor( field, path, event ) {
	const type = field?.type ?? null;
	return OPS.filter( ( op ) => {
		if ( op === 'changed' ) {
			return event === 'record.updated' && path.startsWith( 'record.' );
		}
		if ( type === null ) {
			return true;
		}
		if ( ORDER_OPS.includes( op ) ) {
			return type === 'number' || type === 'date';
		}
		if ( op === 'contains' ) {
			return type === 'taxonomy' && field.multiple === true;
		}
		return true;
	} );
}

/**
 * The kind of value control a leaf shows: none, a list, another field, a date offset or a literal.
 *
 * @param {Object} leaf Leaf model.
 * @return {'none'|'list'|'var'|'date'|'literal'} Kind.
 */
export function valueKind( leaf ) {
	if ( UNARY_OPS.includes( leaf.op ) ) {
		return 'none';
	}
	if ( LIST_OPS.includes( leaf.op ) ) {
		return 'list';
	}
	const value = leaf.value;
	if ( value && typeof value === 'object' && ! Array.isArray( value ) ) {
		if ( 'var' in value ) {
			return 'var';
		}
		if ( 'date' in value ) {
			return 'date';
		}
	}
	return 'literal';
}

/**
 * A literal of a field's type: the first option, false, 0, today's date shape, or ''.
 *
 * @param {?Object} field Field.
 * @return {*} Value.
 */
export function blankValue( field ) {
	switch ( field?.type ) {
		case 'boolean':
			return true;
		case 'number':
			return 0;
		case 'select':
		case 'taxonomy':
			return field.options?.[ 0 ]?.value ?? '';
		default:
			return '';
	}
}

/**
 * A leaf with a new operator, its value reshaped: dropped for unary operators, a list for `in`
 * and `not_in`, one value otherwise.
 *
 * @param {Object}  leaf  Leaf model.
 * @param {string}  op    Operator.
 * @param {?Object} field Field of the var.
 * @return {Object} Leaf.
 */
export function withOp( leaf, op, field ) {
	if ( UNARY_OPS.includes( op ) ) {
		return { ...leaf, op, value: undefined, has: false };
	}
	let value = leaf.has ? leaf.value : blankValue( field );
	if ( LIST_OPS.includes( op ) && ! Array.isArray( value ) ) {
		value =
			value === '' || ( value && typeof value === 'object' )
				? []
				: [ value ];
	} else if ( ! LIST_OPS.includes( op ) && Array.isArray( value ) ) {
		value = value.length ? value[ 0 ] : blankValue( field );
	}
	return { ...leaf, op, value, has: true };
}

/**
 * Every condition in a definition, by JSON pointer: the trigger's `when`, a sweep's `where`,
 * condition steps and branch conditions, at every depth.
 *
 * @param {Object} definition Automation.
 * @return {Object<string, Object>} Conditions.
 */
export function conditionsOf( definition ) {
	const found = {};
	const trigger = definition?.trigger || {};
	if ( trigger.when ) {
		found[ '/trigger/when' ] = trigger.when;
	}
	if ( trigger.for_each?.where ) {
		found[ '/trigger/for_each/where' ] = trigger.for_each.where;
	}
	const walk = ( steps, pointer ) =>
		( steps || [] ).forEach( ( step, index ) => {
			const at = `${ pointer }/${ index }`;
			if ( step.type === 'condition' && step.when ) {
				found[ `${ at }/when` ] = step.when;
			}
			( step.branches || [] ).forEach( ( branch, b ) => {
				found[ `${ at }/branches/${ b }/when` ] = branch.when;
				walk( branch.steps, `${ at }/branches/${ b }/steps` );
			} );
			walk( step.otherwise, `${ at }/otherwise` );
		} );
	walk( definition?.steps, '/steps' );
	for ( const [ name, link ] of Object.entries( definition?.links || {} ) ) {
		walk( link.steps, `/links/${ name }/steps` );
	}
	return found;
}
