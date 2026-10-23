/**
 * What a step can read: the record the trigger binds, the trigger payload, outputs of steps that ran
 * on every path before it (reads included: a read record's fields, totals), the site, and in
 * templates the time, run, signed links and public options. Mirrors AutomationRules::automation_scan()
 * and scan_path(); the shared fixture tests/fixtures/automation/scope.json holds both sides to the same
 * lists. Pure: no WordPress imports.
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
 * Step outputs with their types (STEP_OUTPUTS): `record` takes one key of the read type, `sum` one
 * summed field, `records` is a list (loops and {{#each}} only, never a tag or a condition).
 */
const OUTPUTS = {
	create_record: { record_id: 'number' },
	get_record: { found: 'boolean', record_id: 'number', record: 'record' },
	find_records: { count: 'number', records: 'records', sum: 'sum' },
	loop: { count: 'number' },
};

/**
 * Outputs of a step with `on_error` (ON_ERROR_OUTPUTS).
 */
const ON_ERROR_OUTPUTS = { failed: 'boolean', error: 'text' };

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
	if ( trigger?.type === 'manual' ) {
		return trigger.content_type
			? { binds: true, type: trigger.content_type }
			: { binds: false, type: null };
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
 * Steps that ran on every path before a pointer (earlier siblings in its list and in every
 * enclosing list), and the loops whose body holds it.
 *
 * @param {Array}  steps   The automation's steps.
 * @param {string} pointer Pointer of a step, a branch, or a property inside one.
 * @return {{before: Array<Object>, loops: Array<Object>}} Step objects, in document order.
 */
function around( steps, pointer ) {
	const parts = String( pointer ).split( '/' ).slice( 1 );
	const before = [];
	const loops = [];
	let current = Array.isArray( steps ) ? steps : [];
	let at = parts[ 0 ] === 'steps' ? 1 : parts.length;
	while ( at < parts.length ) {
		const index = Number( parts[ at ] );
		if ( ! Number.isInteger( index ) ) {
			break;
		}
		before.push( ...current.slice( 0, index ) );
		const step = current[ index ];
		if ( parts[ at + 1 ] === 'branches' && parts[ at + 3 ] === 'steps' ) {
			current =
				step?.branches?.[ Number( parts[ at + 2 ] ) ]?.steps || [];
			at += 4;
		} else if ( parts[ at + 1 ] === 'otherwise' ) {
			current = step?.otherwise || [];
			at += 2;
		} else if ( parts[ at + 1 ] === 'steps' && step?.type === 'loop' ) {
			loops.push( step );
			current = step.steps || [];
			at += 2;
		} else {
			break;
		}
	}
	return { before, loops };
}

/**
 * Steps that ran on every path before a pointer: earlier siblings in its list and in every
 * enclosing list (a loop body's steps only inside that body).
 *
 * @param {Array}  steps   The automation's steps.
 * @param {string} pointer Pointer of a step, a branch, or a property inside one.
 * @return {Array<Object>} Steps (the step objects), in document order.
 */
export function stepsBefore( steps, pointer ) {
	return around( steps, pointer ).before;
}

/**
 * The loops around a pointer, each with the content type of the records it repeats over.
 *
 * @param {Array}  steps   The automation's steps.
 * @param {string} pointer Pointer.
 * @return {Array<Object>} Loop steps with `content_type` (null when its find is not known).
 */
export function loopsAround( steps, pointer ) {
	const { before, loops } = around( steps, pointer );
	return loops.map( ( loop ) => ( {
		...loop,
		content_type:
			collections( before ).find(
				( find ) => `steps.${ find.id }.records` === loop.over
			)?.content_type ?? null,
	} ) );
}

/**
 * The lists of records in scope: find_records steps among steps that ran before.
 *
 * @param {Array<Object>} before Steps before the pointer (stepsBefore()).
 * @return {Array<Object>} Find steps.
 */
export function collections( before ) {
	return before.filter( ( step ) => step.type === 'find_records' );
}

/**
 * A content type of the config.
 *
 * @param {Object} config Parsed config.
 * @param {string} type   Content type key.
 * @return {?Object} Type (`key`, `label`, `plural`, `fields`, `taxonomies`).
 */
export function typeOf( config, type ) {
	return config.types.find( ( item ) => item.key === type ) || null;
}

/**
 * Fields and taxonomies of a content type, as paths under a prefix.
 *
 * @param {Object} config Parsed config.
 * @param {string} type   Content type key.
 * @param {string} prefix Path prefix, such as `record.`.
 * @return {Array<Object>} Paths with label, type, options, multiple, private and storage.
 */
export function typeFields( config, type, prefix ) {
	const definition = typeOf( config, type );
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
			storage: field.storage,
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
 * What a create_record or update_record step can write on a content type: its fields and taxonomies,
 * then the relationships whose "from" end it is (the value is the linked record's ID).
 *
 * @param {Object} config Parsed config.
 * @param {string} type   Content type key.
 * @return {Array<Object>} Rows with key and label.
 */
export function valueFields( config, type ) {
	return [
		...typeFields( config, type, '' ),
		...( config.relationships || [] )
			.filter( ( item ) => item.from === type )
			.map( ( item ) => ( { key: item.key, label: item.label } ) ),
	];
}

/**
 * A record of a content type as paths: id, status and type, then its fields and taxonomies.
 *
 * @param {Object}  config Parsed config.
 * @param {?string} type   Content type key (null or '' when not known: no fields).
 * @param {string}  prefix Path prefix, such as `record.` or `steps.donor.record.`.
 * @return {Array<Object>} Paths.
 */
function recordFields( config, type, prefix ) {
	return [
		{ path: `${ prefix }id`, key: 'id', type: 'number' },
		{ path: `${ prefix }status`, key: 'status', type: 'text' },
		{ path: `${ prefix }type`, key: 'type', type: 'text' },
		...( type ? typeFields( config, type, prefix ) : [] ),
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
	if ( trigger?.type === 'manual' ) {
		return [
			{ path: 'trigger.user_id', type: 'number' },
			{ path: 'trigger.source', type: 'text' },
		];
	}
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
 * The output paths of one step in scope: typed values, a read record's keys, and summed fields.
 *
 * @param {Object} step   Step.
 * @param {Object} config Parsed config.
 * @return {Array<Object>} Paths (`group` steps, `step` the id, `output` the output name).
 */
function stepPaths( step, config ) {
	const outputs = {
		...( OUTPUTS[ step.type ] || {} ),
		...( step.on_error ? ON_ERROR_OUTPUTS : {} ),
	};
	const base = `steps.${ step.id }.`;
	const paths = [];
	for ( const [ output, kind ] of Object.entries( outputs ) ) {
		if ( kind === 'record' ) {
			paths.push(
				...recordFields(
					config,
					step.content_type,
					`${ base }record.`
				).map( ( field ) => ( { ...field, output } ) )
			);
		} else if ( kind === 'sum' ) {
			const fields = typeFields( config, step.content_type, '' );
			for ( const key of Array.isArray( step.sum ) ? step.sum : [] ) {
				const field = fields.find( ( item ) => item.key === key );
				paths.push( {
					path: `${ base }sum.${ key }`,
					key,
					label: field?.label,
					type: 'number',
					private: field?.private === true,
					output,
				} );
			}
		} else if ( kind !== 'records' ) {
			paths.push( { path: base + output, type: kind, output } );
		}
	}
	const source = {
		type: step.type,
		content_type: step.content_type,
		relationship: step.related?.relationship,
	};
	return paths.map( ( path ) => ( {
		...path,
		group: 'steps',
		step: step.id,
		source,
	} ) );
}

/**
 * Paths a condition at a pointer can test: the record's fields and taxonomies (private ones
 * included and flagged), id, status and type, the trigger payload, step outputs (a read record's
 * keys and totals included) and the site.
 *
 * @param {Object} definition Automation (trigger and steps).
 * @param {string} pointer    Pointer of the condition.
 * @param {Object} config     Parsed config.
 * @return {Array<Object>} Fields: `path`, `label`, `type` (null when unknown), `group` (and `step`
 *                         for step outputs), and for typed ones `options`, `multiple`, `private`.
 */
export function conditionFields( definition, pointer, config ) {
	const trigger = definition?.trigger || {};
	const { binds, type } = binding( trigger, config );
	const fields = [];
	if ( binds ) {
		fields.push(
			...recordFields( config, type, 'record.' ).map( ( field ) => ( {
				...field,
				group: 'record',
				source: { content_type: type },
			} ) )
		);
	}
	fields.push(
		...triggerPaths( trigger, config ).map( ( field ) => ( {
			...field,
			group: 'trigger',
		} ) )
	);
	for ( const step of stepsBefore( definition?.steps, pointer ) ) {
		fields.push( ...stepPaths( step, config ) );
	}
	// Inside a loop body: the current item (a record of the list's type) and its number.
	for ( const loop of loopsAround( definition?.steps, pointer ) ) {
		const base = `steps.${ loop.id }.`;
		const source = {
			type: 'loop',
			content_type: loop.content_type,
			item: true,
		};
		fields.push(
			...[
				...recordFields( config, loop.content_type, `${ base }item.` ),
				{ path: `${ base }index`, type: 'number' },
			].map( ( field ) => ( {
				...field,
				group: 'steps',
				step: loop.id,
				source,
			} ) )
		);
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
 * @return {Array<Object>} Tags: `path` (inside the braces), `label`, `group`, `step` and `source`
 *                         (the record or step a tag reads, for group names), `private`, `date`
 *                         (takes `| date` and `| add`) and `number` (takes `| number`).
 */
export function mergeTags( definition, pointer, config, context ) {
	const trigger = definition?.trigger || {};
	const { binds } = binding( trigger, config );
	const tags = [];
	const tag = ( field ) => ( {
		path: field.path,
		label: field.label,
		group: field.group,
		step: field.step,
		source: field.source,
		private: field.private,
		date: field.type === 'date',
		number: field.type === 'number',
	} );
	for ( const field of conditionFields( definition, pointer, config ) ) {
		if ( context === 'public' && field.private ) {
			continue;
		}
		tags.push( tag( field ) );
		if (
			field.key === 'type' &&
			/(record|item)\.type$/.test( field.path )
		) {
			const prefix = field.path.slice( 0, -'type'.length );
			tags.push(
				tag( { ...field, path: `${ prefix }permalink`, type: null } ),
				tag( { ...field, path: `${ prefix }edit_url`, type: null } )
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
					number: field.type === 'number',
				} );
			}
		}
	}
	return tags;
}

/**
 * The content type of a template that is exactly a typed record reference, as
 * AutomationRules::typed_reference() reads it: `{{record.id}}` of a bound record, or
 * `{{steps.<id>.record_id}}` of a get_record or create_record step that ran before, or
 * `{{steps.<loop>.item.id}}` inside that loop.
 *
 * @param {string} template   Template.
 * @param {Object} definition Automation (trigger and steps).
 * @param {string} pointer    Pointer of the template.
 * @param {Object} config     Parsed config.
 * @return {?string} Content type key, or null.
 */
export function typedReference( template, definition, pointer, config ) {
	const match = /^\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*\}\}$/.exec(
		typeof template === 'string' ? template : ''
	);
	if ( ! match ) {
		return null;
	}
	const parts = match[ 1 ].split( '.' );
	let type = null;
	if ( match[ 1 ] === 'record.id' ) {
		const bound = binding( definition?.trigger || {}, config );
		type = bound.binds ? bound.type : null;
	} else if (
		parts.length === 3 &&
		parts[ 0 ] === 'steps' &&
		parts[ 2 ] === 'record_id'
	) {
		const step = stepsBefore( definition?.steps, pointer ).find(
			( item ) => item.id === parts[ 1 ]
		);
		type = [ 'get_record', 'create_record' ].includes( step?.type )
			? step.content_type
			: null;
	} else if ( match[ 1 ] === `steps.${ parts[ 1 ] }.item.id` ) {
		type = loopsAround( definition?.steps, pointer ).find(
			( loop ) => loop.id === parts[ 1 ]
		)?.content_type;
	}
	return typeof type === 'string' && type !== '' ? type : null;
}

/**
 * A merge tag as written in a template.
 *
 * @param {string} path      Path.
 * @param {Object} [filters] `add` (signed duration for `| add`), `date` (boolean), `number` (true,
 *                           or the decimals 0 to 4) and `fallback` (text for `| default`).
 * @return {string} Tag such as `{{record.due | add:"P2D" | date}}`.
 */
export function formatTag( path, filters = {} ) {
	let tag = path;
	if ( filters.add ) {
		tag += ` | add:"${ String( filters.add ).replace( /"/g, '' ) }"`;
	}
	if ( filters.date ) {
		tag += ' | date';
	} else if ( filters.number === true ) {
		tag += ' | number';
	} else if ( Number.isInteger( filters.number ) ) {
		tag += ` | number:${ filters.number }`;
	}
	if ( filters.fallback ) {
		tag += ` | default:"${ String( filters.fallback ).replace( /"/g, '' ) }"`;
	}
	return `{{${ tag }}}`;
}

/**
 * An `{{#each}}` block over a find step's records with the type's first field: one line per record
 * in text, a list in HTML.
 *
 * @param {Object}  find   find_records step.
 * @param {Object}  config Parsed config.
 * @param {boolean} [html] HTML template.
 * @return {string} Block such as `{{#each steps.due.records}}- {{item.name}}\n{{/each}}`.
 */
export function eachBlock( find, config, html ) {
	const tag = `{{item.${
		typeFields( config, find.content_type, '' )[ 0 ]?.key || 'id'
	}}}`;
	const open = `{{#each steps.${ find.id }.records}}`;
	return html
		? `<ul>${ open }<li>${ tag }</li>{{/each}}</ul>`
		: `${ open }- ${ tag }\n{{/each}}`;
}
