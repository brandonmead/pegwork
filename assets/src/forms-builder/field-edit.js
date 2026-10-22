/**
 * Edit UI of pegwork/form-field: inline label over a disabled preview control, sidebar settings.
 */

/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	PlainText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import config from './config';
import { ChoicesControl, ConditionControl, Errors } from './controls';
import {
	errorsAt,
	formItems,
	keyForLabel,
	parseExtensions,
	resolveItem,
	ruleParts,
	toInt,
	toNumber,
	withValue,
} from './logic';
import { typeLabel } from './labels';
import { usePointer, useSaveErrors, useBlocks, useTargetType } from './store';

const TEXT_TYPES = [ 'text', 'textarea', 'rich_text', 'url', 'email' ];

/**
 * Wording of a bound item's registry rules ("required, 50 to 300 words").
 *
 * @param {Object}   item  Resolved bound item.
 * @param {Object[]} items Other items of the type (for condition labels).
 * @return {string} Summary.
 */
function rulesText( item, items ) {
	const labelOf = ( key ) =>
		items.find( ( other ) => other.key === key )?.label || key;
	const range = ( min, max, unit ) => {
		if ( min !== null && max !== null ) {
			return sprintf(
				/* translators: 1: minimum, 2: maximum, 3: unit such as "words". */
				__( '%1$s to %2$s %3$s', 'pegwork' ),
				min,
				max,
				unit
			);
		}
		// Translator comments sit right before each __() so they survive minification.
		if ( max !== null ) {
			return sprintf(
				/* translators: 1: maximum, 2: unit such as "words". */
				__( 'up to %1$s %2$s', 'pegwork' ),
				max,
				unit
			);
		}
		return sprintf(
			/* translators: 1: minimum, 2: unit such as "words". */
			__( 'at least %1$s %2$s', 'pegwork' ),
			min,
			unit
		);
	};
	const words = ruleParts( item ).map( ( part ) => {
		switch ( part.rule ) {
			case 'required':
				return __( 'required', 'pegwork' );
			case 'required_when':
				return sprintf(
					/* translators: %s: label of another field. */
					__( 'required when "%s" is set', 'pegwork' ),
					labelOf( part.field )
				);
			case 'show_when':
				return sprintf(
					/* translators: %s: label of another field. */
					__( 'shown when "%s" is set', 'pegwork' ),
					labelOf( part.field )
				);
			case 'length':
				return range(
					part.min,
					part.max,
					__( 'characters', 'pegwork' )
				);
			case 'words':
				return range( part.min, part.max, __( 'words', 'pegwork' ) );
			case 'range':
				if ( part.min !== null && part.max !== null ) {
					return sprintf(
						/* translators: 1: minimum, 2: maximum. */
						__( 'from %1$s to %2$s', 'pegwork' ),
						part.min,
						part.max
					);
				}
				if ( part.max !== null ) {
					return sprintf(
						/* translators: %s: maximum. */
						__( 'at most %s', 'pegwork' ),
						part.max
					);
				}
				return sprintf(
					/* translators: %s: minimum. */
					__( 'at least %s', 'pegwork' ),
					part.min
				);
			case 'pattern':
				return __( 'must match a pattern', 'pegwork' );
			case 'terms':
				return part.multiple
					? __( 'any number of terms', 'pegwork' )
					: __( 'one term', 'pegwork' );
			case 'private':
				return __( 'private', 'pegwork' );
			default:
				return '';
		}
	} );
	return [ typeLabel( item.type ), ...words ].filter( Boolean ).join( ', ' );
}

/**
 * The disabled control a visitor will see (presentation only; hidden from assistive technology).
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Resolved item.
 */
