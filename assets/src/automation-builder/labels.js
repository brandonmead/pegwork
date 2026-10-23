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
import { filterFields, filterToCondition } from './reads';
import { typeFields, typeOf } from './scope';

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
		get_record: __( 'Get a record', 'pegwork' ),
		find_records: __( 'Find records', 'pegwork' ),
		relate: __( 'Link records', 'pegwork' ),
		loop: __( 'Repeat for each', 'pegwork' ),
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
		get_record: __( 'Get', 'pegwork' ),
		find_records: __( 'Find', 'pegwork' ),
		relate: __( 'Link', 'pegwork' ),
		loop: __( 'Repeat', 'pegwork' ),
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
		'trigger.user_id': __( 'Started by (user ID)', 'pegwork' ),
		'trigger.source': __( 'Started from', 'pegwork' ),
		id: __( 'ID', 'pegwork' ),
	};
	return names[ path ] || path;
}

/**
 * The name of a step output: a read record's field label or key name, "Found", "Total Amount".
 *
 * @param {Object} field Step output path from scope.js (`path`, `label`).
 * @return {string} Name.
 */
function outputName( field ) {
	const parts = field.path.split( '.' );
	if ( parts.length === 4 && /^(record|item)$/.test( parts[ 2 ] ) ) {
		return field.label || pathName( `record.${ parts[ 3 ] }` );
	}
	if ( parts.length === 4 && parts[ 2 ] === 'sum' ) {
		return sprintf(
			/* translators: %s: field label. */
			__( 'Total %s', 'pegwork' ),
			field.label || parts[ 3 ]
		);
	}
	const names = {
		found: __( 'Found', 'pegwork' ),
		record_id: __( 'Record ID', 'pegwork' ),
		count:
			field.source?.type === 'loop'
				? __( 'Items repeated', 'pegwork' )
				: __( 'Number found', 'pegwork' ),
		index: __( 'Item number', 'pegwork' ),
		failed: __( 'Failed', 'pegwork' ),
		error: __( 'Error code', 'pegwork' ),
	};
	return names[ parts[ 2 ] ] || parts.slice( 2 ).join( '.' );
}

/**
 * The name of a field for people: its label (with the path for trigger and old values, and the
 * step for step outputs), else a known name, else the path.
 *
 * @param {Object}  field      Field from scope.js (`path`, `label`, `private`, `step`).
 * @param {boolean} [withStep] Name the step of a step output (off under a group heading).
 * @return {string} Name.
 */
