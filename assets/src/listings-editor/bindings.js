/**
 * Editor side of the pegwork/field block bindings source.
 */

/**
 * Internal dependencies
 */
import { findField } from './config';
import { fieldValue, formatValue, isShown } from './format';

export const SOURCE_NAME = 'pegwork/field';

/**
 * The entity record for block context, or null outside a record (e.g. a template).
 *
 * @param {(store: string) => Object} select  Data registry select.
 * @param {Object}                    context Block context.
 * @return {?Object} Edited entity record.
 */
function contextRecord( select, context ) {
	if ( ! context?.postType || ! context?.postId ) {
		return null;
	}
	return (
		select( 'core' ).getEditedEntityRecord(
			'postType',
			context.postType,
			context.postId
		) ?? null
	);
}

/**
 * Preview value of one binding.
 *
 * @param {Object}  config    Parsed config.
 * @param {Object}  deps      dateI18n.
 * @param {?Object} record    Edited entity record, or null.
 * @param {Object}  context   Block context.
 * @param {Object}  args      Binding args.
 * @param {string}  attribute Bound attribute.
 * @return {?string} Value; the field label when there is no record; null for a URL that cannot be shown.
 */
export function previewValue( config, deps, record, context, args, attribute ) {
	const type = config.types[ context?.postType ] ?? null;
	const field = findField( type, args?.key );
	if ( ! field ) {
		return attribute === 'url' ? null : '';
	}
	if ( ! record ) {
		return attribute === 'url' ? null : escapeLabel( field.label );
	}
	const value = isShown( record, field, type )
		? fieldValue( record, field )
		: null;
	const output = formatValue( field, value, args, attribute, {
		dateI18n: deps.dateI18n,
		dateFormat: config.dateFormat,
	} );
	if ( output === null ) {
		return attribute === 'url' ? null : '';
	}
	return output;
}

const escapeLabel = ( label ) =>
	String( label ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' );

/**
 * Fields offered by the editor's bindings UI (WordPress 6.9+; 6.7 and 6.8 ignore
 * getFieldsList for every source except core/post-meta).
 *
 * @param {Object} config  Parsed config.
 * @param {Object} context Block context.
 * @return {Array<{label: string, args: Object, type: string}>} Public fields of the context type.
 */
export function fieldsList( config, context ) {
	const type = config.types[ context?.postType ] ?? null;

	return ( type?.fields ?? [] ).map( ( field ) => ( {
		label: field.label,
		args: { key: field.key },
		type: 'string',
	} ) );
}

/**
 * Builds the source registered with registerBlockBindingsSource.
 *
 * @param {Object}                                               config        Parsed config.
 * @param {Object}                                               deps          Injected helpers.
 * @param {(format: string, date: string, tz: string) => string} deps.dateI18n `@wordpress/date` dateI18n.
 * @param {string}                                               deps.label    Translated source label.
 * @return {Object} Source definition. Read-only: values are edited in the Pegwork panel.
 */
export function createSource( config, deps ) {
	return {
		name: SOURCE_NAME,
		label: deps.label,
		usesContext: [ 'postId', 'postType' ],
		getValues( { select, context, bindings } ) {
			const record = contextRecord( select, context );
			const values = {};
			for ( const [ attribute, binding ] of Object.entries(
				bindings ?? {}
			) ) {
				values[ attribute ] = previewValue(
					config,
					deps,
					record,
					context,
					binding?.args ?? {},
					attribute
				);
			}
			return values;
		},
		canUserEditValue() {
			return false;
		},
		getFieldsList( { context } ) {
			return fieldsList( config, context );
		},
	};
}
