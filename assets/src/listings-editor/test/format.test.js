/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { escapeHtml, fieldValue, formatValue, isShown } from '../format';

const deps = {
	dateFormat: 'F j, Y',
	// Stand-in for @wordpress/date dateI18n: records its arguments.
	dateI18n: ( format, date, tz ) => `${ format }|${ date }|${ tz }`,
};

const field = ( type, extra = {} ) => ( {
	key: 'f',
	type,
	label: 'Needs volunteers',
	storage: 'meta',
	metaKey: '_t_f',
	options: [],
	showWhen: null,
	...extra,
} );

describe( 'formatValue', () => {
	it( 'escapes text so markup renders as text', () => {
		expect(
			formatValue(
				field( 'text' ),
				'<script>alert(1)</script>',
				{},
				'content',
				deps
			)
		).toBe( '&lt;script&gt;alert(1)&lt;/script&gt;' );
		expect( escapeHtml( `a&"'` ) ).toBe( 'a&amp;&quot;&#039;' );
	} );

	it( 'returns null for empty values', () => {
		for ( const value of [ null, false, '' ] ) {
			expect(
				formatValue( field( 'text' ), value, {}, 'content', deps )
			).toBeNull();
		}
		expect(
			formatValue( field( 'text' ), '  ', {}, 'content', deps )
		).toBeNull();
	} );

	it( 'formats dates in UTC with the format arg or site format, plus prefix', () => {
		expect(
			formatValue(
				field( 'date' ),
				'2026-10-05',
				{ format: 'M Y', prefix: 'Verified ' },
				'content',
				deps
			)
		).toBe( 'Verified M Y|2026-10-05T00:00:00Z|+00:00' );
		expect(
			formatValue( field( 'date' ), '2026-10-05', {}, 'content', deps )
		).toBe( 'F j, Y|2026-10-05T00:00:00Z|+00:00' );
		expect(
			formatValue( field( 'date' ), 'soon', {}, 'content', deps )
		).toBeNull();
	} );

	it( 'shows boolean labels only when true', () => {
		expect(
			formatValue( field( 'boolean' ), true, {}, 'content', deps )
		).toBe( 'Needs volunteers' );
		expect(
			formatValue(
				field( 'boolean' ),
				true,
				{ label: 'Help wanted' },
				'content',
				deps
			)
		).toBe( 'Help wanted' );
		expect(
			formatValue( field( 'boolean' ), false, {}, 'content', deps )
		).toBeNull();
	} );

	it( 'shows select option labels', () => {
		const select = field( 'select', {
			options: [ { value: 'a', label: 'A & B' } ],
		} );
		expect( formatValue( select, 'a', {}, 'content', deps ) ).toBe(
			'A &amp; B'
		);
		expect( formatValue( select, 'z', {}, 'content', deps ) ).toBeNull();
	} );

	it( 'allows only http, https and mailto URLs', () => {
		const url = field( 'url' );
		expect(
			formatValue( url, 'https://example.org', {}, 'url', deps )
		).toBe( 'https://example.org' );
		expect(
			formatValue( url, 'mailto:a@example.org', {}, 'url', deps )
		).toBe( 'mailto:a@example.org' );
		expect(
			formatValue( url, 'javascript:alert(1)', {}, 'url', deps )
		).toBeNull();
		expect( formatValue( url, 'not a url', {}, 'url', deps ) ).toBeNull();
		expect(
			formatValue( field( 'email' ), 'a@example.org', {}, 'url', deps )
		).toBe( 'mailto:a@example.org' );
		expect(
			formatValue( field( 'text' ), 'https://x.org', {}, 'url', deps )
		).toBeNull();
	} );
} );

describe( 'fieldValue and isShown', () => {
	const type = {
		fields: [
			field( 'boolean', { key: 'needs', metaKey: '_t_needs' } ),
			field( 'url', {
				key: 'link',
				metaKey: '_t_link',
				showWhen: { field: 'needs', equals: true },
			} ),
			field( 'text', { key: 'name', storage: 'post_title' } ),
			field( 'number', { key: 'n', metaKey: '_t_n' } ),
		],
	};
	const [ needs, link, name, number ] = type.fields;

	it( 'reads typed values from meta and post columns', () => {
		const record = {
			title: { raw: 'Raw title', rendered: 'Rendered' },
			meta: { _t_needs: true, _t_link: 'https://x.org', _t_n: '' },
		};
		expect( fieldValue( record, needs ) ).toBe( true );
		expect( fieldValue( record, link ) ).toBe( 'https://x.org' );
		expect( fieldValue( record, name ) ).toBe( 'Raw title' );
		expect( fieldValue( { title: 'Edited' }, name ) ).toBe( 'Edited' );
		expect( fieldValue( record, number ) ).toBeNull();
		expect( fieldValue( { meta: { _t_n: '4' } }, number ) ).toBe( 4 );
		expect( fieldValue( null, needs ) ).toBeNull();
	} );

	it( 'applies show_when', () => {
		expect( isShown( { meta: { _t_needs: true } }, link, type ) ).toBe(
			true
		);
		expect( isShown( { meta: { _t_needs: false } }, link, type ) ).toBe(
			false
		);
		expect( isShown( {}, needs, type ) ).toBe( true );
	} );
} );
