/**
 * Edit UI of pegwork/automation-step: a card (icon, type, id, one-line summary, errors) and the step
 * inspector (fields per type, values rows, merge tags, preview, Advanced with retry and the id).
 */

/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	CheckboxControl,
	Dashicon,
	Disabled,
	PanelBody,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ConditionBuilder from './condition-builder';
import { DurationControl, Errors, TemplateControl, wire } from './controls';
import { stepSummary, typeLabel } from './labels';
import { ON_ERROR_TYPES, assignId, flatten, toNumber } from './logic';
import MoveToBranch from './move';
import { PreviewPanel } from './preview';
import { ReadFields, RelateFields } from './read-edit';
import {
	binding,
	conditionFields,
	mergeTags,
	typeFields,
	typedReference,
} from './scope';
import { config, useDefinition, useErrorOwners } from './store';

export const ICONS = {
	send_email: 'email',
	update_record: 'update',
	create_record: 'plus-alt',
	call_webhook: 'admin-site-alt3',
	wait: 'clock',
	condition: 'filter',
	get_record: 'search',
	find_records: 'list-view',
	relate: 'admin-links',
};

const STATUSES = [ 'draft', 'pending', 'publish', 'private' ];

/**
 * Gives a new or copied step or decision an id no earlier block uses. Runs once per mount (insert,
 * duplicate, paste, move), never in read-only mode, and never renames on typing.
 *
 * @param {string}   clientId      Block.
 * @param {string}   id            Current id.
 * @param {string}   type          Step type (or `decision`).
 * @param {Function} setAttributes Setter.
 */
