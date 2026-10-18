/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { createSource, fieldsList } from '../bindings';
import { parseConfig } from '../config';

const raw = {
	dateFormat: 'Y-m-d',
	types: {
		sifg_project: {
			label: 'Project',
			fields: [
				{
					key: 'who_for',
					type: 'text',
					label: "Who it's for",
					metaKey: '_sifg_project_who_for',
				},
				{
					key: 'needs_volunteers',
					type: 'boolean',
					label: 'Needs volunteers',
					metaKey: '_sifg_project_needs_volunteers',
				},
				{
					key: 'volunteer_link',
					type: 'url',
					label: 'Volunteer link',
					metaKey: '_sifg_project_volunteer_link',
					showWhen: { field: 'needs_volunteers', equals: true },
				},
				{ key: 'bad' },
				'junk',
			],
			taxonomies: [],
		},
		broken: 'nope',
	},
};

const record = {
	meta: {
		_sifg_project_who_for: 'Kids <3',
		_sifg_project_needs_volunteers: false,
		_sifg_project_volunteer_link: 'https://x.org/v',
		_sifg_project_contact_email: 'secret@example.org',
	},
};

const select = () => ( {
	getEditedEntityRecord: ( kind, name, id ) =>
		kind === 'postType' && name === 'sifg_project' && id === 7
			? record
			: undefined,
} );

const context = { postType: 'sifg_project', postId: 7 };

describe( 'parseConfig', () => {
	it( 'drops malformed fields and types and defaults the date format', () => {
		const config = parseConfig( raw );
		expect( Object.keys( config.types ) ).toEqual( [ 'sifg_project' ] );
		expect(
			config.types.sifg_project.fields.map( ( field ) => field.key )
		).toEqual( [ 'who_for', 'needs_volunteers', 'volunteer_link' ] );
		expect( parseConfig( null ) ).toEqual( {
			dateFormat: 'F j, Y',
			types: {},
		} );
	} );
} );

describe( 'createSource', () => {
	const config = parseConfig( raw );
	const source = createSource( config, {
		dateI18n: () => '',
		label: 'Pegwork field',
	} );

	it( 'previews real values from the entity record', () => {
		expect(
			source.getValues( {
				select,
				context,
				bindings: {
					content: { args: { key: 'who_for', prefix: 'For ' } },
					url: { args: { key: 'volunteer_link' } },
				},
			} )
		).toEqual( { content: 'For Kids &lt;3', url: null } );
	} );

	it( 'never previews private or unknown fields', () => {
		expect(
			source.getValues( {
				select,
				context,
				bindings: {
					content: { args: { key: 'contact_email' } },
					url: { args: { key: 'contact_email' } },
				},
			} )
		).toEqual( { content: '', url: null } );
	} );

	it( 'shows the field label without a record (templates)', () => {
		expect(
			source.getValues( {
				select,
				context: { postType: 'sifg_project' },
				bindings: { content: { args: { key: 'who_for' } } },
			} )
		).toEqual( { content: "Who it's for" } );
	} );

	it( 'is read-only', () => {
		expect( source.canUserEditValue() ).toBe( false );
		expect( source.usesContext ).toEqual( [ 'postId', 'postType' ] );
	} );
} );

describe( 'fieldsList', () => {
	it( 'lists public fields as { label, args, type } (WordPress 6.9+ shape)', () => {
		const list = fieldsList( parseConfig( raw ), context );
		expect( list[ 0 ] ).toEqual( {
			label: "Who it's for",
			args: { key: 'who_for' },
			type: 'string',
		} );
		expect( list.map( ( item ) => item.args.key ) ).toEqual( [
			'who_for',
			'needs_volunteers',
			'volunteer_link',
		] );
	} );

	it( 'is empty for unknown post types', () => {
		expect(
			fieldsList( parseConfig( raw ), { postType: 'page' } )
		).toEqual( [] );
	} );
} );
