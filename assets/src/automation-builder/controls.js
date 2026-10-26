/**
 * Shared inspector and sidebar controls: error messages tied to their control, template fields with
 * "Insert merge tag", durations, and the sample record or payload of previews and test runs.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	CheckboxControl,
	ComboboxControl,
	Dropdown,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { errorsAt, formatDuration, parseDuration } from './logic';
import { fieldName, listName, tagGroups } from './labels';
import { eachBlock, formatTag, withRole } from './scope';
import { config, useSample } from './store';

/**
 * The element ID of the control for a pointer ("Go to first error" focuses it).
 *
 * @param {string} pointer JSON pointer.
 * @return {string} ID.
 */
export function controlId( pointer ) {
	return `pegwork-at${ pointer.replace( /[^A-Za-z0-9_]+/g, '-' ) }`;
}

/**
 * Error messages next to what they concern. Not a live region: core's save notice already
 * announces the failure.
 *
 * @param {Object}   props          Props.
 * @param {string[]} props.messages Messages.
 * @param {string}   [props.id]     Element ID (for aria-describedby).
 */
export function Errors( { messages, id } ) {
	if ( ! messages?.length ) {
		return null;
	}
	return (
		<div className="pegwork-automation__errors" id={ id }>
			{ messages.map( ( message, index ) => (
				<p key={ index } className="pegwork-automation__error">
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
 * A block a loop cannot hold (a pasted wait or loop): the message at its card, an alert that takes
 * focus while the block is selected (after core has focused the block itself).
 *
 * @param {Object}  props         Props.
 * @param {string}  props.message Message.
 * @param {boolean} props.focus   Whether to take focus.
 */
export function Refused( { message, focus } ) {
	const ref = useRef();
	useEffect( () => {
		const timer = focus
			? window.setTimeout( () => ref.current?.focus(), 100 )
			: 0;
		return () => window.clearTimeout( timer );
	}, [ focus ] );
	return (
		<p
			ref={ ref }
			tabIndex={ -1 }
			role="alert"
			className="pegwork-automation__error"
		>
			{ message }
		</p>
	);
}

/**
 * Props that tie a control to its errors, and the messages to show under it.
 *
 * @param {Object<string, string>} errors  Errors of the block or settings, by pointer.
 * @param {string}                 pointer Pointer of the control's value.
 * @param {boolean}                [help]  Whether the control shows help text (its ID is kept).
 * @return {{id: string, messages: string[], props: Object}} Wiring.
 */
export function wire( errors, pointer, help = false ) {
	const id = controlId( pointer );
	const messages = errorsAt( errors, pointer );
	const described = [
		help ? `${ id }__help` : '',
		messages.length ? `${ id }-errors` : '',
	]
		.filter( Boolean )
		.join( ' ' );
	return {
		id,
		messages,
		props: {
			id,
			'aria-invalid': messages.length ? true : undefined,
			'aria-describedby': described || undefined,
		},
	};
}

/**
 * Picks a merge tag (with `| date` and `| default`) and inserts it.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.tags     Tags in scope (scope.js mergeTags()).
 * @param {string}   props.label    Name of the field it inserts into.
 * @param {Function} props.onInsert Called with the tag text.
 */
function MergeTagInserter( { tags, label, onInsert } ) {
	const [ path, setPath ] = useState( '' );
	const [ date, setDate ] = useState( false );
	const [ move, setMove ] = useState( { sign: '', amount: 1, unit: 'days' } );
	const [ number, setNumber ] = useState( '' );
	const [ fallback, setFallback ] = useState( '' );
	const chosen = tags.find( ( tag ) => tag.path === path ) || tags[ 0 ];
	const common = {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};

	return (
		<Dropdown
			className="pegwork-automation__tags"
			contentClassName="pegwork-automation__tags-popover"
			focusOnMount="firstElement"
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="link"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					aria-label={ sprintf(
						/* translators: %s: field label. */
						__( 'Insert merge tag into %s', 'pegwork' ),
						label
					) }
				>
					{ __( 'Insert merge tag', 'pegwork' ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="pegwork-automation__tags-form">
					<SelectControl
						{ ...common }
						label={ __( 'Merge tag', 'pegwork' ) }
						value={ chosen?.path ?? '' }
						onChange={ setPath }
					>
						{ Object.entries( tagGroups( tags, config ) ).map(
							( [ heading, list ] ) => (
								<optgroup key={ heading } label={ heading }>
									{ list.map( ( tag ) => (
										<option
											key={ tag.path }
											value={ tag.path }
										>
											{ `${ fieldName( tag, false ) } {{${ tag.path }}}` }
										</option>
									) ) }
								</optgroup>
							)
						) }
					</SelectControl>
					{ chosen?.date && (
						<>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __( 'Format as a date', 'pegwork' ) }
								checked={ date }
								onChange={ setDate }
							/>
							<SelectControl
								{ ...common }
								label={ __(
									'Plus or minus a duration',
									'pegwork'
								) }
								value={ move.sign }
								options={ [
									{ value: '', label: __( 'No', 'pegwork' ) },
									{
										value: '+',
										label: __( 'Plus', 'pegwork' ),
									},
									{
										value: '-',
										label: __( 'Minus', 'pegwork' ),
									},
								] }
								onChange={ ( sign ) =>
									setMove( { ...move, sign } )
								}
							/>
							{ move.sign && (
								<div className="pegwork-automation__row">
									<TextControl
										{ ...common }
										type="number"
										min={ 1 }
										label={ __( 'Amount', 'pegwork' ) }
										value={ String( move.amount ) }
										onChange={ ( amount ) =>
											setMove( {
												...move,
												amount: Number( amount ),
											} )
										}
									/>
									<SelectControl
										{ ...common }
										label={ __( 'Unit', 'pegwork' ) }
										value={ move.unit }
										options={ UNITS.map( ( unit ) => ( {
											value: unit,
											label: unitLabel( unit ),
										} ) ) }
										onChange={ ( unit ) =>
											setMove( { ...move, unit } )
										}
									/>
								</div>
							) }
						</>
					) }
					{ chosen?.number && (
						<SelectControl
							{ ...common }
							label={ __( 'Format the number', 'pegwork' ) }
							value={ number }
							options={ [
								{ value: '', label: __( 'No', 'pegwork' ) },
								{
									value: 'auto',
									label: __(
										'Formatted (1,250 or 1,250.50)',
										'pegwork'
									),
								},
								...[ 0, 1, 2, 3, 4 ].map( ( places ) => ( {
									value: String( places ),
									label: sprintf(
										/* translators: %d: number of decimal places. */
										_n(
											'Formatted, %d decimal place',
											'Formatted, %d decimal places',
											places,
											'pegwork'
										),
										places
									),
								} ) ),
							] }
							onChange={ setNumber }
						/>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Text when empty (optional)', 'pegwork' ) }
						value={ fallback }
						onChange={ setFallback }
					/>
					<Button
						variant="primary"
						disabled={ ! chosen }
						onClick={ () => {
							let numberFilter;
							if ( chosen.number && number === 'auto' ) {
								numberFilter = true;
							} else if ( chosen.number && number !== '' ) {
								numberFilter = Number( number );
							}
							onInsert(
								formatTag( chosen.path, {
									add:
										chosen.date && move.sign
											? formatDuration(
													move.amount,
													move.unit,
													move.sign === '-' ? '-' : ''
												)
											: '',
									date: date && chosen.date,
									number: numberFilter,
									fallback,
								} )
							);
							onClose();
						} }
					>
						{ __( 'Insert', 'pegwork' ) }
					</Button>
				</div>
			) }
		/>
	);
}

/**
 * A template field: text or textarea, its errors, and "Insert merge tag".
 *
 * @param {Object}   props             Props.
 * @param {string}   props.label       Label.
 * @param {string}   props.value       Template.
 * @param {Function} props.onChange    Called with the new template ('' when cleared).
 * @param {string}   props.pointer     Pointer of the value.
 * @param {Object}   props.errors      Errors by pointer.
 * @param {Array}    props.tags        Merge tags in scope.
 * @param {boolean}  [props.multiline] Textarea.
 * @param {string}   [props.help]      Help text.
 * @param {boolean}  [props.disabled]  Read-only.
 * @param {Array}    [props.lists]     Lists of records in scope (find steps): "Insert list of records".
 * @param {boolean}  [props.html]      An HTML template (the list is inserted as `<ul>`).
 * @param {Array}    [props.roles]     Roles it takes (`slug`, `name`): "Add a role".
 */
export function TemplateControl( {
	label,
	value,
	onChange,
	pointer,
	errors,
	tags,
	multiline,
	help,
	disabled,
	lists,
	html,
	roles,
} ) {
	const ref = useRef();
	const { id, messages, props } = wire( errors, pointer, Boolean( help ) );
	const current = typeof value === 'string' ? value : String( value ?? '' );

	const insert = ( tag ) => {
		const input = ref.current;
		const start = input?.selectionStart ?? current.length;
		const end = input?.selectionEnd ?? current.length;
		onChange( current.slice( 0, start ) + tag + current.slice( end ) );
		window.requestAnimationFrame( () => {
			input?.focus();
			input?.setSelectionRange?.(
				start + tag.length,
				start + tag.length
			);
		} );
	};

	return (
		<div className="pegwork-automation__template">
			{ multiline ? (
				// TextareaControl labels its own instance ID, not ours; BaseControl takes ours.
				<BaseControl
					__nextHasNoMarginBottom
					id={ id }
					label={ label }
					help={ help }
				>
					<textarea
						ref={ ref }
						className="components-textarea-control__input"
						rows={ 5 }
						value={ current }
						onChange={ ( event ) => onChange( event.target.value ) }
						disabled={ disabled }
						{ ...props }
					/>
				</BaseControl>
			) : (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					ref={ ref }
					label={ label }
					help={ help }
					value={ current }
					onChange={ onChange }
					disabled={ disabled }
					{ ...props }
				/>
			) }
			{ ! disabled && tags?.length > 0 && (
				<MergeTagInserter
					tags={ tags }
					label={ label }
					onInsert={ insert }
				/>
			) }
			{ ! disabled && lists?.length > 0 && (
				<Picker
					toggle={ __( 'Insert list of records', 'pegwork' ) }
					name={ sprintf(
						/* translators: %s: field label. */
						__( 'Insert list of records into %s', 'pegwork' ),
						label
					) }
					label={ __( 'List', 'pegwork' ) }
					help={ __(
						'One line per record, with its first field. Inside the block, item tags read that record; text after {{else}} shows when the list is empty.',
						'pegwork'
					) }
					options={ lists.map( ( item ) => ( {
						value: item.id,
						label: listName( item, config ),
					} ) ) }
					action={ __( 'Insert list', 'pegwork' ) }
					onPick={ ( chosen ) =>
						insert(
							eachBlock(
								lists.find( ( item ) => item.id === chosen ),
								config,
								html
							)
						)
					}
				/>
			) }
			{ ! disabled && roles?.length > 0 && (
				<Picker
					toggle={ __( 'Add a role', 'pegwork' ) }
					name={ sprintf(
						/* translators: %s: field label. */
						__( 'Add a role to %s', 'pegwork' ),
						label
					) }
					label={ __( 'Role', 'pegwork' ) }
					help={ __(
						'Everyone with the role when it sends, in Bcc. The log shows how many, never who.',
						'pegwork'
					) }
					options={ roles.map( ( role ) => ( {
						value: role.slug,
						label: role.name,
					} ) ) }
					action={ __( 'Add role', 'pegwork' ) }
					onPick={ ( slug ) => {
						onChange( withRole( current, slug ) );
						window.requestAnimationFrame( () =>
							ref.current?.focus()
						);
					} }
				/>
			) }
			<Errors messages={ messages } id={ `${ id }-errors` } />
		</div>
	);
}

/**
 * A link button beside a template field that opens one select and an action button: "Insert list of
 * records" (a list in scope) and "Add a role" (an eligible role).
 *
 * @param {Object}   props         Props.
 * @param {string}   props.toggle  Visible text of the link button.
 * @param {string}   props.name    Its accessible name (holds the visible text).
 * @param {string}   props.label   Label of the select.
 * @param {string}   [props.help]  Help of the select.
 * @param {Array}    props.options Select options (`value`, `label`).
 * @param {string}   props.action  Text of the action button.
 * @param {Function} props.onPick  Called with the chosen value.
 */
function Picker( { toggle, name, label, help, options, action, onPick } ) {
	const [ chosen, setChosen ] = useState( '' );
	const value = options.some( ( item ) => item.value === chosen )
		? chosen
		: options[ 0 ].value;
	return (
		<Dropdown
			className="pegwork-automation__tags"
			contentClassName="pegwork-automation__tags-popover"
			focusOnMount="firstElement"
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="link"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					aria-label={ name }
				>
					{ toggle }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="pegwork-automation__tags-form">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ label }
						help={ help }
						value={ value }
						options={ options }
						onChange={ setChosen }
					/>
					<Button
						variant="primary"
						onClick={ () => {
							onPick( value );
							onClose();
						} }
					>
						{ action }
					</Button>
				</div>
			) }
		/>
	);
}

export const UNITS = [ 'hours', 'days', 'weeks', 'months', 'years' ];

/**
 * A duration unit's name.
 *
 * @param {string} unit hours, days, weeks, months or years.
 * @return {string} Name.
 */
export function unitLabel( unit ) {
	return {
		hours: __( 'Hours', 'pegwork' ),
		days: __( 'Days', 'pegwork' ),
		weeks: __( 'Weeks', 'pegwork' ),
		months: __( 'Months', 'pegwork' ),
		years: __( 'Years', 'pegwork' ),
	}[ unit ];
}

/**
 * A duration as amount and unit, or as ISO 8601 text when it has several units.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Label (legend).
 * @param {string}   props.value    ISO 8601 duration.
 * @param {Function} props.onChange Called with the new duration ('' when empty).
 * @param {string}   props.pointer  Pointer of the value.
 * @param {Object}   props.errors   Errors by pointer.
 * @param {string}   [props.help]   Help text.
 */
export function DurationControl( {
	label,
	value,
	onChange,
	pointer,
	errors,
	help,
} ) {
	const parts = parseDuration( value );
	const [ raw, setRaw ] = useState( ! value || parts ? '' : value );
	const { id, messages, props } = wire( errors, pointer );

	return (
		<fieldset
			className="pegwork-automation__fieldset"
			aria-describedby={ help ? `${ id }-help` : undefined }
		>
			<legend>{ label }</legend>
			{ help && (
				<p className="pegwork-automation__help" id={ `${ id }-help` }>
					{ help }
				</p>
			) }
			{ raw !== '' ? (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'ISO 8601 duration', 'pegwork' ) }
					value={ raw }
					onChange={ ( next ) => {
						setRaw( next );
						onChange( next );
					} }
					{ ...props }
				/>
			) : (
				<div className="pegwork-automation__row">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Amount', 'pegwork' ) }
						value={ parts ? String( parts.amount ) : '' }
						onChange={ ( next ) =>
							onChange(
								formatDuration(
									Number( next ),
									parts?.unit || 'days'
								)
							)
						}
						{ ...props }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Unit', 'pegwork' ) }
						value={ parts?.unit || 'days' }
						options={ UNITS.map( ( unit ) => ( {
							value: unit,
							label: unitLabel( unit ),
						} ) ) }
						onChange={ ( unit ) =>
							onChange(
								formatDuration( parts?.amount || 1, unit )
							)
						}
					/>
				</div>
			) }
			<Errors messages={ messages } id={ `${ id }-errors` } />
		</fieldset>
	);
}

/**
 * The sample a preview or test run uses: a record of the bound type (searched with core REST), or a
 * pasted JSON payload for webhooks and custom events.
 *
 * @param {Object}  props         Props.
 * @param {?string} props.type    Bound content type, null for none, '' for any or unknown.
 * @param {boolean} props.binds   Whether the trigger binds a record.
 * @param {boolean} props.payload Whether the trigger takes a pasted payload.
 */
export function SampleControl( { type, binds, payload } ) {
	const [ sample, setSample ] = useSample();
	const [ options, setOptions ] = useState( [] );
	const [ search, setSearch ] = useState( '' );
	const rest = type ? config.rest[ type ] : null;

	useEffect( () => {
		if ( ! binds || ! rest ) {
			return undefined;
		}
		let live = true;
		const timer = window.setTimeout( () => {
			apiFetch( {
				path: addQueryArgs( `/wp/v2/${ rest }`, {
					search,
					per_page: 20,
					status: 'publish,future,draft,pending,private',
					context: 'edit',
					_fields: 'id,title',
				} ),
			} )
				.then( ( records ) => {
					if ( live ) {
						setOptions(
							records.map( ( record ) => ( {
								value: String( record.id ),
								label: `${ record.title?.raw || __( '(no title)', 'pegwork' ) } (#${ record.id })`,
							} ) )
						);
					}
				} )
				.catch( () => live && setOptions( [] ) );
		}, 300 );
		return () => {
			live = false;
			window.clearTimeout( timer );
		};
	}, [ binds, rest, search ] );

	return (
		<div className="pegwork-automation__sample">
			{ binds && rest && (
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Sample record', 'pegwork' ) }
					help={ __( 'Type to search records by title.', 'pegwork' ) }
					value={ sample.recordId ? String( sample.recordId ) : null }
					options={
						sample.recordId &&
						! options.some(
							( option ) =>
								option.value === String( sample.recordId )
						)
							? [
									...options,
									{
										value: String( sample.recordId ),
										label: sample.recordLabel,
									},
								]
							: options
					}
					onFilterValueChange={ setSearch }
					onChange={ ( value ) =>
						setSample( {
							recordId: value ? Number( value ) : null,
							recordLabel:
								options.find(
									( option ) => option.value === value
								)?.label || '',
						} )
					}
				/>
			) }
			{ binds && ! rest && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					min={ 1 }
					label={ __( 'Sample record ID', 'pegwork' ) }
					value={ sample.recordId ? String( sample.recordId ) : '' }
					onChange={ ( value ) =>
						setSample( {
							recordId: Number( value ) || null,
							recordLabel: '',
						} )
					}
				/>
			) }
			{ payload && (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Sample payload (JSON)', 'pegwork' ) }
					help={ __(
						'Such as {"kind": "a"}. Read as trigger.payload.* (webhooks) or trigger.* (other events).',
						'pegwork'
					) }
					value={ sample.payload }
					onChange={ ( value ) => setSample( { payload: value } ) }
					rows={ 4 }
				/>
			) }
		</div>
	);
}

/**
 * The sample as request parameters, or an error message when the payload is not a JSON object.
 *
 * @param {Object}  sample  Shared sample.
 * @param {boolean} binds   Whether the trigger binds a record.
 * @param {boolean} payload Whether the trigger takes a payload.
 * @return {{params: Object, error: string}} Parameters.
 */
export function sampleParams( sample, binds, payload ) {
	const params = {};
	if ( binds && sample.recordId ) {
		params.record_id = sample.recordId;
	}
	if ( payload && sample.payload.trim() !== '' ) {
		try {
			const value = JSON.parse( sample.payload );
			if (
				! value ||
				typeof value !== 'object' ||
				Array.isArray( value )
			) {
				throw new Error();
			}
			params.payload = value;
		} catch {
			return {
				params,
				error: __(
					'The sample payload is not a JSON object.',
					'pegwork'
				),
			};
		}
	}
	return { params, error: '' };
}
