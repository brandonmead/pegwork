/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { parseConfig } from '../config';
import {
	RELATIONSHIP_OPS,
	conditionFor,
	conditionProblem,
	describeCondition,
	opsFor,
	resolvePostType,
	targetOf,
} from '../conditions';

const config = parseConfig( {
	types: {
		sifg_project: {
			label: 'Project',
			fields: [
				{
					key: 'needs_volunteers',
					type: 'boolean',
					label: 'Needs volunteers',
					metaKey: '_n',
				},
				{
					key: 'kind',
					type: 'select',
					label: 'Kind',
					metaKey: '_k',
					options: [ { value: 'a', label: 'Alpha' } ],
				},
				{ key: 'who_for', type: 'text', label: 'Who', metaKey: '_w' },
			],
			taxonomies: [
				{
					key: 'sifg_project_status',
					label: 'Project status',
					terms: [ { slug: 'paused', name: 'Paused' } ],
				},
			],
		},
	},
} );
const type = config.types.sifg_project;

describe( 'resolvePostType', () => {
	it( 'uses context, then the template slug', () => {
		expect( resolvePostType( config, 'sifg_project', null ) ).toBe(
			'sifg_project'
		);
		for ( const slug of [ 'single', 'archive' ] ) {
			const id = `theme//${ slug }-sifg_project`;
			expect( resolvePostType( config, 'wp_template', id ) ).toBe(
				'sifg_project'
			);
		}
		expect( resolvePostType( config, 'page', 'theme//single-post' ) ).toBe(
			null
		);
	} );
} );

describe( 'conditionProblem', () => {
	it( 'flags unknown, private and foreign targets and bad operators', () => {
		expect(
			conditionProblem(
				{ field: 'needs_volunteers', op: 'equals', value: true },
				type
			)
		).toBeNull();
		expect(
			conditionProblem(
				{
					taxonomy: 'sifg_project_status',
					op: 'not_in',
					terms: [ 'paused' ],
				},
				type
			)
		).toBeNull();
		expect(
			conditionProblem( { field: 'contact_email', op: 'empty' }, type )
		).toBe( 'unknown' );
		expect(
			conditionProblem(
				{ taxonomy: 'category', op: 'in', terms: [] },
				type
			)
		).toBe( 'unknown' );
		const badOp = { field: 'who_for', op: 'in' };
		expect( conditionProblem( badOp, type ) ).toBe( 'operator' );
		expect( conditionProblem( {}, type ) ).toBe( 'unknown' );
	} );
} );

describe( 'conditionFor and targetOf', () => {
	it( 'creates sensible defaults per target', () => {
		expect( conditionFor( 'field:needs_volunteers', type ) ).toEqual( {
			field: 'needs_volunteers',
			op: 'equals',
			value: true,
		} );
		expect( conditionFor( 'field:kind', type ) ).toEqual( {
			field: 'kind',
			op: 'equals',
			value: 'a',
		} );
		expect( conditionFor( 'field:who_for', type ) ).toEqual( {
			field: 'who_for',
			op: 'not_empty',
		} );
		expect( conditionFor( 'taxonomy:sifg_project_status', type ) ).toEqual(
			{ taxonomy: 'sifg_project_status', op: 'in', terms: [] }
		);
		expect( conditionFor( 'field:contact_email', type ) ).toBeNull();
		expect( targetOf( { taxonomy: 'x' } ) ).toBe( 'taxonomy:x' );
		expect( targetOf( { field: 'y' } ) ).toBe( 'field:y' );
	} );
} );

describe( 'describeCondition', () => {
	it( 'describes the volunteer rule', () => {
		expect(
			describeCondition(
				{ field: 'needs_volunteers', op: 'equals', value: true },
				type
			)
		).toBe( 'Needs volunteers is on' );
		expect(
			describeCondition(
				{
					taxonomy: 'sifg_project_status',
					op: 'not_in',
					terms: [ 'paused' ],
				},
				type
			)
		).toBe( 'Project status is none of Paused' );
		const kind = { field: 'kind', op: 'equals', value: 'a' };
		expect( describeCondition( kind, type ) ).toBe( 'Kind is Alpha' );
		expect(
			describeCondition( { field: 'contact_email', op: 'empty' }, type )
		).toBe( 'contact_email (unavailable: renders nothing)' );
	} );
} );

describe( 'relationship conditions', () => {
	const withRelated = parseConfig( {
		types: {
			sifg_project: {
				label: 'Project',
				relationships: [
					{
						key: 'story_projects',
						type: 'sifg_story',
						label: 'Story',
						plural: 'Stories',
					},
				],
			},
		},
	} ).types.sifg_project;

	it( 'builds, validates and describes them', () => {
		const condition = conditionFor(
			'relationship:story_projects',
			withRelated
		);
		expect( condition ).toEqual( {
			relationship: 'story_projects',
			op: 'not_empty',
		} );
		expect( targetOf( condition ) ).toBe( 'relationship:story_projects' );
		expect( opsFor( condition ) ).toEqual( RELATIONSHIP_OPS );
		expect( conditionProblem( condition, withRelated ) ).toBeNull();
		expect( describeCondition( condition, withRelated ) ).toBe(
			'Related Stories is not empty'
		);
		expect(
			conditionProblem( { ...condition, op: 'in' }, withRelated )
		).toBe( 'operator' );
	} );

	it( 'fails closed on a relationship the type is not part of', () => {
		const condition = { relationship: 'nope', op: 'empty' };
		expect( conditionFor( 'relationship:nope', withRelated ) ).toBeNull();
		expect( conditionProblem( condition, withRelated ) ).toBe( 'unknown' );
		expect( conditionProblem( condition, type ) ).toBe( 'unknown' );
		expect( describeCondition( condition, withRelated ) ).toBe(
			'nope (unavailable: renders nothing)'
		);
	} );
} );
