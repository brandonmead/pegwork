/**
 * Form builder script, loaded only in the block editor of `pegwork_form` posts: the field and step
 * blocks (with one variation per field type and per field and taxonomy of the target type), the
 * document settings panels, and the read-only mode of file-defined forms.
 */

/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import {
	registerBlockType,
	registerBlockVariation,
	unregisterBlockVariation,
	getBlockVariations,
} from '@wordpress/blocks';
import { useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import fieldMetadata from '../../../modules/forms/blocks/form-field/block.json';
import stepMetadata from '../../../modules/forms/blocks/form-step/block.json';
import config from './config';
import FieldEdit from './field-edit';
import { FIELD, FIELD_TYPES } from './logic';
import { typeDescription, typeLabel } from './labels';
import SettingsPanels from './settings';
import StepEdit from './step-edit';
import { useTargetType } from './store';
import './builder.scss';

const ICONS = {
	text: 'editor-textcolor',
	email: 'email',
	textarea: 'editor-paragraph',
	url: 'admin-links',
	number: 'calculator',
	date: 'calendar-alt',
	boolean: 'yes-alt',
	select: 'list-view',
	file: 'upload',
};

const BOUND_PREFIX = 'bound-';

/*
 * A form post is always saved as private, so core's publish wording misleads: relabel it in this editor
 * only (this script loads only for pegwork_form posts). Core strings, default text domain.
 */
const SAVE_WORDING = {
	Publish: __( 'Save', 'pegwork' ),
	'Are you ready to publish?': __( 'Ready to save this form?', 'pegwork' ),
	'Double-check your settings before publishing.': __(
		'Forms are always private. Saving makes the form available to the Form block.',
		'pegwork'
	),
};
addFilter(
	'i18n.gettext_default',
	'pegwork/forms-builder/save-wording',
	( translation, text ) =>
		Object.hasOwn( SAVE_WORDING, text ) ? SAVE_WORDING[ text ] : translation
);

registerBlockType( fieldMetadata, {
	title: __( 'Form field', 'pegwork' ),
	description: __( 'One field of the form.', 'pegwork' ),
	edit: FieldEdit,
	save: () => null,
	variations: FIELD_TYPES.map( ( type ) => ( {
		name: `type-${ type }`,
		title: typeLabel( type ),
		description: typeDescription( type ),
		icon: ICONS[ type ],
		attributes: { type },
		isDefault: type === 'text',
		scope: [ 'inserter', 'transform' ],
		isActive: ( attributes ) =>
			! attributes.bind && ( attributes.type || 'text' ) === type,
	} ) ),
} );

registerBlockType( stepMetadata, {
	title: __( 'Form step', 'pegwork' ),
	description: __(
		'One step of a multi-step form: a title and its fields.',
		'pegwork'
	),
	edit: StepEdit,
	save: () => <InnerBlocks.Content />,
} );

/**
 * Keeps one field variation per field and taxonomy of the target type ("Project: Website").
 */
function BoundVariations() {
	const type = useTargetType( config );
	const key = type?.key ?? '';

	useEffect( () => {
		for ( const variation of getBlockVariations( FIELD ) || [] ) {
			if ( variation.name.startsWith( BOUND_PREFIX ) ) {
				unregisterBlockVariation( FIELD, variation.name );
			}
		}
		if ( ! type ) {
			return;
		}
		const title = ( label ) =>
			sprintf(
				/* translators: 1: content type label, 2: field or taxonomy label. */
				__( '%1$s: %2$s', 'pegwork' ),
				type.label,
				label
			);
		for ( const field of type.fields ) {
			registerBlockVariation( FIELD, {
				name: `${ BOUND_PREFIX }field-${ field.key }`,
				title: title( field.label ),
				description: sprintf(
					/* translators: %s: content type label. */
					__(
						'Takes its type and rules from the %s content type.',
						'pegwork'
					),
					type.label
				),
				icon: 'database',
				attributes: { bind: { field: field.key } },
				scope: [ 'inserter' ],
				keywords: [ type.label, field.key ],
				isActive: ( attributes ) =>
					attributes.bind?.field === field.key,
			} );
		}
		for ( const taxonomy of type.taxonomies ) {
			registerBlockVariation( FIELD, {
				name: `${ BOUND_PREFIX }taxonomy-${ taxonomy.key }`,
				title: title( taxonomy.label ),
				description: sprintf(
					/* translators: %s: content type label. */
					__(
						'A choice of the terms the %s content type uses.',
						'pegwork'
					),
					type.label
				),
				icon: 'tag',
				attributes: { bind: { taxonomy: taxonomy.key } },
				scope: [ 'inserter' ],
				keywords: [ type.label, taxonomy.key ],
				isActive: ( attributes ) =>
					attributes.bind?.taxonomy === taxonomy.key,
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- The type object is rebuilt on every render; its key identifies it.
	}, [ key ] );

	return null;
}

const TITLE = '.editor-post-title__input';

/**
 * Makes the post title read-only. Core has no setting for it (the title is a contenteditable the
 * template lock does not reach, and it lives in the editor canvas iframe, which the admin body
 * class does not cross), so the canvas body gets the same `pegwork-form-readonly` class (styles:
 * no caret, default cursor) and every edit of the title (typing, Backspace, Delete, paste, cut, drop) is cancelled
 * before it reaches the DOM. The title stays focusable and readable by a screen reader.
 *
 * @return {() => void} Stops watching.
 */
function lockTitle() {
	const done = new WeakSet();
	const cancel = ( event ) => {
		// Keys that change text on their own (the rich text's delete handler acts on keydown, not on
		// beforeinput); other keys, arrows and Tab included, still work.
		const edits =
			event.type !== 'keydown' ||
			[ 'Backspace', 'Delete', 'Enter' ].includes( event.key );
		if ( edits && event.target?.closest?.( TITLE ) ) {
			// Stopped as well: the rich text's own paste, cut and delete handlers run on the element
			// and would otherwise still insert or remove text.
			event.preventDefault();
			event.stopPropagation();
		}
	};
	// The canvas iframe is mounted after this runs and replaced when it reloads, so check it on a timer.
	const attach = () => {
		const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
		for ( const doc of [ document, frame?.contentDocument ] ) {
			if ( ! doc?.body || done.has( doc ) ) {
				continue;
			}
			done.add( doc );
			doc.body.classList.add( 'pegwork-form-readonly' );
			for ( const type of [
				'beforeinput',
				'keydown',
				'paste',
				'cut',
				'drop',
			] ) {
				doc.addEventListener( type, cancel, true );
			}
		}
	};
	attach();
	const timer = window.setInterval( attach, 50 );
	return () => window.clearInterval( timer );
}

/**
 * File-defined forms: saving is locked, the title is read-only and a notice offers "Duplicate as
 * site form".
 */
function ReadOnly() {
	const { lockPostSaving } = useDispatch( editorStore );
	const { createWarningNotice } = useDispatch( 'core/notices' );

	useEffect( () => {
		if ( ! config.readOnly ) {
			return undefined;
		}
		const unlock = lockTitle();
		lockPostSaving( 'pegwork-file-form' );
		createWarningNotice(
			sprintf(
				/* translators: %s: package ID. */
				__(
					'This form comes from the file package "%s" and is read-only. Duplicate it to make a site form you can change.',
					'pegwork'
				),
				config.package
			),
			{
				id: 'pegwork-file-form',
				isDismissible: false,
				actions: config.duplicateUrl
					? [
							{
								label: __(
									'Duplicate as site form',
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
 * Removes the Summary panel's status rows (Status, Publish date, link, author, template): a form post is
 * always private and has no public URL or publish date, so they only mislead. Core has no separate panel
 * name for the date row; `post-status` covers the group. The title card above it stays, and with it the
 * actions menu (Move to trash).
 */
function PrivateFormPanels() {
	const { removeEditorPanel } = useDispatch( editorStore );

	useEffect( () => {
		removeEditorPanel( 'post-status' );
	}, [ removeEditorPanel ] );

	return null;
}

registerPlugin( 'pegwork-form-builder', {
	render: () => (
		<>
			<BoundVariations />
			<PrivateFormPanels />
			<ReadOnly />
			<SettingsPanels />
		</>
	),
} );
