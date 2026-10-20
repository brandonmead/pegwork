/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	FIELD,
	STEP,
	clean,
	conditionValues,
	emailItems,
	errorsAt,
	formItems,
	generalErrors,
	keyForLabel,
	missingBound,
	parseConfig,
	parseExtensions,
	parseSettings,
	pointerMap,
	resolveItem,
	ruleParts,
	saveErrors,
	serializeSettings,
	slugify,
	toInt,
	withValue,
} from '../logic';

const project = {
	key: 'sifg_project',
	label: 'Project',
	fields: [
		{ key: 'name', label: 'Name', type: 'text', required: true },
		{
			key: 'description',
			label: 'Description',
			type: 'rich_text',
			required: true,
			validate: { min_words: 50, max_words: 300 },
		},
		{
			key: 'contact_email',
			label: 'Contact email',
			type: 'email',
			required: true,
			private: true,
		},
		{
			key: 'volunteer_link',
			label: 'Volunteer link',
			type: 'url',
			show_when: { field: 'needs_volunteers', equals: true },
		},
	],
	taxonomies: [
		{
			key: 'sifg_impact',
			label: 'Impact categories',
			multiple: true,
			required: false,
			options: [ { value: 'sdg-01', label: 'No Poverty' } ],
		},
	],
};
const config = parseConfig( { types: [ project ], turnstile: true } );

const field = ( clientId, attributes ) => ( {
	clientId,
	name: FIELD,
	attributes,
	innerBlocks: [],
} );
const step = ( clientId, attributes, innerBlocks ) => ( {
	clientId,
	name: STEP,
	attributes,
	innerBlocks,
} );

describe( 'slugify and keyForLabel', () => {
	it( 'matches the PHP rule', () => {
		expect( slugify( 'Get in touch!' ) ).toBe( 'get_in_touch' );
		expect( slugify( 'Café crème' ) ).toBe( 'cafe_creme' );
		expect( slugify( '2026 Survey' ) ).toBe( 'f_2026_survey' );
		expect( slugify( '***' ) ).toBe( '' );
		expect( slugify( 'a'.repeat( 70 ) ) ).toHaveLength( 64 );
	} );

	it( 'follows the label until the key is edited', () => {
		expect( keyForLabel( undefined, undefined, 'Email' ) ).toBe( 'email' );
		expect( keyForLabel( 'email', 'Email', 'Work email' ) ).toBe(
			'work_email'
		);
		expect( keyForLabel( 'mail', 'Email', 'Work email' ) ).toBe( 'mail' );
	} );
} );

describe( 'parseConfig', () => {
	it( 'defaults every key', () => {
		expect( parseConfig( undefined ) ).toEqual( {
			types: [],
			turnstile: false,
			lockedKey: '',
			readOnly: false,
			package: '',
			duplicateUrl: '',
			formsUrl: '',
		} );
		expect( config.types[ 0 ].key ).toBe( 'sifg_project' );
		expect( config.turnstile ).toBe( true );
	} );
} );

describe( 'settings JSON', () => {
	it( 'parses objects only', () => {
		expect( parseSettings( '{"key":"a"}' ) ).toEqual( { key: 'a' } );
		expect( parseSettings( '[1]' ) ).toEqual( {} );
		expect( parseSettings( 'nope' ) ).toEqual( {} );
		expect( parseSettings( '' ) ).toEqual( {} );
	} );

	it( 'drops empty values but keeps list entries', () => {
		expect(
			clean( {
				key: '',
				messages: { success: '' },
				target: { content_type: 'x', status: undefined },
				notifications: [ { key: 'a', reply_to: '' }, { to: '' } ],
				retention_days: 0,
			} )
		).toEqual( {
			target: { content_type: 'x' },
			notifications: [ { key: 'a' }, {} ],
			retention_days: 0,
		} );
		expect( serializeSettings( { key: '' } ) ).toBe( '{}' );
	} );

	it( 'sets and removes properties', () => {
		expect( withValue( { a: 1 }, 'b', 2 ) ).toEqual( { a: 1, b: 2 } );
		expect( withValue( { a: 1 }, 'a', '' ) ).toEqual( {} );
		expect( withValue( undefined, 'a', 'x' ) ).toEqual( { a: 'x' } );
	} );
} );