export function useStepId( clientId, id, type, setAttributes ) {
	const getBlocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks,
		[]
	);
	const { __unstableMarkNextChangeAsNotPersistent: notPersistent } =
		useDispatch( blockEditorStore );
	useEffect( () => {
		if ( config.readOnly ) {
			return;
		}
		const next = assignId( flatten( getBlocks() ), clientId, id, type );
		if ( next ) {
			notPersistent?.();
			setAttributes( { id: next } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Once per mount.
	}, [] );
}

/**
 * Values rows: a field and its value per row, add and remove.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.values   Values (`{ key: value }`).
 * @param {Function} props.onChange Called with the new values (undefined when empty).
 * @param {?Array}   props.fields   Fields of the target type, or null when the type is not known.
 * @param {string}   props.pointer  Pointer of `values`.
 * @param {Object}   props.errors   Errors by pointer.
 * @param {Array}    props.tags     Merge tags in scope.
 */
function ValuesControl( { values, onChange, fields, pointer, errors, tags } ) {
	const entries = Object.entries( values || {} );
	const own = wire( errors, pointer );
	const write = ( next ) =>
		onChange( next.length ? Object.fromEntries( next ) : undefined );
	const update = ( index, key, value ) =>
		write(
			entries.map( ( entry, i ) =>
				i === index ? [ key, value ] : entry
			)
		);
	const unused = ( fields || [] ).filter(
		( field ) => ! ( field.key in ( values || {} ) )
	);

	return (
		<fieldset className="pegwork-automation__fieldset">
			<legend>{ __( 'Values', 'pegwork' ) }</legend>
			<Errors
				messages={ errors[ pointer ] ? [ errors[ pointer ] ] : [] }
				id={ `${ own.id }-errors` }
			/>
			<ol className="pegwork-automation__values">
				{ entries.map( ( [ key, value ], index ) => {
					const field = ( fields || [] ).find(
						( item ) => item.key === key
					);
					const at = `${ pointer }/${ key }`;
					const label = sprintf(
						/* translators: %s: field name. */ __(
							'Value of %s',
							'pegwork'
						),
						field?.label || key || ''
					);
					const valueWire = wire( errors, at );
					let control;
					if ( field?.type === 'boolean' ) {
						control = (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ label }
								value={ value === true ? 'true' : 'false' }
								options={ [
									{
										value: 'true',
										label: __(
											'true (checked)',
											'pegwork'
										),
									},
									{
										value: 'false',
										label: __(
											'false (not checked)',
											'pegwork'
										),
									},
								] }
								onChange={ ( next ) =>
									update( index, key, next === 'true' )
								}
								{ ...valueWire.props }
							/>
						);
					} else if (
						field?.type === 'select' &&
						field.options?.length
					) {
						control = (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ label }
								value={ String( value ) }
								options={ [
									{
										value: '',
										label: __(
											'(clear the field)',
											'pegwork'
										),
									},
									...field.options,
								] }
								onChange={ ( next ) =>
									update( index, key, next )
								}
								{ ...valueWire.props }
							/>
						);
					} else if ( field?.type === 'taxonomy' && field.multiple ) {
						control = (
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ label }
								help={ __(
									'Term slugs, separated by commas.',
									'pegwork'
								) }
								value={
									Array.isArray( value )
										? value.join( ', ' )
										: String( value ?? '' )
								}
								onChange={ ( next ) =>
									update(
										index,
										key,
										next
											.split( ',' )
											.map( ( item ) => item.trim() )
											.filter( Boolean )
									)
								}
								{ ...valueWire.props }
							/>
						);
					} else {
						control = (
							<TemplateControl
								label={ label }
								value={ value }
								onChange={ ( next ) =>
									update(
										index,
										key,
										field?.type === 'number' &&
											/^-?\d+(\.\d+)?$/.test( next )
											? Number( next )
											: next
									)
								}
								pointer={ at }
								errors={ errors }
								tags={ tags }
							/>
						);
					}
					return (
						<li key={ index } className="pegwork-automation__value">
							{ fields ? (
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: %d: row number. */ __(
											'Field %d',
											'pegwork'
										),
										index + 1
									) }
									value={ key }
									options={ [
										...( field
											? []
											: [ { value: key, label: key } ] ),
										...fields
											.filter(
												( item ) =>
													item.key === key ||
													! ( item.key in values )
											)
											.map( ( item ) => ( {
												value: item.key,
												label: item.label,
											} ) ),
									] }
									onChange={ ( next ) =>
										update( index, next, value )
									}
								/>
							) : (
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: %d: row number. */ __(
											'Field key %d',
											'pegwork'
										),
										index + 1
									) }
									value={ key }
									onChange={ ( next ) =>
										update( index, next, value )
									}
								/>
							) }
							{ control }
							{ control.type !== TemplateControl && (
								<Errors
									messages={ valueWire.messages }
									id={ `${ valueWire.id }-errors` }
								/>
							) }
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									write(
										entries.filter(
											( entry, i ) => i !== index
										)
									)
								}
							>
								{ sprintf(
									/* translators: 1: row number, 2: field key. */ __(
										'Remove value %1$d: %2$s',
										'pegwork'
									),
									index + 1,
									key
								) }
							</Button>
						</li>
					);
				} ) }
			</ol>
			<Button
				variant="secondary"
				disabled={ fields !== null && unused.length === 0 }
				onClick={ () => {
					const field = unused[ 0 ];
					const key = field?.key || `field_${ entries.length + 1 }`;
					write( [
						...entries,
						[ key, field?.type === 'boolean' ? true : '' ],
					] );
				} }
			>
				{ __( 'Add value', 'pegwork' ) }
			</Button>
		</fieldset>
	);
}

/**
 * The inspector fields of one step type.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.attributes Step attributes.
 * @param {Function} props.set        Sets one attribute.
 * @param {string}   props.pointer    Pointer of the step.
 * @param {Object}   props.errors     Errors by pointer.
 * @param {Object}   props.definition Edited definition.
 */
