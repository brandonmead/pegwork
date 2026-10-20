/**
 * Hooks over the edited form post: settings meta, save errors, the block tree.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	findType,
	parseSettings,
	pointerMap,
	saveErrors,
	serializeSettings,
} from './logic';

export const SETTINGS_META = '_pegwork_form_settings';

/**
 * The edited settings object and a setter that writes it back to meta.
 *
 * @return {[Object, (next: Object) => void]} Settings and setter.
 */
export function useSettings() {
	const meta = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'meta' ),
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const settings = parseSettings( meta?.[ SETTINGS_META ] );
	const setSettings = useCallback(
		( next ) =>
			editPost( {
				meta: { [ SETTINGS_META ]: serializeSettings( next ) },
			} ),
		[ editPost ]
	);
	return [ settings, setSettings ];
}

/**
 * The target content type of the edited form, from the config.
 *
 * @param {Object} config Parsed config.
 * @return {?Object} Type, or null for an entry-only form.
 */
export function useTargetType( config ) {
	const [ settings ] = useSettings();
	return findType( config, settings?.target?.content_type );
}

/**
 * Messages by pointer from the last failed save of this form.
 *
 * @return {Object<string, string>} Messages.
 */
export function useSaveErrors() {
	const error = useSelect( ( select ) => {
		const { getCurrentPostId, getCurrentPostType } = select( editorStore );
		return select( coreStore ).getLastEntitySaveError(
			'postType',
			getCurrentPostType(),
			getCurrentPostId()
		);
	}, [] );
	return saveErrors( error );
}

/**
 * Top-level blocks (with inner blocks) of the editor.
 *
 * @return {Array} Blocks.
 */
export function useBlocks() {
	return useSelect(
		( select ) => select( blockEditorStore ).getBlocks(),
		[]
	);
}

/**
 * The JSON pointer of a block in the definition, or null.
 *
 * @param {string} clientId Block client ID.
 * @return {?string} Pointer.
 */
export function usePointer( clientId ) {
	const blocks = useBlocks();
	return pointerMap( blocks )[ clientId ] ?? null;
}
