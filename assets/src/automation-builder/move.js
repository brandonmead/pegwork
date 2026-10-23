/**
 * "Move to": a toolbar dropdown of every step list the block may go to (Main steps, each branch
 * named by its decision and label, each loop body as "Repeat <id>"), which moves the selected step,
 * decision or loop there with moveBlocksToPosition. The rules are moveTargets() in logic.js.
 */

/**
 * WordPress dependencies
 */
import {
	BlockControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { ToolbarDropdownMenu, ToolbarGroup } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { moveTargets } from './logic';

/**
 * A target's name: "Main steps", "Repeat each_task", "route: Major gift", "route: Otherwise".
 *
 * @param {Object} target Target from moveTargets().
 * @return {string} Name.
 */
function targetLabel( target ) {
	if ( target.loop !== undefined ) {
		/* translators: %s: loop step id. */
		return sprintf( __( 'Repeat %s', 'pegwork' ), target.loop || '' );
	}
	if ( ! target.branch ) {
		return __( 'Main steps', 'pegwork' );
	}
	return sprintf(
		/* translators: 1: decision id, 2: branch label. */
		__( '%1$s: %2$s', 'pegwork' ),
		target.decision || __( 'decision', 'pegwork' ),
		target.branch.otherwise
			? __( 'Otherwise', 'pegwork' )
			: target.branch.label || __( '(unnamed branch)', 'pegwork' )
	);
}

/**
 * The toolbar control.
 *
 * @param {Object} props          Props.
 * @param {string} props.clientId The selected step, decision or loop.
 */
export default function MoveToBranch( { clientId } ) {
	const { blocks, block, parent } = useSelect(
		( select ) => {
			const editor = select( blockEditorStore );
			return {
				blocks: editor.getBlocks(),
				block: editor.getBlock( clientId ),
				parent: editor.getBlockRootClientId( clientId ) || '',
			};
		},
		[ clientId ]
	);
	const { moveBlocksToPosition } = useDispatch( blockEditorStore );
	if ( ! block ) {
		return null;
	}

	return (
		<BlockControls group="other">
			<ToolbarGroup>
				<ToolbarDropdownMenu
					icon="randomize"
					label={ __( 'Move to', 'pegwork' ) }
					controls={ moveTargets( blocks, block ).map( ( target ) => {
						const label = targetLabel( target );
						return {
							title: label,
							isDisabled: target.rootClientId === parent,
							onClick: () => {
								moveBlocksToPosition(
									[ clientId ],
									parent,
									target.rootClientId,
									target.count
								);
								speak(
									sprintf(
										/* translators: %s: step list name. */
										__( 'Moved to %s.', 'pegwork' ),
										label
									)
								);
							},
						};
					} ) }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
}
