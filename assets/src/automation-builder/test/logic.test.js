/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	BRANCH,
	DECISION,
	LOOP,
	STEP,
	assignId,
	compileBlocks,
	errorOwners,
	errorsAt,
	formatDuration,
	heightOf,
	levelOf,
	moveTargets,
	needsId,
	parseDuration,
	parseSettings,
	pointerMap,
	reportTree,
	saveErrors,
	serializeSettings,
	slugify,
	uniqueId,
	withValue,
} from '../logic';

const step = ( clientId, attributes ) => ( {
	clientId,
	name: STEP,
	attributes,
	innerBlocks: [],
} );
const branch = ( clientId, attributes, innerBlocks = [] ) => ( {
	clientId,
	name: BRANCH,
	attributes,
	innerBlocks,
} );

// A decision with two branches, a nested decision in the first, and a locked otherwise in the middle
// of the block list (the editor keeps it last; compile and pointers must not depend on that).
const blocks = [
	step( 's0', { id: 'mail', type: 'send_email', to: 'a@example.org' } ),
	{
		clientId: 'd1',
		name: DECISION,
		attributes: { id: 'route', lock: { move: false } },
		innerBlocks: [
			branch(
				'b0',
				{
					label: 'High',
					when: { var: 'record.p', op: 'eq', value: 'high' },
				},
				[
					{
						clientId: 'd2',
						name: DECISION,
						attributes: { id: 'inner' },
						innerBlocks: [
							branch(
								'b00',
								{
									label: 'Big',
									when: {
										var: 'record.n',
										op: 'gt',
										value: 5,
									},
								},
								[
									step( 's00', {
										id: 'big',
										type: 'wait',
										for: 'P1D',
									} ),
								]
							),
							branch( 'o00', {
								otherwise: true,
								lock: { remove: true },
							} ),
						],
					},
				]
			),
			branch(
				'o1',
				{ otherwise: true, lock: { move: true, remove: true } },
				[
					step( 's10', {
						id: 'low',
						type: 'send_email',
						retry: null,
					} ),
				]
			),
			branch(
				'b1',
				{
					label: 'Normal',
					when: { var: 'record.p', op: 'eq', value: 'normal' },
				},
				[ step( 's11', { id: 'norm', type: 'wait', for: 'PT2H' } ) ]
			),
		],
	},
	step( 's2', { id: 'done', type: 'call_webhook', metadata: { name: 'x' } } ),
];

describe( 'compileBlocks', () => {
	it( 'builds the definition steps as Builder::compile() does', () => {
		expect( compileBlocks( blocks ) ).toEqual( [
			{ id: 'mail', type: 'send_email', to: 'a@example.org' },
			{
				id: 'route',
				type: 'decision',
				branches: [
					{
						label: 'High',
						when: { var: 'record.p', op: 'eq', value: 'high' },
						steps: [
							{
								id: 'inner',
								type: 'decision',
								branches: [
									{
										label: 'Big',
										when: {
											var: 'record.n',
											op: 'gt',
											value: 5,
										},
										steps: [
											{
												id: 'big',
												type: 'wait',
												for: 'P1D',
											},
										],
									},
								],
							},
						],
					},
					{
						label: 'Normal',
						when: { var: 'record.p', op: 'eq', value: 'normal' },
						steps: [ { id: 'norm', type: 'wait', for: 'PT2H' } ],
					},
				],
				otherwise: [ { id: 'low', type: 'send_email' } ],
			},
			{ id: 'done', type: 'call_webhook' },
		] );
	} );

	it( 'puts id and type first on decisions', () => {
		expect( Object.keys( compileBlocks( blocks )[ 1 ] ) ).toEqual( [
			'id',
			'type',
			'branches',
			'otherwise',
		] );
	} );
} );

