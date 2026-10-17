/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	getTypeConfig,
	parseConfig,
	parseField,
	parseTaxonomy,
} from '../config';

// Same fixture the PHPUnit test asserts the PHP builder produces.
const fixture = JSON.parse(
	readFileSync(
		new URL(
			'../../../../tests/fixtures/editor-panel/config.json',
			import.meta.url
		),
		'utf8'
	)
);

describe( 'parseConfig', () => {
	it( 'parses the sifg_project config built by PHP', () => {
		const { types } = parseConfig( fixture );
		const project = types.sifg_project;

		expect( Object.keys( types ) ).toEqual( [ 'sifg_project' ] );
		expect( project.fields.map( ( field ) => field.key ) ).toEqual( [
			'name',
			'description',
			'who_for',
			'website',
			'needs_volunteers',
			'volunteer_link',
			'github_link',
			'contact_email',
			'last_verified',
		] );
		expect(
			project.taxonomies.map( ( taxonomy ) => taxonomy.select )
		).toEqual( [ 'single', 'single', 'multi' ] );
	} );

	it( 'turns PHP empty arrays into empty objects for validate', () => {
		const { types } = parseConfig( fixture );
		const website = types.sifg_project.fields.find(
			( field ) => field.key === 'website'
		);

		expect( website.validate ).toEqual( {} );
		expect( website.meta_key ).toBe( '_sifg_project_website' );
	} );

	it( 'keeps conditions, visibility and storage', () => {
		const { types } = parseConfig( fixture );
		const byKey = Object.fromEntries(
			types.sifg_project.fields.map( ( field ) => [ field.key, field ] )
		);

		expect( byKey.volunteer_link.show_when ).toEqual( {
			field: 'needs_volunteers',
			equals: true,
		} );
		expect( byKey.contact_email.visibility ).toBe( 'private' );
		expect( byKey.description.storage ).toBe( 'post_content' );
		expect( byKey.description.meta_key ).toBeNull();
		expect( byKey.description.validate ).toEqual( {
			min_words: 50,
			max_words: 300,
		} );
		expect( byKey.needs_volunteers.default ).toBe( false );
	} );

	it( 'returns no types for missing or malformed input', () => {
		expect( parseConfig( undefined ) ).toEqual( { types: {} } );
		expect( parseConfig( null ) ).toEqual( { types: {} } );
		expect( parseConfig( { types: [] } ) ).toEqual( { types: {} } );
		expect( parseConfig( { types: { book: 'nope' } } ) ).toEqual( {
			types: {},
		} );
	} );

	it( 'drops fields without a key, and meta fields without a meta key', () => {
		expect( parseField( { type: 'text' } ) ).toBeNull();
		expect( parseField( { key: 'isbn', storage: 'meta' } ) ).toBeNull();
		expect(
			parseField( { key: 'title', storage: 'post_title' } )
		).toMatchObject( { storage: 'post_title', meta_key: null } );
	} );

	it( 'applies field defaults', () => {
		expect( parseField( { key: 'isbn', meta_key: '_book_isbn' } ) ).toEqual(
			{
				key: 'isbn',
				type: 'text',
				label: 'isbn',
				required: false,
				visibility: 'public',
				meta_key: '_book_isbn',
				storage: 'meta',
				show_when: null,
				required_when: null,
				validate: {},
				options: [],
				default: null,
			}
		);
	} );

	it( 'applies taxonomy defaults', () => {
		expect( parseTaxonomy( { key: 'genre' } ) ).toEqual( {
			key: 'genre',
			rest_base: 'genre',
			select: 'multi',
			required: false,
			label: 'genre',
		} );
		expect( parseTaxonomy( { select: 'single' } ) ).toBeNull();
	} );
} );

describe( 'getTypeConfig', () => {
	const config = parseConfig( fixture );

	it( 'returns the config for a configured post type', () => {
		expect( getTypeConfig( config, 'sifg_project' ) ).toBe(
			config.types.sifg_project
		);
	} );

	it( 'returns null for other post types', () => {
		expect( getTypeConfig( config, 'post' ) ).toBeNull();
		expect( getTypeConfig( config, 'toString' ) ).toBeNull();
		expect( getTypeConfig( config, undefined ) ).toBeNull();
	} );
} );
