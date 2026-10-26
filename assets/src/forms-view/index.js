/**
 * Forms view script: enhances server-rendered Pegwork forms. One step at a time with Back and Next
 * ("Step 2 of 3" in a polite live region, focus to the step heading), hint checks before Next
 * and submit (the server checks everything again), `show_when` items toggled in place, live word
 * counters, error summary links that switch steps, and a submitting state. Without this script the
 * form is one page with one submit button and works the same.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { visible } from './conditions';
import {
	castValue,
	countWords,
	debounce,
	firstErrorStep,
	hints,
	isRequired,
	parseConfig,
	progressText,
	wordCountText,
	wordLimits,
} from './logic';

const COUNT_DELAY_MS = 600;

/**
 * Adds or removes an ID in an element's aria-describedby.
 *
 * @param {Element} element Element.
 * @param {string}  id      ID.
 * @param {boolean} on      Add (true) or remove (false).
 */
function describe( element, id, on ) {
	const ids = ( element.getAttribute( 'aria-describedby' ) || '' )
		.split( /\s+/ )
		.filter( ( value ) => value !== '' && value !== id );
	if ( on ) {
		ids.push( id );
	}
	if ( ids.length ) {
		element.setAttribute( 'aria-describedby', ids.join( ' ' ) );
	} else {
		element.removeAttribute( 'aria-describedby' );
	}
}

/**
 * Text of an element without its screen-reader-only parts (the "Error:" prefix).
 *
 * @param {Element} element Element.
 * @return {string} Text.
 */
function visibleText( element ) {
	const copy = element.cloneNode( true );
	copy.querySelectorAll( '.pegwork-form__sr' ).forEach( ( node ) =>
		node.remove()
	);
	return copy.textContent.trim();
}

/**
 * Enhances one form.
 *
 * @param {HTMLFormElement} form Form rendered by PegworkForms\Renderer.
 */