describe( 'pointerMap', () => {
	it( 'numbers branches without the otherwise branch', () => {
		expect( pointerMap( blocks ) ).toEqual( {
			s0: '/steps/0',
			d1: '/steps/1',
			b0: '/steps/1/branches/0',
			d2: '/steps/1/branches/0/steps/0',
			b00: '/steps/1/branches/0/steps/0/branches/0',
			s00: '/steps/1/branches/0/steps/0/branches/0/steps/0',
			o00: '/steps/1/branches/0/steps/0/otherwise',
			o1: '/steps/1/otherwise',
			s10: '/steps/1/otherwise/0',
			b1: '/steps/1/branches/1',
			s11: '/steps/1/branches/1/steps/0',
			s2: '/steps/2',
		} );
	} );

	it( 'gives the nesting level of a pointer', () => {
		expect( levelOf( '/steps/1' ) ).toBe( 1 );
		expect( levelOf( '/steps/1/otherwise/0' ) ).toBe( 2 );
		expect(
			levelOf( '/steps/1/branches/0/steps/0/branches/0/steps/0' )
		).toBe( 3 );
		expect( levelOf( '/steps/2/steps/0' ) ).toBe( 2 );
		expect( levelOf( '/steps/2/steps/1/otherwise/0' ) ).toBe( 3 );
	} );
} );

// find, each_task (remind, route (Has: inner), stamp), pause (wait), outer (Big: deep), summary.
const block = ( clientId, name, attributes = {}, innerBlocks = [] ) => ( {
	clientId,
	name,
	attributes,
	innerBlocks,
} );
const loopTree = [
	block( 'find', STEP, { id: 'due', type: 'find_records' } ),
	block( 'loop', LOOP, { id: 'each_task', over: 'steps.due.records' }, [
		block( 'remind', STEP, { id: 'remind', type: 'send_email' } ),
		block( 'route', DECISION, { id: 'route' }, [
			block( 'has', BRANCH, { label: 'Has' }, [
				block( 'inner', STEP, { id: 'inner', type: 'relate' } ),
			] ),
		] ),
		block( 'stamp', STEP, { id: 'stamp', type: 'update_record' } ),
	] ),
	block( 'pause', STEP, { id: 'pause', type: 'wait', for: 'P1D' } ),
	block( 'outer', DECISION, { id: 'outer' }, [
		block( 'big', BRANCH, { label: 'Big' }, [
			block( 'deep', DECISION, { id: 'deep' }, [
				block( 'yes', BRANCH, { label: 'Yes' } ),
			] ),
		] ),
	] ),
	block( 'summary', STEP, { id: 'summary', type: 'send_email' } ),
];

describe( 'loops', () => {
	it( 'compiles a loop block to a loop step with its body', () => {
		const [ , loop ] = compileBlocks( loopTree );
		expect( Object.keys( loop ) ).toEqual( [
			'id',
			'type',
			'over',
			'steps',
		] );
		expect( loop.steps.map( ( item ) => item.id ) ).toEqual( [
			'remind',
			'route',
			'stamp',
		] );
		expect( loop.steps[ 1 ].branches[ 0 ].steps[ 0 ].id ).toBe( 'inner' );
	} );

	it( 'points into the body at <loop>/steps', () => {
		const map = pointerMap( loopTree );
		expect( map.loop ).toBe( '/steps/1' );
		expect( map.stamp ).toBe( '/steps/1/steps/2' );
		expect( map.inner ).toBe( '/steps/1/steps/1/branches/0/steps/0' );
		expect( map.summary ).toBe( '/steps/4' );
	} );

	it( 'counts a loop as a nesting level', () => {
		expect( heightOf( loopTree[ 1 ] ) ).toBe( 2 );
		expect( heightOf( loopTree[ 3 ] ) ).toBe( 2 );
		expect( heightOf( loopTree[ 0 ] ) ).toBe( 0 );
	} );

	const names = ( targets ) =>
		targets.map(
			( target ) =>
				target.loop ??
				( target.branch
					? `${ target.decision }:${ target.branch.label }`
					: 'main' )
		);

	it( 'Move to offers loop bodies to steps that fit there', () => {
		expect( names( moveTargets( loopTree, loopTree[ 4 ] ) ) ).toEqual( [
			'main',
			'each_task',
			'route:Has',
			'outer:Big',
			'deep:Yes',
		] );
	} );

	it( 'Move to omits loop bodies for a wait, a loop and what holds them', () => {
		expect( names( moveTargets( loopTree, loopTree[ 2 ] ) ) ).toEqual( [
			'main',
			'outer:Big',
			'deep:Yes',
		] );
		// The loop itself (two levels): not into its own body, not into a loop, nor three levels down.
		expect( names( moveTargets( loopTree, loopTree[ 1 ] ) ) ).toEqual( [
			'main',
			'outer:Big',
		] );
		// A decision holding a decision (two levels) fits the top level, a branch and a loop body.
		expect( names( moveTargets( loopTree, loopTree[ 3 ] ) ) ).toEqual( [
			'main',
			'each_task',
		] );
	} );

	it( 'opens a report level at each loop item, and closes it at the loop’s own lines', () => {
		const item = ( index ) => ( {
			index,
			count: 6,
			id: 40 + index,
			title: `T${ index }`,
			label: 'Task',
		} );
		const tree = reportTree( [
			{ id: 'each', type: 'loop', depth: 0, branch: null, item: null },
			{ id: 'mail', depth: 1, branch: null, item: item( 1 ) },
			{ id: 'stamp', depth: 1, branch: null, item: null },
			{ id: 'mail', depth: 1, branch: null, item: item( 2 ) },
			{
				id: 'each',
				type: 'loop',
				depth: 1,
				branch: null,
				item: null,
				detail: { more: 4 },
			},
			{ id: 'done', depth: 0, branch: null, item: null },
		] );
		expect(
			tree.map( ( node ) => node.entry?.id ?? node.heading?.title )
		).toEqual( [ 'each', 'T1', 'T2', undefined, 'done' ] );
		expect( tree[ 1 ].children.map( ( node ) => node.entry.id ) ).toEqual( [
			'mail',
			'stamp',
		] );
		expect( tree[ 3 ].children[ 0 ].entry.detail.more ).toBe( 4 );
	} );
} );

