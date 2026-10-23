/**
 * Inspector fields of the read and link steps: Get a record and Find records (content type, how to
 * find the records, a relationship and "Of", filter rows, Limit, Also total, and sort and statuses
 * under More options) and Link records (relationship, Add or Remove, From and To).
 */

/**
 * WordPress dependencies
 */
import {
	CheckboxControl,
	ComboboxControl,
	PanelBody,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ConditionBuilder from './condition-builder';
import { Errors, TemplateControl, wire } from './controls';
import { blankValue } from './conditions';
import { toNumber } from './logic';
import {
	READ_STATUSES,
	conditionToFilter,
	filterFields,
	filterToCondition,
	otherEnd,
	readSource,
	relationshipsFor,
	sortFields,
	statusesOf,
	withSort,
	withSource,
	withStatus,
} from './reads';
import { binding, conditionFields, typeFields, typeOf } from './scope';
import { config } from './store';

const STATUS_LABELS = () => ( {
	publish: __( 'Published', 'pegwork' ),
	future: __( 'Scheduled', 'pegwork' ),
	draft: __( 'Draft', 'pegwork' ),
	pending: __( 'Pending review', 'pegwork' ),
	private: __( 'Private', 'pegwork' ),
} );

/**
 * A relationship as an option: "Donor (Gift to Contact)".
 *
 * @param {Object} relationship Relationship.
 * @return {{value: string, label: string}} Option.
 */
function relationshipOption( relationship ) {
	const name = ( key ) => typeOf( config, key )?.label || key;
	return {
		value: relationship.key,
		label: sprintf(
			/* translators: 1: relationship label, 2: "from" content type, 3: "to" content type. */
			__( '%1$s (%2$s to %3$s)', 'pegwork' ),
			relationship.label,
			name( relationship.from ),
			name( relationship.to )
		),
	};
}

/**
 * A relationship combobox with its errors.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    Relationship key.
 * @param {Array}    props.options  Relationships offered.
 * @param {Function} props.onChange Called with the key ('' when cleared).
 * @param {string}   props.pointer  Pointer of the relationship key.
 * @param {Object}   props.errors   Errors by pointer.
 */
function RelationshipControl( { value, options, onChange, pointer, errors } ) {
	const own = wire( errors, pointer );
	const list = options.map( relationshipOption );
	if ( value && ! list.some( ( option ) => option.value === value ) ) {
		list.push( { value, label: value } );
	}
	return (
		<>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Relationship', 'pegwork' ) }
				value={ value || null }
				options={ list }
				onChange={ ( next ) => onChange( next || '' ) }
			/>
			<Errors messages={ own.messages } id={ `${ own.id }-errors` } />
		</>
	);
}

/**
 * Get a record and Find records.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Step attributes.
 * @param {Function} props.set           Sets one attribute.
 * @param {Function} props.setAttributes Sets several attributes.
 * @param {string}   props.pointer       Pointer of the step.
 * @param {Object}   props.errors        Errors by pointer.
 * @param {Object}   props.definition    Edited definition.
 * @param {Array}    props.tags          Merge tags in scope at the step.
 */
export function ReadFields( {
	attributes,
	set,
	setAttributes,
	pointer,
	errors,
	definition,
	tags,
} ) {
	const at = ( name ) => `${ pointer }/${ name }`;
	const get = attributes.type === 'get_record';
	const type = attributes.content_type || '';
	const fields = type ? typeFields( config, type, '' ) : [];
	const filterable = filterFields( fields );
	const source = readSource( attributes );
	const typeWire = wire( errors, at( 'content_type' ) );
	const related = attributes.related || null;
	const relationship = config.relationships.find(
		( item ) => item.key === related?.relationship
	);
	const firstRow = () => {
		const field =
			filterable.find( ( item ) => item.label ) || filterable[ 0 ];
		return { field: field.path, op: 'eq', value: blankValue( field ) };
	};
	const sources = [
		...( get
			? [ { value: 'record', label: __( 'Its ID', 'pegwork' ) } ]
			: [] ),
		{ value: 'related', label: __( 'A relationship', 'pegwork' ) },
		{ value: 'filter', label: __( 'Matching fields', 'pegwork' ) },
		{
			value: 'both',
			label: __( 'A relationship and matching fields', 'pegwork' ),
		},
	];
	const statuses = statusesOf( attributes.status );
	const sortable = sortFields( fields );
	const numbers = fields.filter( ( field ) => field.type === 'number' );
	const sum = attributes.sum || [];
	const limit = wire( errors, at( 'limit' ) );
	const sourceErrors = Object.keys( errors )
		.filter(
			( key ) => key === pointer || key === at( 'record' ) // read_source is reported at the step.
		)
		.map( ( key ) => errors[ key ] );

	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Content type', 'pegwork' ) }
				value={ type }
				options={ [
					{ value: '', label: __( 'Choose a content type', 'pegwork' ) },
					...config.types.map( ( item ) => ( {
						value: item.key,
						label: item.label,
					} ) ),
				] }
				onChange={ ( value ) =>
					// Fields, sums and sort belong to the old type.
					setAttributes( {
						content_type: value || undefined,
						filter: attributes.filter
							? { all: [] }
							: attributes.filter,
						sum: undefined,
						sort: undefined,
					} )
				}
				{ ...typeWire.props }
			/>
			<Errors
				messages={ typeWire.messages }
				id={ `${ typeWire.id }-errors` }
			/>
			<RadioControl
				label={
					get
						? __( 'Find the record by', 'pegwork' )
						: __( 'Find the records by', 'pegwork' )
				}
				selected={ source }
				options={ sources }
				onChange={ ( next ) =>
					setAttributes(
						withSource(
							attributes,
							next,
							filterable.length ? firstRow() : null
						)
					)
				}
			/>
			<Errors messages={ sourceErrors } />
			{ source === 'record' && (
				<TemplateControl
					label={ __( 'Record ID', 'pegwork' ) }
					help={ __(
						'One ID, such as {{trigger.payload.gift_id}}. A record of another type is not found.',
						'pegwork'
					) }
					value={ attributes.record ?? '' }
					onChange={ ( value ) =>
						setAttributes( { record: value } )
					}
					pointer={ at( 'record' ) }
					errors={ errors }
					tags={ tags }
				/>
			) }
			{ ( source === 'related' || source === 'both' ) && (
				<fieldset className="pegwork-automation__fieldset">
					<legend>{ __( 'Linked by', 'pegwork' ) }</legend>
					<RelationshipControl
						value={ related?.relationship }
						options={ relationshipsFor( config, type ) }
						onChange={ ( key ) =>
							set( 'related', { ...related, relationship: key } )
						}
						pointer={ at( 'related/relationship' ) }
						errors={ errors }
					/>
					<TemplateControl
						label={ __( 'Of', 'pegwork' ) }
						help={
							relationship && type
								? sprintf(
										/* translators: %s: content type label. */
										__(
											'The ID of a %s record, such as {{record.id}}.',
											'pegwork'
										),
										typeOf(
											config,
											otherEnd( relationship, type )
										)?.label ||
											otherEnd( relationship, type )
									)
								: __(
										'The ID of the record at the other end, such as {{record.id}}.',
										'pegwork'
									)
						}
						value={ related?.of ?? '' }
						onChange={ ( value ) =>
							set( 'related', { ...related, of: value } )
						}
						pointer={ at( 'related/of' ) }
						errors={ errors }
						tags={ tags }
					/>
					<Errors
						messages={
							errors[ at( 'related' ) ]
								? [ errors[ at( 'related' ) ] ]
								: []
						}
					/>
				</fieldset>
			) }
			{ ( source === 'filter' || source === 'both' ) && (
				<ConditionBuilder
					flat
					label={ __( 'Matching fields', 'pegwork' ) }
					value={ filterToCondition( attributes.filter ) }
					onChange={ ( value ) =>
						set( 'filter', conditionToFilter( value ) )
					}
					fields={ filterable }
					operands={ conditionFields( definition, pointer, config ) }
					event={ null }
					pointer={ at( 'filter' ) }
					errors={ errors }
				/>
			) }
			{ ! get && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						max={ 50 }
						label={ __( 'Limit', 'pegwork' ) }
						help={ __(
							'Records to load, 0 to 50 (10 when empty). Totals count every match, not only these.',
							'pegwork'
						) }
						value={ attributes.limit ?? '' }
						placeholder="10"
						onChange={ ( value ) => {
							const number = toNumber( value );
							set(
								'limit',
								number === undefined
									? undefined
									: Math.trunc( number )
							);
						} }
						{ ...limit.props }
					/>
					<Errors
						messages={ limit.messages }
						id={ `${ limit.id }-errors` }
					/>
					{ numbers.length > 0 && (
						<fieldset className="pegwork-automation__fieldset pegwork-automation__choices">
							<legend>{ __( 'Also total', 'pegwork' ) }</legend>
							{ numbers.map( ( field ) => (
								<CheckboxControl
									key={ field.key }
									__nextHasNoMarginBottom
									label={ field.label }
									checked={ sum.includes( field.key ) }
									disabled={
										! sum.includes( field.key ) &&
										sum.length >= 5
									}
									onChange={ ( checked ) => {
										const next = checked
											? [ ...sum, field.key ]
											: sum.filter(
													( key ) => key !== field.key
												);
										set(
											'sum',
											next.length ? next : undefined
										);
									} }
								/>
							) ) }
							<Errors
								messages={ Object.keys( errors )
									.filter( ( key ) =>
										key.startsWith( at( 'sum' ) )
									)
									.map( ( key ) => errors[ key ] ) }
							/>
						</fieldset>
					) }
				</>
			) }
			<PanelBody
				title={ __( 'More options', 'pegwork' ) }
				initialOpen={ Boolean(
					attributes.sort ||
						attributes.status ||
						errors[ at( 'sort/field' ) ]
				) }
			>
				<div className="pegwork-automation__row">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Sort by', 'pegwork' ) }
						value={ attributes.sort?.field ?? 'id' }
						options={ [
							{ value: 'id', label: __( 'ID', 'pegwork' ) },
							...sortable.map( ( field ) => ( {
								value: field.key,
								label: field.label,
							} ) ),
						] }
						onChange={ ( value ) =>
							set( 'sort', withSort( attributes.sort, 'field', value ) )
						}
						{ ...wire( errors, at( 'sort/field' ) ).props }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Order', 'pegwork' ) }
						value={ attributes.sort?.order ?? 'asc' }
						options={ [
							{
								value: 'asc',
								label: __( 'Lowest or oldest first', 'pegwork' ),
							},
							{
								value: 'desc',
								label: __( 'Highest or newest first', 'pegwork' ),
							},
						] }
						onChange={ ( value ) =>
							set( 'sort', withSort( attributes.sort, 'order', value ) )
						}
					/>
				</div>
				<Errors messages={ wire( errors, at( 'sort' ) ).messages } />
				<fieldset className="pegwork-automation__fieldset pegwork-automation__choices">
					<legend>{ __( 'Include records that are', 'pegwork' ) }</legend>
					{ READ_STATUSES.map( ( value ) => (
						<CheckboxControl
							key={ value }
							__nextHasNoMarginBottom
							label={ STATUS_LABELS()[ value ] }
							checked={ statuses.includes( value ) }
							onChange={ ( checked ) =>
								set(
									'status',
									withStatus( attributes.status, value, checked )
								)
							}
						/>
					) ) }
					<Errors messages={ wire( errors, at( 'status' ) ).messages } />
				</fieldset>
			</PanelBody>
		</>
	);
}