describe( 'pointers and errors', () => {
	it( 'maps blocks to definition pointers', () => {
		expect( pointerMap( [ field( 'a', {} ), field( 'b', {} ) ] ) ).toEqual(
			{ a: '/fields/0', b: '/fields/1' }
		);
		expect(
			pointerMap( [
				step( 's1', {}, [ field( 'a', {} ) ] ),
				step( 's2', {}, [ field( 'b', {} ), field( 'c', {} ) ] ),
			] )
		).toEqual( {
			s1: '/steps/0',
			a: '/steps/0/fields/0',
			s2: '/steps/1',
			b: '/steps/1/fields/0',
			c: '/steps/1/fields/1',
		} );
		expect(
			pointerMap( [ step( 's1', {}, [] ), field( 'a', {} ) ] )
		).toEqual( {} );
	} );

	it( 'reads pegwork_invalid_form errors only', () => {
		const fields = { '/fields/0/key': 'Duplicate' };
		expect(
			saveErrors( {
				code: 'pegwork_invalid_form',
				data: { fields },
			} )
		).toBe( fields );
		expect(
			saveErrors( { code: 'pegwork_invalid_record', data: { fields } } )
		).toEqual( {} );
		expect( saveErrors( null ) ).toEqual( {} );
	} );

	it( 'selects messages by pointer', () => {
		const errors = {
			'/': 'Whole form',
			'/fields/1': 'Item',
			'/fields/1/key': 'Key',
			'/fields/10/label': 'Other',
			'/notifications/0/to': 'To',
		};
		expect( errorsAt( errors, '/fields/1' ) ).toEqual( [ 'Item', 'Key' ] );
		expect( errorsAt( errors, '/fields/1', false ) ).toEqual( [ 'Item' ] );
		expect( errorsAt( errors, null ) ).toEqual( [] );
		expect( generalErrors( errors ) ).toEqual( [ 'Whole form' ] );
	} );
} );

describe( 'items', () => {
	it( 'resolves bound fields and taxonomies from the target type', () => {
		expect(
			resolveItem(
				{ bind: { field: 'description' }, help: 'Hi' },
				project
			)
		).toMatchObject( {
			key: 'description',
			type: 'textarea',
			label: 'Description',
			registryLabel: 'Description',
			required: true,
			help: 'Hi',
			bound: 'field',
		} );
		expect(
			resolveItem(
				{ bind: { taxonomy: 'sifg_impact' }, label: 'Impact' },
				project
			)
		).toMatchObject( {
			key: 'sifg_impact',
			type: 'select',
			multiple: true,
			label: 'Impact',
			display: 'checkboxes',
			bound: 'taxonomy',
		} );
		expect( resolveItem( { bind: { field: 'nope' } }, project ) ).toBe(
			null
		);
		expect( resolveItem( { bind: { field: 'name' } }, null ) ).toBe( null );
		expect( resolveItem( { label: 'Your name' }, null ) ).toMatchObject( {
			key: 'your_name',
			type: 'text',
			bound: null,
		} );
	} );

	it( 'lists items and email items in order', () => {
		const blocks = [
			step( 's1', {}, [
				field( 'a', { bind: { field: 'contact_email' } } ),
				field( 'b', { type: 'email', key: 'cc', label: 'CC' } ),
				field( 'c', { bind: { field: 'gone' } } ),
			] ),
		];
		const items = formItems( blocks, project );
		expect( items.map( ( item ) => item.clientId ) ).toEqual( [
			'a',
			'b',
		] );
		expect( emailItems( items ) ).toEqual( [
			{ key: 'contact_email', label: 'Contact email', required: true },
			{ key: 'cc', label: 'CC', required: false },
		] );
	} );

	it( 'finds bound items the form lacks', () => {
		const blocks = [
			field( 'a', { bind: { field: 'name' } } ),
			field( 'b', { bind: { taxonomy: 'sifg_impact' } } ),
		];
		expect( missingBound( project, blocks ) ).toEqual( [
			{ bind: { field: 'description' } },
			{ bind: { field: 'contact_email' } },
			{ bind: { field: 'volunteer_link' } },
		] );
		expect( missingBound( null, blocks ) ).toEqual( [] );
	} );

	it( 'words registry rules as parts', () => {
		expect(
			ruleParts(
				resolveItem( { bind: { field: 'description' } }, project )
			)
		).toEqual( [
			{ rule: 'required' },
			{ rule: 'words', min: 50, max: 300 },
		] );
		expect(
			ruleParts(
				resolveItem( { bind: { field: 'volunteer_link' } }, project )
			)
		).toEqual( [
			{ rule: 'show_when', field: 'needs_volunteers', equals: true },
		] );
		expect(
			ruleParts(
				resolveItem( { bind: { field: 'contact_email' } }, project )
			)
		).toEqual( [ { rule: 'required' }, { rule: 'private' } ] );
		expect(
			ruleParts(
				resolveItem( { bind: { taxonomy: 'sifg_impact' } }, project )
			)
		).toEqual( [ { rule: 'terms', multiple: true } ] );
	} );

	it( 'offers condition values by type', () => {
		expect( conditionValues( { type: 'boolean' } ).kind ).toBe( 'boolean' );
		expect(
			conditionValues( { type: 'select', options: [ { value: 'a' } ] } )
		).toEqual( { kind: 'options', options: [ { value: 'a' } ] } );
		expect( conditionValues( { type: 'number' } ).kind ).toBe( 'number' );
		expect( conditionValues( null ).kind ).toBe( 'text' );
	} );
} );

describe( 'inputs', () => {
	it( 'parses numbers and extensions', () => {
		expect( toInt( '' ) ).toBeUndefined();
		expect( toInt( '12.7' ) ).toBe( 12 );
		expect( toInt( 'x' ) ).toBeUndefined();
		expect( parseExtensions( 'PDF, .docx  png,pdf' ) ).toEqual( [
			'pdf',
			'docx',
			'png',
		] );
	} );
} );
