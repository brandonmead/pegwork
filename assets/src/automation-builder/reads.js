/**
 * The read inspectors' model (Get a record, Find records, Link records): how a read finds its
 * records, its filter rows as condition leaves, and which fields can be filtered, sorted and
 * totalled. Mirrors the query model rules of AutomationRules (read_source, filter_field) and
 * RecordQuery (UNFILTERABLE_TYPES, SORTABLE_TYPES). Pure: no WordPress imports.
 */

/**
 * Statuses a read may include, and the default (private and published).
 */
export const READ_STATUSES = [
	'publish',
	'future',
	'draft',
	'pending',
	'private',
];
const DEFAULT_STATUSES = [ 'private', 'publish' ];

const UNFILTERABLE = [ 'rich_text', 'textarea' ];
const SORTABLE = [ 'text', 'number', 'date', 'select', 'email' ];

/**
 * How a read finds its records: `record` (by ID, get_record only), `related`, `filter`, `both`, or
 * `created` (only its created date, which also counts for read_source).
 *
 * @param {Object} step Read step.
 * @return {string} Source ('' when it has none yet).
 */
export function readSource( step ) {
	if ( step.record !== undefined ) {
		return 'record';
	}
	if ( step.related && step.filter ) {
		return 'both';
	}
	if ( step.related ) {
		return 'related';
	}
	if ( step.filter ) {
		return 'filter';
	}
	return step.created ? 'created' : '';
}

/**
 * A read's `created` with one bound set, or removed when empty; undefined without bounds.
 *
 * @param {?Object} created `{ before?, after? }`.
 * @param {string}  bound   `before` or `after`.
 * @param {*}       value   `Y-m-d`, `{ date, offset? }` or `{ var }` ('' or undefined removes it).
 * @return {Object|undefined} Created.
 */
export function withCreated( created, bound, value ) {
	const next = { ...created, [ bound ]: value };
	if ( value === undefined || value === '' ) {
		delete next[ bound ];
	}
	return Object.keys( next ).length ? next : undefined;
}

/**
 * The attribute changes that switch a read to another source: what it no longer uses is removed,
 * what it gains starts empty (a relationship to choose, or one filter row).
 *
 * @param {Object}  step     Read step.
 * @param {string}  source   New source.
 * @param {?Object} firstRow First filter leaf for a new filter.
 * @return {Object} Attribute patch.
 */
export function withSource( step, source, firstRow ) {
	const related =
		source === 'related' || source === 'both'
			? step.related || { relationship: '', of: '' }
			: undefined;
	const filter =
		source === 'filter' || source === 'both'
			? step.filter || { all: firstRow ? [ firstRow ] : [] }
			: undefined;
	return {
		record: source === 'record' ? ( step.record ?? '' ) : undefined,
		related,
		filter,
		created:
			source === 'created'
				? step.created || { before: { date: 'today' } }
				: step.created,
	};
}

/**
 * A filter as a condition group the condition rows can edit: `field` becomes `var`.
 *
 * @param {?Object} filter Read filter (`{ all|any: [ { field, op, value } ] }`).
 * @return {Object} Condition (`{ all|any: [ { var, op, value } ] }`).
 */
export function filterToCondition( filter ) {
	const match = Array.isArray( filter?.any ) ? 'any' : 'all';
	return {
		[ match ]: ( filter?.[ match ] || [] ).map( ( leaf ) => {
			const out = { var: leaf.field, op: leaf.op };
			if ( 'value' in leaf ) {
				out.value = leaf.value;
			}
			return out;
		} ),
	};
}

/**
 * The read filter of a condition group (the inverse of filterToCondition()).
 *
 * @param {Object} condition Condition group.
 * @return {Object} Read filter.
 */
export function conditionToFilter( condition ) {
	const match = Array.isArray( condition?.any ) ? 'any' : 'all';
	return {
		[ match ]: ( condition?.[ match ] || [] ).map( ( leaf ) => {
			const out = { field: leaf.var, op: leaf.op };
			if ( 'value' in leaf ) {
				out.value = leaf.value;
			}
			return out;
		} ),
	};
}

/**
 * Fields of a type that a filter can test: `id`, then every field except long text and post
 * content, and the taxonomies. Paths are the bare keys, as filter leaves name them.
 *
 * @param {Array<Object>} fields Type fields from typeFields( config, type, '' ).
 * @return {Array<Object>} Filter fields.
 */
export function filterFields( fields ) {
	return [
		{ path: 'id', key: 'id', type: 'number', group: 'record' },
		...fields
			.filter(
				( field ) =>
					! UNFILTERABLE.includes( field.type ) &&
					field.storage !== 'post_content'
			)
			.map( ( field ) => ( { ...field, group: 'record' } ) ),
	];
}

/**
 * Fields a read can sort by: the title, and text, number, date, select and email fields.
 *
 * @param {Array<Object>} fields Type fields.
 * @return {Array<Object>} Sortable fields (without `id`, which is always offered).
 */
export function sortFields( fields ) {
	return fields.filter(
		( field ) =>
			field.storage !== 'post_content' &&
			( field.storage === 'post_title' ||
				SORTABLE.includes( field.type ) )
	);
}

/**
 * A sort with one part changed; the default (`id`, ascending) is removed.
 *
 * @param {?Object} sort  Sort (`{ field, order? }`).
 * @param {string}  name  `field` or `order`.
 * @param {string}  value New value.
 * @return {Object|undefined} Sort.
 */
export function withSort( sort, name, value ) {
	const next = { field: sort?.field ?? 'id', ...sort, [ name ]: value };
	return next.field === 'id' && ( next.order ?? 'asc' ) === 'asc'
		? undefined
		: next;
}

/**
 * The statuses a read includes.
 *
 * @param {?Array} status Step `status`.
 * @return {string[]} Statuses.
 */
export function statusesOf( status ) {
	return Array.isArray( status ) ? status : DEFAULT_STATUSES;
}

/**
 * Statuses with one switched on or off, in the registry's order; the default set is removed.
 *
 * @param {?Array}  status  Step `status`.
 * @param {string}  value   Status.
 * @param {boolean} checked Whether it is included.
 * @return {string[]|undefined} Statuses.
 */
export function withStatus( status, value, checked ) {
	const current = statusesOf( status );
	const next = READ_STATUSES.filter( ( item ) =>
		item === value ? checked : current.includes( item )
	);
	const isDefault =
		next.length === DEFAULT_STATUSES.length &&
		DEFAULT_STATUSES.every( ( item ) => next.includes( item ) );
	return isDefault ? undefined : next;
}

/**
 * Relationships a read of a type can follow: those with the type at one end.
 *
 * @param {Object} config Parsed config.
 * @param {string} type   Content type read.
 * @return {Array<Object>} Relationships.
 */
export function relationshipsFor( config, type ) {
	return config.relationships.filter(
		( item ) => item.from === type || item.to === type
	);
}

/**
 * The type at the other end of a relationship from a type: what `related.of` names.
 *
 * @param {?Object} relationship Relationship (`from`, `to`).
 * @param {string}  type         Content type read.
 * @return {?string} Content type key.
 */
export function otherEnd( relationship, type ) {
	if ( ! relationship ) {
		return null;
	}
	return relationship.from === type ? relationship.to : relationship.from;
}
