/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import fixture from '../../../../tests/fixtures/forms/conditions.json';
import { isNumeric, phpBool, phpString, shown, visible } from '../conditions';
import { countWords } from '../logic';

// The same rules table PHPUnit runs (tests/Integration/Forms/FormsConditionsTest.php).
describe( 'condition parity with PHP', () => {
	it( 'has the cases', () => {
		expect( fixture.shown.length ).toBeGreaterThan( 30 );
		expect( fixture.visible.length ).toBeGreaterThan( 5 );
		expect( fixture.words.length ).toBeGreaterThan( 5 );
	} );

	it.each( fixture.shown )( 'shown: $name', ( row ) => {
		expect( shown( row.show_when, row.values ) ).toBe( row.expected );
	} );

	it.each( fixture.visible )(
		'visible: $name',
		( { items, values, expected } ) => {
			const result = visible( items, values );
			expect( result ).toEqual( expected );
			// Same key order as PHP's array.
			expect( Object.keys( result ) ).toEqual( Object.keys( expected ) );
		}
	);

	it.each( fixture.words )( 'words: $name', ( { text, count } ) => {
		expect( countWords( text ) ).toBe( count );
	} );
} );

describe( 'PHP casts', () => {
	it( 'is_numeric', () => {
		const yes = [
			3,
			2.5,
			'3',
			' 3',
			'3 ',
			'3.',
			'.5',
			'+3',
			'-3',
			'3e2',
			'1E-2',
		];
		expect( yes.filter( ( v ) => ! isNumeric( v ) ) ).toEqual( [] );
		const no = [
			'',
			' ',
			'.',
			'e2',
			'0x1A',
			'3 3',
			'three',
			true,
			null,
			undefined,
			[],
			NaN,
		];
		expect( no.filter( ( v ) => isNumeric( v ) ) ).toEqual( [] );
	} );

	it( '(bool) and (string)', () => {
		expect( [ '', '0', 0, null, undefined, false ].map( phpBool ) ).toEqual(
			[ false, false, false, false, false, false ]
		);
		expect( [ '0.0', ' ', 'a', 1, true ].map( phpBool ) ).toEqual( [
			true,
			true,
			true,
			true,
			true,
		] );
		expect( [ true, false, null, 5, 'x' ].map( phpString ) ).toEqual( [
			'1',
			'',
			'',
			'5',
			'x',
		] );
	} );
} );