describe( 'save errors', () => {
	const error = {
		code: 'pegwork_invalid_automation',
		data: {
			fields: {
				'/steps/1/branches/0/steps/0/branches/0/steps/0/for':
					'Bad duration.',
				'/steps/1/branches/1/when/var': 'Unknown field.',
				'/steps/10/subject': 'Not here.',
				'/trigger/event': 'Unknown event.',
				'/': 'General.',
			},
		},
	};

	it( 'reads only pegwork_invalid_automation', () => {
		expect( saveErrors( error ) ).toBe( error.data.fields );
		expect( saveErrors( { code: 'rest_forbidden' } ) ).toEqual( {} );
		expect( saveErrors( null ) ).toEqual( {} );
	} );

	it( 'gives each pointer to the block with the longest prefix', () => {
		const owners = errorOwners( saveErrors( error ), pointerMap( blocks ) );
		expect( owners.blocks ).toEqual( {
			s00: {
				'/steps/1/branches/0/steps/0/branches/0/steps/0/for':
					'Bad duration.',
			},
			b1: { '/steps/1/branches/1/when/var': 'Unknown field.' },
		} );
		// "/steps/10" is not under "/steps/1".
		expect( Object.keys( owners.settings ) ).toEqual( [
			'/steps/10/subject',
			'/trigger/event',
			'/',
		] );
	} );

	it( 'lists messages at and below a pointer', () => {
		const fields = { '/a': 'x', '/a/b': 'y', '/ab': 'z' };
		expect( errorsAt( fields, '/a' ) ).toEqual( [ 'x', 'y' ] );
		expect( errorsAt( fields, '/a', false ) ).toEqual( [ 'x' ] );
	} );
} );

