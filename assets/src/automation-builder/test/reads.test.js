/**
 * External dependencies
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import stepMetadata from '../../../../modules/automation/blocks/automation-step/block.json';
import loopMetadata from '../../../../modules/automation/blocks/automation-loop/block.json';
import { toJSON, toModel } from '../conditions';
import { fieldName, groupLabel, stepSummary } from '../labels';
import {
	BRANCH,
	DECISION,
	LOOP,
	STEP,
	compileBlocks,
	parseConfig,
} from '../logic';
import {
	conditionToFilter,
	filterFields,
	filterToCondition,
	readSource,
	sortFields,
	withSort,
	withSource,
	withStatus,
} from '../reads';
import { mergeTags, typeFields, typedReference, valueFields } from '../scope';

const root = new URL( '../../../../', import.meta.url );
const READS = [ 'get_record', 'find_records', 'relate' ];

/**
 * Every automation file of the valid fixture packages, the E2E packages and the examples.
 *
 * @return {Array<[string, Object]>} Relative path and definition.
 */
function automations() {
	const found = [];
	for ( const parent of [
		'tests/fixtures/valid',
		'tests/e2e/packages',
		'examples',
	] ) {
		for ( const name of readdirSync( new URL( parent, root ) ) ) {
			const dir = `${ parent }/${ name }/automations`;
			if ( ! existsSync( new URL( dir, root ) ) ) {
				continue;
			}
			for ( const file of readdirSync( new URL( dir, root ) ) ) {
				if ( file.endsWith( '.json' ) ) {
					const path = `${ dir }/${ file }`;
					found.push( [
						path,
						JSON.parse(
							readFileSync( new URL( path, root ), 'utf8' )
						),
					] );
				}
			}
		}
	}
	return found;
}

/**
 * Every step of a tree, branches and loop bodies included.
 *
 * @param {Array} steps Steps.
 * @return {Array<Object>} Steps.
 */
function walk( steps ) {
	return ( steps || [] ).flatMap( ( step ) => [
		step,
		...( step.branches || [] ).flatMap( ( branch ) =>
			walk( branch.steps )
		),
		...walk( step.otherwise ),
		...walk( step.type === 'loop' ? step.steps : [] ),
	] );
}

// The Automation v4 shapes package and the trash engine package: the builder learns their shapes in
// v4 Editor PRs 8 and 9 (docs/automation-v4-design.md section 11), which round-trip them here.
const v3Automations = () =>
	automations().filter(
		( [ path ] ) =>
			! path.startsWith( 'tests/fixtures/valid/v4/' ) &&
			! path.startsWith( 'tests/fixtures/valid/engine-trash/' )
	);

// The definitions this PR's editor edits: a read, a link, an on_error step or a manual trigger.
const readFixtures = v3Automations().filter(
	( [ , definition ] ) =>
		definition.trigger?.type === 'manual' ||
		walk( definition.steps ).some(
			( step ) => READS.includes( step.type ) || 'on_error' in step
		)
);

// Automation v4 Editor PR 8: the v4 definitions that use only waits until a date and role recipients
// besides v3 shapes (trash, start and created reads arrive with Editor PR 9).
const LATER = [ 'trash_record', 'start_automation' ];
const editorAFixtures = automations().filter(
	( [ path, definition ] ) =>
		/^tests\/fixtures\/valid\/(v4|engine-trash)\//.test( path ) &&
		walk( definition.steps ).every(
			( step ) => ! LATER.includes( step.type ) && ! ( 'created' in step )
		) &&
		walk( definition.steps ).some(
			( step ) => step.until || step.to?.includes( '{role:' )
		)
);

// And the loops (Automation v3 PR 7).
const loopFixtures = v3Automations().filter( ( [ , definition ] ) =>
	walk( definition.steps ).some( ( step ) => step.type === 'loop' )
);

const TYPES = {
	string: ( value ) => typeof value === 'string',
	number: ( value ) => typeof value === 'number',
	object: ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value ),
	array: Array.isArray,
	boolean: ( value ) => typeof value === 'boolean',
};

/**
 * A step's attributes as the block editor parses them: only attributes block.json declares, in its
 * order, and only values of a declared type (core drops the rest).
 *
 * @param {Object} step       Step.
 * @param {Object} [metadata] block.json (the step block's by default).
 * @return {Object} Attributes.
 */