function TypeFields( {
	attributes,
	set,
	setAttributes,
	pointer,
	errors,
	definition,
} ) {
	const at = ( name ) => `${ pointer }/${ name }`;
	const tags = mergeTags( definition, pointer, config, 'private' );
	const template = ( name, label, extra = {} ) => (
		<TemplateControl
			label={ label }
			value={ attributes[ name ] ?? '' }
			onChange={ ( value ) => set( name, value ) }
			pointer={ at( name ) }
			errors={ errors }
			tags={ tags }
			{ ...extra }
		/>
	);
	const { binds, type } = binding( definition.trigger, config );

	switch ( attributes.type ) {
		case 'send_email':
			return (
				<>
					{ template( 'to', __( 'To', 'pegwork' ), {
						help: __(
							'Addresses separated by commas, a merge tag such as {{record.email}}, or {option:page.field} for a private options field.',
							'pegwork'
						),
					} ) }
					{ template(
						'reply_to',
						__( 'Reply-To (optional)', 'pegwork' )
					) }
					{ template( 'subject', __( 'Subject', 'pegwork' ) ) }
					{ template( 'text', __( 'Text body', 'pegwork' ), {
						multiline: true,
					} ) }
					{ template(
						'html',
						__( 'HTML body (optional)', 'pegwork' ),
						{
							multiline: true,
							help: __(
								'Sent beside the text body. Without the unfiltered HTML capability, unsafe markup is removed on save.',
								'pegwork'
							),
						}
					) }
				</>
			);
		case 'get_record':
		case 'find_records':
			return (
				<ReadFields
					attributes={ attributes }
					set={ set }
					setAttributes={ setAttributes }
					pointer={ pointer }
					errors={ errors }
					definition={ definition }
					tags={ tags }
				/>
			);
		case 'relate':
			return (
				<RelateFields
					attributes={ attributes }
					set={ set }
					pointer={ pointer }
					errors={ errors }
					definition={ definition }
					tags={ tags }
				/>
			);
		case 'update_record': {
			// The bound record, or a record whose type the template names exactly ({{steps.donor.record_id}}).
			const known = attributes.record
				? typedReference(
						attributes.record,
						definition,
						pointer,
						config
					)
				: binds && type;
			return (
				<>
					{ template(
						'record',
						__( 'Record (optional)', 'pegwork' ),
						{
							help: __(
								'Empty updates the record the trigger is about. Or a record ID, such as {{steps.make_task.record_id}}.',
								'pegwork'
							),
						}
					) }
					<ValuesControl
						values={ attributes.values }
						onChange={ ( values ) => set( 'values', values ) }
						fields={ known ? typeFields( config, known, '' ) : null }
						pointer={ at( 'values' ) }
						errors={ errors }
						tags={ tags }
					/>
				</>
			);
		}
		case 'create_record': {
			const status = wire( errors, at( 'content_type' ) );
			return (
				<>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Content type', 'pegwork' ) }
						value={ attributes.content_type ?? '' }
						options={ [
							{
								value: '',
								label: __( 'Choose a content type', 'pegwork' ),
							},
							...config.types.map( ( item ) => ( {
								value: item.key,
								label: item.label,
							} ) ),
						] }
						onChange={ ( value ) => set( 'content_type', value ) }
						{ ...status.props }
					/>
					<Errors
						messages={ status.messages }
						id={ `${ status.id }-errors` }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Status', 'pegwork' ) }
						value={ attributes.status ?? 'draft' }
						options={ STATUSES.map( ( value ) => ( {
							value,
							label: {
								draft: __( 'Draft', 'pegwork' ),
								pending: __( 'Pending review', 'pegwork' ),
								publish: __( 'Published', 'pegwork' ),
								private: __( 'Private', 'pegwork' ),
							}[ value ],
						} ) ) }
						onChange={ ( value ) =>
							set(
								'status',
								value === 'draft' ? undefined : value
							)
						}
					/>
					<ValuesControl
						values={ attributes.values }
						onChange={ ( values ) => set( 'values', values ) }
						fields={
							attributes.content_type
								? typeFields(
										config,
										attributes.content_type,
										''
									)
								: null
						}
						pointer={ at( 'values' ) }
						errors={ errors }
						tags={ tags }
					/>
				</>
			);
		}
		case 'call_webhook': {
			const publicFields =
				binds && type
					? typeFields( config, type, '' ).filter(
							( field ) => ! field.private
						)
					: [];
			const chosen = attributes.fields || [];
			const timeout = wire( errors, at( 'timeout' ) );
			return (
				<>
					{ template( 'url', __( 'URL', 'pegwork' ), {
						tags: mergeTags(
							definition,
							pointer,
							config,
							'public'
						),
						help: __(
							'http:// or https://. Private fields cannot appear here.',
							'pegwork'
						),
					} ) }
					{ publicFields.length > 0 && (
						<fieldset className="pegwork-automation__fieldset pegwork-automation__choices">
							<legend>
								{ __( 'Record fields to send', 'pegwork' ) }
							</legend>
							{ publicFields.map( ( field ) => (
								<CheckboxControl
									key={ field.key }
									__nextHasNoMarginBottom
									label={ field.label }
									checked={ chosen.includes( field.key ) }
									onChange={ ( checked ) => {
										const next = checked
											? [ ...chosen, field.key ]
											: chosen.filter(
													( key ) => key !== field.key
												);
										set(
											'fields',
											next.length ? next : undefined
										);
									} }
								/>
							) ) }
							<Errors
								messages={ Object.keys( errors )
									.filter( ( key ) =>
										key.startsWith( at( 'fields' ) )
									)
									.map( ( key ) => errors[ key ] ) }
							/>
						</fieldset>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						max={ 15 }
						label={ __( 'Timeout (seconds, optional)', 'pegwork' ) }
						value={ attributes.timeout ?? '' }
						onChange={ ( value ) =>
							set( 'timeout', toNumber( value ) )
						}
						{ ...timeout.props }
					/>
					<Errors
						messages={ timeout.messages }
						id={ `${ timeout.id }-errors` }
					/>
				</>
			);
		}
		case 'wait':
			return (
				<DurationControl
					label={ __( 'Wait for', 'pegwork' ) }
					help={ __( 'From 1 hour to 2 years.', 'pegwork' ) }
					value={ attributes.for }
					onChange={ ( value ) => set( 'for', value ) }
					pointer={ at( 'for' ) }
					errors={ errors }
				/>
			);
		case 'condition':
			return (
				<ConditionBuilder
					label={ __( 'Continue only if', 'pegwork' ) }
					empty={ __(
						'Add the condition the run needs to go on. When it is false, the run stops here.',
						'pegwork'
					) }
					value={ attributes.when }
					onChange={ ( value ) => set( 'when', value ) }
					fields={ conditionFields(
						definition,
						at( 'when' ),
						config
					) }
					event={ definition.trigger?.event ?? null }
					pointer={ at( 'when' ) }
					errors={ errors }
				/>
			);
		default:
			return null;
	}
}

/**
 * Advanced: retry policy and the step id.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.attributes Step or decision attributes.
 * @param {Function} props.set        Sets one attribute.
 * @param {string}   props.pointer    Pointer of the step.
 * @param {Object}   props.errors     Errors by pointer.
 * @param {boolean}  [props.retry]    Whether the step takes a retry policy.
 */
export function Advanced( { attributes, set, pointer, errors, retry = true } ) {
	const id = wire( errors, `${ pointer }/id`, true );
	const retryWire = wire( errors, `${ pointer }/retry` );
	const onError = wire( errors, `${ pointer }/on_error` );
	const policy = attributes.retry || null;
	const backoff = Array.isArray( policy?.backoff )
		? policy.backoff.join( ', ' )
		: ( policy?.backoff ?? '' );
	return (
		<PanelBody
			title={ __( 'Advanced', 'pegwork' ) }
			initialOpen={ Boolean(
				id.messages.length ||
					retryWire.messages.length ||
					onError.messages.length
			) }
		>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Step ID', 'pegwork' ) }
				help={ __(
					'Runs waiting at this step fail if you change its ID.',
					'pegwork'
				) }
				value={ attributes.id ?? '' }
				onChange={ ( value ) => set( 'id', value ) }
				{ ...id.props }
			/>
			<Errors messages={ id.messages } id={ `${ id.id }-errors` } />
			{ retry && (
				<fieldset className="pegwork-automation__fieldset">
					<legend>
						{ __( 'Retry when the step fails', 'pegwork' ) }
					</legend>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __(
							'Retries (empty for the default)',
							'pegwork'
						) }
						value={ policy?.max ?? '' }
						onChange={ ( value ) => {
							const max = toNumber( value );
							set(
								'retry',
								max === undefined
									? undefined
									: { ...policy, max: Math.trunc( max ) }
							);
						} }
						{ ...retryWire.props }
					/>
					{ policy && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Wait between tries', 'pegwork' ) }
							help={ __(
								'ISO 8601 durations separated by commas, such as PT1M, PT10M, PT1H.',
								'pegwork'
							) }
							value={ backoff }
							onChange={ ( value ) => {
								const list = value
									.split( ',' )
									.map( ( item ) => item.trim() )
									.filter( Boolean );
								const next = { max: policy.max };
								if ( list.length ) {
									next.backoff =
										list.length === 1 ? list[ 0 ] : list;
								}
								set( 'retry', next );
							} }
						/>
					) }
					<Errors
						messages={ retryWire.messages }
						id={ `${ retryWire.id }-errors` }
					/>
				</fieldset>
			) }
			{ ON_ERROR_TYPES.includes( attributes.type ) && (
				<>
					<RadioControl
						label={ __(
							'If this step fails after retries',
							'pegwork'
						) }
						help={
							attributes.on_error === 'continue'
								? __(
										'Later steps can check Failed.',
										'pegwork'
									)
								: undefined
						}
						selected={
							attributes.on_error === 'continue'
								? 'continue'
								: 'stop'
						}
						options={ [
							{
								value: 'stop',
								label: __( 'Stop the run', 'pegwork' ),
							},
							{
								value: 'continue',
								label: __( 'Continue', 'pegwork' ),
							},
						] }
						onChange={ ( value ) =>
							set(
								'on_error',
								value === 'continue' ? 'continue' : undefined
							)
						}
					/>
					<Errors messages={ onError.messages } />
				</>
			) }
		</PanelBody>
	);
}

