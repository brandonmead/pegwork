/**
 * What a step can read: the record the trigger binds, the trigger payload, outputs of steps that ran
 * on every path before it, the site, and in templates the time, run, signed links and public options.
 * Mirrors AutomationRules::automation_scan() and scan_path(); the shared fixture
 * tests/fixtures/automation/scope.json holds both sides to the same lists. Pure: no WordPress imports.
 */

/**
 * Payload keys of the core record events and of the forms module's events (RECORD_EVENT_KEYS,
 * FORM_EVENT_KEYS), without `old`, `changed`, `values` and `origin`, which are not single values.
 */
export const RECORD_EVENTS = {
	'record.created': [ 'id', 'type', 'status', 'user_id' ],
	'record.updated': [ 'id', 'type', 'status', 'user_id' ],
	'record.status_changed': [
		'id',
		'type',
		'old_status',
		'new_status',
		'user_id',
	],
	'record.deleted': [ 'id', 'type', 'status', 'user_id' ],
};
export const FORM_EVENTS = [ 'form.submitted', 'form.confirmed' ];
const FORM_KEYS = [ 'form', 'entry_id', 'post_id', 'record_id' ];

/**
 * Step outputs (STEP_OUTPUTS).
 */
const OUTPUTS = { create_record: [ 'record_id' ] };

/**
 * The record a trigger binds: whether it binds one, and its content type (null when the trigger
 * names none, '' when its form is unknown, so fields cannot be listed).
 *
 * @param {Object} trigger Trigger.
 * @param {Object} config  Parsed config.
 * @return {{binds: boolean, type: ?string}} Binding.
 */
export function binding( trigger, config ) {
	const event = typeof trigger?.event === 'string' ? trigger.event : '';
	if ( trigger?.type === 'schedule' && trigger.for_each ) {
		return { binds: true, type: trigger.for_each.content_type ?? null };
	}
	if ( trigger?.type !== 'event' ) {
		return { binds: false, type: null };
	}
	if ( event.startsWith( 'record.' ) ) {
		return { binds: true, type: trigger.content_type ?? null };
	}
	if ( FORM_EVENTS.includes( event ) && trigger.form ) {
		const form = config.forms.find( ( item ) => item.key === trigger.form );
		if ( ! form ) {
			return { binds: true, type: '' };
		}
		const binds =
			form.target !== '' &&
			( event === 'form.confirmed' || ! form.confirm );
		return { binds, type: binds ? form.target : null };
	}
	return { binds: false, type: null };
}

/**
 * Steps that ran on every path before a pointer: earlier siblings in its list and in every
 * enclosing list.
 *
 * @param {Array}  steps   The automation's steps.
 * @param {string} pointer Pointer of a step, a branch, or a property inside one.
 * @return {Array<{id: string, type: string}>} Steps, in document order.
 */
export function stepsBefore( steps, pointer ) {
	const parts = String( pointer ).split( '/' ).slice( 1 );
	const found = [];
	let current = Array.isArray( steps ) ? steps : [];
	let at = parts[ 0 ] === 'steps' ? 1 : parts.length;
	while ( at < parts.length ) {
		const index = Number( parts[ at ] );
		if ( ! Number.isInteger( index ) ) {
			break;
		}
		current
			.slice( 0, index )
			.forEach( ( step ) =>
				found.push( { id: step.id, type: step.type } )
			);
		const step = current[ index ];
		if ( parts[ at + 1 ] === 'branches' && parts[ at + 3 ] === 'steps' ) {
			current = step?.branches?.[ Number( parts[ at + 2 ] ) ]?.steps || [];
			at += 4;
		} else if ( parts[ at + 1 ] === 'otherwise' ) {
			current = step?.otherwise || [];
			at += 2;
		} else {
			break;
		}
	}
	return found;
}

/**
 * Fields and taxonomies of a content type, as paths under a prefix.
 *
 * @param {Object} config Parsed config.
 * @param {string} type   Content type key.
 * @param {string} prefix Path prefix, such as `record.`.
 * @return {Array<Object>} Paths with label, type, options, multiple and private.
 */
export function typeFields( config, type, prefix ) {
	const definition = config.types.find( ( item ) => item.key === type );
	if ( ! definition ) {
		return [];
	}
	return [
		...( definition.fields || [] ).map( ( field ) => ( {
			path: prefix + field.key,
			key: field.key,
			label: field.label,
			type: field.type,
			options: field.options,
			private: field.private === true,
		} ) ),
		...( definition.taxonomies || [] ).map( ( taxonomy ) => ( {
			path: prefix + taxonomy.key,
			key: taxonomy.key,
			label: taxonomy.label,
			type: 'taxonomy',
			options: taxonomy.options,
			multiple: taxonomy.multiple === true,
		} ) ),
	];
}

/**
 * The paths a trigger's payload offers.
 *
 * @param {Object} trigger Trigger.
 * @param {Object} config  Parsed config.
 * @return {Array<Object>} Paths (`group` trigger).
 */