function parsed( step, metadata = stepMetadata ) {
	const attributes = {};
	for ( const [ name, schema ] of Object.entries( metadata.attributes ) ) {
		const types = [].concat( schema.type );
		if (
			name in step &&
			types.some( ( type ) => TYPES[ type ]( step[ name ] ) )
		) {
			attributes[ name ] = step[ name ];
		}
	}
	return attributes;
}

/**
 * Blocks of a step list, as Builder::generate() makes them and the editor parses them.
 *
 * @param {Array} steps Steps.
 * @return {Array} Blocks.
 */
function blocks( steps ) {
	return steps.map( ( step ) => {
		if ( step.type === 'loop' ) {
			return {
				name: LOOP,
				attributes: parsed( step, loopMetadata ),
				innerBlocks: blocks( step.steps ),
			};
		}
		if ( step.type !== 'decision' ) {
			return { name: STEP, attributes: parsed( step ), innerBlocks: [] };
		}
		const branches = step.branches.map( ( { steps: inner, ...rest } ) => ( {
			name: BRANCH,
			attributes: rest,
			innerBlocks: blocks( inner ),
		} ) );
		if ( step.otherwise ) {
			branches.push( {
				name: BRANCH,
				attributes: { otherwise: true },
				innerBlocks: blocks( step.otherwise ),
			} );
		}
		return {
			name: DECISION,
			attributes: { id: step.id },
			innerBlocks: branches,
		};
	} );
}

/**
 * JSON with object keys sorted, so key order (block.json order versus the file's) does not count.
 *
 * @param {*} value Value.
 * @return {string} JSON.
 */
function canonical( value ) {
	return JSON.stringify( value, ( key, item ) =>
		item && typeof item === 'object' && ! Array.isArray( item )
			? Object.fromEntries(
					Object.keys( item )
						.sort()
						.map( ( name ) => [ name, item[ name ] ] )
				)
			: item
	);
}

describe( 'read fixtures round trip', () => {
	it( 'finds the read fixtures', () => {
		expect( readFixtures.length ).toBeGreaterThanOrEqual( 10 );
	} );

	it( 'finds the loop fixtures, and every loop property is a block attribute', () => {
		expect( loopFixtures.length ).toBeGreaterThanOrEqual( 5 );
		for ( const [ , definition ] of loopFixtures ) {
			for ( const loop of walk( definition.steps ).filter(
				( step ) => step.type === 'loop'
			) ) {
				const { type, steps: body, ...rest } = loop;
				expect( [ type, body.length > 0 ] ).toEqual( [ 'loop', true ] );
				expect( parsed( loop, loopMetadata ) ).toEqual( rest );
			}
		}
	} );

	it.each( [
		...readFixtures,
		...loopFixtures.filter(
			( [ path ] ) =>
				! readFixtures.some( ( [ other ] ) => other === path )
		),
	] )(
		'%s: the blocks compile back to the same steps',
		( name, definition ) => {
			expect(
				canonical( compileBlocks( blocks( definition.steps ) ) )
			).toBe( canonical( definition.steps ) );
		}
	);

	it( 'finds the wait until and role fixtures', () => {
		expect( editorAFixtures.map( ( [ path ] ) => path ) ).toEqual(
			expect.arrayContaining( [
				'tests/fixtures/valid/v4/automations/barn_task_due_reminder.json',
				'tests/fixtures/valid/v4/automations/barn_volunteer_review.json',
				'tests/fixtures/valid/v4/automations/barn_weekly_note.json',
			] )
		);
	} );

	it.each( editorAFixtures )(
		'%s: the blocks compile back to the same steps, byte for byte',
		( name, definition ) => {
			expect(
				canonical( compileBlocks( blocks( definition.steps ) ) )
			).toBe( canonical( definition.steps ) );
			const own = walk( definition.steps ).filter(
				( step ) => step.until || step.to?.includes( '{role:' )
			);
			// Each such step's properties are block attributes, `until` with its keys in order.
			expect(
				own.map( ( step ) => JSON.stringify( parsed( step ) ) )
			).toEqual(
				own.map( ( step ) =>
					JSON.stringify(
						Object.fromEntries(
							Object.keys( stepMetadata.attributes )
								.filter( ( key ) => key in step )
								.map( ( key ) => [ key, step[ key ] ] )
						)
					)
				)
			);
			expect(
				own.map( ( step ) => Object.keys( parsed( step ) ).sort() )
			).toEqual( own.map( ( step ) => Object.keys( step ).sort() ) );
		}
	);

	const steps = readFixtures.flatMap( ( [ path, definition ] ) =>
		walk( definition.steps )
			.filter( ( step ) => READS.includes( step.type ) )
			.map( ( step ) => [ `${ path }#${ step.id }`, step ] )
	);

	it.each( steps )(
		'%s: every read property is a block attribute',
		( name, step ) => {
			expect( JSON.stringify( parsed( step ) ) ).toBe(
				JSON.stringify(
					Object.fromEntries(
						Object.keys( stepMetadata.attributes )
							.filter( ( key ) => key in step )
							.map( ( key ) => [ key, step[ key ] ] )
					)
				)
			);
			expect( Object.keys( parsed( step ) ).sort() ).toEqual(
				Object.keys( step ).sort()
			);
		}
	);

	it.each( steps.filter( ( [ , step ] ) => step.type !== 'relate' ) )(
		'%s: the inspector knows how it finds its records',
		( name, step ) => {
			expect( readSource( step ) ).not.toBe( '' );
		}
	);

	it.each( steps.filter( ( [ , step ] ) => step.filter ) )(
		'%s: the filter rows come back byte for byte',
		( name, step ) => {
			const rows = toJSON( toModel( filterToCondition( step.filter ) ) );
			expect( JSON.stringify( conditionToFilter( rows ) ) ).toBe(
				JSON.stringify( step.filter )
			);
		}
	);
} );

