/**
 * Edit UI of pegwork/automation-loop, "Repeat for each": a card ("For each Task from due (at most
 * 50)") over its body, the appender "Add step for each item", "Then continue below", Move to, and
 * the inspector ("For each" over the lists of records in scope, Advanced with the id).
 */

/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	Dashicon,
	Disabled,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Errors, Refused, wire } from './controls';
import { AddStep } from './decision-edit';
import { listName, loopSummary, typeLabel } from './labels';
import { DECISION, STEP } from './logic';
import MoveToBranch from './move';
import { collections, loopsAround, stepsBefore } from './scope';
import { Advanced, useStepId } from './step-edit';
import { config, useDefinition, useErrorOwners } from './store';

/**
 * Loop edit component.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes (`id`, `over`).
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.clientId      Block client ID.
 * @param {boolean}  props.isSelected    Whether the block is selected.
 */
export default function LoopEdit( {
	attributes,
	setAttributes,
	clientId,
	isSelected,
} ) {
	useStepId( clientId, attributes.id, 'loop', setAttributes );
	const { definition, pointers } = useDefinition();
	const pointer = pointers[ clientId ] || '/steps/0';
	const errors = useErrorOwners().blocks[ clientId ] || {};
	const messages = Object.values( errors );
	const lists = collections( stepsBefore( definition.steps, pointer ) );
	const over = attributes.over ?? '';
	const find = lists.find(
		( step ) => `steps.${ step.id }.records` === over
	);
	// A loop pasted or moved into another loop's body.
	const nested = loopsAround( definition.steps, pointer ).length > 0;
	const control = wire( errors, `${ pointer }/over`, true );
	// Stable between renders, like a branch's (core renders renderAppender as a component).
	const appender = useCallback(
		() => (
			<AddStep
				clientId={ clientId }
				label={ __( 'Add step for each item', 'pegwork' ) }
			/>
		),
		[ clientId ]
	);

	const blockProps = useBlockProps( {
		className: `pegwork-automation-decision pegwork-automation-loop${ messages.length || nested ? ' has-error' : '' }`,
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{
			className:
				'pegwork-automation-branch pegwork-automation-loop__steps',
		},
		{
			allowedBlocks: [ STEP, DECISION ],
			renderAppender: config.readOnly ? false : appender,
		}
	);
	const set = ( name, value ) =>
		setAttributes( { [ name ]: value === '' ? undefined : value } );

	const settings = (
		<>
			<PanelBody title={ typeLabel( 'loop' ) }>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'For each', 'pegwork' ) }
					help={ __(
						'The records a Find records step before this loaded (at most 50). The steps inside run once for each, in order; a wait or another loop cannot go inside.',
						'pegwork'
					) }
					value={ over }
					options={ [
						{
							value: '',
							label: lists.length
								? __( 'Choose a list of records', 'pegwork' )
								: __(
										'No Find records step runs before this',
										'pegwork'
									),
						},
						...( over && ! find
							? [ { value: over, label: over } ]
							: [] ),
						...lists.map( ( step ) => ( {
							value: `steps.${ step.id }.records`,
							label: listName( step, config ),
						} ) ),
					] }
					onChange={ ( value ) => set( 'over', value ) }
					{ ...control.props }
				/>
				<Errors
					messages={ control.messages }
					id={ `${ control.id }-errors` }
				/>
			</PanelBody>
			<Advanced
				attributes={ attributes }
				set={ set }
				pointer={ pointer }
				errors={ errors }
				retry={ false }
			/>
		</>
	);

	return (
		<div { ...blockProps }>
			<p className="pegwork-automation-decision__heading">
				<Dashicon icon="update-alt" />
				<strong>{ typeLabel( 'loop' ) }</strong>{ ' ' }
				<code>{ attributes.id }</code>
				<span className="pegwork-automation-step__summary">
					{ loopSummary( find, config ) ||
						__( 'Choose the records to repeat for.', 'pegwork' ) }
				</span>
			</p>
			{ nested && (
				<Refused
					message={ __(
						'A loop cannot hold another loop.',
						'pegwork'
					) }
					focus={ isSelected }
				/>
			) }
			<Errors messages={ messages } />
			<div { ...innerBlocksProps } />
			<p className="pegwork-automation-decision__then">
				{ __( 'Then continue below', 'pegwork' ) }
			</p>
			{ isSelected && ! config.readOnly && (
				<MoveToBranch clientId={ clientId } />
			) }
			<InspectorControls>
				<Errors messages={ messages } />
				{ config.readOnly ? (
					<Disabled>{ settings }</Disabled>
				) : (
					settings
				) }
			</InspectorControls>
		</div>
	);
}
