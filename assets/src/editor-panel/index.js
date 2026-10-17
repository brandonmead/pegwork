/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { parseConfig } from './config';
import Panel from './panel';
import './editor-panel.scss';

const config = parseConfig( window.pegworkEditor );

if ( Object.keys( config.types ).length > 0 ) {
	registerPlugin( 'pegwork-editor-panel', {
		render: () => <Panel config={ config } />,
	} );
}
