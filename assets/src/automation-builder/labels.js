/**
 * Words for people: step types, operators, conditions and step summaries.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { parseDuration } from './logic';

/**
 * A step type's name, as the inserter shows it.
 *
 * @param {string} type Step type.
 * @return {string} Name.
 */
export function typeLabel( type ) {
	const labels = {
		send_email: __( 'Send email', 'pegwork' ),
		update_record: __( 'Update record', 'pegwork' ),
		create_record: __( 'Create record', 'pegwork' ),
		call_webhook: __( 'Call webhook', 'pegwork' ),
		wait: __( 'Wait', 'pegwork' ),
		condition: __( 'Continue only if', 'pegwork' ),
		decision: __( 'Decision', 'pegwork' ),
	};
	return labels[ type ] || type || __( 'Step', 'pegwork' );
}

/**
 * A short type word for List View names ("Email thank_major").
 *
 * @param {string} type Step type.
 * @return {string} Word.
 */
export function typeShort( type ) {
	const labels = {
		send_email: __( 'Email', 'pegwork' ),
		update_record: __( 'Update', 'pegwork' ),
		create_record: __( 'Create', 'pegwork' ),
		call_webhook: __( 'Webhook', 'pegwork' ),
		wait: __( 'Wait', 'pegwork' ),
		condition: __( 'Check', 'pegwork' ),
	};
	return labels[ type ] || __( 'Step', 'pegwork' );
}

/**
 * Operator words, by the type of value compared (dates read "is before").
 *
 * @param {string}  op   Operator.
 * @param {?string} type Field type.
 * @return {string} Words.
 */
export function opLabel( op, type ) {
	const date = type === 'date';
	const labels = {
		eq: __( 'is', 'pegwork' ),
		ne: __( 'is not', 'pegwork' ),
		in: __( 'is one of', 'pegwork' ),
		not_in: __( 'is none of', 'pegwork' ),
		lt: date
			? __( 'is before', 'pegwork' )
			: __( 'is less than', 'pegwork' ),
		lte: date
			? __( 'is on or before', 'pegwork' )
			: __( 'is at most', 'pegwork' ),
		gt: date
			? __( 'is after', 'pegwork' )
			: __( 'is more than', 'pegwork' ),
		gte: date
			? __( 'is on or after', 'pegwork' )
			: __( 'is at least', 'pegwork' ),
		empty: __( 'is empty', 'pegwork' ),
		not_empty: __( 'is not empty', 'pegwork' ),
		contains: __( 'contains', 'pegwork' ),
		changed: __( 'changed', 'pegwork' ),
	};
	return labels[ op ] || op;
}

/**
 * Names of pseudo fields and well-known paths.
 *
 * @param {string} path Path.
 * @return {string} Name, or the path itself.
 */
function pathName( path ) {
	const names = {
		'record.id': __( 'Record ID', 'pegwork' ),
		'record.status': __( 'Record status', 'pegwork' ),
		'record.type': __( 'Record type', 'pegwork' ),
		'record.permalink': __( 'Record link', 'pegwork' ),
		'record.edit_url': __( 'Record edit link', 'pegwork' ),
		'site.name': __( 'Site name', 'pegwork' ),
		'site.url': __( 'Site address', 'pegwork' ),
		today: __( 'Today', 'pegwork' ),
		now: __( 'Now', 'pegwork' ),
		'run.id': __( 'Run ID', 'pegwork' ),
	};
	return names[ path ] || path;
}

/**
 * The name of a field for people: its label (with the path for trigger and old values), else a
 * known name, else the path.
 *
 * @param {Object} field Field from scope.js (`path`, `label`, `private`).
 * @return {string} Name.
 */
export function fieldName( field ) {
	let name = field.label || pathName( field.path );
	if ( field.label && field.path.startsWith( 'trigger.old.' ) ) {
		/* translators: %s: field label. */
		name = sprintf( __( '%s (before the change)', 'pegwork' ), name );
	} else if ( field.label && field.path.startsWith( 'trigger.values.' ) ) {
		/* translators: %s: form field label. */
		name = sprintf( __( '%s (as submitted)', 'pegwork' ), name );
	}
	return field.private
		? /* translators: %s: field name. */ sprintf(
				__( '%s (private)', 'pegwork' ),
				name
			)
		: name;
}

/**
 * A duration in words ("30 days"), or the ISO text when it has several units.
 *
 * @param {string} value ISO 8601 duration.
 * @return {string} Words.
 */
export function durationText( value ) {
	const parts = parseDuration( value );
	if ( ! parts ) {
		return value || '';
	}
	const n = parts.amount;
	switch ( parts.unit ) {
		case 'years':
			/* translators: %d: number of years. */
			return sprintf( _n( '%d year', '%d years', n, 'pegwork' ), n );
		case 'months':
			/* translators: %d: number of months. */
			return sprintf( _n( '%d month', '%d months', n, 'pegwork' ), n );
		case 'weeks':
			/* translators: %d: number of weeks. */
			return sprintf( _n( '%d week', '%d weeks', n, 'pegwork' ), n );
		case 'hours':
			/* translators: %d: number of hours. */
			return sprintf( _n( '%d hour', '%d hours', n, 'pegwork' ), n );
		default:
			/* translators: %d: number of days. */
			return sprintf( _n( '%d day', '%d days', n, 'pegwork' ), n );
	}
}

const LIST_SEPARATOR = ', ';

