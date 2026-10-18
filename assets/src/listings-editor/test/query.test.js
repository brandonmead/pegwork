/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { parseConfig } from '../config';
import {
	OPTION_KEY,
	addPegworkAttribute,
	directoryVariations,
	isPegworkQuery,
	presetFields,
	setWhere,
	whereValue,
} from '../query';

const config = parseConfig( {
	types: {
		sifg_project: {
			label: 'Project',
			plural: 'Projects',
			listing: true,
			cardPattern: 'pegwork/sifg_project-card',
			noResults: 'No projects match your search or filters.',
			optionPosts: [
				{
					value: 'sifg_settings.featured_project',
					label: 'SI for Good settings: Featured project',
				},
				{ value: 'no-dot', label: 'Bad' },
			],
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
				{
					key: 'name',
					type: 'text',
					label: 'Name',
					storage: 'post_title',
				},
			],
		},
		sifg_story: { label: 'Story', plural: 'Stories', fields: [] },
	},
} );

const strings = {
	title: ( plural ) => `${ plural } directory`,
	description: ( plural ) => `Grid of ${ plural }`,
};

describe( 'config', () => {
	it( 'parses listing settings and drops malformed option references', () => {
		const type = config.types.sifg_project;
		expect( type.plural ).toBe( 'Projects' );
		expect( type.listing ).toBe( true );
		expect( type.cardPattern ).toBe( 'pegwork/sifg_project-card' );
		expect( type.optionPosts ).toEqual( [
			{
				value: 'sifg_settings.featured_project',
				label: 'SI for Good settings: Featured project',
			},
		] );
		expect( config.types.sifg_story.listing ).toBe( false );
		expect( config.types.sifg_story.optionPosts ).toEqual( [] );
	} );
} );

describe( 'addPegworkAttribute', () => {
	it( 'adds the object attribute to core/query only', () => {
		const settings = { attributes: { queryId: { type: 'number' } } };
		expect( addPegworkAttribute( settings, 'core/query' ) ).toEqual( {
			attributes: {
				queryId: { type: 'number' },
				pegwork: { type: 'object' },
			},
		} );
		expect( addPegworkAttribute( settings, 'core/group' ) ).toBe(
			settings
		);
		expect( settings.attributes.pegwork ).toBeUndefined();
	} );
} );

describe( 'directoryVariations', () => {
	const variations = directoryVariations( config, strings );

	it( 'registers one variation per type with a listing', () => {
		expect( variations.map( ( v ) => v.name ) ).toEqual( [
			'pegwork/sifg_project-directory',
		] );
		const [ variation ] = variations;
		expect( variation.title ).toBe( 'Projects directory' );
		expect( variation.category ).toBe( 'pegwork' );
		expect( variation.attributes.query.postType ).toBe( 'sifg_project' );
		expect( variation.attributes.query.inherit ).toBe( false );
		expect( variation.attributes.enhancedPagination ).toBe( true );
		expect( variation.attributes.pegwork ).toEqual( { filters: true } );
	} );

	it( 'nests the card pattern, pagination and no results', () => {
		const [ template, pagination, noResults ] = variations[ 0 ].innerBlocks;
		expect( template[ 0 ] ).toBe( 'core/post-template' );
		expect( template[ 2 ] ).toEqual( [
			[ 'core/pattern', { slug: 'pegwork/sifg_project-card' } ],
		] );
		expect( pagination[ 0 ] ).toBe( 'core/query-pagination' );
		expect( pagination[ 2 ].map( ( b ) => b[ 0 ] ) ).toEqual( [
			'core/query-pagination-previous',
			'core/query-pagination-numbers',
			'core/query-pagination-next',
		] );
		expect( noResults[ 2 ][ 0 ][ 1 ].content ).toBe(
			'No projects match your search or filters.'
		);
	} );

	it( 'is active for Pegwork queries of its type', () => {
		const { isActive } = variations[ 0 ];
		expect(
			isActive( { pegwork: {}, query: { postType: 'sifg_project' } } )
		).toBe( true );
		expect( isActive( { query: { postType: 'sifg_project' } } ) ).toBe(
			false
		);
		expect(
			isActive( { pegwork: {}, query: { postType: 'sifg_story' } } )
		).toBe( false );
	} );
} );

describe( 'presets', () => {
	const type = config.types.sifg_project;
	const [ volunteers, kind ] = presetFields( type );

	it( 'offers meta-stored boolean and select fields only', () => {
		expect( presetFields( type ).map( ( f ) => f.key ) ).toEqual( [
			'needs_volunteers',
			'kind',
		] );
		expect( presetFields( null ) ).toEqual( [] );
	} );

	it( 'sets, reads and clears presets', () => {
		let pegwork = { filters: true };
		pegwork = setWhere( pegwork, 'needs_volunteers', '1', 'boolean' );
		expect( pegwork ).toEqual( {
			filters: true,
			where: { needs_volunteers: true },
		} );
		expect( whereValue( pegwork, volunteers ) ).toBe( '1' );

		pegwork = setWhere( pegwork, 'needs_volunteers', '0', 'boolean' );
		expect( whereValue( pegwork, volunteers ) ).toBe( '0' );

		pegwork = setWhere( pegwork, 'kind', 'a', 'select' );
		expect( whereValue( pegwork, kind ) ).toBe( 'a' );

		pegwork = setWhere(
			pegwork,
			OPTION_KEY,
			'sifg_settings.featured_project',
			'option'
		);
		expect( pegwork.where[ OPTION_KEY ] ).toBe(
			'sifg_settings.featured_project'
		);

		pegwork = setWhere( pegwork, 'needs_volunteers', '', 'boolean' );
		pegwork = setWhere( pegwork, 'kind', '', 'select' );
		pegwork = setWhere( pegwork, OPTION_KEY, '', 'option' );
		expect( pegwork ).toEqual( { filters: true } );
		expect( whereValue( pegwork, volunteers ) ).toBe( '' );
	} );

	it( 'treats a missing or malformed attribute as none', () => {
		expect( isPegworkQuery( {} ) ).toBe( false );
		expect( isPegworkQuery( { pegwork: [] } ) ).toBe( false );
		expect( isPegworkQuery( { pegwork: {} } ) ).toBe( true );
		expect( setWhere( null, 'kind', 'a', 'select' ) ).toEqual( {
			where: { kind: 'a' },
		} );
	} );
} );
