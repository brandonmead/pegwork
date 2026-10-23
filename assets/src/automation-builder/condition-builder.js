/**
 * The condition builder: rows of field, operator and value inside All, Any and Not groups, each a
 * fieldset with a legend. It edits the registry condition grammar one node per model node, so any
 * valid tree comes back unchanged (conditions.js).
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	MAX_DEPTH,
	MAX_NODES,
	blankValue,
	opsFor,
	size,
	toJSON,
	toModel,
	valueKind,
	withOp,
} from './conditions';
import { Errors, controlId } from './controls';
import { conditionText, fieldName, opLabel } from './labels';
import { errorsAt, formatDuration, parseDuration, toNumber } from './logic';

const OTHER = '__other';

const at = ( model, path ) =>
	path.reduce(
		( node, step ) => ( step === 'item' ? node.item : node.items[ step ] ),
		model
	);

const replace = ( model, path, fn ) => {
	if ( ! path.length ) {
		return fn( model );
	}
	const [ head, ...rest ] = path;
	if ( head === 'item' ) {
		return { ...model, item: replace( model.item, rest, fn ) };
	}
	const items = [ ...model.items ];
	items[ head ] = replace( items[ head ], rest, fn );
	return { ...model, items };
};

const newLeaf = ( fields ) => {
	const field =
		fields.find( ( item ) => item.group === 'record' && item.label ) ||
		fields[ 0 ];
	return {
		kind: 'leaf',
		var: field?.path || '',
		op: 'eq',
		value: blankValue( field ),
		has: true,
	};
};

/**
 * The legend of a group: what must hold.
 *
 * @param {string} match all, any, not_all, not_any or not.
 * @return {string} Legend.
 */
function legendOf( match ) {
	const legends = {
		all: __( 'All of these are true', 'pegwork' ),
		any: __( 'Any of these is true', 'pegwork' ),
		not_all: __( 'Not all of these are true', 'pegwork' ),
		not_any: __( 'None of these are true', 'pegwork' ),
		not: __( 'This is not true', 'pegwork' ),
	};
	return legends[ match ];
}

/**
 * The value control of a leaf.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.leaf     Leaf model.
 * @param {?Object}  props.field    Field of the var.
 * @param {Array}    props.fields   Fields in scope.
 * @param {Function} props.onChange Called with the new leaf.
 * @param {string}   props.label    Row name, for control labels.
 */
