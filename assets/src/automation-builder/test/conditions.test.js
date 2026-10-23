/**
 * External dependencies
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	MAX_DEPTH,
	conditionsOf,
	opsFor,
	size,
	toJSON,
	toModel,
	valueKind,
	withOp,
} from '../conditions';

const root = new URL( '../../../../', import.meta.url );

/**
 * Every automation file of the valid fixture packages, the E2E packages and the examples.
 *
 * @return {Array<[string, Object]>} Relative path and definition.
 */
function automations() {
	const found = [];
	for ( const parent of [
		'tests/fixtures/valid',
		'tests/e2e/packages',
		'examples',
	] ) {
		for ( const name of readdirSync( new URL( parent, root ) ) ) {
			const dir = `${ parent }/${ name }/automations`;
			if ( ! existsSync( new URL( dir, root ) ) ) {
				continue;
			}
			for ( const file of readdirSync( new URL( dir, root ) ) ) {
				if ( file.endsWith( '.json' ) ) {
					const path = `${ dir }/${ file }`;
					found.push( [
						path,
						JSON.parse(
							readFileSync( new URL( path, root ), 'utf8' )
						),
					] );
				}
			}
		}
	}
	return found;
}

const cases = automations().flatMap( ( [ path, definition ] ) =>
	Object.entries( conditionsOf( definition ) ).map( ( [ pointer, node ] ) => [
		`${ path }#${ pointer }`,
		node,
	] )
);

describe( 'condition model round trip', () => {
	it( 'finds the conditions of every fixture', () => {
		expect( cases.length ).toBeGreaterThan( 40 );
	} );

	it.each( cases )( '%s comes back byte for byte', ( name, node ) => {
		expect( JSON.stringify( toJSON( toModel( node ) ) ) ).toBe(
			JSON.stringify( node )
		);
	} );

	it( 'keeps every valid tree within the limits', () => {
		for ( const [ , node ] of cases ) {
			expect( size( toModel( node ) ).depth ).toBeLessThanOrEqual(
				MAX_DEPTH
			);
		}
	} );
} );

describe( 'size', () => {
	it( 'counts depth and nodes as the validator does', () => {
		const model = toModel( {
			all: [
				{ var: 'record.a', op: 'empty' },
				{ not: { any: [ { var: 'record.b', op: 'eq', value: 1 } ] } },
			],
		} );
		expect( size( model ) ).toEqual( { depth: 4, nodes: 5 } );
		expect( size( null ) ).toEqual( { depth: 0, nodes: 0 } );
	} );

	it( 'measures the too-deep fixture past the limit', () => {
		const definition = JSON.parse(
			readFileSync(
				new URL(
					'tests/fixtures/invalid/automation-condition-too-deep/automations/follow.json',
					root
				),
				'utf8'
			)
		);
		expect(
			size( toModel( definition.trigger.when ) ).depth
		).toBeGreaterThan( MAX_DEPTH );
	} );
} );

describe( 'opsFor', () => {
	it( 'offers order operators for numbers and dates only', () => {
		expect( opsFor( { type: 'number' }, 'record.n', null ) ).toContain(
			'gte'
		);
		expect( opsFor( { type: 'date' }, 'record.d', null ) ).toContain(
			'lt'
		);
		expect( opsFor( { type: 'text' }, 'record.t', null ) ).not.toContain(
			'lt'
		);
	} );

	it( 'offers contains for multi-select taxonomies only', () => {
		expect(
			opsFor( { type: 'taxonomy', multiple: true }, 'record.t', null )
		).toContain( 'contains' );
		expect(
			opsFor( { type: 'taxonomy', multiple: false }, 'record.t', null )
		).not.toContain( 'contains' );
	} );

	it( 'offers changed for record vars on record.updated only', () => {
		expect( opsFor( null, 'record.x', 'record.updated' ) ).toContain(
			'changed'
		);
		expect( opsFor( null, 'trigger.x', 'record.updated' ) ).not.toContain(
			'changed'
		);
		expect( opsFor( null, 'record.x', 'record.created' ) ).not.toContain(
			'changed'
		);
	} );
} );

describe( 'withOp and valueKind', () => {
	const leaf = {
		kind: 'leaf',
		var: 'record.n',
		op: 'eq',
		value: 3,
		has: true,
	};

	it( 'drops the value for unary operators', () => {
		const next = withOp( leaf, 'empty', { type: 'number' } );
		expect( toJSON( next ) ).toEqual( { var: 'record.n', op: 'empty' } );
		expect( valueKind( next ) ).toBe( 'none' );
	} );

	it( 'wraps and unwraps lists', () => {
		const list = withOp( leaf, 'in', { type: 'number' } );
		expect( list.value ).toEqual( [ 3 ] );
		expect( valueKind( list ) ).toBe( 'list' );
		expect( withOp( list, 'eq', { type: 'number' } ).value ).toBe( 3 );
	} );

	it( 'tells operands from literals', () => {
		expect( valueKind( { ...leaf, value: { var: 'record.m' } } ) ).toBe(
			'var'
		);
		expect(
			valueKind( { ...leaf, value: { date: 'today', offset: 'P1D' } } )
		).toBe( 'date' );
		expect( valueKind( leaf ) ).toBe( 'literal' );
	} );
} );
