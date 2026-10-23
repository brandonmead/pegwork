/**
 * "Run an automation": one button per manual automation the user may start on this record, at the end
 * of the Pegwork Details panel (slot `pegwork-editor-panel-after`). Each opens a confirmation dialog,
 * then posts to `pegwork/v1/automations/<key>/start` with one random request ID per dialog, so a double
 * submit starts one run. The result is announced with speak() and shown as a notice (with a link to the
 * run for administrators). Config: `window.pegworkAutomationActions` (PHP RecordActions).
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Fill,
	// Core's confirmation dialog (focus trap, Escape, focus return) is still experimental in WordPress 6.7 to 7.1.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const automations = (
	window.pegworkAutomationActions?.automations || []
).filter(
	( item ) =>
		item &&
		typeof item.key === 'string' &&
		typeof item.button === 'string' &&
		typeof item.path === 'string'
);

/**
 * A random ID for one dialog.
 *
 * @return {string} ID of 8 to 64 letters, digits and hyphens.
 */
export function requestId() {
	return window.crypto?.randomUUID
		? window.crypto.randomUUID()
		: `${ Date.now().toString( 36 ) }-${ Math.random()
				.toString( 36 )
				.slice( 2, 12 ) }`;
}

/**
 * One automation's button and its confirmation dialog.
 *
 * @param {Object}  props            Props.
 * @param {Object}  props.automation `{ key, button, path }`.
 * @param {number}  props.postId     Record ID.
 * @param {string}  props.title      Record title.
 * @param {boolean} props.isDirty    Whether the record has unsaved changes.
 */
function RunButton( { automation, postId, title, isDirty } ) {
	const [ request, setRequest ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );

	const start = async () => {
		const id = request;
		setRequest( null );
		setBusy( true );
		try {
			const result = await apiFetch( {
				path: automation.path,
				method: 'POST',
				data: { record_id: postId, request: id },
			} );
			speak( result.message );
			createSuccessNotice( result.message, {
				speak: false,
				actions: result.url
					? [
							{
								label: __( 'View run', 'pegwork' ),
								url: result.url,
							},
						]
					: [],
			} );
		} catch ( error ) {
			const message =
				error?.message ||
				__( 'The automation could not be started.', 'pegwork' );
			speak( message, 'assertive' );
			createErrorNotice( message, { speak: false } );
		}
		setBusy( false );
	};

	return (
		<>
			<Button
				variant="secondary"
				isBusy={ busy }
				aria-disabled={ busy }
				onClick={ () => ! busy && setRequest( requestId() ) }
			>
				{ automation.button }
			</Button>
			<ConfirmDialog
				isOpen={ request !== null }
				onConfirm={ start }
				onCancel={ () => setRequest( null ) }
				confirmButtonText={ automation.button }
				cancelButtonText={ __( 'Cancel', 'pegwork' ) }
			>
				{ sprintf(
					/* translators: 1: button text such as "Resend thank-you", 2: record title. */
					__( '%1$s for %2$s?', 'pegwork' ),
					automation.button,
					title
				) }
				{ isDirty && (
					<p>
						{ __(
							'It uses the last saved version; unsaved changes are not included.',
							'pegwork'
						) }
					</p>
				) }
			</ConfirmDialog>
		</>
	);
}

/**
 * The section.
 */
function RunSection() {
	const { postId, title, isNew, isDirty } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			title: editor.getEditedPostAttribute( 'title' ),
			isNew: editor.isEditedPostNew(),
			isDirty: editor.isEditedPostDirty(),
		};
	}, [] );

	return (
		<div className="pegwork-editor-panel__actions">
			<h3 className="pegwork-editor-panel__heading">
				{ __( 'Run an automation', 'pegwork' ) }
			</h3>
			{ isNew ? (
				<p className="pegwork-editor-panel__help">
					{ __(
						'Save this record first to run an automation on it.',
						'pegwork'
					) }
				</p>
			) : (
				automations.map( ( automation ) => (
					<RunButton
						key={ automation.key }
						automation={ automation }
						postId={ postId }
						title={
							title ||
							/* translators: %d: record ID. */
							sprintf( __( 'record #%d', 'pegwork' ), postId )
						}
						isDirty={ isDirty }
					/>
				) )
			) }
		</div>
	);
}

if ( automations.length > 0 ) {
	registerPlugin( 'pegwork-automation-actions', {
		render: () => (
			<Fill name="pegwork-editor-panel-after">
				<RunSection />
			</Fill>
		),
	} );
}