/**
 * Link records.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.attributes Step attributes.
 * @param {Function} props.set        Sets one attribute.
 * @param {string}   props.pointer    Pointer of the step.
 * @param {Object}   props.errors     Errors by pointer.
 * @param {Object}   props.definition Edited definition.
 * @param {Array}    props.tags       Merge tags in scope at the step.
 */
export function RelateFields( {
	attributes,
	set,
	pointer,
	errors,
	definition,
	tags,
} ) {
	const at = ( name ) => `${ pointer }/${ name }`;
	const relationship = config.relationships.find(
		( item ) => item.key === attributes.relationship
	);
	const bound = binding( definition.trigger, config );
	const name = ( key ) => typeOf( config, key )?.label || key;
	const defaultFrom =
		relationship && bound.binds && bound.type === relationship.from;
	const own = Object.keys( errors )
		.filter( ( key ) => key === pointer )
		.map( ( key ) => errors[ key ] );

	return (
		<>
			<RelationshipControl
				value={ attributes.relationship }
				options={ config.relationships }
				onChange={ ( key ) => set( 'relationship', key ) }
				pointer={ at( 'relationship' ) }
				errors={ errors }
			/>
			<RadioControl
				label={ __( 'Link', 'pegwork' ) }
				selected={ attributes.action === 'remove' ? 'remove' : 'add' }
				options={ [
					{ value: 'add', label: __( 'Add', 'pegwork' ) },
					{ value: 'remove', label: __( 'Remove', 'pegwork' ) },
				] }
				onChange={ ( value ) =>
					set( 'action', value === 'remove' ? 'remove' : undefined )
				}
			/>
			<TemplateControl
				label={
					relationship
						? /* translators: %s: content type label. */ sprintf(
								__( 'From (a %s ID)', 'pegwork' ),
								name( relationship.from )
							)
						: __( 'From', 'pegwork' )
				}
				help={
					defaultFrom
						? /* translators: %s: content type label. */ sprintf(
								__( 'Empty: this %s.', 'pegwork' ),
								name( relationship.from )
							)
						: __( 'A record ID, such as {{steps.task.record_id}}.', 'pegwork' )
				}
				value={ attributes.from ?? '' }
				onChange={ ( value ) => set( 'from', value ) }
				pointer={ at( 'from' ) }
				errors={ errors }
				tags={ tags }
			/>
			<TemplateControl
				label={
					relationship
						? /* translators: %s: content type label. */ sprintf(
								__( 'To (a %s ID)', 'pegwork' ),
								name( relationship.to )
							)
						: __( 'To', 'pegwork' )
				}
				value={ attributes.to ?? '' }
				onChange={ ( value ) => set( 'to', value ) }
				pointer={ at( 'to' ) }
				errors={ errors }
				tags={ tags }
			/>
			<Errors messages={ own } />
		</>
	);
}