describe( 'step ids', () => {
	it( 'picks the first free id of the type', () => {
		expect( uniqueId( 'send_email', new Set() ) ).toBe( 'email' );
		expect(
			uniqueId( 'send_email', new Set( [ 'email', 'email_2' ] ) )
		).toBe( 'email_3' );
		expect( uniqueId( 'decision', new Set() ) ).toBe( 'decision' );
	} );

	it( 'renames a missing id or one another block uses, before or after it', () => {
		const list = [
			step( 'x', { id: 'mail' } ),
			step( 'y', { id: 'mail' } ),
			step( 'z', { id: 'other' } ),
		];
		expect( needsId( list, 'x', 'mail' ) ).toBe( true );
		expect( needsId( list, 'y', 'mail' ) ).toBe( true );
		expect( needsId( list, 'z', 'other' ) ).toBe( false );
		expect( needsId( list, 'x', '' ) ).toBe( true );
	} );

	it( 'renames a step pasted before its original (QA B-01)', () => {
		// The pasted copy mounts; the original, later in the document, does not mount again.
		const pasted = [
			step( 'copy', { id: 'follow_up' } ),
			step( 'original', { id: 'follow_up' } ),
		];
		expect( assignId( pasted, 'copy', 'follow_up', 'send_email' ) ).toBe(
			'email'
		);
	} );

	it( 'gives a new block one id, even when its mount effect runs twice', () => {
		const fresh = [ step( 'a', { id: 'decision' } ), step( 'b', {} ) ];
		expect( assignId( fresh, 'b', undefined, 'decision' ) ).toBe(
			'decision_2'
		);
		const once = [ step( 'x', {} ) ];
		expect( assignId( once, 'x', undefined, 'decision' ) ).toBe(
			'decision'
		);
		// StrictMode's second run still has the mount props (no id), but the store has the id.
		const twice = [ step( 'x', { id: 'decision' } ) ];
		expect( assignId( twice, 'x', undefined, 'decision' ) ).toBeNull();
		// A pasted duplicate later in the document is renamed when it mounts.
		const pasted = [
			step( 'x', { id: 'mail' } ),
			step( 'y', { id: 'mail' } ),
		];
		expect( assignId( pasted, 'y', 'mail', 'send_email' ) ).toBe( 'email' );
	} );

	it( 'slugifies labels into keys', () => {
		expect( slugify( 'Thank donors!' ) ).toBe( 'thank_donors' );
		expect( slugify( '2026 renewals' ) ).toBe( 'a_2026_renewals' );
	} );
} );

describe( 'durations', () => {
	it.each( [
		[ 'P30D', { sign: '', amount: 30, unit: 'days' } ],
		[ 'P2W', { sign: '', amount: 2, unit: 'weeks' } ],
		[ '-P12M', { sign: '-', amount: 12, unit: 'months' } ],
		[ 'PT6H', { sign: '', amount: 6, unit: 'hours' } ],
	] )( '%s', ( text, parts ) => {
		expect( parseDuration( text ) ).toEqual( parts );
		expect( formatDuration( parts.amount, parts.unit, parts.sign ) ).toBe(
			text
		);
	} );

	it( 'leaves several units and bad amounts alone', () => {
		expect( parseDuration( 'P1DT2H' ) ).toBeNull();
		expect( parseDuration( 3 ) ).toBeNull();
		expect( formatDuration( 0, 'days' ) ).toBe( '' );
		expect( formatDuration( 1.5, 'days' ) ).toBe( '' );
	} );
} );

describe( 'settings', () => {
	it( 'keeps unknown properties through a round trip', () => {
		const json = '{"trigger":{"type":"webhook"},"ui":{"x":1},"links":{}}';
		expect( serializeSettings( parseSettings( json ) ) ).toBe( json );
		expect( parseSettings( '[1]' ) ).toEqual( {} );
		expect( parseSettings( '{' ) ).toEqual( {} );
	} );

	it( 'removes empty values', () => {
		expect( withValue( { a: 1, b: 2 }, 'b', '' ) ).toEqual( { a: 1 } );
		expect( withValue( { a: 1 }, 'b', 0 ) ).toEqual( { a: 1, b: 0 } );
	} );
} );

describe( 'reportTree', () => {
	it( 'opens a level at each branch heading', () => {
		const steps = [
			{ id: 'mail', depth: 0, branch: null, message: 'm' },
			{ id: 'route', depth: 0, branch: null, message: 'r' },
			{ id: 'inner', depth: 1, branch: 'High', message: 'i' },
			{ id: 'big', depth: 2, branch: 'Big', message: 'b' },
			{ id: 'after', depth: 1, branch: null, message: 'a' },
			{ id: 'done', depth: 0, branch: null, message: 'd' },
		];
		const tree = reportTree( steps );
		expect(
			tree.map( ( item ) => item.entry?.id ?? item.heading )
		).toEqual( [ 'mail', 'route', 'High', 'done' ] );
		const high = tree[ 2 ];
		expect(
			high.children.map( ( item ) => item.entry?.id ?? item.heading )
		).toEqual( [ 'inner', 'Big', 'after' ] );
		expect( high.children[ 1 ].children[ 0 ].entry.id ).toBe( 'big' );
	} );
} );
