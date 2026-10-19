/**
 * The `pegwork/filters` store definition, built from injected dependencies so it can be unit tested
 * without WordPress (index.js wires the real Interactivity API and router).
 *
 * Context (from the server, per block): `{ type, base, search, filters, active, summaries, countText }`.
 * State (from the server, replaced by the router on every navigation; read with getServerState):
 * `{ strings, directories: { [type]: { count, countText } } }`.
 *
 * The form controls are the source of truth for the values (no bindings that a navigation could
 * overwrite while someone types); the URL is the only stored state.
 */

/**
 * Internal dependencies
 */
import {
	buildUrl,
	isActive,
	parseUrl,
	stateFromEntries,
	summaryText,
} from './url';

export const DEBOUNCE = 300;
export const ROUTER_REGION = 'data-wp-router-region';
export const LOADING_CLASS = 'is-loading';

/**
 * Creates the store definition.
 *
 * @param {Object}                 deps                 Dependencies.
 * @param {() => Object}           deps.getContext      Interactivity getContext.
 * @param {() => {ref: Element}}   deps.getElement      Interactivity getElement.
 * @param {() => Object}           deps.getState        Returns the latest server state of the store.
 * @param {(fn: Object) => Object} [deps.withSyncEvent] Interactivity withSyncEvent (absent before WordPress 6.8).
 * @param {() => Promise<Object>}  deps.loadRouter      Resolves to the router module (`{ actions: { navigate } }`).
 * @param {Window}                 deps.win             Window (location, document, timers, FormData).
 * @return {Object} Store definition with `actions` and `callbacks`.
 */
