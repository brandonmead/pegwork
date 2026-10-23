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
	STEP,
	compileBlocks,
	errorOwners,
	errorsAt,
	formatDuration,
	levelOf,
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

	it( 'renames a missing or later duplicate id only', () => {
		const list = [
			step( 'x', { id: 'mail' } ),
			step( 'y', { id: 'mail' } ),
		];
		expect( needsId( list, 'x', 'mail' ) ).toBe( false );
		expect( needsId( list, 'y', 'mail' ) ).toBe( true );
		expect( needsId( list, 'x', '' ) ).toBe( true );
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