function Preview( { item } ) {
	const common = { disabled: true, tabIndex: -1 };
	let control;
	const options = item.options || [];
	if ( item.type === 'select' ) {
		const display =
			item.display || ( item.multiple ? 'checkboxes' : 'select' );
		if ( display === 'radio' || display === 'checkboxes' ) {
			control = (
				<ul className="pegwork-builder-field__choices">
					{ options.slice( 0, 6 ).map( ( option ) => (
						<li key={ option.value }>
							<input
								type={
									display === 'radio' ? 'radio' : 'checkbox'
								}
								{ ...common }
							/>{ ' ' }
							{ option.label }
						</li>
					) ) }
					{ options.length === 0 && (
						<li>{ __( 'No choices yet', 'pegwork' ) }</li>
					) }
					{ options.length > 6 && <li>&hellip;</li> }
				</ul>
			);
		} else {
			control = (
				<select { ...common } multiple={ item.multiple }>
					<option>{ __( 'Choose…', 'pegwork' ) }</option>
				</select>
			);
		}
	} else if ( item.type === 'textarea' || item.type === 'rich_text' ) {
		control = (
			<textarea
				rows={ 3 }
				placeholder={ item.placeholder }
				{ ...common }
			/>
		);
	} else if ( item.type === 'boolean' ) {
		control = <input type="checkbox" { ...common } />;
	} else {
		const types = {
			email: 'email',
			url: 'url',
			number: 'number',
			date: 'date',
			file: 'file',
		};
		control = (
			<input
				type={ types[ item.type ] || 'text' }
				placeholder={ item.placeholder }
				{ ...common }
			/>
		);
	}
	return (
		<div className="pegwork-builder-field__preview" aria-hidden="true">
			{ control }
		</div>
	);
}

/**
 * Number inputs for a pair of `validate` rules.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.validate Current rules.
 * @param {Array}    props.rules    `[name, label, integer]` per input.
 * @param {Function} props.onChange Called with the new rules (undefined when empty).
 */
function RuleInputs( { validate, rules, onChange } ) {
	return rules.map( ( [ name, label, integer ] ) => (
		<TextControl
			key={ name }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			type="number"
			min={ integer ? 0 : undefined }
			value={ validate?.[ name ] ?? '' }
			onChange={ ( value ) => {
				const next = withValue(
					validate,
					name,
					integer ? toInt( value ) : toNumber( value )
				);
				onChange( Object.keys( next ).length ? next : undefined );
			} }
		/>
	) );
}

/**
 * Sidebar for an unbound item.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Setter.
 * @param {Object[]} props.others        Other items of the form.
 */
