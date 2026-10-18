/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { resolveType, toggleFilter, typeOptions } from '../settings';

const config = {
	types: [
		{
			key: 'sifg_project',
			label: 'Projects',
			filters: [ { key: 'a' }, { key: 'b' }, { key: 'c' } ],
		},
	],
};

describe( 'filters block settings', () => {
	it( 'resolves the previewed type', () => {
		expect( resolveType( config, 'sifg_project', '' ) ).toBe(
			'sifg_project'
		);
		expect( resolveType( config, 'page', '' ) ).toBe( '' );
		expect( resolveType( config, '', 'archive-sifg_project' ) ).toBe(
			'sifg_project'
		);
		expect( resolveType( config, '', '' ) ).toBe( 'sifg_project' );
		const two = {
			types: [ ...config.types, { key: 'sifg_resource', filters: [] } ],
		};
		expect( resolveType( two, '', 'home' ) ).toBe( '' );
	} );

	it( 'lists types after the automatic choice', () => {
		expect( typeOptions( config, 'Auto' ) ).toEqual( [
			{ value: '', label: 'Auto' },
			{ value: 'sifg_project', label: 'Projects' },
		] );
	} );

	it( 'toggles filters in type order; all shown is empty; the last one stays', () => {
		const type = config.types[ 0 ];
		expect( toggleFilter( type, [], 'b', false ) ).toEqual( [ 'a', 'c' ] );
		expect( toggleFilter( type, [ 'c', 'a' ], 'b', true ) ).toEqual( [] );
		expect( toggleFilter( type, [ 'c' ], 'a', true ) ).toEqual( [
			'a',
			'c',
		] );
		expect( toggleFilter( type, [ 'c' ], 'c', false ) ).toEqual( [ 'c' ] );
	} );
} );
