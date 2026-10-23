/**
 * Edit UI of pegwork/automation-decision and pegwork/automation-branch: a decision card over its
 * branches ("If <condition in words>", "Otherwise" kept last), "Then continue below", Add branch,
 * Add and Remove otherwise on the toolbar, appenders named "Add step to <branch>", and the branch
 * inspector (name and condition).
 */

/**
 * WordPress dependencies
 */
import {
	BlockControls,
	Inserter,
	InspectorControls,
	PlainText,
	store as blockEditorStore,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import {
	Button,
	Dashicon,
	Disabled,
	PanelBody,
	TextControl,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ConditionBuilder from './condition-builder';
import { Errors, wire } from './controls';
import { conditionText } from './labels';
import { BRANCH, DECISION, MAX_BRANCHES, STEP } from './logic';
import MoveToBranch from './move';
import { conditionFields } from './scope';
import { Advanced, useStepId } from './step-edit';
import { config, useDefinition, useErrorOwners } from './store';

/**
 * Attributes of a new otherwise branch: flagged, and locked in place (Builder::OTHERWISE).
 */
export const OTHERWISE = {
	otherwise: true,
	lock: { move: true, remove: true },
};

/**
 * A branch label no other branch of the decision uses ("Path 3").
 *
 * @param {Array} branches Branch blocks.
 * @return {string} Label.
 */
function freeLabel( branches ) {
	const used = new Set(
		branches.map( ( branch ) => branch.attributes.label )
	);
	let n =
		branches.filter( ( branch ) => ! branch.attributes.otherwise ).length +
		1;
	/* translators: %d: branch number. */
	const label = ( number ) => sprintf( __( 'Path %d', 'pegwork' ), number );
	while ( used.has( label( n ) ) ) {
		n++;
	}
	return label( n );
}

/**
 * Dispatchers for a decision's branches.
 *
 * @param {string} clientId Decision block.
 * @return {Object} `branches`, `otherwise` (block or undefined), `addBranch`, `addOtherwise`, `removeOtherwise`.
 */
function useBranches( clientId ) {
	const branches = useSelect(
		( select ) => select( blockEditorStore ).getBlocks( clientId ),
		[ clientId ]
	);
	const { insertBlock, removeBlock, updateBlockAttributes } =
		useDispatch( blockEditorStore );
	const otherwise = branches.find(
		( branch ) => branch.attributes.otherwise
	);
	const count = branches.length - ( otherwise ? 1 : 0 );
	return {
		branches,
		otherwise,
		count,
		addBranch: () =>
			insertBlock(
				createBlock( BRANCH, { label: freeLabel( branches ) } ),
				count,
				clientId
			),
		addOtherwise: () =>
			insertBlock(
				createBlock( BRANCH, OTHERWISE ),
				branches.length,
				clientId
			),
		removeOtherwise: () => {
			if ( otherwise ) {
				updateBlockAttributes( otherwise.clientId, {
					lock: undefined,
				} );
				removeBlock( otherwise.clientId, false );
			}
		},
	};
}

/**
 * The toolbar's Add otherwise or Remove otherwise.
 *
 * @param {Object} props          Props.
 * @param {string} props.clientId Decision block.
 */
function OtherwiseToolbar( { clientId } ) {
	const { otherwise, addOtherwise, removeOtherwise } =
		useBranches( clientId );
	return (
		<BlockControls group="other">
			<ToolbarGroup>
				<ToolbarButton
					onClick={ otherwise ? removeOtherwise : addOtherwise }
				>
					{ otherwise
						? __( 'Remove otherwise', 'pegwork' )
						: __( 'Add otherwise', 'pegwork' ) }
				</ToolbarButton>
			</ToolbarGroup>
		</BlockControls>
	);
}

/**
 * Decision edit component.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes (`id`).
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.clientId      Block client ID.
 * @param {boolean}  props.isSelected    Whether the block is selected.
 */
export function DecisionEdit( {
	attributes,
	setAttributes,
	clientId,
	isSelected,
} ) {
	useStepId( clientId, attributes.id, 'decision', setAttributes );
	const { pointers } = useDefinition();
	const pointer = pointers[ clientId ] || '/steps/0';
	const errors = useErrorOwners().blocks[ clientId ] || {};
	const messages = Object.values( errors );
	const { branches, otherwise, count, addBranch } = useBranches( clientId );
	const { moveBlockToPosition } = useDispatch( blockEditorStore );

	// The otherwise branch stays last whatever moves around it.
	const last = branches[ branches.length - 1 ];
	useEffect( () => {
		if (
			otherwise &&
			last &&
			last.clientId !== otherwise.clientId &&
			! config.readOnly
		) {
			moveBlockToPosition(
				otherwise.clientId,
				clientId,
				clientId,
				branches.length - 1
			);
		}
	}, [ otherwise, last, branches.length, clientId, moveBlockToPosition ] );

	const blockProps = useBlockProps( {
		className: `pegwork-automation-decision${ messages.length ? ' has-error' : '' }`,
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'pegwork-automation-decision__branches' },
		{
			allowedBlocks: [ BRANCH ],
			renderAppender: () =>
				config.readOnly || count >= MAX_BRANCHES ? null : (
					<Button
						variant="secondary"
						icon="plus"
						className="pegwork-automation__appender"
						onClick={ addBranch }
					>
						{
							/* translators: %s: decision id. */ sprintf(
								__( 'Add branch to %s', 'pegwork' ),
								attributes.id || __( 'decision', 'pegwork' )
							)
						}
					</Button>
				),
		}
	);
	const set = ( name, value ) =>
		setAttributes( { [ name ]: value === '' ? undefined : value } );

	return (
		<div { ...blockProps }>
			<p className="pegwork-automation-decision__heading">
				<Dashicon icon="randomize" />
				<strong>{ __( 'Decision', 'pegwork' ) }</strong>{ ' ' }
				<code>{ attributes.id }</code>
				<span className="pegwork-automation-step__summary">
					{ __(
						'Runs the first branch whose condition holds.',
						'pegwork'
					) }
				</span>
			</p>
			<Errors messages={ messages } />
			<div { ...innerBlocksProps } />
			<p className="pegwork-automation-decision__then">
				{ __( 'Then continue below', 'pegwork' ) }
			</p>
			{ isSelected && ! config.readOnly && (
				<>
					<OtherwiseToolbar clientId={ clientId } />
					<MoveToBranch clientId={ clientId } />
				</>
			) }
			<InspectorControls>
				<Errors messages={ messages } />
				{ config.readOnly ? (
					<Disabled>
						<Advanced
							attributes={ attributes }
							set={ set }
							pointer={ pointer }
							errors={ errors }
							retry={ false }
						/>
					</Disabled>
				) : (
					<Advanced
						attributes={ attributes }
						set={ set }
						pointer={ pointer }
						errors={ errors }
						retry={ false }
					/>
				) }
			</InspectorControls>
		</div>
	);
}

/**
 * The appender of a branch: the quick inserter behind a button named for the branch.
 *
 * @param {Object} props          Props.
 * @param {string} props.clientId Branch block.
 * @param {string} props.name     Branch name.
 */
function AddStep( { clientId, name } ) {
	return (
		<Inserter
			rootClientId={ clientId }
			isAppender
			__experimentalIsQuick
			renderToggle={ ( { onToggle, disabled, isOpen } ) => (
				<Button
					variant="secondary"
					icon="plus"
					className="pegwork-automation__appender"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					disabled={ disabled }
				>
					{
						/* translators: %s: branch name. */ sprintf(
							__( 'Add step to %s', 'pegwork' ),
							name
						)
					}
				</Button>
			) }
		/>
	);
}

/**
 * Branch edit component.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes (`label`, `when`, `otherwise`).
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.clientId      Block client ID.
 * @param {boolean}  props.isSelected    Whether the block is selected.
 */
export function BranchEdit( {
	attributes,
	setAttributes,
	clientId,
	isSelected,
} ) {
	const { definition, pointers } = useDefinition();
	const pointer = pointers[ clientId ] || '/steps/0/branches/0';
	const errors = useErrorOwners().blocks[ clientId ] || {};
	const messages = Object.values( errors );
	const decision = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockRootClientId( clientId ),
		[ clientId ]
	);
	const fields = conditionFields( definition, pointer, config );
	const name = attributes.otherwise
		? __( 'Otherwise', 'pegwork' )
		: attributes.label || __( '(unnamed branch)', 'pegwork' );
	const words = attributes.when
		? conditionText( attributes.when, fields )
		: __( '(no condition yet)', 'pegwork' );
	const label = wire( errors, `${ pointer }/label` );

	const blockProps = useBlockProps( {
		className: `pegwork-automation-branch${ attributes.otherwise ? ' is-otherwise' : '' }${ messages.length ? ' has-error' : '' }`,
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'pegwork-automation-branch__steps' },
		{
			allowedBlocks: [ STEP, DECISION ],
			renderAppender: config.readOnly
				? false
				: () => <AddStep clientId={ clientId } name={ name } />,
		}
	);

	const settings = attributes.otherwise ? (
		<PanelBody title={ __( 'Otherwise', 'pegwork' ) }>
			<p className="pegwork-automation__help">
				{ __(
					'Runs when no branch above matches. Remove it from the decision toolbar.',
					'pegwork'
				) }
			</p>
		</PanelBody>
	) : (
		<PanelBody title={ __( 'Branch', 'pegwork' ) }>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Branch name', 'pegwork' ) }
				help={ __(
					'Shown in the run log. Unique within the decision.',
					'pegwork'
				) }
				value={ attributes.label ?? '' }
				onChange={ ( value ) => setAttributes( { label: value } ) }
				{ ...wire( errors, `${ pointer }/label`, true ).props }
			/>
			<Errors messages={ label.messages } id={ `${ label.id }-errors` } />
			<ConditionBuilder
				label={ __( 'Run this branch when', 'pegwork' ) }
				empty={ __( 'Every branch needs a condition.', 'pegwork' ) }
				value={ attributes.when }
				onChange={ ( when ) => setAttributes( { when } ) }
				fields={ fields }
				event={ definition.trigger?.event ?? null }
				pointer={ `${ pointer }/when` }
				errors={ errors }
			/>
		</PanelBody>
	);

	return (
		<div { ...blockProps }>
			<div className="pegwork-automation-branch__head">
				<p className="pegwork-automation-branch__heading">
					{ attributes.otherwise
						? __( 'Otherwise', 'pegwork' )
						: /* translators: %s: condition in words. */ sprintf(
								__( 'If %s', 'pegwork' ),
								words
							) }
				</p>
				{ ! attributes.otherwise &&
					( config.readOnly ? (
						<span className="pegwork-automation-branch__label">
							{ attributes.label }
						</span>
					) : (
						<PlainText
							className="pegwork-automation-branch__label"
							value={ attributes.label ?? '' }
							placeholder={ __( 'Branch name', 'pegwork' ) }
							onChange={ ( value ) =>
								setAttributes( {
									label: value.replace( /[\r\n]+/g, ' ' ),
								} )
							}
							aria-label={ __( 'Branch name', 'pegwork' ) }
						/>
					) ) }
			</div>
			<Errors messages={ messages } />
			<div { ...innerBlocksProps } />
			{ isSelected &&
				attributes.otherwise &&
				! config.readOnly &&
				decision && <OtherwiseToolbar clientId={ decision } /> }
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
