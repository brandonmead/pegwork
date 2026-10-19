/**
 * Shared sidebar controls: error messages, a `{field, equals}` condition picker, a choices list.
 */

/**
 * WordPress dependencies
 */
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { conditionValues, slugify, toNumber } from './logic';

/**
 * Error messages next to what they concern. Not a live region: core's save notice already
 * announces the failure with every message.
 *
 * @param {Object}   props          Props.
 * @param {string[]} props.messages Messages.
 * @param {string}   [props.id]     Element ID (for aria-describedby).
 */
export function Errors( { messages, id } ) {
	if ( ! messages || messages.length === 0 ) {
		return null;
	}
	return (
		<div className="pegwork-builder__errors" id={ id }>
			{ messages.map( ( message, index ) => (
				<p key={ index } className="pegwork-builder__error">
					<span className="screen-reader-text">
						{ __( 'Error:', 'pegwork' ) }{ ' ' }
					</span>
					{ message }
				</p>
			) ) }
		</div>
	);
}

/**
 * A `{field, equals}` condition: pick another item of the form, then the value it must have.
 *
 * @param {Object}   props            Props.
 * @param {string}   props.label      Control label ("Show only when").
 * @param {?Object}  props.value      Condition, or undefined.
 * @param {Object[]} props.items      Other items of the form (resolved).
 * @param {Function} props.onChange   Called with the new condition, or undefined to remove it.
 * @param {boolean}  [props.disabled] Read-only.
 */
export function ConditionControl( {
	label,
	value,
	items,
	onChange,
	disabled,
} ) {
	const other = items.find( ( item ) => item.key === value?.field ) || null;
	const picker = conditionValues( other );
	const fieldOptions = [
		{ value: '', label: __( 'Always', 'pegwork' ) },
		...items.map( ( item ) => ( {
			value: item.key,
			label: item.label || item.key,
		} ) ),
	];
	if ( value?.field && ! other ) {
		fieldOptions.push( {
			value: value.field,
			label: sprintf(
				/* translators: %s: field key. */
				__( '%s (not in this form)', 'pegwork' ),
				value.field
			),
		} );
	}

	const setField = ( field ) => {
		if ( ! field ) {
			onChange( undefined );
			return;
		}
		const next = conditionValues(
			items.find( ( item ) => item.key === field )
		);
		let equals = '';
		if ( next.kind === 'boolean' ) {
			equals = true;
		} else if ( next.kind === 'options' && next.options.length ) {
			equals = next.options[ 0 ].value;
		}
		onChange( { field, equals } );
	};

	let valueControl = null;
	if ( value?.field ) {
		if ( picker.kind === 'boolean' ) {
			valueControl = (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Is', 'pegwork' ) }
					value={ value.equals === false ? 'false' : 'true' }
					options={ [
						{ value: 'true', label: __( 'Checked', 'pegwork' ) },
						{
							value: 'false',
							label: __( 'Not checked', 'pegwork' ),
						},
					] }
					onChange={ ( next ) =>
						onChange( { ...value, equals: next === 'true' } )
					}
					disabled={ disabled }
				/>
			);
		} else if ( picker.kind === 'options' ) {
			valueControl = (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Is', 'pegwork' ) }
					value={ String( value.equals ?? '' ) }
					options={ picker.options.map( ( option ) => ( {
						value: String( option.value ),
						label: option.label,
					} ) ) }
					onChange={ ( next ) =>
						onChange( { ...value, equals: next } )
					}
					disabled={ disabled }
				/>
			);
		} else {
			valueControl = (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Is', 'pegwork' ) }
					type={ picker.kind === 'number' ? 'number' : 'text' }
					value={ String( value.equals ?? '' ) }
					onChange={ ( next ) =>
						onChange( {
							...value,
							equals:
								picker.kind === 'number'
									? ( toNumber( next ) ?? '' )
									: next,
						} )
					}
					disabled={ disabled }
				/>
			);
		}
	}

	return (
		<div className="pegwork-builder__condition">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value={ value?.field || '' }
				options={ fieldOptions }
				onChange={ setField }
				disabled={ disabled }
			/>
			{ valueControl }
		</div>
	);
}

/**
 * Choices of a dropdown field: value and label per row, add and remove.
 *
 * @param {Object}   props            Props.
 * @param {Array}    props.value      Options (`{value, label}`).
 * @param {Function} props.onChange   Called with the new list (undefined when empty).
 * @param {boolean}  [props.disabled] Read-only.
 */
export function ChoicesControl( { value, onChange, disabled } ) {
	const instance = useInstanceId( ChoicesControl, 'pegwork-choices' );
	const options = Array.isArray( value ) ? value : [];
	const update = ( index, patch ) =>
		onChange(
			options.map( ( option, position ) =>
				position === index ? { ...option, ...patch } : option
			)
		);
	const remove = ( index ) => {
		const next = options.filter(
			( option, position ) => position !== index
		);
		onChange( next.length ? next : undefined );
	};

	return (
		<fieldset
			className="pegwork-builder__fieldset"
			aria-describedby={ `${ instance }-help` }
		>
			<legend>{ __( 'Choices', 'pegwork' ) }</legend>
			<p className="pegwork-builder__help" id={ `${ instance }-help` }>
				{ __(
					'The value is stored; the label is shown. A new value follows its label until you edit it.',
					'pegwork'
				) }
			</p>
			<ol className="pegwork-builder__choices">
				{ options.map( ( option, index ) => (
					<li key={ index } className="pegwork-builder__choice">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ sprintf(
								/* translators: %d: choice number. */
								__( 'Choice %d label', 'pegwork' ),
								index + 1
							) }
							value={ option.label ?? '' }
							onChange={ ( label ) =>
								update( index, {
									label,
									value:
										! option.value ||
										option.value === slugify( option.label )
											? slugify( label )
											: option.value,
								} )
							}
							disabled={ disabled }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ sprintf(
								/* translators: %d: choice number. */
								__( 'Choice %d value', 'pegwork' ),
								index + 1
							) }
							value={ option.value ?? '' }
							onChange={ ( next ) =>
								update( index, { value: next } )
							}
							disabled={ disabled }
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () => remove( index ) }
							disabled={ disabled }
						>
							{ sprintf(
								/* translators: %d: choice number. */
								__( 'Remove choice %d', 'pegwork' ),
								index + 1
							) }
						</Button>
					</li>
				) ) }
			</ol>
			<Button
				variant="secondary"
				onClick={ () =>
					onChange( [ ...options, { value: '', label: '' } ] )
				}
				disabled={ disabled }
			>
				{ __( 'Add choice', 'pegwork' ) }
			</Button>
		</fieldset>
	);
}
