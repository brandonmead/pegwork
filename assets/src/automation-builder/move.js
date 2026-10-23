/**
 * "Move to branch": a toolbar dropdown of every step list (Main steps, and each branch named by its
 * decision and label), which moves the selected step or decision there with moveBlocksToPosition.
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
import { BRANCH, DECISION, MAX_LEVELS } from './logic';

/**
 * Every list a block can move to: the top level and each branch outside the block itself, with
 * the level of its steps. Decisions only go where they stay within the nesting limit.
 *
 * @param {Array}  blocks   Top-level blocks.
 * @param {string} clientId The block being moved.
 * @param {number} height   Decision levels the block holds (0 for a step, 1 or more for a decision).
 * @return {Array<{rootClientId: string, label: string, count: number}>} Targets.
 */
export function moveTargets( blocks, clientId, height ) {
	const targets = [
		{
			rootClientId: '',
			label: __( 'Main steps', 'pegwork' ),
			count: blocks.length,
			level: 1,
		},
	];
	const walk = ( list, level ) => {
		for ( const block of list ) {
			if ( block.clientId === clientId || block.name !== DECISION ) {
				continue;
			}
			for ( const branch of block.innerBlocks ) {
				if ( branch.name !== BRANCH ) {
					continue;
				}
				const name = branch.attributes.otherwise
					? __( 'Otherwise', 'pegwork' )
					: branch.attributes.label ||
						__( '(unnamed branch)', 'pegwork' );
				targets.push( {
					rootClientId: branch.clientId,
					label: sprintf(
						/* translators: 1: decision id, 2: branch label. */
						__( '%1$s: %2$s', 'pegwork' ),
						block.attributes.id || __( 'decision', 'pegwork' ),
						name
					),
					count: branch.innerBlocks.length,
					level: level + 1,
				} );
				walk( branch.innerBlocks, level + 1 );
			}
		}
	};
	walk( blocks, 1 );
	return targets.filter(
		( target ) => target.level + height - 1 <= MAX_LEVELS
	);
}

/**
 * Decision levels in a block: 0 for a step, 1 plus the deepest inner decision for a decision.
 *
 * @param {Object} block Block.
 * @return {number} Height.
 */
export function heightOf( block ) {
	if ( block?.name !== DECISION ) {
		return 0;
	}
	let inner = 0;
	for ( const branch of block.innerBlocks || [] ) {
		for ( const child of branch.innerBlocks || [] ) {
			inner = Math.max( inner, heightOf( child ) );
		}
	}
	return 1 + inner;
}

/**
 * The toolbar control.
 *
 * @param {Object} props          Props.
 * @param {string} props.clientId The selected step or decision.
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
	const targets = moveTargets( blocks, clientId, heightOf( block ) );

	return (
		<BlockControls group="other">
			<ToolbarGroup>
				<ToolbarDropdownMenu
					icon="randomize"
					label={ __( 'Move to branch', 'pegwork' ) }
					controls={ targets.map( ( target ) => ( {
						title: target.label,
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
									/* translators: %s: branch name. */
									__( 'Moved to %s.', 'pegwork' ),
									target.label
								)
							);
						},
					} ) ) }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
}
