/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	addLink,
	focusAfterRemove,
	linkedIds,
	linkedQuery,
	normalize,
	removeLink,
	searchQuery,
	searchStatus,
	suggestionItems,
	toItem,
} from '../relationship-logic';

describe( 'linkedIds', () => {
	it( 'keeps positive integer IDs once, in stored order', () => {
		expect( linkedIds( [ 25, '24', 25, 0, -3, 'x', 1.5, 26 ] ) ).toEqual( [
			25, 24, 26,
		] );
	} );

	it( 'reads anything but an array as no links', () => {
		expect( linkedIds( undefined ) ).toEqual( [] );
		expect( linkedIds( null ) ).toEqual( [] );
		expect( linkedIds( 24 ) ).toEqual( [] );
	} );
} );

describe( 'addLink and removeLink', () => {
	it( 'appends a new ID', () => {
		expect( addLink( [ 24 ], 25 ) ).toEqual( [ 24, 25 ] );
	} );

	it( 'ignores duplicates and invalid IDs, returning the same list', () => {
		const ids = [ 24, 25 ];

		expect( addLink( ids, 24 ) ).toBe( ids );
		expect( addLink( ids, 0 ) ).toBe( ids );
		expect( addLink( ids, Number.NaN ) ).toBe( ids );
	} );

	it( 'removes an ID', () => {
		expect( removeLink( [ 24, 25, 26 ], 25 ) ).toEqual( [ 24, 26 ] );
		expect( removeLink( [ 24 ], 99 ) ).toEqual( [ 24 ] );
	} );
} );

describe( 'focusAfterRemove', () => {
	it( 'moves to the next row, else the combobox', () => {
		// Removed 25 at index 1 from [ 24, 25, 26 ].
		expect( focusAfterRemove( [ 24, 26 ], 1 ) ).toBe( 26 );
		// Removed the last row.
		expect( focusAfterRemove( [ 24, 25 ], 2 ) ).toBeNull();
		// Removed the only row.
		expect( focusAfterRemove( [], 0 ) ).toBeNull();
	} );
} );

describe( 'queries', () => {
	it( 'searches non-trashed statuses, 20 at a time, ids and titles only', () => {
		expect( searchQuery( '  solar ' ) ).toEqual( {
			per_page: 20,
			status: 'publish,future,draft,pending,private',
			_fields: 'id,title,status',
			context: 'view',
			search: 'solar',
		} );
	} );

	it( 'lists recent records for an empty search', () => {
		expect( searchQuery( '' ) ).not.toHaveProperty( 'search' );
		expect( searchQuery( null ) ).not.toHaveProperty( 'search' );
	} );

	it( 'resolves linked titles in one request, trash included, IDs sorted', () => {
		expect( linkedQuery( [ 26, 24 ] ) ).toEqual( {
			include: [ 24, 26 ],
			per_page: 100,
			status: 'publish,future,draft,pending,private,trash',
			_fields: 'id,title,status',
			context: 'view',
		} );
	} );
} );

describe( 'toItem', () => {
	it( 'reads rendered titles and decodes them', () => {
		const decode = ( text ) => text.replace( '&#8217;', '’' );

		expect(
			toItem(
				{
					id: 24,
					title: { rendered: 'Kid&#8217;s code' },
					status: 'draft',
				},
				decode
			)
		).toEqual( { id: 24, title: 'Kid’s code', status: 'draft' } );
	} );

	it( 'defaults a missing title and status', () => {
		expect( toItem( { id: '7' } ) ).toEqual( {
			id: 7,
			title: '',
			status: 'publish',
		} );
	} );
} );

describe( 'suggestionItems', () => {
	const items = [
		{ id: 24, title: 'Solar Schools', status: 'publish' },
		{ id: 25, title: 'Café Coders', status: 'draft' },
		{ id: 26, title: 'Open Data Lab', status: 'publish' },
	];
	const label = ( item ) =>
		item.status === 'draft' ? `${ item.title } (Draft)` : item.title;

	it( 'excludes linked records', () => {
		expect(
			suggestionItems( items, [ 24 ], '', label ).map(
				( item ) => item.id
			)
		).toEqual( [ 25, 26 ] );
	} );

	it( 'keeps only labels containing the text, ignoring case and accents', () => {
		expect(
			suggestionItems( items, [], 'cafe', label ).map(
				( item ) => item.id
			)
		).toEqual( [ 25 ] );
		expect( suggestionItems( items, [], 'zzz', label ) ).toEqual( [] );
	} );

	it( 'normalizes like ComboboxControl', () => {
		expect( normalize( 'Café ÉCOLE' ) ).toBe( 'cafe ecole' );
	} );
} );

describe( 'searchStatus', () => {
	const base = {
		search: 'solar',
		debounced: 'solar',
		isSearching: false,
		hasFailed: false,
		count: 2,
	};

	it( 'is idle before the first focus', () => {
		expect(
			searchStatus( { ...base, search: null, debounced: null } )
		).toBe( 'idle' );
	} );

	it( 'is loading while waiting for the debounce or the request', () => {
		expect( searchStatus( { ...base, debounced: 'sol' } ) ).toBe(
			'loading'
		);
		expect( searchStatus( { ...base, isSearching: true } ) ).toBe(
			'loading'
		);
	} );

	it( 'reports a failed search', () => {
		expect( searchStatus( { ...base, hasFailed: true } ) ).toBe( 'error' );
	} );

	it( 'reports no matches only for typed text', () => {
		expect( searchStatus( { ...base, count: 0 } ) ).toBe( 'no-matches' );
		expect(
			searchStatus( { ...base, search: '', debounced: '', count: 0 } )
		).toBe( 'ready' );
		expect( searchStatus( base ) ).toBe( 'ready' );
	} );
} );
