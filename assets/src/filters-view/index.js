/**
 * View script module of the pegwork/filters block: the `pegwork/filters` Interactivity API store.
 *
 * Without this module the block is a plain GET form. With it, changes navigate with the Interactivity
 * router, which fetches the server-rendered page and swaps only the directory's Query Loop region.
 */

/**
 * WordPress dependencies
 */
import * as interactivity from '@wordpress/interactivity';

/**
 * Internal dependencies
 */
import { createStore } from './store';

// withSyncEvent exists from WordPress 6.8. Read by a computed name so the build keeps a namespace import:
// a named import of a missing export would stop the module from loading on 6.7.
const optional = ( name ) => interactivity[ name ];

const NAMESPACE = 'pegwork/filters';
const serverState = optional( 'getServerState' );

let api = null;

api = interactivity.store(
	NAMESPACE,
	createStore( {
		getContext: interactivity.getContext,
		getElement: interactivity.getElement,
		// The router merges a new page's state into the store without overwriting existing keys, so the
		// latest server values are read with getServerState (WordPress 6.7+).
		getState: () => ( serverState ? serverState( NAMESPACE ) : api?.state ),
		withSyncEvent: optional( 'withSyncEvent' ),
		loadRouter: () => import( '@wordpress/interactivity-router' ),
		win: window,
	} )
);
