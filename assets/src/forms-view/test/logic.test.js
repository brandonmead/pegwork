/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	castValue,
	debounce,
	firstErrorStep,
	hint,
	hints,
	parseConfig,
	progressText,
	stepOf,
	wordCountText,
	wordLimits,
} from '../logic';

// The shape PegworkForms\View::config() writes for the E2E Submit a project form (abridged).
const raw = JSON.stringify( {
	items: [
		{
			key: 'name',
			type: 'text',
			label: 'Name',
			control: 'p-name',
			required: true,
			bound: true,
		},
		{
			key: 'description',
			type: 'textarea',
			label: 'Description',
			control: 'p-description',
			required: true,
			bound: true,
			validate: { min_words: 50, max_words: 300 },
		},
		{
			key: 'sifg_impact',
			type: 'select',
			label: 'Impact categories',
			control: 'p-sifg_impact-0',
			group: true,
			multiple: true,
			bound: true,
		},
		{
			key: 'needs_volunteers',
			type: 'boolean',
			label: 'Needs volunteers',
			control: 'p-needs_volunteers',
			bound: true,
		},
		{
			key: 'volunteer_link',
			type: 'url',
			label: 'Volunteer link',
			control: 'p-volunteer_link',
			bound: true,
			show_when: { field: 'needs_volunteers', equals: true },
			required_when: { field: 'needs_volunteers', equals: true },
			help: '',
		},
		{
			key: 'age',
			type: 'number',
			label: 'Age',
			control: 'p-age',
			validate: { min: 18 },
		},
		{
			key: 'contact_email',
			type: 'email',
			label: 'Contact email',
			control: 'p-contact_email',
			required: true,
			bound: true,
		},
		{
			key: 'consent',
			type: 'boolean',
			label: 'I agree',
			control: 'p-consent',
			required: true,
		},
	],
	steps: [
		{
			label: 'Your project',
			items: [ 'name', 'description', 'sifg_impact' ],
		},
		{
			label: 'Links',
			items: [ 'needs_volunteers', 'volunteer_link', 'age', 'ghost' ],
		},
		{ label: 'Contact', items: [ 'contact_email', 'consent' ] },
	],
} );

const config = parseConfig( raw );
const item = ( key ) => config.items.find( ( i ) => i.key === key );

describe( 'parseConfig', () => {
	it( 'reads items with defaults and steps with known keys only', () => {
		expect( config.items ).toHaveLength( 8 );
		expect( item( 'name' ) ).toEqual( {
			key: 'name',
			type: 'text',
			label: 'Name',
			control: 'p-name',
			group: false,
			multiple: false,
			required: true,
			bound: true,
			show_when: null,
			required_when: null,
			help: null,
			validate: {},
		} );
		expect( item( 'volunteer_link' ).show_when ).toEqual( {
			field: 'needs_volunteers',
			equals: true,
		} );
		expect( item( 'volunteer_link' ).help ).toBe( '' );
		expect( item( 'age' ).validate ).toEqual( { min: 18 } );
		expect( config.steps.map( ( s ) => s.label ) ).toEqual( [
			'Your project',
			'Links',
			'Contact',
		] );
		expect( config.steps[ 1 ].items ).toEqual( [
			'needs_volunteers',
			'volunteer_link',
			'age',
		] );
	} );

	it( 'rejects what it cannot use', () => {
		expect( parseConfig( '{nope' ) ).toBeNull();
		expect( parseConfig( '{}' ) ).toBeNull();
		expect( parseConfig( undefined ) ).toBeNull();
		expect(
			parseConfig( JSON.stringify( { items: [ { key: 'x' }, null ] } ) )
				.items
		).toEqual( [] );
		expect( parseConfig( JSON.stringify( { items: [] } ) ).steps ).toEqual(
			[]
		);
	} );
} );

describe( 'castValue', () => {
	it( 'casts like the server sanitizes', () => {
		expect( castValue( item( 'needs_volunteers' ), true ) ).toBe( true );
		expect( castValue( item( 'needs_volunteers' ), '1' ) ).toBe( false );
		expect( castValue( item( 'sifg_impact' ), [ 'sdg-06' ] ) ).toEqual( [
			'sdg-06',
		] );
		expect( castValue( item( 'sifg_impact' ), '' ) ).toEqual( [] );
		expect( castValue( item( 'age' ), ' 21 ' ) ).toBe( 21 );
		expect( castValue( item( 'age' ), 'old' ) ).toBe( '' );
		expect( castValue( item( 'name' ), '  River  ' ) ).toBe( 'River' );
		expect( castValue( item( 'description' ), ' a\n' ) ).toBe( ' a\n' );
	} );
} );