export function createStore( deps ) {
	const { getContext, getElement, getState, loadRouter, win } = deps;
	const withSyncEvent = deps.withSyncEvent || ( ( fn ) => fn );

	// Latest navigation (older ones neither unmark the regions nor announce), its URL while pending.
	let latest = 0;
	let pending = null;
	let timer = null;

	const definition = {
		actions: {},
		callbacks: {},
	};

	/**
	 * Updates `active` and the multi-value summaries from a state.
	 *
	 * @param {Object} ctx    Block context.
	 * @param {Object} values URL state.
	 */
	function reflect( ctx, values ) {
		ctx.active = isActive( values );
		for ( const filter of ctx.filters ) {
			if ( filter.multiple ) {
				ctx.summaries[ filter.key ] = summaryText(
					getState()?.strings,
					filter.label,
					values.values[ filter.key ].length
				);
			}
		}
	}

	/**
	 * Re-announces the count in the block's live region (cleared first, so an unchanged count is
	 * announced again).
	 *
	 * @param {Object} ctx Block context.
	 */
	function announce( ctx ) {
		const text = getState()?.directories?.[ ctx.type ]?.countText;
		if ( typeof text !== 'string' ) {
			return;
		}
		ctx.countText = '';
		win.setTimeout( () => {
			ctx.countText = text;
		}, 100 );
	}

	/**
	 * The directory regions of a type on the page.
	 *
	 * @param {string} type Content type key.
	 * @return {Element[]} Regions.
	 */
	function regions( type ) {
		return Array.from(
			win.document.querySelectorAll(
				`[data-pegwork-directory="${ type }"]`
			)
		);
	}

	/**
	 * Marks regions busy or idle.
	 *
	 * @param {Element[]} list Regions.
	 * @param {boolean}   on   Busy.
	 */
	function busy( list, on ) {
		for ( const region of list ) {
			if ( on ) {
				region.setAttribute( 'aria-busy', 'true' );
				region.classList.add( LOADING_CLASS );
			} else {
				region.removeAttribute( 'aria-busy' );
				region.classList.remove( LOADING_CLASS );
			}
		}
	}

	/**
	 * Reads the form, then navigates to the matching URL: with the router when the directory is a router
	 * region (Query Loop with enhanced pagination), else with a normal page load.
	 *
	 * @param {Object}          ctx  Block context.
	 * @param {HTMLFormElement} form The filters form.
	 * @return {Promise<void>} Settles when the results are shown.
	 */
	async function update( ctx, form ) {
		win.clearTimeout( timer );
		const values = stateFromEntries(
			new win.FormData( form ).entries(),
			ctx.filters,
			ctx.search
		);
		reflect( ctx, values );

		const url = buildUrl( ctx.base, values, ctx.filters );
		if ( url === ( pending ?? win.location.href ) ) {
			return;
		}

		const targets = regions( ctx.type );
		if (
			! targets.some( ( region ) => region.hasAttribute( ROUTER_REGION ) )
		) {
			win.location.assign( url );
			return;
		}

		const id = ++latest;
		pending = url;
		busy( targets, true );
		try {
			const { actions } = await loadRouter();
			await actions.navigate( url, { screenReaderAnnouncement: false } );
		} catch {
			win.location.assign( url );
			return;
		}
		if ( id !== latest ) {
			return;
		}
		pending = null;
		busy( regions( ctx.type ), false );
		announce( ctx );
	}

	/**
	 * The form a control or the block belongs to.
	 *
	 * @param {Event} event Event.
	 * @return {HTMLFormElement|null} Form.
	 */
	function formOf( event ) {
		const target = event?.target;
		if ( target?.tagName === 'FORM' ) {
			return target;
		}
		return target?.form || target?.closest?.( 'form' ) || null;
	}

	definition.actions.submit = withSyncEvent( ( event ) => {
		event.preventDefault();
		const form = formOf( event );
		return form ? update( getContext(), form ) : undefined;
	} );

	definition.actions.change = ( event ) => {
		const target = event?.target;
		if ( ! target || target.type === 'search' || ! target.form ) {
			return undefined;
		}
		return update( getContext(), target.form );
	};

	definition.actions.input = ( event ) => {
		const target = event?.target;
		if ( ! target || target.type !== 'search' || ! target.form ) {
			return;
		}
		const ctx = getContext();
		const form = target.form;
		win.clearTimeout( timer );
		timer = win.setTimeout( () => update( ctx, form ), DEBOUNCE );
	};

	definition.actions.clear = withSyncEvent( ( event ) => {
		event.preventDefault();
		const form = getElement().ref?.closest( 'form' );
		if ( ! form ) {
			return undefined;
		}
		for ( const element of Array.from( form.elements ) ) {
			if ( element.type === 'checkbox' ) {
				element.checked = false;
			} else if ( element.type === 'radio' ) {
				element.checked = element.value === '';
			} else if ( element.type === 'search' ) {
				element.value = '';
			} else if ( element.tagName === 'SELECT' ) {
				element.value = '';
			}
		}
		const first = form.querySelector(
			'input[type="search"], input:not([type="hidden"]), select'
		);
		first?.focus();
		return update( getContext(), form );
	} );

	/**
	 * Back and forward: put the controls back to the URL's state. The router restores the results
	 * (and the merged state) from its cache; announce the restored count after it has.
	 */
	definition.callbacks.popstate = () => {
		const form = getElement().ref?.querySelector( 'form' );
		if ( ! form ) {
			return;
		}
		const ctx = getContext();
		win.clearTimeout( timer );
		const values = parseUrl( win.location.search, ctx.filters, ctx.search );
		applyState( form, values );
		reflect( ctx, values );
		busy( regions( ctx.type ), false );
		win.setTimeout( () => announce( ctx ), 0 );
	};

	return definition;
}

/**
 * Sets form controls to a state (element properties only; no markup is written).
 *
 * @param {HTMLFormElement}                               form   Form.
 * @param {{q: string, values: Object<string, string[]>}} values State.
 */
export function applyState( form, values ) {
	for ( const element of Array.from( form.elements ) ) {
		const name = ( element.name || '' ).replace( /\[\]$/, '' );
		if ( element.type === 'search' ) {
			element.value = values.q;
			continue;
		}
		const current = values.values[ name ];
		if ( ! current ) {
			continue;
		}
		if ( element.type === 'checkbox' ) {
			element.checked = current.includes( element.value );
		} else if ( element.type === 'radio' ) {
			element.checked = ( current[ 0 ] || '' ) === element.value;
		} else if ( element.tagName === 'SELECT' ) {
			element.value = current[ 0 ] || '';
		}
	}
}
