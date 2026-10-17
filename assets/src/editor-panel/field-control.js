/**
 * WordPress dependencies
 */
import {
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { controlFor, fromControlValue, toControlValue } from './controls';
import { isEmpty } from './logic';

const COMPONENTS = {
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
};

/**
 * Field label with "Required" and "Private" badges.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.field    The field.
 * @param {boolean} props.required Whether the field is required now.
 */
export function FieldLabel( { field, required } ) {
	return (
		<span className="pegwork-editor-panel__label">
			{ field.label }
			{ required && (
				<span className="pegwork-editor-panel__badge is-required">
					{ __( 'Required', 'pegwork' ) }
				</span>
			) }
			{ field.visibility === 'private' && (
				<span className="pegwork-editor-panel__badge is-private">
					{ __( 'Private', 'pegwork' ) }
				</span>
			) }
		</span>
	);
}

/**
 * Server validation message for a field.
 *
 * @param {Object} props         Props.
 * @param {string} props.message Message from `pegwork_invalid_record` `data.fields`.
 */
export function FieldError( { message } ) {
	return (
		<span className="pegwork-editor-panel__error" role="alert">
			{ message }
		</span>
	);
}

/**
 * Inline help for a field: required hint and private note.
 *
 * @param {Object}   field    The field.
 * @param {boolean}  required Whether the field is required now.
 * @param {unknown}  value    Current value.
 * @param {Object[]} fields   All fields of the type.
 * @return {string|undefined} Help text.
 */
function helpText( field, required, value, fields ) {
	const parts = [];

	if ( required && isEmpty( value ) ) {
		if ( field.required ) {
			parts.push( __( 'Required to publish.', 'pegwork' ) );
		} else {
			const source = fields.find(
				( candidate ) => candidate.key === field.required_when.field
			);
			parts.push(
				sprintf(
					/* translators: %s: label of the field this one depends on. */
					__( 'Required to publish because of "%s".', 'pegwork' ),
					source ? source.label : field.required_when.field
				)
			);
		}
	}

	if ( field.visibility === 'private' ) {
		parts.push( __( 'Not shown on the public site.', 'pegwork' ) );
	}

	return parts.length ? parts.join( ' ' ) : undefined;
}

/**
 * One meta-stored field.
 *
 * @param {Object}                   props          Props.
 * @param {Object}                   props.field    The field.
 * @param {Object[]}                 props.fields   All fields of the type.
 * @param {unknown}                  props.value    Current value (or default).
 * @param {boolean}                  props.required Whether the field is required now.
 * @param {(value: unknown) => void} props.onChange Receives the new meta value.
 * @param {string}                   [props.error]  Server validation message from the last save.
 */
export default function FieldControl( {
	field,
	fields,
	value,
	required,
	onChange,
	error,
} ) {
	const { control, inputType } = controlFor( field.type );
	const Control = COMPONENTS[ control ];
	const props = {
		label: <FieldLabel field={ field } required={ required } />,
		help: error ? (
			<FieldError message={ error } />
		) : (
			helpText( field, required, value, fields )
		),
		onChange: ( input ) =>
			onChange( fromControlValue( field.type, input ) ),
		__nextHasNoMarginBottom: true,
	};

	if ( control === 'ToggleControl' ) {
		return (
			<Control
				{ ...props }
				checked={ toControlValue( field.type, value ) }
			/>
		);
	}

	if ( control === 'SelectControl' ) {
		return (
			<Control
				{ ...props }
				__next40pxDefaultSize
				value={ toControlValue( field.type, value ) }
				options={ [
					{ value: '', label: __( 'Choose an option', 'pegwork' ) },
					...field.options,
				] }
			/>
		);
	}

	if ( control === 'TextControl' ) {
		return (
			<Control
				{ ...props }
				__next40pxDefaultSize
				type={ inputType }
				value={ toControlValue( field.type, value ) }
			/>
		);
	}

	return (
		<Control { ...props } value={ toControlValue( field.type, value ) } />
	);
}
