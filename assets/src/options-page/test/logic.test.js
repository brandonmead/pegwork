/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	PER_PAGE,
	mergeOptions,
	parseConfig,
	searchPath,
	searchStatus,
	toOption,
} from '../logic';

const config = {
	restBase: 'projects',
	restNamespace: 'wp/v2',
	singular: 'Project',
	current: null,
};

describe( 'parseConfig', () => {
	it( 'reads a full config', () => {
		expect(
			parseConfig(
				JSON.stringify( {
					restBase: 'projects',
					restNamespace: 'wp/v2',
					singular: 'Project',
					current: { id: 24, title: 'Seed library' },
				} )
			)
		).toEqual( {
			restBase: 'projects',
			restNamespace: 'wp/v2',
			singular: 'Project',
			current: { id: 24, title: 'Seed library' },
		} );
	} );

	it( 'defaults the namespace and drops a bad current record', () => {
		expect(
			parseConfig(
				JSON.stringify( { restBase: 'projects', current: { id: 0 } } )
			)
		).toEqual( {
			restBase: 'projects',
			restNamespace: 'wp/v2',
			singular: '',
			current: null,
		} );
	} );

	it( 'returns null for missing or malformed config', () => {
		expect( parseConfig( null ) ).toBeNull();
		expect( parseConfig( '{' ) ).toBeNull();
		expect( parseConfig( '{"restBase":""}' ) ).toBeNull();
	} );
} );

describe( 'searchPath', () => {
	it( 'searches published records in view context', () => {
		const path = searchPath( config, ' seed ' );
		const [ base, query ] = path.split( '?' );
		const params = new URLSearchParams( query );
		expect( base ).toBe( '/wp/v2/projects' );
		expect( params.get( 'search' ) ).toBe( 'seed' );
		expect( params.get( 'status' ) ).toBe( 'publish' );
		expect( params.get( 'context' ) ).toBe( 'view' );
		expect( params.get( '_fields' ) ).toBe( 'id,title' );
		expect( params.get( 'per_page' ) ).toBe( String( PER_PAGE ) );
		expect( params.has( 'orderby' ) ).toBe( false );
	} );

	it( 'lists by title when the search is empty', () => {
		const params = new URLSearchParams(
			searchPath( config, '  ' ).split( '?' )[ 1 ]
		);
		expect( params.has( 'search' ) ).toBe( false );
		expect( params.get( 'orderby' ) ).toBe( 'title' );
		expect( params.get( 'order' ) ).toBe( 'asc' );
	} );
} );

describe( 'toOption', () => {
	const decode = ( text ) => text.replace( '&amp;', '&' );
	const untitled = ( id ) => `(no title, #${ id })`;

	it( 'decodes the rendered title', () => {
		expect(
			toOption(
				{ id: 7, title: { rendered: 'Seeds &amp; soil' } },
				decode,
				untitled
			)
		).toEqual( { value: '7', label: 'Seeds & soil' } );
	} );

	it( 'labels untitled records by ID', () => {
		expect(
			toOption( { id: 8, title: { rendered: ' ' } }, decode, untitled )
		).toEqual( { value: '8', label: '(no title, #8)' } );
		expect( toOption( { id: 9 }, decode, untitled ).label ).toBe(
			'(no title, #9)'
		);
	} );
} );

describe( 'mergeOptions', () => {
	it( 'puts the selected record first, once', () => {
		const selected = { value: '2', label: 'B' };
		expect(
			mergeOptions( selected, [
				{ value: '1', label: 'A' },
				{ value: '2', label: 'B' },
			] )
		).toEqual( [
			{ value: '2', label: 'B' },
			{ value: '1', label: 'A' },
		] );
	} );

	it( 'works without a selection', () => {
		expect( mergeOptions( null, [ { value: '1', label: 'A' } ] ) ).toEqual(
			[ { value: '1', label: 'A' } ]
		);
	} );
} );

describe( 'searchStatus', () => {
	const base = {
		search: 'x',
		isSearching: false,
		hasFailed: false,
		count: 0,
	};

	it( 'says nothing before the first search', () => {
		expect( searchStatus( { ...base, search: null } ) ).toBeNull();
	} );

	it( 'reports loading, failure and no matches', () => {
		expect( searchStatus( { ...base, isSearching: true } ) ).toBe(
			'loading'
		);
		expect(
			searchStatus( { ...base, hasFailed: true, isSearching: true } )
		).toBe( 'error' );
		expect( searchStatus( base ) ).toBe( 'no-matches' );
	} );

	it( 'says nothing with results or an empty search', () => {
		expect( searchStatus( { ...base, count: 3 } ) ).toBeNull();
		expect( searchStatus( { ...base, search: '' } ) ).toBeNull();
	} );
} );