function enhance( form ) {
	const config = parseConfig( form.getAttribute( 'data-pegwork-config' ) );
	const root = form.closest( '.pegwork-form' );
	if ( ! config || ! root || form.hasAttribute( 'data-pegwork-enhanced' ) ) {
		return;
	}
	form.setAttribute( 'data-pegwork-enhanced', '' );
	root.classList.add( 'pegwork-form--enhanced' );

	const doc = form.ownerDocument;
	const base = root.id;
	const reducedMotion =
		doc.defaultView?.matchMedia?.( '(prefers-reduced-motion: reduce)' )
			.matches ?? false;

	/** @type {Map<string, {item: Object, control: HTMLElement, wrapper: ?HTMLElement, described: HTMLElement, id: string}>} */
	const fields = new Map();
	for ( const item of config.items ) {
		const control = doc.getElementById( item.control );
		if ( ! control || ! form.contains( control ) ) {
			continue;
		}
		const wrapper = control.closest( '.pegwork-form__field' );
		fields.set( item.key, {
			item,
			control,
			wrapper,
			described: item.group && wrapper ? wrapper : control,
			id: `${ base }-${ item.key }`,
		} );
	}

	/**
	 * The raw value of an item: a list for multi-choice, a boolean for a checkbox, else text.
	 *
	 * @param {Object} field Field entry.
	 * @return {string|string[]|boolean} Raw value.
	 */
	const rawValue = ( field ) => {
		const { item, control } = field;
		const name = `pegwork[${ item.key }]`;
		if ( item.multiple ) {
			if ( control.tagName === 'SELECT' ) {
				return Array.from( control.selectedOptions, ( o ) => o.value );
			}
			return Array.from(
				form.querySelectorAll( 'input[type="checkbox"]' )
			)
				.filter( ( input ) => input.name === `${ name }[]` )
				.filter( ( input ) => input.checked )
				.map( ( input ) => input.value );
		}
		if ( item.type === 'boolean' ) {
			return control.checked;
		}
		if ( item.group ) {
			const checked = Array.from(
				form.querySelectorAll( 'input[type="radio"]' )
			).find( ( input ) => input.name === name && input.checked );
			return checked ? checked.value : '';
		}
		return control.value;
	};

	const read = () => {
		const values = {};
		const texts = {};
		for ( const [ key, field ] of fields ) {
			const raw = rawValue( field );
			values[ key ] = castValue( field.item, raw );
			texts[ key ] = typeof raw === 'string' ? raw : '';
		}
		return { values, texts };
	};

	// Conditions: the page without JavaScript states them as help text; here the items toggle instead.
	for ( const field of fields.values() ) {
		if ( ! field.item.show_when ) {
			continue;
		}
		const help = doc.getElementById( `${ field.id }-help` );
		if ( ! help ) {
			continue;
		}
		if ( field.item.help ) {
			help.textContent = field.item.help;
		} else {
			describe( field.described, help.id, false );
			help.remove();
		}
	}

	// Items required only while their `required_when` holds: the page without JavaScript says
	// "(required if ...)"; here the marker reads "(required)" and aria-required is set while it holds.
	const conditional = [];
	for ( const field of fields.values() ) {
		const marker = field.item.required
			? null
			: field.wrapper?.querySelector( '[data-pegwork-required-when]' );
		if ( ! marker || ! field.item.required_when ) {
			continue;
		}
		marker.textContent = __( '(required)', 'pegwork' );
		// Checkbox groups take no aria-required (not allowed on a group); radio groups carry it on the fieldset.
		let target = field.control;
		if ( field.item.group ) {
			target = field.item.multiple ? null : field.wrapper;
		}
		conditional.push( { field, marker, target } );
	}

	const applyConditions = () => {
		const { values } = read();
		const shown = visible( config.items, values );
		for ( const [ key, field ] of fields ) {
			if ( field.wrapper ) {
				field.wrapper.hidden = shown[ key ] === false;
			}
		}
		for ( const { field, marker, target } of conditional ) {
			const required = isRequired( field.item, values );
			marker.hidden = ! required;
			if ( required ) {
				target?.setAttribute( 'aria-required', 'true' );
			} else {
				target?.removeAttribute( 'aria-required' );
			}
		}
		return shown;
	};
	applyConditions();
	form.addEventListener( 'input', applyConditions );
	form.addEventListener( 'change', applyConditions );

	// Live word counters for items with word limits.
	for ( const field of fields.values() ) {
		const limits = wordLimits( field.item );
		if ( ! limits || field.control.tagName !== 'TEXTAREA' ) {
			continue;
		}
		const counter = doc.createElement( 'p' );
		counter.className = 'pegwork-form__count';
		counter.id = `${ field.id }-count`;
		counter.setAttribute( 'aria-live', 'polite' );
		const update = () => {
			counter.textContent = wordCountText(
				countWords( field.control.value ),
				limits
			);
		};
		update();
		field.control.after( counter );
		describe( field.control, counter.id, true );
		field.control.addEventListener(
			'input',
			debounce( update, COUNT_DELAY_MS )
		);
	}

	// Errors: start from the server's, keep a server message until its value changes.
	const errors = new Map();
	const server = new Map();
	for ( const [ key, field ] of fields ) {
		const message = doc.getElementById( `${ field.id }-error` );
		if ( message ) {
			errors.set( key, visibleText( message ) );
			server.set( key, {
				message: visibleText( message ),
				value: JSON.stringify( rawValue( field ) ),
			} );
		}
	}
	let summary = Array.from( root.children ).find( ( child ) =>
		child.classList.contains( 'pegwork-form__errors' )
	);
	const unlinked = summary
		? Array.from( summary.querySelectorAll( 'li' ) )
				.filter( ( li ) => ! li.querySelector( 'a' ) )
				.map( ( li ) => li.textContent )
		: [];

	const titleFormat = ( title ) =>
		/* translators: %s: page title. */
		sprintf( __( 'Error: %s', 'pegwork' ), title );
	const titlePrefix = titleFormat( '' );
	const bareTitle =
		titlePrefix !== '' && doc.title.startsWith( titlePrefix )
			? doc.title.slice( titlePrefix.length )
			: doc.title;
	const setTitle = ( hasErrors ) => {
		doc.title = hasErrors ? titleFormat( bareTitle ) : bareTitle;
	};

	const setFieldError = ( field, message ) => {
		const id = `${ field.id }-error`;
		let element = doc.getElementById( id );
		if ( message === null ) {
			element?.remove();
			describe( field.described, id, false );
			field.described.removeAttribute( 'aria-invalid' );
			field.wrapper?.classList.remove( 'pegwork-form__field--error' );
			errors.delete( field.item.key );
			return;
		}
		if ( ! element ) {
			element = doc.createElement( 'p' );
			element.className = 'pegwork-form__error';
			element.id = id;
			const anchor = field.item.group
				? field.control.closest( '.pegwork-form__option' ) ||
					field.control
				: field.control;
			anchor.before( element );
		}
		const prefix = doc.createElement( 'span' );
		prefix.className = 'pegwork-form__sr';
		prefix.textContent = __( 'Error:', 'pegwork' ) + ' ';
		element.replaceChildren( prefix, message );
		describe( field.described, id, true );
		field.described.setAttribute( 'aria-invalid', 'true' );
		field.wrapper?.classList.add( 'pegwork-form__field--error' );
		errors.set( field.item.key, message );
	};

	const renderSummary = ( focus ) => {
		const listed = config.items.filter(
			( item ) => errors.has( item.key ) && fields.has( item.key )
		);
		if ( ! listed.length && ! unlinked.length ) {
			summary?.remove();
			summary = null;
			setTitle( false );
			return;
		}
		if ( ! summary ) {
			summary = doc.createElement( 'div' );
			summary.className = 'pegwork-form__errors';
			summary.tabIndex = -1;
			summary.setAttribute( 'aria-labelledby', `${ base }-errors-title` );
			const heading = doc.createElement( 'h2' );
			heading.className = 'pegwork-form__errors-title';
			heading.id = `${ base }-errors-title`;
			heading.textContent = __( 'There is a problem', 'pegwork' );
			summary.append( heading, doc.createElement( 'ul' ) );
			form.before( summary );
		}
		const list = summary.querySelector( 'ul' ) || doc.createElement( 'ul' );
		list.replaceChildren(
			...listed.map( ( item ) => {
				const li = doc.createElement( 'li' );
				const link = doc.createElement( 'a' );
				link.href = `#${ item.control }`;
				link.textContent = errors.get( item.key );
				li.append( link );
				return li;
			} ),
			...unlinked.map( ( text ) => {
				const li = doc.createElement( 'li' );
				li.textContent = text;
				return li;
			} )
		);
		if ( ! list.parentNode ) {
			summary.append( list );
		}
		setTitle( true );
		if ( focus ) {
			summary.focus();
		}
	};

	/**
	 * Runs the hints for some items and updates their error state.
	 *
	 * @param {?string[]} keys Item keys, or null for every item.
	 * @return {number} How many items have a problem now.
	 */
	const check = ( keys ) => {
		const { values, texts } = read();
		const shown = visible( config.items, values );
		const items = config.items.filter(
			( item ) =>
				fields.has( item.key ) &&
				( ! keys || keys.includes( item.key ) )
		);
		const found = hints( items, values, shown, texts );
		let problems = 0;
		for ( const item of items ) {
			const field = fields.get( item.key );
			let message = found.get( item.key ) ?? null;
			// A server message stays while its value is unchanged (the server knows rules the hints skip).
			const kept = server.get( item.key );
			if (
				message === null &&
				kept &&
				shown[ item.key ] !== false &&
				kept.value === JSON.stringify( rawValue( field ) )
			) {
				message = kept.message;
			}
			setFieldError( field, message );
			problems += message === null ? 0 : 1;
		}
		return problems;
	};

	// Steps.
	const actions = Array.from( form.children ).find( ( child ) =>
		child.classList.contains( 'pegwork-form__actions' )
	);
	const submit = actions?.querySelector( 'button[type="submit"]' );
	const stepElements = Array.from( form.children ).filter(
		( child ) =>
			child.tagName === 'FIELDSET' &&
			child.classList.contains( 'pegwork-form__step' )
	);
	const steps =
		submit &&
		stepElements.length > 1 &&
		config.steps.length === stepElements.length
			? config.steps
			: [];
	const last = steps.length - 1;
	let current = 0;
	let progress = null;
	let back = null;
	let next = null;
	// Whatever the server puts between the last step and the buttons (a Turnstile widget) belongs to
	// the last step.
	const trailing = [];

	const goTo = ( index, focus ) => {
		current = index;
		stepElements.forEach( ( element, i ) => {
			element.hidden = i !== index;
		} );
		trailing.forEach( ( element ) => {
			element.hidden = index !== last;
		} );
		back.hidden = index === 0;
		next.hidden = index === last;
		submit.hidden = index !== last;
		const text = progressText( index, steps.length );
		if ( progress.textContent !== text ) {
			progress.textContent = text;
		}
		if ( focus ) {
			const heading =
				stepElements[ index ].querySelector(
					'.pegwork-form__step-heading'
				) || stepElements[ index ].querySelector( 'legend' );
			heading.tabIndex = -1;
			heading.focus();
		}
	};

	const goNext = () => {
		const problems = check( steps[ current ].items );
		renderSummary( problems > 0 );
		if ( problems === 0 ) {
			goTo( current + 1, true );
		}
	};

	if ( steps.length ) {
		for (
			let element = stepElements[ last ].nextElementSibling;
			element && element !== actions;
			element = element.nextElementSibling
		) {
			trailing.push( element );
		}
		current = firstErrorStep( steps, errors.keys() );

		progress = doc.createElement( 'p' );
		progress.className = 'pegwork-form__progress';
		progress.id = `${ base }-progress`;
		progress.setAttribute( 'aria-live', 'polite' );
		progress.textContent = progressText( current, steps.length );
		stepElements[ 0 ].before( progress );

		back = doc.createElement( 'button' );
		back.type = 'button';
		back.className = 'pegwork-form__back';
		back.textContent = __( 'Back', 'pegwork' );
		back.addEventListener( 'click', () => goTo( current - 1, true ) );

		next = doc.createElement( 'button' );
		next.type = 'button';
		next.className = 'pegwork-form__next wp-element-button';
		next.textContent = __( 'Next', 'pegwork' );
		next.addEventListener( 'click', goNext );

		submit.before( back, next );
		goTo( current, false );
	}

	// Error summary links: switch to the step that holds the control, then focus it.
	root.addEventListener( 'click', ( event ) => {
		const link = event.target.closest?.(
			'.pegwork-form__errors a[href^="#"]'
		);
		if ( ! link || ! root.contains( link ) ) {
			return;
		}
		const target = doc.getElementById(
			decodeURIComponent( link.getAttribute( 'href' ).slice( 1 ) )
		);
		if ( ! target || ! form.contains( target ) ) {
			return;
		}
		event.preventDefault();
		const index = stepElements.findIndex( ( element ) =>
			element.contains( target )
		);
		if ( steps.length && index >= 0 && index !== current ) {
			goTo( index, false );
		}
		( target.closest( '.pegwork-form__field' ) || target ).scrollIntoView( {
			block: 'start',
			behavior: reducedMotion ? 'instant' : 'auto',
		} );
		target.focus( { preventScroll: true } );
	} );

	// Turnstile: the widget writes its token to a hidden field. Until it has, sending would store the entry
	// as spam, so the form waits and says so in a polite live region (without JavaScript the server decides).
	const captcha = form.querySelector( '.pegwork-form__captcha' );
	let captchaStatus = null;
	if ( captcha ) {
		captchaStatus = doc.createElement( 'p' );
		captchaStatus.className = 'pegwork-form__captcha-status';
		captchaStatus.setAttribute( 'role', 'status' );
		captcha.after( captchaStatus );
	}

	// Submit: Enter on an earlier step means Next; on the last step check everything, then "Sending".
	let submitting = false;
	let submitLabel = '';
	form.addEventListener( 'submit', ( event ) => {
		if ( submitting ) {
			event.preventDefault();
			return;
		}
		if ( steps.length && current !== last ) {
			event.preventDefault();
			goNext();
			return;
		}
		const problems = check( null );
		if ( problems > 0 ) {
			event.preventDefault();
			renderSummary( true );
			return;
		}
		if ( captchaStatus ) {
			const token = form.querySelector(
				'input[name="pegwork_form[captcha]"]'
			);
			captchaStatus.textContent = '';
			if ( ! token || ! token.value ) {
				event.preventDefault();
				captchaStatus.textContent = __(
					'The spam check is still running. Wait a moment, then send the form again.',
					'pegwork'
				);
				return;
			}
		}
		if ( submit ) {
			submitting = true;
			submitLabel = submit.textContent;
			form.setAttribute( 'aria-busy', 'true' );
			// aria-disabled, not disabled: the button keeps focus while the page loads.
			submit.setAttribute( 'aria-disabled', 'true' );
			submit.textContent = __( 'Sending', 'pegwork' );
		}
	} );

	// Back-forward cache: a restored page is not sending any more.
	doc.defaultView?.addEventListener( 'pageshow', ( event ) => {
		if ( event.persisted && submitting ) {
			submitting = false;
			form.removeAttribute( 'aria-busy' );
			submit.removeAttribute( 'aria-disabled' );
			submit.textContent = submitLabel;
		}
	} );
}

/**
 * Enhances every Pegwork form on the page.
 */
function init() {
	document
		.querySelectorAll( 'form.pegwork-form__form[data-pegwork-config]' )
		.forEach( enhance );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
