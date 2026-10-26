/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { opsFor } from '../conditions';
import { parseConfig } from '../logic';
import {
	binding,
	conditionFields,
	dateFields,
	formatTag,
	isSweep,
	mergeTags,
	recordTargets,
	stepsBefore,
	takesRoles,
	withRole,
} from '../scope';

const root = new URL( '../../../../', import.meta.url );

// Same fixture AutomationBuilderScopeTest validates every expectation of against the PHP rules.
const fixture = JSON.parse(
	readFileSync(
		new URL( 'tests/fixtures/automation/scope.json', root ),
		'utf8'
	)
);

/**
 * The automation and parsed config of a fixture case.
 *
 * @param {Object} item Case (`package`, `automation`).
 * @return {{definition: Object, config: Object}} Automation and config.
 */
function load( item ) {
	const pkg = fixture.packages[ item.package ];
	return {
		definition: JSON.parse(
			readFileSync(
				new URL(
					`${ pkg.dir }/automations/${ item.automation }.json`,
					root
				),
				'utf8'
			)
		),
		config: parseConfig( pkg.config ),
	};
}

describe( 'merge tags (shared fixture)', () => {
	it.each(
		fixture.tags.map( ( item ) => [
			`${ item.automation }${ item.at }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition, config } = load( item );
		const paths = mergeTags(
			definition,
			item.at,
			config,
			item.context
		).map( ( tag ) => tag.path );
		expect( paths ).toEqual( item.expect );
		for ( const refused of item.refuse ) {
			expect( paths ).not.toContain( refused );
		}
	} );
} );

describe( 'condition fields (shared fixture)', () => {
	it.each(
		fixture.fields.map( ( item ) => [
			`${ item.automation }${ item.at }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition, config } = load( item );
		expect(
			conditionFields( definition, item.at, config ).map(
				( field ) => field.path
			)
		).toEqual( item.expect );
	} );
} );

describe( 'operators (shared fixture)', () => {
	it.each(
		fixture.operators.map( ( item ) => [
			`${ item.automation } ${ item.var }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition, config } = load( item );
		const known = conditionFields(
			definition,
			item.at ?? '/trigger/when',
			config
		).find( ( field ) => field.path === item.var );
		const field = known?.type ? known : null;
		expect(
			opsFor( field, item.var, definition.trigger.event ?? null )
		).toEqual( item.expect );
	} );
} );

describe( 'wait date fields (shared fixture)', () => {
	it.each(
		fixture.dates.map( ( item ) => [
			`${ item.automation }${ item.at }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition, config } = load( item );
		const paths = dateFields( definition, config ).map(
			( field ) => field.path
		);
		expect( paths ).toEqual( item.expect );
		for ( const refused of item.refuse ) {
			expect( paths ).not.toContain( refused );
		}
	} );
} );

describe( 'trash targets (shared fixture)', () => {
	it.each(
		fixture.trash.map( ( item ) => [
			`${ item.automation }${ item.at }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition, config } = load( item );
		const targets = recordTargets(
			conditionFields( definition, item.at, config )
		);
		expect( targets ).toEqual( item.expect );
		for ( const refused of item.refuse ) {
			expect( targets.map( ( target ) => target.value ) ).not.toContain(
				refused
			);
		}
	} );

	it( 'knows a sweep', () => {
		expect(
			isSweep( {
				type: 'schedule',
				every: 'day',
				for_each: { content_type: 'x' },
			} )
		).toBe( true );
		expect( isSweep( { type: 'schedule', every: 'day' } ) ).toBe( false );
		expect( isSweep( { type: 'event' } ) ).toBe( false );
	} );
} );

describe( 'created operands (shared fixture)', () => {
	it.each(
		fixture.created.map( ( item ) => [
			`${ item.automation }${ item.at }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition, config } = load( item );
		// read-edit.js offers the date fields in scope at the read.
		const paths = conditionFields( definition, item.at, config )
			.filter( ( field ) => field.type === 'date' )
			.map( ( field ) => field.path );
		expect( paths ).toEqual( item.expect );
		for ( const refused of item.refuse ) {
			expect( paths ).not.toContain( refused );
		}
	} );
} );

describe( 'role templates (shared fixture)', () => {
	it.each(
		fixture.roles.map( ( item ) => [
			`${ item.automation }${ item.at }`,
			item,
		] )
	)( '%s', ( name, item ) => {
		const { definition } = load( item );
		expect( takesRoles( definition, item.at ) ).toBe( item.offered );
	} );

	it( 'adds a role as the last part', () => {
		expect( withRole( '', 'editor' ) ).toBe( '{role:editor}' );
		expect( withRole( '{{record.email}}, ', 'editor' ) ).toBe(
			'{{record.email}}, {role:editor}'
		);
		expect( withRole( 'a@example.org;', 'barn_reviewer' ) ).toBe(
			'a@example.org, {role:barn_reviewer}'
		);
	} );
} );

describe( 'binding', () => {
	const config = parseConfig( {
		forms: [
			{ key: 'signup', target: 'person', confirm: true },
			{ key: 'note', target: '', confirm: false },
		],
	} );

	it( 'binds a confirmed form with a target, and not its submission', () => {
		expect(
			binding(
				{ type: 'event', event: 'form.confirmed', form: 'signup' },
				config
			)
		).toEqual( { binds: true, type: 'person' } );
		expect(
			binding(
				{ type: 'event', event: 'form.submitted', form: 'signup' },
				config
			).binds
		).toBe( false );
		expect(
			binding(
				{ type: 'event', event: 'form.submitted', form: 'note' },
				config
			).binds
		).toBe( false );
	} );

	it( 'binds record events and sweeps', () => {
		expect(
			binding(
				{ type: 'event', event: 'record.created', content_type: 'x' },
				config
			)
		).toEqual( { binds: true, type: 'x' } );
		expect(
			binding(
				{ type: 'schedule', for_each: { content_type: 'y' } },
				config
			)
		).toEqual( { binds: true, type: 'y' } );
		expect( binding( { type: 'webhook' }, config ).binds ).toBe( false );
	} );
} );

describe( 'stepsBefore', () => {
	const steps = [
		{ id: 'a', type: 'create_record' },
		{
			id: 'd',
			type: 'decision',
			branches: [
				{
					label: 'X',
					steps: [
						{ id: 'b', type: 'create_record' },
						{ id: 'c', type: 'send_email' },
					],
				},
			],
			otherwise: [ { id: 'e', type: 'wait' } ],
		},
		{ id: 'f', type: 'send_email' },
	];

	it( 'takes earlier siblings in every enclosing list', () => {
		expect(
			stepsBefore( steps, '/steps/1/branches/0/steps/1/subject' ).map(
				( step ) => step.id
			)
		).toEqual( [ 'a', 'b' ] );
		expect(
			stepsBefore( steps, '/steps/1/otherwise/0' ).map(
				( step ) => step.id
			)
		).toEqual( [ 'a' ] );
		expect(
			stepsBefore( steps, '/steps/2/text' ).map( ( step ) => step.id )
		).toEqual( [ 'a', 'd' ] );
		expect( stepsBefore( steps, '/trigger/when' ) ).toEqual( [] );
	} );
} );

describe( 'formatTag', () => {
	it( 'writes filters', () => {
		expect( formatTag( 'record.name' ) ).toBe( '{{record.name}}' );
		expect( formatTag( 'today', { date: true } ) ).toBe(
			'{{today | date}}'
		);
		expect( formatTag( 'record.name', { fallback: 'friend "x"' } ) ).toBe(
			'{{record.name | default:"friend x"}}'
		);
	} );
} );
