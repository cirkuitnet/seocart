/**
 * Keeps the Product data block in every product's visual editor, as the last of the root blocks.
 *
 * WordPress applies a post type's template to a new post only, so a product created over REST, an
 * imported one or one whose marker was deleted in the code editor would have no block. And
 * clicking below the last block appends a paragraph after it, and a second marker typed in the code
 * editor, or one inside another block, is a second block. Each is put right here, without making
 * the post dirty or leaving an undo level: the block is inserted, the blocks after it moved before
 * it, or the copies taken out, as changes that are neither persistent nor kept for Undo; the block
 * is saved with the next real save.
 */

/**
 * WordPress dependencies
 *
 * WordPress's own scripts: the build leaves them out and lists them in the .asset.json file.
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { placementOf, withoutNestedCopies } from './placement';

/**
 * Marks the block editor's next change as not persistent, and as none of the undo history.
 *
 * The one call of an unstable action. A change that is only not persistent is still kept, to be
 * merged into the next undo level: with no level yet, the next persistent signal of any kind makes
 * it one, and Undo takes the block away. Without the action a change is made persistently: the
 * product opens dirty, which the end-to-end spec's first assertion reports on that version of
 * WordPress.
 *
 * @param {Object} blockEditor The block editor store's actions.
 */
function markNextChangeAsNotPersistent( blockEditor ) {
	blockEditor.__unstableMarkNextChangeAsNotPersistent?.( {
		history: 'ignore',
	} );
}

/**
 * Puts the block in its place among the root blocks, when it is not, and takes out any copy inside another block.
 *
 * @param {Object} registry  The data registry.
 * @param {string} blockName The name of the Product data block.
 */
function keep( registry, blockName ) {
	const { getBlockOrder, getBlockName, getBlocks } =
		registry.select( blockEditorStore );
	const blockEditor = registry.dispatch( blockEditorStore );
	const nested = withoutNestedCopies( getBlocks(), blockName );

	if ( nested !== null ) {
		// A copy inside another block, such as a marker in a group, is locked against removal as the block is: the
		// list is set without it, the block last among the root blocks, in the one change the mark covers.
		markNextChangeAsNotPersistent( blockEditor );
		blockEditor.resetBlocks( [
			...nested.filter( ( block ) => block.name !== blockName ),
			nested.find( ( block ) => block.name === blockName ) ??
				createBlock( blockName ),
		] );

		return;
	}

	const order = getBlockOrder();
	const placement = placementOf(
		order.map( ( clientId ) => getBlockName( clientId ) ),
		blockName
	);

	if ( placement === null ) {
		return;
	}

	markNextChangeAsNotPersistent( blockEditor );

	if ( 'insertAt' in placement ) {
		blockEditor.insertBlocks(
			createBlock( blockName ),
			placement.insertAt,
			'',
			false
		);
	} else if ( 'remove' in placement ) {
		// A copy is locked against removal as the block is, so the list is set without the copies.
		blockEditor.resetBlocks(
			getBlocks().filter(
				( block, index ) => ! placement.remove.includes( index )
			)
		);
	} else {
		blockEditor.moveBlocksToPosition(
			order.slice( placement.moveFrom ),
			'',
			'',
			placement.moveTo
		);
	}
}

/**
 * Watches the root block list of the post's visual editor, and keeps the block in it, last.
 *
 * Only there: the code editor shows what the merchant typed until they leave it, the template
 * view has other root blocks, and a preview such as a revision's edits nothing.
 *
 * @param {string} blockName The name of the Product data block.
 */
export function useKeepArea( blockName ) {
	const registry = useRegistry();
	const { watching, order } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const blockEditor = select( blockEditorStore );

		return {
			watching:
				editor.getCurrentPostId() !== null &&
				editor.getEditorMode() === 'visual' &&
				editor.getRenderingMode() === 'post-only' &&
				! blockEditor.getSettings().isPreviewMode,
			order: blockEditor.getBlockOrder(),
		};
	}, [] );

	useEffect( () => {
		if ( ! watching ) {
			return undefined;
		}

		// The editor copies the post's blocks into this list in an effect of the same commit, which runs
		// after this one: asking now could see an empty list, and an insert would be overwritten.
		const timer = setTimeout( () => keep( registry, blockName ) );

		return () => clearTimeout( timer );
	}, [ watching, order, registry, blockName ] );
}