/**
 * A literal or operand in words, with option labels for choices.
 *
 * @param {*}       value  Value.
 * @param {?Object} field  Field compared.
 * @param {Array}   fields Fields in scope (for `{var}` operands).
 * @return {string} Words.
 */
function valueText( value, field, fields ) {
	if ( Array.isArray( value ) ) {
		return value
			.map( ( item ) => valueText( item, field, fields ) )
			.join( LIST_SEPARATOR );
	}
	if ( value && typeof value === 'object' ) {
		if ( 'var' in value ) {
			const other = fields.find( ( item ) => item.path === value.var );
			return other ? fieldName( other ) : String( value.var );
		}
		const base =
			value.date === 'now'
				? __( 'now', 'pegwork' )
				: __( 'today', 'pegwork' );
		if ( ! value.offset ) {
			return base;
		}
		const parts = parseDuration( value.offset );
		const amount = durationText(
			String( value.offset ).replace( /^[-+]/, '' )
		);
		return parts?.sign === '-'
			? /* translators: 1: today or now, 2: duration such as "12 months". */ sprintf(
					__( '%1$s minus %2$s', 'pegwork' ),
					base,
					amount
				)
			: /* translators: 1: today or now, 2: duration such as "12 months". */ sprintf(
					__( '%1$s plus %2$s', 'pegwork' ),
					base,
					amount
				);
	}
	if ( typeof value === 'boolean' ) {
		return value ? __( 'true', 'pegwork' ) : __( 'false', 'pegwork' );
	}
	const option = ( field?.options || [] ).find(
		( item ) => item.value === value
	);
	return option ? option.label : `"${ value }"`;
}

/**
 * A condition in words: "Amount is at least 250", groups joined with "and" or "or".
 *
 * @param {?Object} node   Condition JSON.
 * @param {Array}   fields Fields in scope.
 * @return {string} Words ('' for none).
 */
export function conditionText( node, fields ) {
	if ( ! node || typeof node !== 'object' ) {
		return '';
	}
	const join = ( items, word ) => {
		const parts = items.map( ( item ) => conditionText( item, fields ) );
		return parts.length > 1
			? parts.map( ( part ) => `(${ part })` ).join( ` ${ word } ` )
			: parts.join( '' );
	};
	if ( Array.isArray( node.all ) ) {
		return join( node.all, __( 'and', 'pegwork' ) );
	}
	if ( Array.isArray( node.any ) ) {
		return join( node.any, __( 'or', 'pegwork' ) );
	}
	if ( 'not' in node ) {
		return sprintf(
			/* translators: %s: a condition in words. */
			__( 'not (%s)', 'pegwork' ),
			conditionText( node.not, fields )
		);
	}
	const field = fields.find( ( item ) => item.path === node.var ) || null;
	const name = field
		? fieldName( field )
		: pathName( String( node.var || '' ) );
	const op = opLabel( node.op, field?.type );
	return 'value' in node
		? `${ name } ${ op } ${ valueText( node.value, field, fields ) }`
		: `${ name } ${ op }`;
}

/**
 * The host of a URL template, or the template itself.
 *
 * @param {string} url URL.
 * @return {string} Host.
 */
function hostOf( url ) {
	const match = /^https?:\/\/([^/?#]+)/.exec( url || '' );
	return match ? match[ 1 ] : url || '';
}

/**
 * A step's one-line summary for its card ("Email to {{record.donor_email}}: Thank you").
 *
 * @param {Object} step   Step attributes.
 * @param {Array}  fields Fields in scope at the step.
 * @param {Object} config Parsed config.
 * @return {string} Summary ('' when there is nothing to say yet).
 */
export function stepSummary( step, fields, config ) {
	const keysOf = () =>
		Object.keys( step.values || {} ).join( LIST_SEPARATOR );
	switch ( step.type ) {
		case 'send_email':
			return step.to || step.subject
				? /* translators: 1: recipient, 2: subject. */ sprintf(
						__( 'Email to %1$s: %2$s', 'pegwork' ),
						step.to || __( '(no recipient)', 'pegwork' ),
						step.subject || __( '(no subject)', 'pegwork' )
					)
				: '';
		case 'update_record': {
			const keys = keysOf();
			if ( ! keys ) {
				return '';
			}
			return step.record
				? /* translators: 1: record ID template, 2: field keys. */ sprintf(
						__( 'Update record %1$s: %2$s', 'pegwork' ),
						step.record,
						keys
					)
				: /* translators: %s: field keys. */ sprintf(
						__( 'Update this record: %s', 'pegwork' ),
						keys
					);
		}
		case 'create_record': {
			const type = config.types.find(
				( item ) => item.key === step.content_type
			);
			return step.content_type
				? /* translators: 1: content type label, 2: field keys. */ sprintf(
						__( 'Create %1$s: %2$s', 'pegwork' ),
						type?.label || step.content_type,
						keysOf() || __( '(no values)', 'pegwork' )
					)
				: '';
		}
		case 'call_webhook':
			return step.url
				? /* translators: %s: host name. */ sprintf(
						__( 'Send to %s', 'pegwork' ),
						hostOf( step.url )
					)
				: '';
		case 'wait':
			return step.for
				? /* translators: %s: duration such as "30 days". */ sprintf(
						__( 'Wait %s, then continue', 'pegwork' ),
						durationText( step.for )
					)
				: '';
		case 'condition':
			return step.when
				? /* translators: %s: condition in words. */ sprintf(
						__( 'Continue only if %s', 'pegwork' ),
						conditionText( step.when, fields )
					)
				: '';
		default:
			return '';
	}
}