function triggerPaths( trigger, config ) {
	const event = typeof trigger?.event === 'string' ? trigger.event : '';
	if ( trigger?.type !== 'event' ) {
		return [];
	}
	const plain = ( key ) => ( { path: `trigger.${ key }`, type: null } );
	if ( RECORD_EVENTS[ event ] ) {
		const paths = RECORD_EVENTS[ event ].map( plain );
		if ( event === 'record.updated' && trigger.content_type ) {
			paths.push(
				...typeFields( config, trigger.content_type, 'trigger.old.' )
			);
		}
		return paths;
	}
	if ( FORM_EVENTS.includes( event ) ) {
		const form = config.forms.find( ( item ) => item.key === trigger.form );
		return [
			...FORM_KEYS.map( plain ),
			...( form?.fields || [] ).map( ( field ) => ( {
				path: `trigger.values.${ field.key }`,
				label: field.label,
				type: null,
				private: field.private === true,
			} ) ),
		];
	}
	return [];
}

/**
 * Paths a condition at a pointer can test: the record's fields and taxonomies (private ones
 * included and flagged), id, status and type, the trigger payload, step outputs and the site.
 *
 * @param {Object} definition Automation (trigger and steps).
 * @param {string} pointer    Pointer of the condition.
 * @param {Object} config     Parsed config.
 * @return {Array<Object>} Fields: `path`, `label`, `type` (null when unknown), `group`, and for
 *                         typed ones `options`, `multiple`, `private`.
 */
export function conditionFields( definition, pointer, config ) {
	const trigger = definition?.trigger || {};
	const { binds, type } = binding( trigger, config );
	const fields = [];
	if ( binds ) {
		fields.push(
			{ path: 'record.id', type: 'number', group: 'record' },
			{ path: 'record.status', type: 'text', group: 'record' },
			{ path: 'record.type', type: 'text', group: 'record' }
		);
		if ( type ) {
			fields.push(
				...typeFields( config, type, 'record.' ).map( ( field ) => ( {
					...field,
					group: 'record',
				} ) )
			);
		}
	}
	fields.push(
		...triggerPaths( trigger, config ).map( ( field ) => ( {
			...field,
			group: 'trigger',
		} ) )
	);
	for ( const step of stepsBefore( definition?.steps, pointer ) ) {
		for ( const output of OUTPUTS[ step.type ] || [] ) {
			fields.push( {
				path: `steps.${ step.id }.${ output }`,
				type: null,
				group: 'steps',
			} );
		}
	}
	fields.push(
		{ path: 'site.name', type: null, group: 'site' },
		{ path: 'site.url', type: null, group: 'site' }
	);
	return fields;
}

/**
 * Merge tags a template at a pointer can use. `private` contexts (email, record values, run once
 * per) take every field; `public` ones (webhook URLs, link pages) drop private fields.
 *
 * @param {Object} definition Automation (trigger, steps, links).
 * @param {string} pointer    Pointer of the template.
 * @param {Object} config     Parsed config.
 * @param {string} context    `private` or `public`.
 * @return {Array<Object>} Tags: `path` (inside the braces), `label`, `group`, `date` (takes `| date`).
 */
export function mergeTags( definition, pointer, config, context ) {
	const trigger = definition?.trigger || {};
	const { binds } = binding( trigger, config );
	const tags = [];
	for ( const field of conditionFields( definition, pointer, config ) ) {
		if ( context === 'public' && field.private ) {
			continue;
		}
		tags.push( {
			path: field.path,
			label: field.label,
			group: field.group,
			date: field.type === 'date',
		} );
		if ( field.path === 'record.type' ) {
			tags.push(
				{ path: 'record.permalink', group: 'record', date: false },
				{ path: 'record.edit_url', group: 'record', date: false }
			);
		}
	}
	if ( binds ) {
		for ( const name of Object.keys( definition?.links || {} ) ) {
			tags.push( { path: `link.${ name }`, group: 'link', date: false } );
		}
	}
	tags.push(
		{ path: 'today', group: 'time', date: true },
		{ path: 'now', group: 'time', date: true },
		{ path: 'run.id', group: 'site', date: false }
	);
	for ( const page of config.optionsPages ) {
		for ( const field of page.fields || [] ) {
			if ( ! field.private ) {
				tags.push( {
					path: `option:${ page.key }.${ field.key }`,
					label: `${ page.label }: ${ field.label }`,
					group: 'option',
					date: field.type === 'date',
				} );
			}
		}
	}
	return tags;
}

/**
 * A merge tag as written in a template.
 *
 * @param {string} path      Path.
 * @param {Object} [filters] `date` (boolean) and `fallback` (text for `| default`).
 * @return {string} Tag such as `{{record.due | date}}`.
 */
export function formatTag( path, filters = {} ) {
	let tag = path;
	if ( filters.date ) {
		tag += ' | date';
	}
	if ( filters.fallback ) {
		tag += ` | default:"${ String( filters.fallback ).replace( /"/g, '' ) }"`;
	}
	return `{{${ tag }}}`;
}
