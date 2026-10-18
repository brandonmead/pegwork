/**
 * Pure helpers for the Show if (pegwork/conditional) edit UI.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { findField, findTaxonomy } from './config';

export const FIELD_OPS = [ 'equals', 'not_equals', 'empty', 'not_empty' ];
export const TAXONOMY_OPS = [ 'in', 'not_in' ];

/**
 * The content type the block edits for: the context post type when Pegwork knows it,
 * else the type of a `single-<type>` or `archive-<type>` template, else null.
 *
 * @param {Object}  config       Parsed config.
 * @param {?string} contextType  Block context postType.
 * @param {?string} templateSlug Current template ID or slug, if editing a template.
 * @return {?string} Post type key.
 */
export function resolvePostType( config, contextType, templateSlug ) {
	if ( contextType && config.types[ contextType ] ) {
		return contextType;
	}
	const match = /(?:^|\/)(?:single|archive)-([a-z0-9_-]+)$/.exec(
		templateSlug ?? ''
	);
	return match && config.types[ match[ 1 ] ] ? match[ 1 ] : null;
}

/**
 * Why a condition renders nothing, or null when it is valid. Mirrors the server: unknown
 * or private fields, taxonomies not on the type, and unknown operators fail closed.
 *
 * @param {Object}  condition Condition.
 * @param {?Object} type      Parsed type config.
 * @return {?string} `unknown`, `operator`, or null.
 */
export function conditionProblem( condition, type ) {
	if ( typeof condition?.taxonomy === 'string' ) {
		if ( ! findTaxonomy( type, condition.taxonomy ) ) {
			return 'unknown';
		}
		return TAXONOMY_OPS.includes( condition.op ) ? null : 'operator';
	}
	if ( typeof condition?.field === 'string' ) {
		if ( ! findField( type, condition.field ) ) {
			return 'unknown';
		}
		return FIELD_OPS.includes( condition.op ) ? null : 'operator';
	}
	return 'unknown';
}

/**
 * Select value naming a condition's target.
 *
 * @param {Object} condition Condition.
 * @return {string} `field:<key>`, `taxonomy:<key>`, or ''.
 */
export function targetOf( condition ) {
	if ( typeof condition?.taxonomy === 'string' ) {
		return `taxonomy:${ condition.taxonomy }`;
	}
	if ( typeof condition?.field === 'string' ) {
		return `field:${ condition.field }`;
	}
	return '';
}

/**
 * A new condition for a target, with a sensible operator and value.
 *
 * @param {string}  target `field:<key>` or `taxonomy:<key>`.
 * @param {?Object} type   Parsed type config.
 * @return {?Object} Condition, or null for an unknown target.
 */
export function conditionFor( target, type ) {
	const [ kind, key ] = target.split( ':' );
	if ( kind === 'taxonomy' && findTaxonomy( type, key ) ) {
		return { taxonomy: key, op: 'in', terms: [] };
	}
	const field = kind === 'field' ? findField( type, key ) : null;
	if ( ! field ) {
		return null;
	}
	if ( field.type === 'boolean' ) {
		return { field: key, op: 'equals', value: true };
	}
	if ( field.type === 'select' && field.options.length ) {
		return { field: key, op: 'equals', value: field.options[ 0 ].value };
	}
	return { field: key, op: 'not_empty' };
}

const OP_LABELS = () => ( {
	equals: __( 'is', 'pegwork' ),
	not_equals: __( 'is not', 'pegwork' ),
	empty: __( 'is empty', 'pegwork' ),
	not_empty: __( 'is not empty', 'pegwork' ),
	in: __( 'is any of', 'pegwork' ),
	not_in: __( 'is none of', 'pegwork' ),
} );

/**
 * Operator label.
 *
 * @param {string} op Operator.
 * @return {string} Label.
 */
export function opLabel( op ) {
	return OP_LABELS()[ op ] ?? op;
}

/**
 * One-line, human description of a condition.
 *
 * @param {Object}  condition Condition.
 * @param {?Object} type      Parsed type config.
 * @return {string} Description.
 */
export function describeCondition( condition, type ) {
	if ( conditionProblem( condition, type ) ) {
		return sprintf(
			/* translators: %s: field or taxonomy key */
			__( '%s (unavailable: renders nothing)', 'pegwork' ),
			condition?.field ?? condition?.taxonomy ?? '?'
		);
	}
	if ( condition.taxonomy ) {
		const taxonomy = findTaxonomy( type, condition.taxonomy );
		const names = ( condition.terms ?? [] ).map(
			( slug ) =>
				taxonomy.terms.find( ( term ) => term.slug === slug )?.name ??
				slug
		);
		return `${ taxonomy.label } ${ opLabel( condition.op ) } ${
			names.join( ', ' ) || '-'
		}`;
	}

	const field = findField( type, condition.field );
	if ( condition.op === 'empty' || condition.op === 'not_empty' ) {
		return `${ field.label } ${ opLabel( condition.op ) }`;
	}
	let value = condition.value;
	if ( field.type === 'boolean' ) {
		const on = value === true || value === '1';
		value = on ? __( 'on', 'pegwork' ) : __( 'off', 'pegwork' );
	} else if ( field.type === 'select' ) {
		value =
			field.options.find( ( option ) => option.value === String( value ) )
				?.label ?? value;
	}
	return `${ field.label } ${ opLabel( condition.op ) } ${ value ?? '' }`.trim();
}
