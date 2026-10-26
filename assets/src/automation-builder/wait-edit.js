/**
 * The Wait inspector: for a length of time (`for`), or until a date field of the bound record
 * (`until`: the field, an offset before or after it, and a time of day).
 */

/**
 * WordPress dependencies
 */
import { SelectControl, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DurationControl, Errors, controlId, wire } from './controls';
import { dateFields } from './scope';
import { config } from './store';

const AT = '09:00';

/**
 * `until` with one property changed, in the definition's order (var, offset, at).
 *
 * @param {Object}  until Current value.
 * @param {string}  key   Property.
 * @param {?string} value New value (undefined removes it).
 * @return {Object} New value.
 */
export function withUntil( until, key, value ) {
	const next = { ...until, [ key ]: value };
	return Object.fromEntries(
		[ 'var', 'offset', 'at' ]
			.filter( ( name ) => next[ name ] !== undefined )
			.map( ( name ) => [ name, next[ name ] ] )
	);
}

/**
 * Inspector fields of a wait step.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Step attributes.
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.pointer       Pointer of the step.
 * @param {Object}   props.errors        Errors by pointer.
 * @param {Object}   props.definition    Edited definition.
 */
export default function WaitFields( {
	attributes,
	setAttributes,
	pointer,
	errors,
	definition,
} ) {
	const until = attributes.until;
	const dates = dateFields( definition, config );
	const name = controlId( `${ pointer }/wait` );
	const reason = dates.length
		? ''
		: __(
				'Needs a trigger with a record of one content type that has a date field.',
				'pegwork'
			);
	const set = ( key, value ) =>
		setAttributes( { until: withUntil( until, key, value ) } );
	const field = wire( errors, `${ pointer }/until/var` );
	const at = wire( errors, `${ pointer }/until/at`, true );
	// The offset as a direction and an unsigned duration (DurationControl edits the duration).
	const offset = until?.offset ?? '';
	const mark = /^[-+]/.test( offset ) ? offset[ 0 ] : '';
	const bare = offset.slice( mark.length );
	let direction = 'after';
	if ( ! offset ) {
		direction = 'on';
	} else if ( mark === '-' ) {
		direction = 'before';
	}
	const options = dates.map( ( item ) => ( {
		value: item.path,
		label: item.private
			? sprintf(
					/* translators: %s: field label. */
					__( '%s (private)', 'pegwork' ),
					item.label
				)
			: item.label,
	} ) );
	if ( until?.var && ! dates.some( ( item ) => item.path === until.var ) ) {
		options.unshift( { value: until.var, label: until.var } );
	}

	return (
		<>
			<fieldset className="pegwork-automation__fieldset">
				<legend>{ __( 'Wait', 'pegwork' ) }</legend>
				{ [
					[ 'for', __( 'For a length of time', 'pegwork' ) ],
					[ 'until', __( 'Until a date', 'pegwork' ) ],
				].map( ( [ value, label ] ) => (
					<div key={ value } className="pegwork-automation__radio">
						<input
							type="radio"
							id={ `${ name }-${ value }` }
							name={ name }
							checked={ ( until ? 'until' : 'for' ) === value }
							disabled={
								value === 'until' && ! until && ! dates.length
							}
							aria-describedby={
								value === 'until' && reason
									? `${ name }-reason`
									: undefined
							}
							onChange={ () =>
								setAttributes(
									value === 'until'
										? {
												for: undefined,
												until: {
													var: dates[ 0 ]?.path ?? '',
													at: AT,
												},
											}
										: { until: undefined, for: 'P1D' }
								)
							}
						/>
						<label htmlFor={ `${ name }-${ value }` }>
							{ label }
						</label>
					</div>
				) ) }
				{ reason && (
					<p
						className="pegwork-automation__help"
						id={ `${ name }-reason` }
					>
						{ reason }
					</p>
				) }
			</fieldset>
			{ ! until && (
				<DurationControl
					label={ __( 'Wait for', 'pegwork' ) }
					help={ __( 'From 1 hour to 2 years.', 'pegwork' ) }
					value={ attributes.for }
					onChange={ ( value ) =>
						setAttributes( { for: value || undefined } )
					}
					pointer={ `${ pointer }/for` }
					errors={ errors }
				/>
			) }
			{ until && (
				<>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Date field', 'pegwork' ) }
						value={ until.var ?? '' }
						options={ options }
						onChange={ ( value ) => set( 'var', value ) }
						{ ...field.props }
					/>
					<Errors
						messages={ field.messages }
						id={ `${ field.id }-errors` }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Before or after', 'pegwork' ) }
						value={ direction }
						options={ [
							{
								value: 'before',
								label: __( 'Before', 'pegwork' ),
							},
							{
								value: 'on',
								label: __( 'On the day', 'pegwork' ),
							},
							{ value: 'after', label: __( 'After', 'pegwork' ) },
						] }
						onChange={ ( value ) =>
							set(
								'offset',
								value === 'on'
									? undefined
									: `${ value === 'before' ? '-' : '' }${
											bare || 'P1D'
										}`
							)
						}
					/>
					{ direction !== 'on' && (
						<DurationControl
							label={ __( 'How long', 'pegwork' ) }
							value={ bare }
							onChange={ ( value ) =>
								set( 'offset', `${ mark }${ value || 'P0D' }` )
							}
							pointer={ `${ pointer }/until/offset` }
							errors={ errors }
						/>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="time"
						label={ __( 'At', 'pegwork' ) }
						help={ __(
							'Time of day on the date, in site time, before the offset applies.',
							'pegwork'
						) }
						value={ until.at ?? AT }
						onChange={ ( value ) =>
							set( 'at', value || undefined )
						}
						{ ...at.props }
					/>
					<Errors
						messages={ at.messages }
						id={ `${ at.id }-errors` }
					/>
				</>
			) }
		</>
	);
}