function ValueControl( { leaf, field, fields, onChange, label } ) {
	const kind = valueKind( leaf );
	const type = field?.type ?? null;
	const set = ( value ) => onChange( { ...leaf, value, has: true } );
	const common = {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};
	const options = field?.options || [];

	if ( kind === 'none' ) {
		return null;
	}
	if ( kind === 'list' ) {
		const values = Array.isArray( leaf.value ) ? leaf.value : [];
		if ( options.length ) {
			return (
				<fieldset className="pegwork-automation__fieldset pegwork-automation__choices">
					<legend>
						{
							/* translators: %s: condition name. */ sprintf(
								__( 'Values of %s', 'pegwork' ),
								label
							)
						}
					</legend>
					{ options.map( ( option ) => (
						<CheckboxControl
							key={ option.value }
							__nextHasNoMarginBottom
							label={ option.label }
							checked={ values.includes( option.value ) }
							onChange={ ( checked ) =>
								set(
									checked
										? [ ...values, option.value ]
										: values.filter(
												( item ) =>
													item !== option.value
											)
								)
							}
						/>
					) ) }
				</fieldset>
			);
		}
		return (
			<TextControl
				{ ...common }
				label={ __( 'Values, separated by commas', 'pegwork' ) }
				value={ values.join( ', ' ) }
				onChange={ ( text ) =>
					set(
						text
							.split( ',' )
							.map( ( item ) => item.trim() )
							.filter( Boolean )
							.map( ( item ) =>
								type === 'number'
									? ( toNumber( item ) ?? item )
									: item
							)
					)
				}
			/>
		);
	}

	const compare = (
		<SelectControl
			{ ...common }
			label={ __( 'Compare with', 'pegwork' ) }
			value={ kind }
			options={ [
				{ value: 'literal', label: __( 'A value', 'pegwork' ) },
				{ value: 'var', label: __( 'Another field', 'pegwork' ) },
				...( type === 'date' || type === null
					? [
							{
								value: 'date',
								label: __(
									'Today or now, plus or minus',
									'pegwork'
								),
							},
						]
					: [] ),
			] }
			onChange={ ( next ) => {
				if ( next === 'var' ) {
					set( {
						var:
							fields.find( ( item ) => item.path !== leaf.var )
								?.path || '',
					} );
				} else if ( next === 'date' ) {
					set( { date: 'today' } );
				} else {
					set( blankValue( field ) );
				}
			} }
		/>
	);

	let control;
	if ( kind === 'var' ) {
		control = (
			<SelectControl
				{ ...common }
				label={ __( 'Other field', 'pegwork' ) }
				value={ leaf.value.var }
				options={ [
					...fields.map( ( item ) => ( {
						value: item.path,
						label: fieldName( item ),
					} ) ),
					...( fields.some( ( item ) => item.path === leaf.value.var )
						? []
						: [
								{
									value: leaf.value.var,
									label: leaf.value.var,
								},
							] ),
				] }
				onChange={ ( path ) => set( { var: path } ) }
			/>
		);
	} else if ( kind === 'date' ) {
		const parts = parseDuration( leaf.value.offset );
		const setOffset = ( sign, amount, unit ) => {
			const offset = formatDuration( amount, unit, sign );
			const value = { date: leaf.value.date };
			if ( offset ) {
				value.offset = offset;
			}
			set( value );
		};
		control = (
			<div className="pegwork-automation__row">
				<SelectControl
					{ ...common }
					label={ __( 'From', 'pegwork' ) }
					value={ leaf.value.date }
					options={ [
						{ value: 'today', label: __( 'Today', 'pegwork' ) },
						{ value: 'now', label: __( 'Now', 'pegwork' ) },
					] }
					onChange={ ( date ) => set( { ...leaf.value, date } ) }
				/>
				{ leaf.value.offset && ! parts ? (
					<TextControl
						{ ...common }
						label={ __( 'Offset (ISO 8601)', 'pegwork' ) }
						value={ leaf.value.offset }
						onChange={ ( offset ) =>
							set( { ...leaf.value, offset } )
						}
					/>
				) : (
					<>
						<SelectControl
							{ ...common }
							label={ __( 'Plus or minus', 'pegwork' ) }
							value={ parts?.sign === '-' ? '-' : '' }
							options={ [
								{ value: '', label: __( 'plus', 'pegwork' ) },
								{ value: '-', label: __( 'minus', 'pegwork' ) },
							] }
							onChange={ ( sign ) =>
								setOffset(
									sign,
									parts?.amount || 1,
									parts?.unit || 'days'
								)
							}
						/>
						<TextControl
							{ ...common }
							type="number"
							min={ 0 }
							label={ __( 'Amount', 'pegwork' ) }
							value={ parts ? String( parts.amount ) : '0' }
							onChange={ ( amount ) =>
								setOffset(
									parts?.sign || '',
									Number( amount ),
									parts?.unit || 'days'
								)
							}
						/>
						<SelectControl
							{ ...common }
							label={ __( 'Unit', 'pegwork' ) }
							value={ parts?.unit || 'days' }
							options={ [
								{
									value: 'hours',
									label: __( 'Hours', 'pegwork' ),
								},
								{
									value: 'days',
									label: __( 'Days', 'pegwork' ),
								},
								{
									value: 'weeks',
									label: __( 'Weeks', 'pegwork' ),
								},
								{
									value: 'months',
									label: __( 'Months', 'pegwork' ),
								},
								{
									value: 'years',
									label: __( 'Years', 'pegwork' ),
								},
							] }
							onChange={ ( unit ) =>
								setOffset(
									parts?.sign || '',
									parts?.amount || 1,
									unit
								)
							}
						/>
					</>
				) }
			</div>
		);
	} else if ( type === 'boolean' ) {
		control = (
			<SelectControl
				{ ...common }
				label={ __( 'Value', 'pegwork' ) }
				value={ leaf.value === false ? 'false' : 'true' }
				options={ [
					{ value: 'true', label: __( 'true (checked)', 'pegwork' ) },
					{
						value: 'false',
						label: __( 'false (not checked)', 'pegwork' ),
					},
				] }
				onChange={ ( value ) => set( value === 'true' ) }
			/>
		);
	} else if ( options.length ) {
		control = (
			<SelectControl
				{ ...common }
				label={ __( 'Value', 'pegwork' ) }
				value={ String( leaf.value ?? '' ) }
				options={ [
					...options.map( ( option ) => ( {
						value: option.value,
						label: option.label,
					} ) ),
					...( options.some(
						( option ) => option.value === leaf.value
					)
						? []
						: [
								{
									value: String( leaf.value ?? '' ),
									label: String( leaf.value ?? '' ),
								},
							] ),
				] }
				onChange={ set }
			/>
		);
	} else {
		control = (
			<TextControl
				{ ...common }
				label={ __( 'Value', 'pegwork' ) }
				type={ { number: 'number', date: 'date' }[ type ] || 'text' }
				value={ String( leaf.value ?? '' ) }
				onChange={ ( value ) =>
					set(
						type === 'number'
							? ( toNumber( value ) ?? value )
							: value
					)
				}
			/>
		);
	}

	return (
		<>
			{ compare }
			{ control }
		</>
	);
}

