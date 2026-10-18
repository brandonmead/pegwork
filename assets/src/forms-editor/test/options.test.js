/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { formOptions, parseConfig } from '../options';

const labels = {
	none: 'Choose a form',
	later: ( label ) => `${ label } (not shown yet)`,
	gone: ( key ) => `${ key } (not defined)`,
};

describe( 'parseConfig', () => {
	it( 'keeps well-formed forms and defaults supported to true', () => {
		expect(
			parseConfig( {
				forms: [
					{ key: 'contact', label: 'Contact' },
					{ key: 'submit', label: 'Submit', supported: false },
					{ key: '', label: 'Empty key' },
					{ label: 'No key' },
					null,
				],
			} )
		).toEqual( {
			forms: [
				{ key: 'contact', label: 'Contact', supported: true },
				{ key: 'submit', label: 'Submit', supported: false },
			],
		} );
	} );

	it( 'tolerates a missing config', () => {
		expect( parseConfig( undefined ) ).toEqual( { forms: [] } );
		expect( parseConfig( { forms: 'nope' } ) ).toEqual( { forms: [] } );
	} );
} );

describe( 'formOptions', () => {
	const forms = [
		{ key: 'contact', label: 'Contact', supported: true },
		{ key: 'submit', label: 'Submit', supported: false },
	];

	it( 'lists a placeholder then every form, marking unsupported ones', () => {
		expect( formOptions( forms, '', labels ) ).toEqual( [
			{ value: '', label: 'Choose a form' },
			{ value: 'contact', label: 'Contact (contact)' },
			{ value: 'submit', label: 'Submit (submit) (not shown yet)' },
		] );
	} );

	it( 'keeps a selected key that is no longer defined', () => {
		expect( formOptions( forms, 'old_form', labels ).at( -1 ) ).toEqual( {
			value: 'old_form',
			label: 'old_form (not defined)',
		} );
	} );
} );
