/**
 * Automation builder script, loaded only in the block editor of `pegwork_automation` posts.
 *
 * Phase 6 PR 3 placeholder: registers the step, decision and branch blocks with plain read-only
 * cards, so a generated post opens, nests and saves unchanged, and locks saving of file-defined
 * automations with a "Duplicate as site automation" notice. The editing UI (variations, inspector,
 * trigger picker, condition builder) is PR 5.
 */

/**
 * WordPress dependencies
 */
import {
	InnerBlocks,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import stepMetadata from '../../../modules/automation/blocks/automation-step/block.json';
import decisionMetadata from '../../../modules/automation/blocks/automation-decision/block.json';
import branchMetadata from '../../../modules/automation/blocks/automation-branch/block.json';
import './builder.scss';

const STEP = stepMetadata.name;
const DECISION = decisionMetadata.name;
const BRANCH = branchMetadata.name;

const raw = window.pegworkAutomationBuilder || {};
const config = {
	readOnly: raw.readOnly === true,
	package: typeof raw.package === 'string' ? raw.package : '',
	duplicateUrl: typeof raw.duplicateUrl === 'string' ? raw.duplicateUrl : '',
};

/**
 * A step type's name for people.
 *
 * @param {string} type Step type.
 * @return {string} Label.
 */
function typeLabel( type ) {
	switch ( type ) {
		case 'send_email':
			return __( 'Send email', 'pegwork' );
		case 'update_record':
			return __( 'Update record', 'pegwork' );
		case 'create_record':
			return __( 'Create record', 'pegwork' );
		case 'call_webhook':
			return __( 'Call webhook', 'pegwork' );
		case 'wait':
			return __( 'Wait', 'pegwork' );
		case 'condition':
			return __( 'Continue only if', 'pegwork' );
		default:
			return type || __( 'Step', 'pegwork' );
	}
}

/**
 * Placeholder card of a step: its type and id.
 *
 * @param {Object} props            Props.
 * @param {Object} props.attributes The step.
 */
function StepEdit( { attributes } ) {
	const blockProps = useBlockProps( {
		className: 'pegwork-automation-step',
	} );
	return (
		<div { ...blockProps }>
			<strong>{ typeLabel( attributes.type ) }</strong>{ ' ' }
			<code>{ attributes.id }</code>
		</div>
	);
}

/**
 * Placeholder card of a decision: a heading over its branches.
 *
 * @param {Object} props            Props.
 * @param {Object} props.attributes Decision attributes (`id`).
 */
function DecisionEdit( { attributes } ) {
	const blockProps = useBlockProps( {
		className: 'pegwork-automation-decision',
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'pegwork-automation-decision__branches' },
		{ allowedBlocks: [ BRANCH ], renderAppender: false }
	);
	return (
		<div { ...blockProps }>
			<p className="pegwork-automation-decision__heading">
				<strong>{ __( 'Decision', 'pegwork' ) }</strong>{ ' ' }
				<code>{ attributes.id }</code>
			</p>
			<div { ...innerBlocksProps } />
			<p className="pegwork-automation-decision__then">
				{ __( 'Then continue below', 'pegwork' ) }
			</p>
		</div>
	);
}

/**
 * Placeholder card of a branch: "If <label>" or "Otherwise" over its steps.
 *
 * @param {Object} props            Props.
 * @param {Object} props.attributes Branch attributes (`label`, `when`, `otherwise`).
 */
function BranchEdit( { attributes } ) {
	const blockProps = useBlockProps( {
		className: 'pegwork-automation-branch',
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'pegwork-automation-branch__steps' },
		// No appender until PR 5: a placeholder step has no type or fields to fill in.
		{ allowedBlocks: [ STEP, DECISION ], renderAppender: false }
	);
	return (
		<div { ...blockProps }>
			<p className="pegwork-automation-branch__heading">
				{ attributes.otherwise
					? __( 'Otherwise', 'pegwork' )
					: sprintf(
							/* translators: %s: branch label. */
							__( 'If %s', 'pegwork' ),
							attributes.label || ''
						) }
			</p>
			<div { ...innerBlocksProps } />
		</div>
	);
}

registerBlockType( stepMetadata, {
	title: __( 'Automation step', 'pegwork' ),
	description: __( 'One step of the automation.', 'pegwork' ),
	edit: StepEdit,
	save: () => null,
} );

registerBlockType( decisionMetadata, {
	title: __( 'Decision', 'pegwork' ),
	description: __(
		'Runs the first branch whose condition holds, else Otherwise, then continues below.',
		'pegwork'
	),
	edit: DecisionEdit,
	save: () => <InnerBlocks.Content />,
} );

registerBlockType( branchMetadata, {
	title: __( 'Branch', 'pegwork' ),
	description: __( 'One branch of a decision.', 'pegwork' ),
	edit: BranchEdit,
	save: () => <InnerBlocks.Content />,
} );

/**
 * File-defined automations: saving is locked and a notice offers "Duplicate as site automation".
 */
function ReadOnly() {
	const { lockPostSaving } = useDispatch( editorStore );
	const { createWarningNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		if ( ! config.readOnly ) {
			return;
		}
		lockPostSaving( 'pegwork-file-automation' );
		createWarningNotice(
			sprintf(
				/* translators: %s: package ID. */
				__(
					'This automation comes from the file package "%s" and is read-only. Duplicate it to make a site automation you can change.',
					'pegwork'
				),
				config.package
			),
			{
				id: 'pegwork-file-automation',
				isDismissible: false,
				actions: config.duplicateUrl
					? [
							{
								label: __(
									'Duplicate as site automation',
									'pegwork'
								),
								url: config.duplicateUrl,
							},
						]
					: [],
			}
		);
	}, [ lockPostSaving, createWarningNotice ] );

	return null;
}

/**
 * Removes the Summary panel's status rows: an automation post is always private and has no public
 * URL or publish date.
 */
function PrivatePanels() {
	const { removeEditorPanel } = useDispatch( editorStore );

	useEffect( () => {
		removeEditorPanel( 'post-status' );
	}, [ removeEditorPanel ] );

	return null;
}

registerPlugin( 'pegwork-automation-builder', {
	render: () => (
		<>
			<PrivatePanels />
			<ReadOnly />
		</>
	),
} );
