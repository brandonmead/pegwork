/**
 * Automation builder script, loaded only in the block editor of `pegwork_automation` posts: the step,
 * decision and branch blocks with their variations, the Automation, Trigger and Test run panels,
 * save errors with "Go to first error", the open-runs warning, and the read-only mode of
 * file-defined automations.
 */

/**
 * WordPress dependencies
 */
import {
	InnerBlocks,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	registerBlockType,
	registerBlockVariation,
	unregisterBlockVariation,
} from '@wordpress/blocks';
import {
	dispatch,
	select,
	subscribe,
	useDispatch,
	useSelect,
} from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import stepMetadata from '../../../modules/automation/blocks/automation-step/block.json';
import decisionMetadata from '../../../modules/automation/blocks/automation-decision/block.json';
import branchMetadata from '../../../modules/automation/blocks/automation-branch/block.json';
import loopMetadata from '../../../modules/automation/blocks/automation-loop/block.json';
import lockTitle from '../shared/lock-title';
import { controlId } from './controls';
import { BranchEdit, DecisionEdit, OTHERWISE } from './decision-edit';
import { typeLabel, typeShort } from './labels';
import {
	BRANCH,
	LOOP,
	STEP,
	STEP_TYPES,
	errorOwners,
	flatten,
	pointerMap,
	saveErrors,
} from './logic';
import LoopEdit from './loop-edit';
import { TestRunPanel } from './preview';
import SettingsPanels, { PANEL, TRIGGER_PANEL } from './settings';
import StepEdit, { ICONS } from './step-edit';
import { config, useSaveErrors } from './store';
import './builder.scss';

// The notices store by name: @wordpress/notices is not a dependency (the forms builder does the same).
const noticesStore = 'core/notices';

/*
 * An automation post is always private, so core's publish wording misleads: relabel it in this
 * editor only (this script loads only for pegwork_automation posts). Core strings.
 */
const SAVE_WORDING = {
	Publish: __( 'Save', 'pegwork' ),
	'Are you ready to publish?': __(
		'Ready to save this automation?',
		'pegwork'
	),
	'Double-check your settings before publishing.': __(
		'Saving checks the automation and adds it to this site. New automations start off.',
		'pegwork'
	),
};
addFilter(
	'i18n.gettext_default',
	'pegwork/automation-builder/save-wording',
	( translation, text ) =>
		Object.hasOwn( SAVE_WORDING, text ) ? SAVE_WORDING[ text ] : translation
);

const DESCRIPTIONS = {
	send_email: __( 'Sends an email built from merge tags.', 'pegwork' ),
	update_record: __(
		'Writes fields of the record, or of a record by ID.',
		'pegwork'
	),
	create_record: __( 'Creates a record of a content type.', 'pegwork' ),
	call_webhook: __(
		'Posts public record fields to a URL, signed.',
		'pegwork'
	),
	wait: __( 'Pauses the run, then continues.', 'pegwork' ),
	condition: __( 'Stops the run unless a condition holds.', 'pegwork' ),
	get_record: __(
		'Reads one record: by ID, through a relationship, or by matching fields.',
		'pegwork'
	),
	find_records: __(
		'Counts and totals the matching records, and loads up to 50.',
		'pegwork'
	),
	relate: __( 'Adds or removes one relationship link.', 'pegwork' ),
};

const VARIATIONS = STEP_TYPES.map( ( type ) => ( {
	name: type,
	title: typeLabel( type ),
	description: DESCRIPTIONS[ type ],
	icon: ICONS[ type ],
	attributes: type === 'wait' ? { type, for: 'P1D' } : { type },
	isDefault: type === 'send_email',
	scope: [ 'inserter', 'transform' ],
	isActive: [ 'type' ],
} ) );

registerBlockType( stepMetadata, {
	title: __( 'Automation step', 'pegwork' ),
	description: __( 'One step of the automation.', 'pegwork' ),
	edit: StepEdit,
	save: () => null,
	__experimentalLabel: ( attributes ) =>
		`${ typeShort( attributes.type ) } ${ attributes.id || '' }`.trim(),
	variations: VARIATIONS,
} );