/**
 * Block edit component.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    The step.
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.clientId      Block client ID.
 * @param {boolean}  props.isSelected    Whether the block is selected.
 */
export default function StepEdit( {
	attributes,
	setAttributes,
	clientId,
	isSelected,
} ) {
	useStepId( clientId, attributes.id, attributes.type, setAttributes );
	const { definition, pointers } = useDefinition();
	const pointer = pointers[ clientId ] || '/steps/0';
	const errors = useErrorOwners().blocks[ clientId ] || {};
	const messages = Object.values( errors );
	const summary = stepSummary(
		attributes,
		conditionFields( definition, pointer, config ),
		config,
		binding( definition.trigger, config ).type
	);
	const set = ( name, value ) =>
		setAttributes( {
			[ name ]: value === '' || value === null ? undefined : value,
		} );

	const blockProps = useBlockProps( {
		className: `pegwork-automation-step${ messages.length ? ' has-error' : '' }`,
	} );

	const inspector = (
		<>
			<PanelBody title={ typeLabel( attributes.type ) }>
				<TypeFields
					attributes={ attributes }
					set={ set }
					setAttributes={ setAttributes }
					pointer={ pointer }
					errors={ errors }
					definition={ definition }
				/>
			</PanelBody>
			{ attributes.type === 'send_email' && (
				<PanelBody
					title={ __( 'Preview', 'pegwork' ) }
					initialOpen={ false }
				>
					<PreviewPanel
						templates={ [
							[ __( 'To', 'pegwork' ), attributes.to ],
							[ __( 'Subject', 'pegwork' ), attributes.subject ],
							[ __( 'Text body', 'pegwork' ), attributes.text ],
							[
								__( 'HTML body', 'pegwork' ),
								attributes.html,
								true,
							],
						] }
					/>
				</PanelBody>
			) }
			<Advanced
				attributes={ attributes }
				set={ set }
				pointer={ pointer }
				errors={ errors }
			/>
		</>
	);

	return (
		<div { ...blockProps }>
			<p className="pegwork-automation-step__head">
				<Dashicon
					icon={ ICONS[ attributes.type ] || 'controls-play' }
				/>
				<strong>{ typeLabel( attributes.type ) }</strong>{ ' ' }
				<code>{ attributes.id }</code>
			</p>
			{ summary && (
				<p className="pegwork-automation-step__summary">{ summary }</p>
			) }
			{ attributes.on_error === 'continue' && (
				<p className="pegwork-automation-step__note">
					{ __( 'Continues if it fails', 'pegwork' ) }
				</p>
			) }
			<Errors messages={ messages } />
			{ isSelected && ! config.readOnly && (
				<MoveToBranch clientId={ clientId } />
			) }
			<InspectorControls>
				<Errors messages={ messages } />
				{ config.readOnly ? (
					<Disabled>{ inspector }</Disabled>
				) : (
					inspector
				) }
			</InspectorControls>
		</div>
	);
}
