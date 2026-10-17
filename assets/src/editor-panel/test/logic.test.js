/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	countWords,
	fieldValue,
	isEmpty,
	isRequired,
	isVisible,
	wordCountStatus,
	wordLimits,
} from '../logic';

const needsVolunteers = {
	key: 'needs_volunteers',
	type: 'boolean',
	storage: 'meta',
	meta_key: '_sifg_project_needs_volunteers',
	required: false,
	default: false,
	show_when: null,
	required_when: null,
};

const volunteerLink = {
	key: 'volunteer_link',
	type: 'url',
	storage: 'meta',
	meta_key: '_sifg_project_volunteer_link',
	required: false,
	default: null,
	show_when: { field: 'needs_volunteers', equals: true },
	required_when: { field: 'needs_volunteers', equals: true },
};

const contactEmail = {
	key: 'contact_email',
	type: 'email',
	storage: 'meta',
	meta_key: '_sifg_project_contact_email',
	required: true,
	default: null,
	show_when: null,
	required_when: null,
};

const fields = [ needsVolunteers, volunteerLink, contactEmail ];

describe( 'show_when', () => {
	it( 'hides the volunteer link while Needs volunteers is off', () => {
		expect( isVisible( volunteerLink, fields, {} ) ).toBe( false );
		expect(
			isVisible( volunteerLink, fields, {
				_sifg_project_needs_volunteers: false,
			} )
		).toBe( false );
	} );

	it( 'shows the volunteer link when Needs volunteers is on', () => {
		expect(
			isVisible( volunteerLink, fields, {
				_sifg_project_needs_volunteers: true,
			} )
		).toBe( true );
	} );

	it( 'compares strictly', () => {
		expect(
			isVisible( volunteerLink, fields, {
				_sifg_project_needs_volunteers: 'true',
			} )
		).toBe( false );
	} );

	it( 'always shows fields without a condition', () => {
		expect( isVisible( contactEmail, fields, {} ) ).toBe( true );
	} );

	it( 'hides a field whose condition references an unknown field', () => {
		const orphan = {
			...volunteerLink,
			show_when: { field: 'missing', equals: true },
		};
		expect( isVisible( orphan, fields, {} ) ).toBe( false );
	} );
} );

describe( 'required and required_when', () => {
	it( 'is always required when required is set', () => {
		expect( isRequired( contactEmail, fields, {} ) ).toBe( true );
	} );

	it( 'is required only while the condition holds', () => {
		expect( isRequired( volunteerLink, fields, {} ) ).toBe( false );
		expect(
			isRequired( volunteerLink, fields, {
				_sifg_project_needs_volunteers: true,
			} )
		).toBe( true );
	} );
} );

describe( 'fieldValue', () => {
	it( 'falls back to the default for missing or null values', () => {
		expect( fieldValue( fields, {}, 'needs_volunteers' ) ).toBe( false );
		expect(
			fieldValue(
				fields,
				{ _sifg_project_needs_volunteers: null },
				'needs_volunteers'
			)
		).toBe( false );
		expect( fieldValue( fields, undefined, 'needs_volunteers' ) ).toBe(
			false
		);
	} );

	it( 'returns undefined for unknown fields', () => {
		expect( fieldValue( fields, {}, 'nope' ) ).toBeUndefined();
	} );
} );

describe( 'isEmpty', () => {
	it.each( [
		[ undefined, true ],
		[ null, true ],
		[ '', true ],
		[ [], true ],
		[ 'a', false ],
		[ 0, false ],
		[ false, false ],
	] )( 'isEmpty(%j) is %s', ( value, expected ) => {
		expect( isEmpty( value ) ).toBe( expected );
	} );
} );

describe( 'countWords', () => {
	it( 'counts plain words', () => {
		expect( countWords( 'one two  three\nfour' ) ).toBe( 4 );
	} );

	it( 'ignores block delimiters and tags', () => {
		const content =
			'<!-- wp:paragraph -->\n<p>Hello <strong>big</strong> world</p>\n<!-- /wp:paragraph -->\n' +
			'<!-- wp:list --><ul><li>one</li><li>two</li></ul><!-- /wp:list -->';
		expect( countWords( content ) ).toBe( 5 );
	} );

	it( 'treats non-breaking spaces as spaces', () => {
		expect( countWords( 'a&nbsp;b&#160;c' ) ).toBe( 3 );
	} );

	it( 'returns zero for empty or non-string content', () => {
		expect( countWords( '' ) ).toBe( 0 );
		expect(
			countWords( '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' )
		).toBe( 0 );
		expect( countWords( undefined ) ).toBe( 0 );
	} );
} );

describe( 'word limits', () => {
	it( 'reads min_words and max_words', () => {
		expect(
			wordLimits( { validate: { min_words: 50, max_words: 300 } } )
		).toEqual( { min: 50, max: 300 } );
		expect( wordLimits( { validate: { max_words: 10 } } ) ).toEqual( {
			min: null,
			max: 10,
		} );
		expect( wordLimits( { validate: { max_length: 200 } } ) ).toBeNull();
		expect( wordLimits( {} ) ).toBeNull();
	} );

	it( 'reports under, ok and over', () => {
		const limits = { min: 50, max: 300 };
		expect( wordCountStatus( 49, limits ) ).toBe( 'under' );
		expect( wordCountStatus( 50, limits ) ).toBe( 'ok' );
		expect( wordCountStatus( 300, limits ) ).toBe( 'ok' );
		expect( wordCountStatus( 301, limits ) ).toBe( 'over' );
		expect( wordCountStatus( 5, { min: null, max: 10 } ) ).toBe( 'ok' );
	} );
} );
