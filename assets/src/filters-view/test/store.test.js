/**
 * External dependencies
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { applyState, createStore, DEBOUNCE } from '../store';

const filters = [
	{
		key: 'project_type',
		kind: 'taxonomy',
		multiple: false,
		label: 'Type',
	},
	{ key: 'impact', kind: 'taxonomy', multiple: true, label: 'Impact' },
	{
		key: 'volunteers',
		kind: 'boolean',
		multiple: false,
		label: 'Needs volunteers',
	},
];

const BASE = 'https://example.org/projects/';

// Minimal stand-ins for form controls, the form, regions and the window (Vitest runs in Node).
function control( props ) {
	return {
		checked: false,
		value: '',
		tagName: 'INPUT',
		focus: vi.fn(),
		...props,
	};
}

function makeForm() {
	const form = {
		tagName: 'FORM',
		elements: [
			control( { type: 'search', name: 'q' } ),
			control( {
				type: 'radio',
				name: 'project_type',
				value: '',
				checked: true,
			} ),
			control( {
				type: 'radio',
				name: 'project_type',
				value: 'nonprofit',
			} ),
			control( { type: 'checkbox', name: 'impact[]', value: 'sdg-04' } ),
			control( { type: 'checkbox', name: 'impact[]', value: 'sdg-13' } ),
			control( { type: 'checkbox', name: 'volunteers', value: '1' } ),
		],
	};
	form.elements.forEach( ( element ) => ( element.form = form ) );
	form.querySelector = () => form.elements[ 0 ];
	return form;
}

function makeRegion( routed = true ) {
	const attributes = new Map(
		routed ? [ [ 'data-wp-router-region', 'query-1' ] ] : []
	);
	const classes = new Set();
	return {
		attributes,
		classes,
		hasAttribute: ( name ) => attributes.has( name ),
		setAttribute: ( name, value ) => attributes.set( name, value ),
		removeAttribute: ( name ) => attributes.delete( name ),
		classList: {
			add: ( name ) => classes.add( name ),
			remove: ( name ) => classes.delete( name ),
		},
	};
}

class FakeFormData {
	constructor( form ) {
		this.list = form.elements
			.filter(
				( element ) =>
					! [ 'checkbox', 'radio' ].includes( element.type ) ||
					element.checked
			)
			.map( ( element ) => [ element.name, element.value ] );
	}
	entries() {
		return this.list[ Symbol.iterator ]();
	}
}

function setup( { routed = true, navigate } = {} ) {
	const form = makeForm();
	const region = makeRegion( routed );
	const ctx = {
		type: 'sifg_project',
		base: BASE,
		search: true,
		filters,
		active: false,
		summaries: { impact: 'Impact' },
		countText: '5 results',
	};
	const state = {
		strings: {
			selectedOne: '%1$s, %2$s selected',
			selectedOther: '%1$s, %2$s selected',
		},
		directories: { sifg_project: { count: 5, countText: '5 results' } },
	};
	const win = {
		location: { href: BASE, search: '', assign: vi.fn() },
		document: { querySelectorAll: () => [ region ] },
		FormData: FakeFormData,
		setTimeout: ( fn, ms ) => setTimeout( fn, ms ),
		clearTimeout: ( id ) => clearTimeout( id ),
	};
	const router = {
		actions: {
			navigate: vi.fn(
				navigate ||
					( async ( url ) => {
						win.location.href = url;
						state.directories.sifg_project = {
							count: 2,
							countText: '2 results',
						};
					} )
			),
		},
	};
	let element = { ref: form.elements[ 0 ] };
	const definition = createStore( {
		getContext: () => ctx,
		getElement: () => element,
		getState: () => state,
		loadRouter: async () => router,
		win,
	} );
	return {
		ctx,
		definition,
		form,
		region,
		router,
		state,
		win,
		setElement: ( value ) => ( element = value ),
	};
}

describe( 'pegwork/filters store', () => {
	beforeEach( () => {
		vi.useFakeTimers();
	} );

	it( 'navigates with the router on change, marks the region busy, then announces the new count', async () => {
		const { ctx, definition, form, region, router } = setup();
		form.elements[ 3 ].checked = true;
		form.elements[ 5 ].checked = true;

		const done = definition.actions.change( {
			target: form.elements[ 3 ],
		} );
		expect( region.attributes.get( 'aria-busy' ) ).toBe( 'true' );
		expect( region.classes.has( 'is-loading' ) ).toBe( true );
		expect( ctx.active ).toBe( true );
		expect( ctx.summaries.impact ).toBe( 'Impact, 1 selected' );
		await done;

		expect( router.actions.navigate ).toHaveBeenCalledWith(
			`${ BASE }?impact=sdg-04&volunteers=1`,
			{ screenReaderAnnouncement: false }
		);
		expect( region.attributes.has( 'aria-busy' ) ).toBe( false );
		expect( region.classes.has( 'is-loading' ) ).toBe( false );
		expect( ctx.countText ).toBe( '' );
		vi.advanceTimersByTime( 100 );
		expect( ctx.countText ).toBe( '2 results' );
	} );

	it( 'debounces the search box', async () => {
		const { definition, form, router } = setup();
		form.elements[ 0 ].value = 'da';
		definition.actions.input( { target: form.elements[ 0 ] } );
		form.elements[ 0 ].value = 'data';
		definition.actions.input( { target: form.elements[ 0 ] } );
		expect( router.actions.navigate ).not.toHaveBeenCalled();

		await vi.advanceTimersByTimeAsync( DEBOUNCE );
		expect( router.actions.navigate ).toHaveBeenCalledTimes( 1 );
		expect( router.actions.navigate.mock.calls[ 0 ][ 0 ] ).toBe(
			`${ BASE }?q=data`
		);

		// Change events of the search box itself are left to the debounced input.
		expect(
			definition.actions.change( { target: form.elements[ 0 ] } )
		).toBeUndefined();
	} );

	it( 'submits without a page load and skips a URL already shown', async () => {
		const { definition, form, router } = setup();
		const event = { target: form, preventDefault: vi.fn() };
		await definition.actions.submit( event );
		expect( event.preventDefault ).toHaveBeenCalled();
		expect( router.actions.navigate ).not.toHaveBeenCalled();

		form.elements[ 2 ].checked = true;
		form.elements[ 1 ].checked = false;
		await definition.actions.submit( event );
		expect( router.actions.navigate.mock.calls[ 0 ][ 0 ] ).toBe(
			`${ BASE }?project_type=nonprofit`
		);
	} );

	it( 'only the latest of overlapping navigations unmarks and announces', async () => {
		let release;
		const { ctx, definition, form, region } = setup( {
			navigate: ( url ) =>
				url.includes( 'sdg-13' )
					? Promise.resolve()
					: new Promise( ( resolve ) => ( release = resolve ) ),
		} );
		form.elements[ 3 ].checked = true;
		const first = definition.actions.change( {
			target: form.elements[ 3 ],
		} );
		await Promise.resolve();
		form.elements[ 4 ].checked = true;
		await definition.actions.change( { target: form.elements[ 4 ] } );
		expect( region.attributes.has( 'aria-busy' ) ).toBe( false );
		ctx.countText = 'latest';
		release();
		await first;
		expect( ctx.countText ).toBe( 'latest' );
	} );

	it( 'falls back to a page load without a router region or when the router fails', async () => {
		const plain = setup( { routed: false } );
		plain.form.elements[ 5 ].checked = true;
		await plain.definition.actions.change( {
			target: plain.form.elements[ 5 ],
		} );
		expect( plain.win.location.assign ).toHaveBeenCalledWith(
			`${ BASE }?volunteers=1`
		);
		expect( plain.router.actions.navigate ).not.toHaveBeenCalled();

		const failing = setup( {
			navigate: () => Promise.reject( new Error( 'offline' ) ),
		} );
		failing.form.elements[ 5 ].checked = true;
		await failing.definition.actions.change( {
			target: failing.form.elements[ 5 ],
		} );
		expect( failing.win.location.assign ).toHaveBeenCalledWith(
			`${ BASE }?volunteers=1`
		);
	} );

	it( 'Clear all resets every control, focuses the search and navigates to the base URL', async () => {
		const { ctx, definition, form, router, win, setElement } = setup();
		win.location.href = `${ BASE }?project_type=nonprofit&impact=sdg-04`;
		form.elements[ 0 ].value = 'x';
		form.elements[ 1 ].checked = false;
		form.elements[ 2 ].checked = true;
		form.elements[ 3 ].checked = true;
		ctx.active = true;
		setElement( { ref: { closest: () => form } } );

		const event = { preventDefault: vi.fn() };
		await definition.actions.clear( event );
		expect( event.preventDefault ).toHaveBeenCalled();
		expect( form.elements[ 0 ].value ).toBe( '' );
		expect( form.elements[ 1 ].checked ).toBe( true );
		expect( form.elements[ 2 ].checked ).toBe( false );
		expect( form.elements[ 3 ].checked ).toBe( false );
		expect( form.elements[ 0 ].focus ).toHaveBeenCalled();
		expect( ctx.active ).toBe( false );
		expect( router.actions.navigate.mock.calls[ 0 ][ 0 ] ).toBe( BASE );
	} );

	it( 'popstate puts the controls back to the URL and announces the restored count', () => {
		const { ctx, definition, form, win, setElement } = setup();
		win.location.search = '?impact=sdg-13&q=river';
		setElement( { ref: { querySelector: () => form } } );
		definition.callbacks.popstate();

		expect( form.elements[ 0 ].value ).toBe( 'river' );
		expect( form.elements[ 1 ].checked ).toBe( true );
		expect( form.elements[ 4 ].checked ).toBe( true );
		expect( form.elements[ 3 ].checked ).toBe( false );
		expect( ctx.summaries.impact ).toBe( 'Impact, 1 selected' );
		expect( ctx.active ).toBe( true );
		vi.advanceTimersByTime( 0 );
		vi.advanceTimersByTime( 100 );
		expect( ctx.countText ).toBe( '5 results' );
	} );
} );

describe( 'applyState', () => {
	it( 'sets selects too, and ignores values without a control', () => {
		const select = control( { tagName: 'SELECT', name: 'size' } );
		const form = { elements: [ select ] };
		applyState( form, { q: '', values: { size: [ 'large' ] } } );
		expect( select.value ).toBe( 'large' );
		applyState( form, { q: '', values: { size: [] } } );
		expect( select.value ).toBe( '' );
		applyState( form, { q: '', values: {} } );
		expect( select.value ).toBe( '' );
	} );
} );
