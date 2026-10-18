/**
 * Options page screens: turns each post field's select into a searchable picker.
 * Without JavaScript the select still works. Loaded in the footer, after the form.
 */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { parseConfig } from './logic';
import PostPicker from './post-picker';

function enhance() {
	document
		.querySelectorAll( '[data-pegwork-post-picker]' )
		.forEach( ( container ) => {
			const config = parseConfig(
				container.getAttribute( 'data-pegwork-post-picker' )
			);
			const select = container.querySelector( 'select' );
			if ( ! config || ! select ) {
				return;
			}
			const label = select.id
				? document.querySelector( `label[for="${ select.id }"]` )
				: null;
			const props = {
				config,
				name: select.name,
				label,
				describedBy: select.getAttribute( 'aria-describedby' ),
				invalid: select.getAttribute( 'aria-invalid' ) === 'true',
				required: select.hasAttribute( 'required' ),
				initialValue: select.value,
			};
			const row = container.closest( 'td' );
			row?.querySelectorAll( '.pegwork-no-js-only' ).forEach(
				( element ) => element.remove()
			);
			const mount = document.createElement( 'div' );
			container.replaceChildren( mount );
			createRoot( mount ).render( <PostPicker { ...props } /> );
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', enhance );
} else {
	enhance();
}
