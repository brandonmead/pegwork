/**
 * Inspector fields of Move to trash (content type and which record: the bound record, a read's
 * record or a loop's item) and Start another automation (an enabled manual automation, and the
 * record it runs on).
 */

/**
 * WordPress dependencies
 */
import { RadioControl, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Errors, TemplateControl, wire } from './controls';
import { recordName, typeName } from './labels';
import { binding, conditionFields, recordTargets } from './scope';
import { config } from './store';

/**
 * Move to trash.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Step attributes.
 * @param {Function} props.setAttributes Sets several attributes.
 * @param {string}   props.pointer       Pointer of the step.
 * @param {Object}   props.errors        Errors by pointer.
 * @param {Object}   props.definition    Edited definition.
 */
export function TrashFields( {
	attributes,
	setAttributes,
	pointer,
	errors,
	definition,
} ) {
	const fields = conditionFields( definition, pointer, config );
	const targets = recordTargets( fields );
	const type = attributes.content_type || '';
	const record = attributes.record ?? '';
	const typeWire = wire( errors, `${ pointer }/content_type` );
	const options = targets
		.filter( ( target ) => ! type || target.type === type )
		.map( ( target ) => ( {
			value: target.value,
			label: target.value
				? recordName( target.value, fields, config )
				: /* translators: %s: content type label. */ sprintf(
						__( 'This %s', 'pegwork' ),
						typeName( config, target.type )
					),
		} ) );
	if ( record && ! options.some( ( option ) => option.value === record ) ) {
		options.push( { value: record, label: record } );
	}
	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Content type', 'pegwork' ) }
				value={ type }
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
				onChange={ ( value ) =>
					setAttributes( {
						content_type: value || undefined,
						// A record of another type no longer fits.
						record: targets.some(
							( target ) =>
								target.value === record && target.type !== value
						)
							? undefined
							: attributes.record,
					} )
				}
				{ ...typeWire.props }
			/>
			<Errors
				messages={ typeWire.messages }
				id={ `${ typeWire.id }-errors` }
			/>
			{ options.length ? (
				<RadioControl
					label={ __( 'Which record', 'pegwork' ) }
					selected={ record }
					options={ options }
					onChange={ ( value ) =>
						setAttributes( {
							record: value || undefined,
							content_type:
								targets.find(
									( target ) => target.value === value
								)?.type || attributes.content_type,
						} )
					}
				/>
			) : (
				<p className="pegwork-automation__help">
					{ __(
						'No record of this type is in reach here. Get a record, or Find records and Repeat for each, gives one.',
						'pegwork'
					) }
				</p>
			) }
			<Errors
				messages={ Object.keys( errors )
					.filter(
						( key ) =>
							key === pointer || key === `${ pointer }/record`
					)
					.map( ( key ) => errors[ key ] ) }
			/>
			<p className="pegwork-automation__help">
				{ __(
					'Moves the record to the trash. WordPress deletes trashed items for good after 30 days (EMPTY_TRASH_DAYS). At most 50 per run.',
					'pegwork'
				) }
			</p>
		</>
	);
}

/**
 * Start another automation.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.attributes Step attributes.
 * @param {Function} props.set        Sets one attribute.
 * @param {string}   props.pointer    Pointer of the step.
 * @param {Object}   props.errors     Errors by pointer.
 * @param {Object}   props.definition Edited definition.
 * @param {Array}    props.tags       Merge tags in scope at the step.
 */
export function StartFields( {
	attributes,
	set,
	pointer,
	errors,
	definition,
	tags,
} ) {
	const own = wire( errors, `${ pointer }/automation`, true );
	const key = attributes.automation ?? '';
	const list = config.automations.filter(
		( item ) => item.key !== config.lockedKey
	);
	const target = list.find( ( item ) => item.key === key );
	const bound = binding( definition.trigger, config ).type;
	const options = [
		{ value: '', label: __( 'Choose an automation', 'pegwork' ) },
		...list.map( ( item ) => ( {
			value: item.key,
			label: item.content_type
				? /* translators: 1: automation label, 2: content type label. */ sprintf(
						__( '%1$s: on each %2$s', 'pegwork' ),
						item.label,
						typeName( config, item.content_type )
					)
				: /* translators: %s: automation label. */ sprintf(
						__( '%s: no record', 'pegwork' ),
						item.label
					),
		} ) ),
	];
	if ( key && ! target ) {
		options.push( { value: key, label: key } );
	}
	const type = target?.content_type;
	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Automation', 'pegwork' ) }
				help={ __(
					'Enabled automations with a manual trigger. It runs separately, as its own run. This automation does not wait for it.',
					'pegwork'
				) }
				value={ key }
				options={ options }
				onChange={ ( value ) => set( 'automation', value ) }
				{ ...own.props }
			/>
			<Errors messages={ own.messages } id={ `${ own.id }-errors` } />
			{ ( type || attributes.record ) && (
				<TemplateControl
					label={ __( 'For record', 'pegwork' ) }
					help={
						type === bound
							? /* translators: %s: content type label. */ sprintf(
									__( 'Empty: this %s.', 'pegwork' ),
									typeName( config, type )
								)
							: /* translators: %s: content type label. */ sprintf(
									__(
										'The ID of a %s record, such as {{record.id}}.',
										'pegwork'
									),
									typeName( config, type )
								)
					}
					value={ attributes.record ?? '' }
					onChange={ ( value ) => set( 'record', value ) }
					pointer={ `${ pointer }/record` }
					errors={ errors }
					tags={ tags }
				/>
			) }
			<Errors
				messages={ errors[ pointer ] ? [ errors[ pointer ] ] : [] }
			/>
		</>
	);
}
