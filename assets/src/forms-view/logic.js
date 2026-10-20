/**
 * Pure logic for the forms view script: config parsing, value casting, hint checks, step helpers and
 * messages. No DOM access, so it runs in unit tests as is.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { countWords } from '../editor-panel/logic';
import { isNumeric } from './conditions';

export { countWords };

const HINT_RULES = [
	'min_words',
	'max_words',
	'min_length',
	'max_length',
	'min',
	'max',
];

/**
 * Reads a condition, or null.
 *
 * @param {unknown} raw Condition from the config.
 * @return {?{field: string, equals: unknown}} Condition.
 */
function condition( raw ) {
	if ( ! raw || typeof raw !== 'object' || typeof raw.field !== 'string' ) {
		return null;
	}
	return { field: raw.field, equals: raw.equals ?? null };
}

/**
 * Parses a form's `data-pegwork-config` attribute (written by PegworkForms\View::config()).
 *
 * @param {unknown} raw JSON text.
 * @return {?{items: Object[], steps: Array<{label: string, items: string[]}>}} Config, or null when unusable.
 */
export function parseConfig( raw ) {
	let data;
	try {
		data = typeof raw === 'string' ? JSON.parse( raw ) : null;
	} catch {
		return null;
	}
	if ( ! data || ! Array.isArray( data.items ) ) {
		return null;
	}
	const items = data.items
		.filter(
			( item ) =>
				item &&
				typeof item.key === 'string' &&
				typeof item.control === 'string'
		)
		.map( ( item ) => {
			const validate = {};
			for ( const rule of HINT_RULES ) {
				if ( Number.isFinite( item.validate?.[ rule ] ) ) {
					validate[ rule ] = item.validate[ rule ];
				}
			}
			return {
				key: item.key,
				type: typeof item.type === 'string' ? item.type : 'text',
				label: typeof item.label === 'string' ? item.label : item.key,
				control: item.control,
				group: item.group === true,
				multiple: item.multiple === true,
				required: item.required === true,
				bound: item.bound === true,
				show_when: condition( item.show_when ),
				required_when: condition( item.required_when ),
				help: typeof item.help === 'string' ? item.help : null,
				validate,
			};
		} );
	const known = new Set( items.map( ( item ) => item.key ) );
	const steps = Array.isArray( data.steps )
		? data.steps
				.filter( ( step ) => step && Array.isArray( step.items ) )
				.map( ( step ) => ( {
					label: typeof step.label === 'string' ? step.label : '',
					items: step.items.filter( ( key ) => known.has( key ) ),
				} ) )
		: [];
	return { items, steps };
}

/**
 * Casts a control's raw value the way the server sanitizes it, as far as conditions and hints need:
 * checkboxes to booleans, numbers to numbers (or '' when not numeric), choices to lists, text trimmed.
 *
 * @param {Object}                  item Config item.
 * @param {string|string[]|boolean} raw  Raw value: a list for multi-choice, a boolean for checkboxes.
 * @return {unknown} Value.
 */
export function castValue( item, raw ) {
	if ( item.multiple ) {
		return Array.isArray( raw ) ? raw : [];
	}
	if ( item.type === 'boolean' ) {
		return raw === true;
	}
	const text = typeof raw === 'string' ? raw : '';
	if ( item.type === 'number' ) {
		return isNumeric( text.trim() ) ? Number( text.trim() ) : '';
	}
	if ( item.type === 'textarea' ) {
		return text;
	}
	return text.trim();
}

/**
 * Whether a value is empty for the required check (PHP RecordValidator::is_empty()).
 *
 * @param {unknown} value Value.
 * @return {boolean} Empty.
 */
function isEmpty( value ) {
	if ( value === undefined || value === null ) {
		return true;
	}
	if ( Array.isArray( value ) ) {
		return value.length === 0;
	}
	return typeof value === 'string' && value.trim() === '';
}

/**
 * Whether an item is required for these values: `required`, or its `required_when` holds.
 *
 * @param {Object}                  item   Config item.
 * @param {Object<string, unknown>} values Cast values by key.
 * @return {boolean} Required.
 */
export function isRequired( item, values ) {
	const when = item.required_when;
	return (
		item.required ||
		( when !== null && ( values[ when.field ] ?? null ) === when.equals )
	);
}