registerBlockType( loopMetadata, {
	title: typeLabel( 'loop' ),
	description: __(
		'Runs its steps once for each record a Find records step loaded, then continues below.',
		'pegwork'
	),
	edit: LoopEdit,
	save: () => <InnerBlocks.Content />,
	__experimentalLabel: ( attributes ) =>
		/* translators: %s: loop step id. */ sprintf(
			__( 'Repeat %s', 'pegwork' ),
			attributes.id || ''
		).trim(),
} );

/*
 * Inserter rules inside a loop body (AutomationRules loop_wait, loop_nested): a loop is never
 * offered there, and the Wait variation is withdrawn while the selection is a loop or inside one
 * (inserters and the block switcher list variations, which no filter can drop per location; a
 * focused appender selects its loop or branch). A wait pasted in anyway is refused at its card.
 */
addFilter(
	'blockEditor.__unstableCanInsertBlockType',
	'pegwork/automation-builder/no-nested-loop',
	(
		can,
		blockType,
		rootClientId,
		{ getBlock, getBlockParentsByBlockName }
	) =>
		can &&
		! (
			blockType.name === LOOP &&
			rootClientId &&
			( getBlock( rootClientId )?.name === LOOP ||
				getBlockParentsByBlockName( rootClientId, LOOP ).length )
		)
);
let waitHidden = false;
subscribe( () => {
	const editor = select( blockEditorStore );
	const id = editor.getSelectedBlockClientId();
	const hide =
		!! id &&
		( editor.getBlockName( id ) === LOOP ||
			editor.getBlockParentsByBlockName( id, LOOP ).length > 0 );
	if ( hide !== waitHidden ) {
		waitHidden = hide;
		if ( hide ) {
			unregisterBlockVariation( STEP, 'wait' );
		} else {
			registerBlockVariation(
				STEP,
				VARIATIONS.find( ( item ) => item.name === 'wait' )
			);
		}
	}
}, blockEditorStore );

const branch = ( label ) => [ BRANCH, { label } ];

registerBlockType( decisionMetadata, {
	title: __( 'Decision', 'pegwork' ),
	description: __(
		'Runs the steps of the first branch whose condition holds, else Otherwise, then continues below.',
		'pegwork'
	),
	edit: DecisionEdit,
	save: () => <InnerBlocks.Content />,
	__experimentalLabel: ( attributes ) =>
		/* translators: %s: decision id. */ sprintf(
			__( 'Decision %s', 'pegwork' ),
			attributes.id || ''
		).trim(),
	variations: [
		{
			name: 'if-otherwise',
			title: __( 'If / Otherwise', 'pegwork' ),
			description: __(
				'One condition: its steps, or the Otherwise steps.',
				'pegwork'
			),
			icon: 'randomize',
			isDefault: true,
			scope: [ 'inserter' ],
			innerBlocks: [
				branch( __( 'Path 1', 'pegwork' ) ),
				[ BRANCH, OTHERWISE ],
			],
		},
		{
			name: 'choose-path',
			title: __( 'Choose a path', 'pegwork' ),
			description: __(
				'Several conditions, checked in order; the first that holds runs.',
				'pegwork'
			),
			icon: 'networking',
			scope: [ 'inserter' ],
			innerBlocks: [
				branch( __( 'Path 1', 'pegwork' ) ),
				branch( __( 'Path 2', 'pegwork' ) ),
				[ BRANCH, OTHERWISE ],
			],
		},
	],
} );

registerBlockType( branchMetadata, {
	title: __( 'Branch', 'pegwork' ),
	description: __( 'One branch of a decision.', 'pegwork' ),
	edit: BranchEdit,
	save: () => <InnerBlocks.Content />,
	__experimentalLabel: ( attributes ) =>
		attributes.otherwise
			? __( 'Otherwise', 'pegwork' )
			: /* translators: %s: branch label. */ sprintf(
					__( 'Branch %s', 'pegwork' ),
					attributes.label || ''
				).trim(),
} );

/**
 * File-defined automations: saving is locked, the title is read-only and a notice offers
 * "Duplicate as site automation".
 */
