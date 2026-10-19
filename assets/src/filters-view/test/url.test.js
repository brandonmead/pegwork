/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	buildUrl,
	isActive,
	parseUrl,
	stateFromEntries,
	summaryText,
} from '../url';

const filters = [
	{ key: 'project_type', kind: 'taxonomy', multiple: false },
	{ key: 'impact', kind: 'taxonomy', multiple: true },
	{ key: 'volunteers', kind: 'boolean', multiple: false },
];

const empty = {
	q: '',
	values: { project_type: [], impact: [], volunteers: [] },
};

describe( 'parseUrl', () => {
	it( 'reads the comma and the bracket forms alike', () => {
		const comma = parseUrl( '?impact=sdg-04,sdg-13', filters, true );
		const bracket = parseUrl(
			'?impact%5B%5D=sdg-04&impact%5B%5D=sdg-13',
			filters,
			true
		);
		expect( comma.values.impact ).toEqual( [ 'sdg-04', 'sdg-13' ] );
		expect( bracket ).toEqual( comma );
	} );

	it( 'keeps declared keys only, one value for single filters, `1` for booleans', () => {
		expect(
			parseUrl(
				'?project_type=a,b&volunteers=2&contact_email=x&meta_key=y&q=+data+',
				filters,
				true
			)
		).toEqual( {
			q: 'data',
			values: { project_type: [ 'a' ], impact: [], volunteers: [] },
		} );
		expect( parseUrl( '?volunteers=1', filters, true ).values ).toEqual( {
			...empty.values,
			volunteers: [ '1' ],
		} );
	} );

	it( 'drops the search when the block has none, and caps it at 100 characters', () => {
		expect( parseUrl( '?q=data', filters, false ).q ).toBe( '' );
		expect(
			parseUrl( `?q=${ 'x'.repeat( 150 ) }`, filters, true ).q
		).toHaveLength( 100 );
	} );

	it( 'caps multi-value filters at 20 and removes duplicates', () => {
		const many = Array.from( { length: 30 }, ( _, i ) => `t${ i }` );
		const state = parseUrl(
			`?impact=${ [ 't0', ...many ].join( ',' ) }`,
			filters,
			true
		);
		expect( state.values.impact ).toHaveLength( 20 );
		expect( state.values.impact[ 0 ] ).toBe( 't0' );
		expect( state.values.impact[ 1 ] ).toBe( 't1' );
	} );
} );

describe( 'stateFromEntries', () => {
	it( 'reads form data: radios, checkboxes, the boolean and the search', () => {
		expect(
			stateFromEntries(
				[
					[ 'page_id', '5' ],
					[ 'q', ' tools ' ],
					[ 'project_type', '' ],
					[ 'impact[]', 'sdg-04' ],
					[ 'impact[]', 'sdg-13' ],
					[ 'volunteers', '1' ],
				],
				filters,
				true
			)
		).toEqual( {
			q: 'tools',
			values: {
				project_type: [],
				impact: [ 'sdg-04', 'sdg-13' ],
				volunteers: [ '1' ],
			},
		} );
	} );
} );

describe( 'buildUrl', () => {
	const base = 'https://example.org/projects/';

	it( 'writes the comma form in filter order and keeps commas readable', () => {
		expect(
			buildUrl(
				base,
				{
					q: 'open data',
					values: {
						project_type: [ 'nonprofit' ],
						impact: [ 'sdg-04', 'sdg-13' ],
						volunteers: [ '1' ],
					},
				},
				filters
			)
		).toBe(
			'https://example.org/projects/?q=open+data&project_type=nonprofit&impact=sdg-04,sdg-13&volunteers=1'
		);
	} );

	it( 'returns the base URL for an empty state', () => {
		expect( buildUrl( base, empty, filters ) ).toBe( base );
	} );

	it( 'drops paging and stale filter params, keeps the base URL’s own params', () => {
		expect(
			buildUrl(
				'https://example.org/projects/page/3/?page_id=5&query-12-page=2&paged=4&impact%5B%5D=x&q=old#top',
				{ ...empty, values: { ...empty.values, volunteers: [ '1' ] } },
				filters
			)
		).toBe( 'https://example.org/projects/?page_id=5&volunteers=1' );
	} );

	it( 'round-trips with parseUrl', () => {
		const state = {
			q: 'river & data',
			values: {
				project_type: [ 'corporation' ],
				impact: [ 'sdg-15' ],
				volunteers: [],
			},
		};
		const url = new URL( buildUrl( base, state, filters ) );
		expect( parseUrl( url.search, filters, true ) ).toEqual( state );
	} );
} );

describe( 'isActive and summaryText', () => {
	it( 'is active when any filter or the search is set', () => {
		expect( isActive( empty ) ).toBe( false );
		expect( isActive( { ...empty, q: 'x' } ) ).toBe( true );
		expect(
			isActive( {
				...empty,
				values: { ...empty.values, impact: [ 'a' ] },
			} )
		).toBe( true );
	} );

	it( 'formats the multi-value summary with the translated strings', () => {
		const strings = {
			selectedOne: '%1$s, %2$s selected',
			selectedOther: '%1$s: %2$s choisis',
		};
		expect( summaryText( strings, 'Impact', 0 ) ).toBe( 'Impact' );
		expect( summaryText( strings, 'Impact', 1 ) ).toBe(
			'Impact, 1 selected'
		);
		expect( summaryText( strings, 'Impact', 3 ) ).toBe(
			'Impact: 3 choisis'
		);
		expect( summaryText( undefined, 'Impact', 2 ) ).toBe(
			'Impact, 2 selected'
		);
	} );
} );