const config = parseConfig( {
	types: [
		{
			key: 'gift',
			label: 'Gift',
			plural: 'Gifts',
			fields: [
				{
					key: 'name',
					label: 'Name',
					type: 'text',
					storage: 'post_title',
				},
				{ key: 'amount', label: 'Amount', type: 'number' },
				{ key: 'notes', label: 'Notes', type: 'rich_text' },
				{
					key: 'body',
					label: 'Body',
					type: 'text',
					storage: 'post_content',
				},
				{ key: 'paid', label: 'Paid', type: 'boolean' },
			],
			taxonomies: [],
		},
		{
			key: 'contact',
			label: 'Contact',
			plural: 'Contacts',
			fields: [
				{ key: 'name', label: 'Name', type: 'text' },
				{ key: 'email', label: 'Email', type: 'email', private: true },
			],
			taxonomies: [],
		},
		{
			key: 'task',
			label: 'Task',
			plural: 'Tasks',
			fields: [],
			taxonomies: [],
		},
	],
	relationships: [
		{ key: 'gift_donor', label: 'Donor', from: 'gift', to: 'contact' },
		{ key: 'task_gift', label: 'Gift', from: 'task', to: 'gift' },
	],
} );
const trigger = {
	type: 'event',
	event: 'record.created',
	content_type: 'gift',
};

describe( 'read helpers', () => {
	it( 'switches the source, keeping what stays and starting what is new', () => {
		const step = { type: 'get_record', record: '{{record.id}}' };
		expect( withSource( step, 'related', null ) ).toEqual( {
			record: undefined,
			related: { relationship: '', of: '' },
			filter: undefined,
		} );
		const row = { field: 'name', op: 'eq', value: '' };
		expect( withSource( { related: { a: 1 } }, 'both', row ) ).toEqual( {
			record: undefined,
			related: { a: 1 },
			filter: { all: [ row ] },
		} );
		expect( withSource( {}, 'record', null ).record ).toBe( '' );
	} );

	it( 'offers the relationships a type is the "from" end of as values', () => {
		expect(
			valueFields( config, 'task' ).map( ( field ) => field.key )
		).toEqual( [ 'task_gift' ] );
		expect(
			valueFields( config, 'gift' ).map( ( field ) => field.key )
		).toEqual( [
			'name',
			'amount',
			'notes',
			'body',
			'paid',
			'gift_donor',
		] );
		expect( valueFields( config, 'contact' ).at( -1 ).key ).toBe( 'email' );
	} );

	it( 'filters, sorts and totals only the fields the query allows', () => {
		const fields = typeFields( config, 'gift', '' );
		expect( filterFields( fields ).map( ( field ) => field.path ) ).toEqual(
			[ 'id', 'name', 'amount', 'paid' ]
		);
		expect( sortFields( fields ).map( ( field ) => field.key ) ).toEqual( [
			'name',
			'amount',
		] );
	} );

	it( 'drops the default sort and statuses', () => {
		expect( withSort( undefined, 'order', 'desc' ) ).toEqual( {
			field: 'id',
			order: 'desc',
		} );
		expect( withSort( { field: 'name' }, 'field', 'id' ) ).toBeUndefined();
		expect( withSort( { field: 'name' }, 'order', 'asc' ) ).toEqual( {
			field: 'name',
			order: 'asc',
		} );
		expect( withStatus( undefined, 'draft', true ) ).toEqual( [
			'publish',
			'draft',
			'private',
		] );
		expect(
			withStatus( [ 'publish', 'draft', 'private' ], 'draft', false )
		).toBeUndefined();
	} );

	it( 'knows the type of a record reference', () => {
		const definition = {
			trigger,
			steps: [
				{ id: 'donor', type: 'get_record', content_type: 'contact' },
				{ id: 'stamp', type: 'update_record' },
			],
		};
		expect(
			typedReference(
				'{{steps.donor.record_id}}',
				definition,
				'/steps/1/record',
				config
			)
		).toBe( 'contact' );
		expect(
			typedReference( '{{record.id}}', definition, '/steps/1', config )
		).toBe( 'gift' );
		expect(
			typedReference(
				'{{steps.donor.record_id}} ',
				definition,
				'/steps/1',
				config
			)
		).toBeNull();
		expect(
			typedReference(
				'{{steps.later.record_id}}',
				definition,
				'/steps/1',
				config
			)
		).toBeNull();
	} );
} );