describe( 'hints', () => {
	const words = ( n ) => Array( n ).fill( 'word' ).join( ' ' );
	const values = {
		name: '',
		description: words( 2 ),
		sifg_impact: [],
		needs_volunteers: true,
		volunteer_link: '',
		age: 12,
		contact_email: 'ada@example',
		consent: false,
	};

	it( 'gives the server messages', () => {
		expect(
			Object.fromEntries( hints( config.items, values, {} ) )
		).toEqual( {
			name: 'Name is required.',
			description: 'Description must be at least 50 words (it has 2).',
			volunteer_link: 'Volunteer link is required.',
			age: 'Age must be at least 18.',
			contact_email:
				'Enter Contact email as an email address, like name@example.com.',
			consent: 'Check the box to confirm: I agree',
		} );
	} );

	it( 'skips hidden items and honours required_when', () => {
		const found = hints(
			config.items,
			{ ...values, needs_volunteers: false },
			{ volunteer_link: false }
		);
		expect( found.has( 'volunteer_link' ) ).toBe( false );
		expect(
			hint( item( 'volunteer_link' ), {
				volunteer_link: '',
				needs_volunteers: false,
			} )
		).toBeNull();
	} );

	it( 'passes valid values', () => {
		expect(
			hint( item( 'description' ), { description: words( 50 ) } )
		).toBeNull();
		expect(
			hint( item( 'description' ), { description: words( 301 ) } )
		).toBe( 'Description must be at most 300 words (it has 301).' );
		expect(
			hint( item( 'contact_email' ), {
				contact_email: 'ada@example.org',
			} )
		).toBeNull();
		expect( hint( item( 'consent' ), { consent: true } ) ).toBeNull();
		expect( hint( item( 'age' ), { age: '' } ) ).toBeNull();
		expect( hint( item( 'age' ), { age: 18 } ) ).toBeNull();
	} );

	it( 'tells not-a-number from empty', () => {
		expect( hint( item( 'age' ), { age: '' }, { age: 'old' } ) ).toBe(
			'Age must be a number.'
		);
	} );

	it( 'leaves a bound checkbox to the server', () => {
		const bound = { ...item( 'consent' ), bound: true };
		expect( hint( bound, { consent: false } ) ).toBeNull();
	} );

	it( 'never checks URL format (the server adds a missing scheme)', () => {
		expect(
			hint( item( 'volunteer_link' ), {
				volunteer_link: 'example.org/join',
				needs_volunteers: true,
			} )
		).toBeNull();
	} );

	it( 'counts length in characters, not UTF-16 units', () => {
		const short = parseConfig(
			JSON.stringify( {
				items: [
					{
						key: 't',
						label: 'T',
						control: 'c',
						validate: { max_length: 2 },
					},
				],
			} )
		).items[ 0 ];
		expect( hint( short, { t: '😀😀' } ) ).toBeNull();
		expect( hint( short, { t: '😀😀😀' } ) ).toBe(
			'T must be at most 2 characters.'
		);
	} );
} );

describe( 'steps', () => {
	it( 'finds the step of a key and the first step with an error', () => {
		expect( stepOf( config.steps, 'consent' ) ).toBe( 2 );
		expect( stepOf( config.steps, 'nope' ) ).toBe( -1 );
		expect(
			firstErrorStep( config.steps, [ 'consent', 'volunteer_link' ] )
		).toBe( 1 );
		expect( firstErrorStep( config.steps, [] ) ).toBe( 0 );
		expect(
			firstErrorStep(
				config.steps,
				new Map( [ [ 'nope', 'x' ] ] ).keys()
			)
		).toBe( 0 );
	} );

	it( 'announces progress', () => {
		expect( progressText( 1, 3, 'Links' ) ).toBe( 'Step 2 of 3: Links' );
	} );
} );

describe( 'word counter', () => {
	it( 'reads limits and words the count like the editor panel', () => {
		expect( wordLimits( item( 'description' ) ) ).toEqual( {
			min: 50,
			max: 300,
		} );
		expect( wordLimits( item( 'name' ) ) ).toBeNull();
		expect( wordCountText( 12, { min: 50, max: 300 } ) ).toBe(
			'12 of 50 to 300 words'
		);
		expect( wordCountText( 1, { min: 5, max: null } ) ).toBe(
			'1 word, at least 5 needed'
		);
		expect( wordCountText( 7, { min: null, max: 10 } ) ).toBe(
			'7 of up to 10 words'
		);
	} );

	it( 'debounces', () => {
		vi.useFakeTimers();
		const fn = vi.fn();
		const debounced = debounce( fn, 600 );
		debounced( 1 );
		debounced( 2 );
		vi.advanceTimersByTime( 599 );
		expect( fn ).not.toHaveBeenCalled();
		vi.advanceTimersByTime( 1 );
		expect( fn ).toHaveBeenCalledTimes( 1 );
		expect( fn ).toHaveBeenCalledWith( 2 );
		vi.useRealTimers();
	} );
} );
