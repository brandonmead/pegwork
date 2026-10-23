/**
 * Read-only post title for builders of file-defined definitions (form builder, automation builder).
 */

const TITLE = '.editor-post-title__input';

/**
 * Makes the post title read-only. Core has no setting for it (the title is a contenteditable the
 * template lock does not reach, and it lives in the editor canvas iframe, which the admin body
 * class does not cross), so the canvas body gets the builder's read-only class (styles: no caret,
 * default cursor) and every edit of the title (typing, Backspace, Delete, paste, cut, drop) is
 * cancelled before it reaches the DOM. The title stays focusable and readable by a screen reader.
 *
 * @param {string} className Body class of the builder's read-only mode.
 * @return {() => void} Stops watching.
 */
export default function lockTitle( className ) {
	const done = new WeakSet();
	const cancel = ( event ) => {
		// Keys that change text on their own (the rich text's delete handler acts on keydown, not on
		// beforeinput); other keys, arrows and Tab included, still work.
		const edits =
			event.type !== 'keydown' ||
			[ 'Backspace', 'Delete', 'Enter' ].includes( event.key );
		if ( edits && event.target?.closest?.( TITLE ) ) {
			// Stopped as well: the rich text's own paste, cut and delete handlers run on the element
			// and would otherwise still insert or remove text.
			event.preventDefault();
			event.stopPropagation();
		}
	};
	// The canvas iframe is mounted after this runs and replaced when it reloads, so check it on a timer.
	const attach = () => {
		const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		for ( const doc of [ document, frame?.contentDocument ] ) {
			if ( ! doc?.body || done.has( doc ) ) {
				continue;
			}
			done.add( doc );
			doc.body.classList.add( className );
			for ( const type of [
				'beforeinput',
				'keydown',
				'paste',
				'cut',
				'drop',
			] ) {
				doc.addEventListener( type, cancel, true );
			}
		}
	};
	attach();
	const timer = window.setInterval( attach, 50 );
	return () => window.clearInterval( timer );
}