function UnboundSettings( { attributes, setAttributes, others } ) {
	const type = attributes.type || 'text';
	const set = ( name ) => ( value ) =>
		setAttributes( { [ name ]: value === '' ? undefined : value } );
	const validate = attributes.validate;
	const setValidate = ( next ) => setAttributes( { validate: next } );

	return (
		<>
			<PanelBody title={ __( 'Field settings', 'pegwork' ) }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Key', 'pegwork' ) }
					help={ __(
						'Names the value in entries and merge tags such as {field:key}. It follows the label until you change it.',
						'pegwork'
					) }
					value={ attributes.key ?? '' }
					onChange={ set( 'key' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={
						type === 'boolean'
							? __( 'Must be checked', 'pegwork' )
							: __( 'Required', 'pegwork' )
					}
					checked={ attributes.required === true }
					onChange={ ( required ) => setAttributes( { required } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Help text', 'pegwork' ) }
					value={ attributes.help ?? '' }
					onChange={ set( 'help' ) }
				/>
				{ TEXT_TYPES.includes( type ) || type === 'number' ? (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Placeholder', 'pegwork' ) }
						value={ attributes.placeholder ?? '' }
						onChange={ set( 'placeholder' ) }
					/>
				) : null }
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Autocomplete', 'pegwork' ) }
					help={ __(
						'An autocomplete token such as name, email, organization or tel, so browsers can fill it in.',
						'pegwork'
					) }
					value={ attributes.autocomplete ?? '' }
					onChange={ set( 'autocomplete' ) }
				/>
			</PanelBody>
			{ type === 'select' && (
				<PanelBody title={ __( 'Choices', 'pegwork' ) }>
					<ChoicesControl
						value={ attributes.options }
						onChange={ ( options ) => setAttributes( { options } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show as', 'pegwork' ) }
						value={ attributes.display ?? '' }
						options={ [
							{ value: '', label: __( 'Dropdown', 'pegwork' ) },
							{
								value: 'radio',
								label: __( 'Radio buttons', 'pegwork' ),
							},
						] }
						onChange={ set( 'display' ) }
					/>
				</PanelBody>
			) }
			{ ( TEXT_TYPES.includes( type ) || type === 'number' ) && (
				<PanelBody
					title={ __( 'Validation', 'pegwork' ) }
					initialOpen={ false }
				>
					{ type === 'number' ? (
						<RuleInputs
							validate={ validate }
							onChange={ setValidate }
							rules={ [
								[ 'min', __( 'Minimum', 'pegwork' ), false ],
								[ 'max', __( 'Maximum', 'pegwork' ), false ],
							] }
						/>
					) : (
						<>
							<RuleInputs
								validate={ validate }
								onChange={ setValidate }
								rules={ [
									[
										'min_length',
										__( 'Minimum characters', 'pegwork' ),
										true,
									],
									[
										'max_length',
										__( 'Maximum characters', 'pegwork' ),
										true,
									],
									[
										'min_words',
										__( 'Minimum words', 'pegwork' ),
										true,
									],
									[
										'max_words',
										__( 'Maximum words', 'pegwork' ),
										true,
									],
								] }
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Pattern', 'pegwork' ) }
								help={ __(
									'A regular expression the whole value must match.',
									'pegwork'
								) }
								value={ validate?.pattern ?? '' }
								onChange={ ( pattern ) => {
									const next = withValue(
										validate,
										'pattern',
										pattern
									);
									setValidate(
										Object.keys( next ).length
											? next
											: undefined
									);
								} }
							/>
						</>
					) }
				</PanelBody>
			) }
			{ type === 'file' && (
				<PanelBody title={ __( 'Uploads', 'pegwork' ) }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Allowed file types', 'pegwork' ) }
						help={ __(
							'Extensions separated by commas, such as pdf, docx, png. Scripts and web pages are never allowed.',
							'pegwork'
						) }
						value={ ( attributes.accept || [] ).join( ', ' ) }
						onChange={ ( value ) => {
							const accept = parseExtensions( value );
							setAttributes( {
								accept: accept.length ? accept : undefined,
							} );
						} }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Largest file (MB)', 'pegwork' ) }
						type="number"
						min={ 0 }
						value={ attributes.max_size_mb ?? '' }
						onChange={ ( value ) =>
							setAttributes( { max_size_mb: toNumber( value ) } )
						}
					/>
				</PanelBody>
			) }
			<PanelBody
				title={ __( 'Conditions', 'pegwork' ) }
				initialOpen={ false }
			>
				<ConditionControl
					label={ __( 'Show only when', 'pegwork' ) }
					value={ attributes.show_when }
					items={ others }
					onChange={ ( condition ) =>
						setAttributes( { show_when: condition } )
					}
				/>
				<ConditionControl
					label={ __( 'Required when', 'pegwork' ) }
					value={ attributes.required_when }
					items={ others }
					onChange={ ( condition ) =>
						setAttributes( { required_when: condition } )
					}
				/>
			</PanelBody>
		</>
	);
}

/**
 * Sidebar for a bound item: the registry rules, read-only, then the presentational overrides.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Setter.
 * @param {?Object}  props.item          Resolved item, or null when it does not resolve.
 * @param {?Object}  props.type          Target type.
 * @param {string}   props.summary       Rules wording.
 */
function BoundSettings( { attributes, setAttributes, item, type, summary } ) {
	const set = ( name ) => ( value ) =>
		setAttributes( { [ name ]: value === '' ? undefined : value } );
	let displays = null;
	if ( item && item.type === 'select' ) {
		displays = item.multiple
			? [
					{ value: '', label: __( 'Checkboxes', 'pegwork' ) },
					{
						value: 'select',
						label: __( 'Multiple select', 'pegwork' ),
					},
				]
			: [
					{ value: '', label: __( 'Dropdown', 'pegwork' ) },
					{ value: 'radio', label: __( 'Radio buttons', 'pegwork' ) },
				];
	}
	const displayValue =
		item?.multiple && attributes.display === 'checkboxes'
			? ''
			: ( attributes.display ?? '' );

	return (
		<PanelBody title={ __( 'Field settings', 'pegwork' ) }>
			{ item && type ? (
				<p className="pegwork-builder__rules">
					{ sprintf(
						/* translators: 1: content type label, 2: rules such as "Text, required, up to 200 characters". */
						__(
							'From %1$s: %2$s. Change these rules in the content type.',
							'pegwork'
						),
						type.label,
						summary
					) }
				</p>
			) : null }
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Help text', 'pegwork' ) }
				value={ attributes.help ?? '' }
				onChange={ set( 'help' ) }
			/>
			{ item && item.type !== 'select' && item.type !== 'boolean' && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Placeholder', 'pegwork' ) }
					value={ attributes.placeholder ?? '' }
					onChange={ set( 'placeholder' ) }
				/>
			) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Autocomplete', 'pegwork' ) }
				help={ __(
					'An autocomplete token such as name, email, organization or tel, so browsers can fill it in.',
					'pegwork'
				) }
				value={ attributes.autocomplete ?? '' }
				onChange={ set( 'autocomplete' ) }
			/>
			{ displays && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show as', 'pegwork' ) }
					value={ displayValue }
					options={ displays }
					onChange={ set( 'display' ) }
				/>
			) }
		</PanelBody>
	);
}

/**
 * Block edit component.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes (the form item).
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.clientId      Block client ID.
 */
export default function FieldEdit( { attributes, setAttributes, clientId } ) {
	const type = useTargetType( config );
	const blocks = useBlocks();
	const pointer = usePointer( clientId );
	const errors = errorsAt( useSaveErrors(), pointer );
	const errorId = useInstanceId( FieldEdit, 'pegwork-field-errors' );
	const item = resolveItem( attributes, type );
	const bound = Boolean( attributes.bind );
	const others = formItems( blocks, type ).filter(
		( other ) => other.clientId !== clientId
	);
	const summary = item && bound ? rulesText( item, type?.fields || [] ) : '';
	const required = item?.required === true;

	const blockProps = useBlockProps( {
		className: [
			'pegwork-builder-field',
			bound ? 'is-bound' : '',
			errors.length ? 'has-error' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
	} );

	const setLabel = ( raw ) => {
		const label = raw.replace( /[\r\n]+/g, ' ' );
		if ( bound ) {
			setAttributes( { label: label === '' ? undefined : label } );
			return;
		}
		const key = keyForLabel( attributes.key, attributes.label, label );
		setAttributes( {
			label: label === '' ? undefined : label,
			key: key === '' ? undefined : key,
		} );
	};

	let badge;
	if ( bound ) {
		const name =
			item?.registryLabel ||
			attributes.bind.field ||
			attributes.bind.taxonomy;
		badge = type
			? /* translators: 1: content type label, 2: field label. */ sprintf(
					__( '%1$s: %2$s', 'pegwork' ),
					type.label,
					name
				)
			: name;
	} else {
		badge = typeLabel( attributes.type || 'text' );
	}

	const settings = bound ? (
		<BoundSettings
			attributes={ attributes }
			setAttributes={ setAttributes }
			item={ item }
			type={ type }
			summary={ summary }
		/>
	) : (
		<UnboundSettings
			attributes={ attributes }
			setAttributes={ setAttributes }
			others={ others }
		/>
	);

	return (
		<div { ...blockProps }>
			<div className="pegwork-builder-field__head">
				{ config.readOnly ? (
					<span className="pegwork-builder-field__label">
						{ item?.label || attributes.label }
					</span>
				) : (
					<PlainText
						className="pegwork-builder-field__label"
						value={ attributes.label ?? '' }
						placeholder={
							bound && item
								? item.registryLabel
								: __( 'Field label', 'pegwork' )
						}
						onChange={ setLabel }
						aria-label={ __( 'Field label', 'pegwork' ) }
						aria-describedby={ errors.length ? errorId : undefined }
					/>
				) }
				{ required && (
					<span className="pegwork-builder-field__required">
						{ __( '(required)', 'pegwork' ) }
					</span>
				) }
				<span className="pegwork-builder-field__badge">{ badge }</span>
			</div>
			{ item ? <Preview item={ item } /> : null }
			{ ( attributes.help || item?.help ) && (
				<p className="pegwork-builder-field__help">
					{ attributes.help || item.help }
				</p>
			) }
			{ bound && ! item && (
				<p className="pegwork-builder__error">
					{ type
						? __(
								'This field is not on the target content type. Remove it or change the target.',
								'pegwork'
							)
						: __(
								'Bound fields need a target content type (Form settings).',
								'pegwork'
							) }
				</p>
			) }
			<Errors messages={ errors } id={ errorId } />
			<InspectorControls>
				<Errors messages={ errors } />
				{ config.readOnly ? (
					<Disabled>{ settings }</Disabled>
				) : (
					settings
				) }
			</InspectorControls>
		</div>
	);
}