/**
 * Whether text is a web address as the server accepts it (Pegwork\Content\Meta::sanitize_url() and
 * valid_url()): `https://` assumed without a scheme, http or https, no user or password, a host that is
 * an IPv4 address or labels ending in a top-level label of two or more letters. Never stricter than the
 * server: the browser's URL parser also turns IDNs into punycode, which the server accepts too.
 *
 * @param {string} text Text.
 * @return {boolean} Valid.
 */
export function isWebAddress( text ) {
	const value = String( text ).trim();
	const candidate = /^[a-z][a-z0-9+.-]*:/i.test( value )
		? value
		: `https://${ value.replace( /^\/+/, '' ) }`;
	let url;
	try {
		url = new URL( candidate );
	} catch {
		return false;
	}
	if (
		( url.protocol !== 'http:' && url.protocol !== 'https:' ) ||
		url.username !== '' ||
		url.password !== ''
	) {
		return false;
	}
	const host = url.hostname.replace( /\.$/, '' );
	if ( /^[0-9]{1,3}(\.[0-9]{1,3}){3}$/.test( host ) ) {
		return true;
	}
	return /^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/.test(
		host
	);
}

/**
 * Text length in characters (code points, as PHP mb_strlen).
 *
 * @param {string} text Text.
 * @return {number} Length.
 */
function length( text ) {
	return Array.from( text ).length;
}

/**
 * One item's hint: the message the server would give for a rule it surely enforces, or null. Never
 * stricter than the server (dates and patterns are left to it).
 *
 * @param {Object}                  item    Config item.
 * @param {Object<string, unknown>} values  Cast values by key.
 * @param {Object<string, string>}  [texts] Raw text by key (to tell "not a number" from empty).
 * @return {?string} Message.
 */
export function hint( item, values, texts = {} ) {
	const value = values[ item.key ];
	const required = isRequired( item, values );

	if ( item.type === 'boolean' ) {
		// Only unbound items must be checked; a bound boolean's "required" accepts false.
		return required && ! item.bound && value !== true
			? sprintf(
					/* translators: %s: checkbox label. */
					__( 'Check the box to confirm: %s', 'pegwork' ),
					item.label
				)
			: null;
	}
	const rules = item.validate;
	const text = typeof texts[ item.key ] === 'string' ? texts[ item.key ] : '';
	if ( item.type === 'number' && text.trim() !== '' && ! isNumeric( text ) ) {
		return sprintf(
			/* translators: %s: field label. */
			__( '%s must be a number.', 'pegwork' ),
			item.label
		);
	}
	if ( isEmpty( value ) ) {
		return required
			? sprintf(
					/* translators: %s: field label. */
					__( '%s is required.', 'pegwork' ),
					item.label
				)
			: null;
	}
	if ( typeof value === 'number' ) {
		if ( rules.min !== undefined && value < rules.min ) {
			return sprintf(
				/* translators: 1: field label, 2: minimum value. */
				__( '%1$s must be at least %2$s.', 'pegwork' ),
				item.label,
				String( rules.min )
			);
		}
		if ( rules.max !== undefined && value > rules.max ) {
			return sprintf(
				/* translators: 1: field label, 2: maximum value. */
				__( '%1$s must be at most %2$s.', 'pegwork' ),
				item.label,
				String( rules.max )
			);
		}
		return null;
	}
	if ( typeof value !== 'string' ) {
		return null;
	}
	if (
		item.type === 'email' &&
		! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value )
	) {
		return sprintf(
			/* translators: %s: field label. */
			__(
				'Enter %s as an email address, like name@example.com.',
				'pegwork'
			),
			item.label
		);
	}
	if ( item.type === 'url' && ! isWebAddress( value ) ) {
		return sprintf(
			/* translators: %s: field label. */
			__(
				'Enter %s as a web address, like https://example.org.',
				'pegwork'
			),
			item.label
		);
	}
	if (
		rules.min_length !== undefined &&
		length( value ) < rules.min_length
	) {
		return sprintf(
			/* translators: 1: field label, 2: character count. */
			__( '%1$s must be at least %2$d characters.', 'pegwork' ),
			item.label,
			rules.min_length
		);
	}
	if (
		rules.max_length !== undefined &&
		length( value ) > rules.max_length
	) {
		return sprintf(
			/* translators: 1: field label, 2: character count. */
			__( '%1$s must be at most %2$d characters.', 'pegwork' ),
			item.label,
			rules.max_length
		);
	}
	const words = countWords( value );
	if ( rules.min_words !== undefined && words < rules.min_words ) {
		return sprintf(
			/* translators: 1: field label, 2: minimum words, 3: current words. */
			__( '%1$s must be at least %2$d words (it has %3$d).', 'pegwork' ),
			item.label,
			rules.min_words,
			words
		);
	}
	if ( rules.max_words !== undefined && words > rules.max_words ) {
		return sprintf(
			/* translators: 1: field label, 2: maximum words, 3: current words. */
			__( '%1$s must be at most %2$d words (it has %3$d).', 'pegwork' ),
			item.label,
			rules.max_words,
			words
		);
	}
	return null;
}