/**
 * One condition row: field, operator, value, and its remove button.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.leaf     Leaf model.
 * @param {number}   props.number   Row number, for names.
 * @param {string}   props.pointer  Pointer of the leaf.
 * @param {Object}   props.errors   Errors by pointer.
 * @param {Array}    props.fields   Fields in scope.
 * @param {?string}  props.event    Trigger event.
 * @param {Function} props.onChange Called with the new leaf.
 * @param {Function} props.onRemove Removes the row.
 */
function LeafRow( {
	leaf,
	number,
	pointer,
	errors,
	fields,
	event,
	onChange,
	onRemove,
} ) {
	const known = fields.find( ( item ) => item.path === leaf.var ) || null;
	const field = known?.type ? known : null;
	const other = ! known;
	const messages = errorsAt( errors, pointer );
	const id = controlId( pointer );
	const ops = opsFor( field, leaf.var, event );
	const name = sprintf(
		/* translators: %d: condition number. */
		__( 'Condition %d', 'pegwork' ),
		number
	);
	const words = conditionText( toJSON( leaf ), fields );
	const invalid = messages.length
		? { 'aria-invalid': true, 'aria-describedby': `${ id }-errors` }
		: {};
	const setVar = ( path ) => {
		const next = fields.find( ( item ) => item.path === path );
		const nextField = next?.type ? next : null;
		const allowed = opsFor( nextField, path, event );
		const op = allowed.includes( leaf.op ) ? leaf.op : allowed[ 0 ];
		const reset = ( next?.type ?? null ) !== ( known?.type ?? null );
		onChange(
			withOp(
				{
					...leaf,
					var: path,
					value: reset ? undefined : leaf.value,
					has: ! reset && leaf.has,
				},
				op,
				nextField
			)
		);
	};

	return (
		<fieldset className="pegwork-automation__condition">
			<legend className="screen-reader-text">{ `${ name }: ${ words }` }</legend>
			<div className="pegwork-automation__row">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Field', 'pegwork' ) }
					id={ id }
					value={ other ? OTHER : leaf.var }
					options={ [
						...fields.map( ( item ) => ( {
							value: item.path,
							label: fieldName( item ),
						} ) ),
						{
							value: OTHER,
							label: __( 'Another path…', 'pegwork' ),
						},
					] }
					onChange={ ( path ) =>
						path === OTHER ? setVar( '' ) : setVar( path )
					}
					{ ...invalid }
				/>
				{ other && (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Path', 'pegwork' ) }
						help={ __(
							'Such as trigger.payload.kind.',
							'pegwork'
						) }
						value={ leaf.var }
						onChange={ ( path ) =>
							onChange( { ...leaf, var: path } )
						}
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Operator', 'pegwork' ) }
					value={ leaf.op }
					options={ [
						...ops.map( ( op ) => ( {
							value: op,
							label: opLabel( op, field?.type ),
						} ) ),
						...( ops.includes( leaf.op )
							? []
							: [
									{
										value: leaf.op,
										label: opLabel( leaf.op ),
									},
								] ),
					] }
					onChange={ ( op ) => onChange( withOp( leaf, op, field ) ) }
				/>
				<ValueControl
					leaf={ leaf }
					field={ field }
					fields={ fields }
					onChange={ onChange }
					label={ name }
				/>
			</div>
			<Errors messages={ messages } id={ `${ id }-errors` } />
			<Button
				variant="link"
				isDestructive
				className="pegwork-automation__remove"
				onClick={ onRemove }
			>
				{
					/* translators: 1: condition number, 2: condition in words. */ sprintf(
						__( 'Remove condition %1$d: %2$s', 'pegwork' ),
						number,
						words
					)
				}
			</Button>
		</fieldset>
	);
}

/**
 * The condition builder.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    What the condition decides ("Run this branch when").
 * @param {?Object}  props.value    Condition JSON, or undefined.
 * @param {Function} props.onChange Called with the new condition (undefined when removed).
 * @param {Array}    props.fields   Fields in scope (scope.js conditionFields()).
 * @param {?string}  props.event    Trigger event (for `changed`).
 * @param {string}   props.pointer  Pointer of the condition.
 * @param {Object}   props.errors   Errors by pointer.
 * @param {string}   [props.empty]  Text shown when there is no condition.
 */
export default function ConditionBuilder( {
	label,
	value,
	onChange,
	fields,
	event,
	pointer,
	errors,
	empty,
} ) {
	const model = toModel( value );
	const { depth, nodes } = size( model );
	const set = ( next ) => onChange( toJSON( next ) );
	const full = nodes >= MAX_NODES;
	let counter = 0;

	const rootErrors = Object.keys( errors || {} )
		.filter(
			( key ) =>
				key === pointer ||
				key === `${ pointer }/all` ||
				key === `${ pointer }/any`
		)
		.map( ( key ) => errors[ key ] );

	// The model without the node at a path. Removing the only child of a Not removes the Not too.
	const without = ( path ) => {
		if ( ! path.length ) {
			return null;
		}
		const parentPath = path.slice( 0, -1 );
		if ( at( model, parentPath ).kind === 'not' ) {
			return without( parentPath );
		}
		return replace( model, parentPath, ( group ) => ( {
			...group,
			items: group.items.filter(
				( item, i ) => i !== path[ path.length - 1 ]
			),
		} ) );
	};

	const render = ( node, path, nodePointer, level, index ) => {
		if ( ! node ) {
			return null;
		}
		const remove = () => set( without( path ) );

		if ( node.kind === 'leaf' ) {
			counter += 1;
			return (
				<LeafRow
					key={ nodePointer }
					leaf={ node }
					number={ counter }
					pointer={ nodePointer }
					errors={ errors }
					fields={ fields }
					event={ event }
					onChange={ ( next ) =>
						set( replace( model, path, () => next ) )
					}
					onRemove={ remove }
				/>
			);
		}

		// A Not around a group shows as one group ("None of these are true"); around a leaf, as its own.
		const negated = node.kind === 'not';
		const group = negated ? node.item : node;
		const groupPath = negated ? [ ...path, 'item' ] : path;
		const groupPointer = negated ? `${ nodePointer }/not` : nodePointer;
		const groupLevel = negated ? level + 1 : level;
		if (
			negated &&
			( ! group || group.kind === 'leaf' || group.kind === 'not' )
		) {
			return (
				<fieldset
					key={ nodePointer }
					className="pegwork-automation__group"
				>
					<legend>{ legendOf( 'not' ) }</legend>
					{ render( group, groupPath, groupPointer, groupLevel, 0 ) }
					<Button
						variant="link"
						onClick={ () =>
							set( replace( model, path, () => group ) )
						}
					>
						{ __( 'Remove Not', 'pegwork' ) }
					</Button>
				</fieldset>
			);
		}
		const match = ( negated ? 'not_' : '' ) + group.kind;
		const canNest = groupLevel + 2 <= MAX_DEPTH && nodes + 2 <= MAX_NODES;
		const legend = legendOf( match );
		return (
			<fieldset key={ nodePointer } className="pegwork-automation__group">
				<legend>{ legend }</legend>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Match', 'pegwork' ) }
					value={ match }
					options={ [ 'all', 'any', 'not_all', 'not_any' ].map(
						( option ) => ( {
							value: option,
							label: legendOf( option ),
						} )
					) }
					onChange={ ( next ) => {
						const kind = next.replace( 'not_', '' );
						const inner = { kind, items: group.items };
						set(
							replace( model, path, () =>
								next.startsWith( 'not_' )
									? { kind: 'not', item: inner }
									: inner
							)
						);
					} }
				/>
				<div className="pegwork-automation__group-items">
					{ group.items.map( ( child, i ) =>
						render(
							child,
							[ ...groupPath, i ],
							`${ groupPointer }/${ group.kind }/${ i }`,
							groupLevel + 1,
							i
						)
					) }
				</div>
				<div className="pegwork-automation__actions">
					<Button
						variant="secondary"
						disabled={ full || groupLevel + 1 > MAX_DEPTH }
						onClick={ () =>
							set(
								replace( model, groupPath, ( g ) => ( {
									...g,
									items: [ ...g.items, newLeaf( fields ) ],
								} ) )
							)
						}
					>
						{ __( 'Add condition', 'pegwork' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={ ! canNest }
						onClick={ () =>
							set(
								replace( model, groupPath, ( g ) => ( {
									...g,
									items: [
										...g.items,
										{
											kind:
												g.kind === 'all'
													? 'any'
													: 'all',
											items: [ newLeaf( fields ) ],
										},
									],
								} ) )
							)
						}
					>
						{ __( 'Add group', 'pegwork' ) }
					</Button>
					{ path.length > 0 && (
						<Button variant="link" isDestructive onClick={ remove }>
							{
								/* translators: 1: group number, 2: group legend. */ sprintf(
									__( 'Remove group %1$d: %2$s', 'pegwork' ),
									index + 1,
									legend
								)
							}
						</Button>
					) }
				</div>
			</fieldset>
		);
	};

	return (
		<div className="pegwork-automation__conditions">
			<p className="pegwork-automation__conditions-label">
				<strong>{ label }</strong>
			</p>
			<Errors
				messages={ rootErrors }
				id={ `${ controlId( pointer ) }-errors` }
			/>
			{ ! model && (
				<p className="pegwork-automation__help">
					{ empty || __( 'No condition yet.', 'pegwork' ) }
				</p>
			) }
			{ render( model, [], pointer, 1, 0 ) }
			{ model?.kind === 'leaf' && (
				<Button
					variant="secondary"
					onClick={ () =>
						set( {
							kind: 'all',
							items: [ model, newLeaf( fields ) ],
						} )
					}
				>
					{ __( 'Add condition', 'pegwork' ) }
				</Button>
			) }
			{ ! model && (
				<Button
					variant="secondary"
					id={ controlId( pointer ) }
					onClick={ () => set( newLeaf( fields ) ) }
				>
					{ __( 'Add condition', 'pegwork' ) }
				</Button>
			) }
			{ ( depth >= MAX_DEPTH || full ) && (
				<p className="pegwork-automation__help">
					{
						/* translators: 1: maximum depth, 2: maximum conditions. */ sprintf(
							__(
								'Conditions nest at most %1$d levels deep, with at most %2$d parts.',
								'pegwork'
							),
							MAX_DEPTH,
							MAX_NODES
						)
					}
				</p>
			) }
		</div>
	);
}