export function fieldName( field, withStep = true ) {
	let name = field.label || pathName( field.path );
	if ( field.step && ! withStep ) {
		name = outputName( field );
	} else if ( field.step ) {
		name = sprintf(
			/* translators: 1: output name, 2: step ID. */
			__( '%1$s (from %2$s)', 'pegwork' ),
			outputName( field ),
			field.step
		);
	} else if ( field.label && field.path.startsWith( 'trigger.old.' ) ) {
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
 * The heading of a merge tag's group in the picker: "This Gift", "Donor (from donor)", "Totals
 * (from giving)", Trigger, Site, Time, Options, Links.
 *
 * @param {Object} tag    Tag from scope.js mergeTags().
 * @param {Object} config Parsed config.
 * @return {string} Heading.
 */
export function groupLabel( tag, config ) {
	const source = tag.source || {};
	switch ( tag.group ) {
		case 'record':
			return source.content_type
				? /* translators: %s: content type label. */ sprintf(
						__( 'This %s', 'pegwork' ),
						typeName( config, source.content_type )
					)
				: __( 'This record', 'pegwork' );
		case 'trigger':
			return __( 'Trigger', 'pegwork' );
		case 'steps': {
			let name;
			if ( source.item ) {
				return sprintf(
					/* translators: 1: content type label, 2: loop step ID. */
					__( 'Each %1$s (in %2$s)', 'pegwork' ),
					typeName( config, source.content_type ) ||
						__( 'item', 'pegwork' ),
					tag.step
				);
			}
			if ( source.type === 'get_record' ) {
				name =
					config.relationships.find(
						( item ) => item.key === source.relationship
					)?.label || typeName( config, source.content_type );
			} else if ( source.type === 'find_records' ) {
				name = __( 'Totals', 'pegwork' );
			} else {
				name = typeLabel( source.type );
			}
			return sprintf(
				/* translators: 1: group name, 2: step ID. */
				__( '%1$s (from %2$s)', 'pegwork' ),
				name,
				tag.step
			);
		}
		case 'time':
			return __( 'Time', 'pegwork' );
		case 'option':
			return __( 'Options', 'pegwork' );
		case 'link':
			return __( 'Links', 'pegwork' );
		default:
			return __( 'Site', 'pegwork' );
	}
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
	if ( option ) {
		return option.label;
	}
	// A number reads as itself ("Amount is at least 250"); only text is quoted.
	return typeof value === 'number' ? String( value ) : `"${ value }"`;
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
 * A content type's label for people.
 *
 * @param {Object}  config Parsed config.
 * @param {string}  key    Content type key.
 * @param {boolean} plural Plural label.
 * @return {string} Label (the key when unknown).
 */
function typeName( config, key, plural = false ) {
	const type = typeOf( config, key );
	return ( plural ? type?.plural : type?.label ) || type?.label || key;
}

/**
 * The record a reference template names, in words: "this Gift" for `{{record.id}}`, the step id
 * for `{{steps.<id>.record_id}}`, else the template.
 *
 * @param {string}  template Template.
 * @param {?string} bound    Bound content type.
 * @param {Object}  config   Parsed config.
 * @return {string} Words.
 */
function recordWords( template, bound, config ) {
	const value = String( template ?? '' ).trim();
	if ( /^\{\{\s*record\.id\s*\}\}$/.test( value ) ) {
		return bound
			? /* translators: %s: content type label. */ sprintf(
					__( 'this %s', 'pegwork' ),
					typeName( config, bound )
				)
			: __( 'this record', 'pegwork' );
	}
	const step = /^\{\{\s*steps\.([a-z0-9_]+)\.record_id\s*\}\}$/.exec( value );
	return step ? step[ 1 ] : value;
}

/**
 * The one-line summary of a get_record or find_records step ("Get Contact by relationship Donor of
 * this Gift", "Find Gifts linked to donor: total Amount").
 *
 * @param {Object}  step   Read step.
 * @param {Object}  config Parsed config.
 * @param {?string} bound  Bound content type.
 * @return {string} Summary.
 */
function readSummary( step, config, bound ) {
	if ( ! step.content_type ) {
		return '';
	}
	const get = step.type === 'get_record';
	const name = typeName( config, step.content_type, ! get );
	const relationship = config.relationships.find(
		( item ) => item.key === step.related?.relationship
	);
	const fields = filterFields( typeFields( config, step.content_type, '' ) );
	const where = step.filter
		? conditionText( filterToCondition( step.filter ), fields )
		: '';
	let text;
	if ( get && step.record !== undefined ) {
		text = sprintf(
			/* translators: 1: content type label, 2: record ID template. */
			__( 'Get %1$s by ID %2$s', 'pegwork' ),
			name,
			step.record
		);
	} else if ( step.related ) {
		const of = recordWords( step.related.of, bound, config );
		text = get
			? /* translators: 1: content type label, 2: relationship label, 3: record in words. */ sprintf(
					__( 'Get %1$s by relationship %2$s of %3$s', 'pegwork' ),
					name,
					relationship?.label || step.related.relationship,
					of
				)
			: /* translators: 1: content type plural label, 2: record in words. */ sprintf(
					__( 'Find %1$s linked to %2$s', 'pegwork' ),
					name,
					of
				);
		if ( where ) {
			/* translators: 1: read in words, 2: filter in words. */
			text = sprintf( __( '%1$s, where %2$s', 'pegwork' ), text, where );
		}
	} else {
		text = get
			? /* translators: 1: content type label, 2: filter in words. */ sprintf(
					__( 'Get %1$s where %2$s', 'pegwork' ),
					name,
					where
				)
			: /* translators: 1: content type plural label, 2: filter in words. */ sprintf(
					__( 'Find %1$s where %2$s', 'pegwork' ),
					name,
					where
				);
	}
	if ( ! get && step.sum?.length ) {
		const totals = step.sum
			.map(
				( key ) =>
					fields.find( ( field ) => field.key === key )?.label || key
			)
			.join( LIST_SEPARATOR );
		/* translators: 1: read in words, 2: field labels. */
		text = sprintf( __( '%1$s: total %2$s', 'pegwork' ), text, totals );
	}
	return text;
}

/**
 * A step's one-line summary for its card ("Email to {{record.donor_email}}: Thank you").
 *
 * @param {Object}  step     Step attributes.
 * @param {Array}   fields   Fields in scope at the step.
 * @param {Object}  config   Parsed config.
 * @param {?string} [bound]  Content type of the record the trigger binds.
 * @param {boolean} [inLoop] Whether a loop body holds the step.
 * @return {string} Summary ('' when there is nothing to say yet).
 */
export function stepSummary( step, fields, config, bound = null, inLoop ) {
	const keysOf = () =>
		Object.keys( step.values || {} ).join( LIST_SEPARATOR );
	switch ( step.type ) {
		case 'get_record':
		case 'find_records':
			return readSummary( step, config, bound );
		case 'relate': {
			const relationship = config.relationships.find(
				( item ) => item.key === step.relationship
			);
			return relationship
				? /* translators: 1: content type label, 2: content type label, 3: add or remove. */ sprintf(
						__( 'Link %1$s to %2$s (%3$s)', 'pegwork' ),
						typeName( config, relationship.from ),
						typeName( config, relationship.to ),
						step.action === 'remove'
							? __( 'remove', 'pegwork' )
							: __( 'add', 'pegwork' )
					)
				: '';
		}
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
				? `${ conditionLabel( inLoop ) } ${ conditionText(
						step.when,
						fields
					) }`
				: '';
		default:
			return '';
	}
}

/**
 * The name of a condition step: "Continue with this item only if" inside a loop body (a false
 * condition skips to the next item there), else "Continue only if".
 *
 * @param {boolean} inLoop Whether a loop body holds the step.
 * @return {string} Name.
 */
export function conditionLabel( inLoop ) {
	return inLoop
		? __( 'Continue with this item only if', 'pegwork' )
		: typeLabel( 'condition' );
}

/**
 * A list of records for people, as "For each" offers it: "Tasks from due".
 *
 * @param {Object} find   A find_records step.
 * @param {Object} config Parsed config.
 * @return {string} Name.
 */
export function listName( find, config ) {
	return sprintf(
		/* translators: 1: content type plural label, 2: step ID. */
		__( '%1$s from %2$s', 'pegwork' ),
		typeName( config, find.content_type, true ),
		find.id
	);
}

/**
 * A loop's card line: "For each Task from due (at most 50)".
 *
 * @param {?Object} find   The find_records step it repeats over.
 * @param {Object}  config Parsed config.
 * @return {string} Summary ('' without a list).
 */
export function loopSummary( find, config ) {
	return find
		? sprintf(
				/* translators: 1: content type label, 2: step ID, 3: most items. */
				__( 'For each %1$s from %2$s (at most %3$d)', 'pegwork' ),
				typeName( config, find.content_type ),
				find.id,
				find.limit ?? 10
			)
		: '';
}