function ReadOnly() {
	const { lockPostSaving } = useDispatch( editorStore );
	const { createWarningNotice } = useDispatch( noticesStore );

	useEffect( () => {
		if ( ! config.readOnly ) {
			return undefined;
		}
		const unlock = lockTitle( 'pegwork-automation-readonly' );
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
		return unlock;
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

/**
 * Focuses the control of a settings pointer (or of its nearest parent), once its panel renders.
 *
 * @param {string} pointer Pointer.
 * @param {number} [tries] Frames left to wait.
 */
function focusSetting( pointer, tries = 20 ) {
	const parts = pointer.split( '/' );
	for ( let length = parts.length; length > 1; length-- ) {
		const element = document.getElementById(
			controlId( parts.slice( 0, length ).join( '/' ) )
		);
		if ( element ) {
			element.focus();
			return;
		}
	}
	if ( tries > 0 ) {
		window.requestAnimationFrame( () =>
			focusSetting( pointer, tries - 1 )
		);
	}
}

/**
 * Selects the block (or focuses the setting) of the first error: settings first, then blocks in
 * document order. The block's card and its inspector controls show the message.
 */
export function goToFirstError() {
	const errors = saveErrors(
		select( 'core' ).getLastEntitySaveError(
			'postType',
			select( editorStore ).getCurrentPostType(),
			select( editorStore ).getCurrentPostId()
		)
	);
	const blocks = select( blockEditorStore ).getBlocks();
	const owners = errorOwners( errors, pointerMap( blocks ) );
	const setting = Object.keys( owners.settings )[ 0 ];
	if ( setting ) {
		dispatch( 'core/edit-post' )?.openGeneralSidebar?.(
			'edit-post/document'
		);
		const panel =
			setting.startsWith( '/trigger' ) ||
			setting.startsWith( '/idempotency' )
				? TRIGGER_PANEL
				: PANEL;
		if ( ! select( editorStore ).isEditorPanelOpened( panel ) ) {
			dispatch( editorStore ).toggleEditorPanelOpened( panel );
		}
		focusSetting( setting );
		return;
	}
	const first = flatten( blocks ).find(
		( block ) => owners.blocks[ block.clientId ]
	);
	if ( first ) {
		dispatch( 'core/edit-post' )?.openGeneralSidebar?.( 'edit-post/block' );
		dispatch( blockEditorStore ).selectBlock( first.clientId, 0 );
	}
}

/**
 * After a refused save: a notice with the number of problems and "Go to first error".
 */
function SaveErrorsNotice() {
	const errors = useSaveErrors();
	const { createErrorNotice, removeNotice } = useDispatch( noticesStore );
	const count = Object.keys( errors ).length;

	useEffect( () => {
		if ( ! count ) {
			removeNotice( 'pegwork-automation-errors' );
			return;
		}
		createErrorNotice(
			sprintf(
				/* translators: %d: number of problems. */
				_n(
					'%d problem stops this automation from saving. Each is shown at its step or setting.',
					'%d problems stop this automation from saving. Each is shown at its step or setting.',
					count,
					'pegwork'
				),
				count
			),
			{
				id: 'pegwork-automation-errors',
				actions: [
					{
						label: __( 'Go to first error', 'pegwork' ),
						onClick: goToFirstError,
					},
				],
			}
		);
	}, [ errors, count, createErrorNotice, removeNotice ] );

	return null;
}

/**
 * Before saving an automation that has runs in progress: a warning while there are unsaved changes.
 */
function OpenRunsNotice() {
	const dirty = useSelect(
		( selectStore ) => selectStore( editorStore ).isEditedPostDirty(),
		[]
	);
	const { createWarningNotice, removeNotice } = useDispatch( noticesStore );

	useEffect( () => {
		if ( ! config.openRuns || config.readOnly ) {
			return;
		}
		if ( ! dirty ) {
			removeNotice( 'pegwork-open-runs' );
			return;
		}
		createWarningNotice(
			sprintf(
				/* translators: %d: number of runs. */
				_n(
					'%d run is in progress. It continues from its current step when you save; a run at a step you removed will fail.',
					'%d runs are in progress. They continue from their current step when you save; runs at a step you removed will fail.',
					config.openRuns,
					'pegwork'
				),
				config.openRuns
			),
			{ id: 'pegwork-open-runs', isDismissible: false }
		);
	}, [ dirty, createWarningNotice, removeNotice ] );

	return null;
}

registerPlugin( 'pegwork-automation-builder', {
	render: () => (
		<>
			<PrivatePanels />
			<ReadOnly />
			<SaveErrorsNotice />
			<OpenRunsNotice />
			<SettingsPanels />
			<TestRunPanel />
		</>
	),
} );
