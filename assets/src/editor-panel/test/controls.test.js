/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { controlFor, fromControlValue, toControlValue } from '../controls';

describe( 'controlFor', () => {
	it.each( [
		[ 'text', { control: 'TextControl', inputType: 'text' } ],
		[ 'textarea', { control: 'TextareaControl' } ],
		[ 'rich_text', { control: 'TextareaControl' } ],
		[ 'url', { control: 'TextControl', inputType: 'url' } ],
		[ 'email', { control: 'TextControl', inputType: 'email' } ],
		[ 'number', { control: 'TextControl', inputType: 'number' } ],
		[ 'boolean', { control: 'ToggleControl' } ],
		[ 'date', { control: 'TextControl', inputType: 'date' } ],
		[ 'select', { control: 'SelectControl' } ],
	] )( 'maps %s', ( type, expected ) => {
		expect( controlFor( type ) ).toEqual( expected );
	} );

	it( 'falls back to a text input for unknown types', () => {
		expect( controlFor( 'color' ) ).toEqual( {
			control: 'TextControl',
			inputType: 'text',
		} );
		expect( controlFor( 'constructor' ) ).toEqual( {
			control: 'TextControl',
			inputType: 'text',
		} );
	} );
} );

describe( 'value conversion', () => {
	it( 'shows missing values as empty strings', () => {
		expect( toControlValue( 'text', null ) ).toBe( '' );
		expect( toControlValue( 'number', undefined ) ).toBe( '' );
		expect( toControlValue( 'number', 3 ) ).toBe( '3' );
	} );

	it( 'treats booleans strictly', () => {
		expect( toControlValue( 'boolean', true ) ).toBe( true );
		expect( toControlValue( 'boolean', null ) ).toBe( false );
		expect( fromControlValue( 'boolean', true ) ).toBe( true );
		expect( fromControlValue( 'boolean', false ) ).toBe( false );
	} );

	it( 'stores numbers as numbers, and empty numbers as null', () => {
		expect( fromControlValue( 'number', '42' ) ).toBe( 42 );
		expect( fromControlValue( 'number', '2.5' ) ).toBe( 2.5 );
		expect( fromControlValue( 'number', '' ) ).toBeNull();
		expect( fromControlValue( 'number', 'abc' ) ).toBeNull();
	} );

	it( 'stores other types as strings', () => {
		expect( fromControlValue( 'date', '2026-10-17' ) ).toBe( '2026-10-17' );
		expect( fromControlValue( 'url', undefined ) ).toBe( '' );
	} );
} );
