/**
 * The builder config, and hooks over the edited automation post: settings meta, save errors, the
 * block tree as a definition, pointers, and the sample used by previews and the test run.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	compileBlocks,
	errorOwners,
	parseConfig,
	parseSettings,
	pointerMap,
	saveErrors,
	serializeSettings,
} from './logic';

export const config = parseConfig( window.pegworkAutomationBuilder );

export const SETTINGS_META = '_pegwork_automation_settings';

/**
 * The edited settings object and a setter that writes it back to meta.
 *
 * @return {[Object, (next: Object) => void]} Settings and setter.
 */
export function useSettings() {
	const json = useSelect(
		( select ) =>
			select( editorStore ).getEditedPostAttribute( 'meta' )?.[
				SETTINGS_META
			],
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const settings = useMemo( () => parseSettings( json ), [ json ] );
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
 * Messages by pointer from the last failed save of this automation.
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
 * Top-level blocks, with inner blocks.
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
 * The edited definition as scope rules read it (trigger, links, steps from the blocks), and the
 * pointer of every block.
 *
 * @return {{definition: Object, pointers: Object<string, string>}} Definition and pointers.
 */
export function useDefinition() {
	const blocks = useBlocks();
	const [ settings ] = useSettings();
	return useMemo(
		() => ( {
			definition: {
				trigger: settings.trigger || {},
				links: settings.links || {},
				steps: compileBlocks( blocks ),
			},
			pointers: pointerMap( blocks ),
		} ),
		[ blocks, settings ]
	);
}

/**
 * Save errors split by owner: blocks (by client ID) and settings (by pointer).
 *
 * @return {{blocks: Object, settings: Object<string, string>}} Errors.
 */
export function useErrorOwners() {
	const errors = useSaveErrors();
	const { pointers } = useDefinition();
	return useMemo(
		() => errorOwners( errors, pointers ),
		[ errors, pointers ]
	);
}

/*
 * The sample record and payload shared by every preview and the test run: one choice for the whole
 * editor, kept for the page's life.
 */
let sample = { recordId: null, recordLabel: '', payload: '' };
const listeners = new Set();

/**
 * The shared sample and its setter.
 *
 * @return {[Object, (patch: Object) => void]} Sample and setter.
 */
export function useSample() {
	const [ value, setValue ] = useState( sample );
	useEffect( () => {
		listeners.add( setValue );
		return () => listeners.delete( setValue );
	}, [] );
	const update = useCallback( ( patch ) => {
		sample = { ...sample, ...patch };
		listeners.forEach( ( listener ) => listener( sample ) );
	}, [] );
	return [ value, update ];
}