describe( 'read words', () => {
	it( 'summarizes the cards as the design reads them', () => {
		expect(
			stepSummary(
				{
					type: 'get_record',
					content_type: 'contact',
					related: {
						relationship: 'gift_donor',
						of: '{{record.id}}',
					},
				},
				[],
				config,
				'gift'
			)
		).toBe( 'Get Contact by relationship Donor of this Gift' );
		expect(
			stepSummary(
				{
					type: 'find_records',
					content_type: 'gift',
					related: {
						relationship: 'gift_donor',
						of: '{{steps.donor.record_id}}',
					},
					limit: 0,
					sum: [ 'amount' ],
				},
				[],
				config,
				'gift'
			)
		).toBe( 'Find Gifts linked to donor: total Amount' );
		expect(
			stepSummary(
				{
					type: 'find_records',
					content_type: 'gift',
					filter: {
						all: [ { field: 'amount', op: 'gte', value: 5 } ],
					},
				},
				[],
				config
			)
		).toBe( 'Find Gifts where Amount is at least 5' );
		expect(
			stepSummary(
				{
					type: 'relate',
					relationship: 'task_gift',
					to: '{{record.id}}',
				},
				[],
				config
			)
		).toBe( 'Link Task to Gift (add)' );
	} );

	it( 'groups merge tags by where they come from', () => {
		const definition = {
			trigger,
			steps: [
				{
					id: 'donor',
					type: 'get_record',
					content_type: 'contact',
					related: {
						relationship: 'gift_donor',
						of: '{{record.id}}',
					},
				},
				{
					id: 'giving',
					type: 'find_records',
					content_type: 'gift',
					sum: [ 'amount' ],
					on_error: 'continue',
				},
				{ id: 'mail', type: 'send_email' },
			],
		};
		const tags = mergeTags(
			definition,
			'/steps/2/text',
			config,
			'private'
		);
		const heading = ( path ) =>
			groupLabel(
				tags.find( ( tag ) => tag.path === path ),
				config
			);
		expect( heading( 'record.name' ) ).toBe( 'This Gift' );
		expect( heading( 'steps.donor.record.email' ) ).toBe(
			'Donor (from donor)'
		);
		expect( heading( 'steps.giving.sum.amount' ) ).toBe(
			'Totals (from giving)'
		);
		expect( heading( 'today' ) ).toBe( 'Time' );
		const name = ( path, withStep ) =>
			fieldName(
				tags.find( ( tag ) => tag.path === path ),
				withStep
			);
		expect( name( 'steps.donor.record.email' ) ).toBe(
			'Email (from donor) (private)'
		);
		expect( name( 'steps.giving.sum.amount', false ) ).toBe(
			'Total Amount'
		);
		expect( name( 'steps.giving.failed' ) ).toBe( 'Failed (from giving)' );
		expect(
			mergeTags( definition, '/steps/2/url', config, 'public' ).map(
				( tag ) => tag.path
			)
		).not.toContain( 'steps.donor.record.email' );
	} );
} );