/**
 * Hints for some items, skipping hidden ones (the server ignores their values).
 *
 * @param {Object[]}                items   Config items to check, in form order.
 * @param {Object<string, unknown>} values  Cast values by key.
 * @param {Object<string, boolean>} visible Shown flags by key.
 * @param {Object<string, string>}  [texts] Raw text by key.
 * @return {Map<string, string>} Message by key, in form order.
 */
export function hints( items, values, visible, texts = {} ) {
	const found = new Map();
	for ( const item of items ) {
		if ( visible[ item.key ] === false ) {
			continue;
		}
		const message = hint( item, values, texts );
		if ( message !== null ) {
			found.set( item.key, message );
		}
	}
	return found;
}

/**
 * Index of the step holding a key, or -1.
 *
 * @param {Array<{items: string[]}>} steps Steps.
 * @param {string}                   key   Item key.
 * @return {number} Step index.
 */
export function stepOf( steps, key ) {
	return steps.findIndex( ( step ) => step.items.includes( key ) );
}

/**
 * The step to open on: the first step holding one of these keys, else the first step.
 *
 * @param {Array<{items: string[]}>} steps Steps.
 * @param {Iterable<string>}         keys  Keys with errors.
 * @return {number} Step index.
 */
export function firstErrorStep( steps, keys ) {
	let first = -1;
	for ( const key of keys ) {
		const index = stepOf( steps, key );
		if ( index >= 0 && ( first < 0 || index < first ) ) {
			first = index;
		}
	}
	return Math.max( first, 0 );
}

/**
 * The progress announcement, such as "Step 2 of 3". The step's label is its heading, which gets focus,
 * so the counter does not repeat it.
 *
 * @param {number} index Step index (0-based).
 * @param {number} total Number of steps.
 * @return {string} Text.
 */
export function progressText( index, total ) {
	return sprintf(
		/* translators: 1: step number, 2: number of steps. */
		__( 'Step %1$d of %2$d', 'pegwork' ),
		index + 1,
		total
	);
}

/**
 * Word limits of an item, or null.
 *
 * @param {Object} item Config item.
 * @return {?{min: ?number, max: ?number}} Limits.
 */
export function wordLimits( item ) {
	const min = item.validate.min_words ?? null;
	const max = item.validate.max_words ?? null;
	return min === null && max === null ? null : { min, max };
}

/**
 * The word counter text (same strings as the editor panel's counter).
 *
 * @param {number}                       count  Word count.
 * @param {{min: ?number, max: ?number}} limits Limits.
 * @return {string} Text.
 */
export function wordCountText( count, limits ) {
	if ( limits.min !== null && limits.max !== null ) {
		return sprintf(
			/* translators: 1: current word count, 2: minimum, 3: maximum. */
			_n(
				'%1$d word (%2$d to %3$d)',
				'%1$d words (%2$d to %3$d)',
				count,
				'pegwork'
			),
			count,
			limits.min,
			limits.max
		);
	}
	if ( limits.min !== null ) {
		return sprintf(
			/* translators: 1: current word count, 2: minimum. */
			_n(
				'%1$d word (at least %2$d)',
				'%1$d words (at least %2$d)',
				count,
				'pegwork'
			),
			count,
			limits.min
		);
	}
	return sprintf(
		/* translators: 1: current word count, 2: maximum. */
		_n(
			'%1$d word (up to %2$d)',
			'%1$d words (up to %2$d)',
			count,
			'pegwork'
		),
		count,
		limits.max
	);
}

/**
 * Calls `fn` once input pauses for `wait` milliseconds.
 *
 * @param {(...args: unknown[]) => void} fn   Function.
 * @param {number}                       wait Milliseconds.
 * @return {(...args: unknown[]) => void} Debounced function.
 */
export function debounce( fn, wait ) {
	let timer = null;
	return ( ...args ) => {
		clearTimeout( timer );
		timer = setTimeout( () => fn( ...args ), wait );
	};
}
