/**
 * Editor preview of pegwork/field values. Mirrors PegworkListings\Formatter
 * (the server output is authoritative; this only previews it).
 */

export const URL_PROTOCOLS = [ 'http:', 'https:', 'mailto:' ];

/**
 * Escapes text for use as rich text HTML.
 *
 * @param {string} text Plain text.
 * @return {string} Escaped HTML.
 */
export function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

const raw = ( value, record ) => {
	if ( typeof value === 'function' ) {
		return value( record );
	}
	if ( value !== null && typeof value === 'object' ) {
		return value.raw ?? value.rendered ?? '';
	}
	return value;
};

/**
 * Typed value of a field in an entity record, like pegwork_get_field().
 *
 * @param {?Object} record Edited entity record.
 * @param {Object}  field  Parsed field config.
 * @return {?(boolean|number|string)} Boolean, number or null, or string.
 */
export function fieldValue( record, field ) {
	if ( ! record ) {
		return null;
	}
	let value;
	if ( field.storage === 'post_title' ) {
		value = raw( record.title, record );
	} else if ( field.storage === 'post_content' ) {
		value = raw( record.content, record );
	} else {
		value = record.meta?.[ field.metaKey ];
	}

	if ( field.type === 'boolean' ) {
		return value === true || value === 1 || value === '1';
	}
	if ( field.type === 'number' ) {
		return value === '' || value === null || value === undefined
			? null
			: Number( value );
	}
	return value === null || value === undefined ? '' : String( value );
}

/**
 * Whether a field's show_when holds in a record.
 *
 * @param {?Object} record Edited entity record.
 * @param {Object}  field  Parsed field config.
 * @param {Object}  type   Parsed type config.
 * @return {boolean} True when shown.
 */
export function isShown( record, field, type ) {
	if ( ! field.showWhen ) {
		return true;
	}
	const controller = type.fields.find(
		( candidate ) => candidate.key === field.showWhen.field
	);
	return (
		!! controller &&
		fieldValue( record, controller ) === field.showWhen.equals
	);
}

const textArg = ( args, name ) =>
	typeof args?.[ name ] === 'string' && args[ name ] !== ''
		? escapeHtml( args[ name ] )
		: null;

function safeUrl( field, value ) {
	if ( typeof value !== 'string' || value === '' ) {
		return null;
	}
	if ( field.type === 'email' ) {
		return `mailto:${ value }`;
	}
	if ( field.type !== 'url' ) {
		return null;
	}
	try {
		const url = new URL( value.trim() );
		return URL_PROTOCOLS.includes( url.protocol ) ? value.trim() : null;
	} catch {
		return null;
	}
}

/**
 * Formats a typed value for one block attribute.
 *
 * @param {Object}                                               field           Parsed field config.
 * @param {?(boolean|number|string)}                             value           Typed value.
 * @param {Object}                                               args            Binding args (format, prefix, label).
 * @param {string}                                               attribute       Bound attribute.
 * @param {Object}                                               deps            Injected helpers.
 * @param {(format: string, date: string, tz: string) => string} deps.dateI18n   `@wordpress/date` dateI18n.
 * @param {string}                                               deps.dateFormat Site date format.
 * @return {?string} HTML for text attributes, a URL for `url`, or null when empty or refused.
 */
export function formatValue( field, value, args, attribute, deps ) {
	if ( value === null || value === false || value === '' ) {
		return null;
	}
	if ( attribute === 'url' ) {
		return safeUrl( field, value );
	}

	let text = null;
	switch ( field.type ) {
		case 'boolean':
			if ( value === true ) {
				text = textArg( args, 'label' ) ?? escapeHtml( field.label );
			}
			break;
		case 'date':
			if (
				typeof value === 'string' &&
				/^\d{4}-\d{2}-\d{2}$/.test( value )
			) {
				const format =
					typeof args?.format === 'string' && args.format !== ''
						? args.format
						: deps.dateFormat;
				text = escapeHtml(
					deps.dateI18n( format, `${ value }T00:00:00Z`, '+00:00' )
				);
			}
			break;
		case 'select': {
			const option = field.options.find(
				( candidate ) => candidate.value === String( value )
			);
			text = option ? escapeHtml( option.label ) : null;
			break;
		}
		case 'rich_text':
			text = String( value );
			break;
		default:
			text = escapeHtml( value );
	}

	if ( text === null || text.trim() === '' ) {
		return null;
	}
	return ( textArg( args, 'prefix' ) ?? '' ) + text;
}
