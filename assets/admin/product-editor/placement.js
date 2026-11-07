/**
 * Where the Product data block belongs among the post's root blocks: last, and there once, and in no other block.
 */

/**
 * Says what has to change for the block to be the last of the root blocks, and the only one.
 *
 * @param {string[]} names     The names of the root blocks, in order.
 * @param {string}   blockName The name of the Product data block.
 * @return {{insertAt: number}|{remove: number[]}|{moveFrom: number, moveTo: number}|null} Where to insert the
 *         block when it is absent; the indexes of the copies after the first, to take out; from where to move
 *         the blocks after it, and to which index; null when it is in its place.
 */
export function placementOf( names, blockName ) {
	const at = names.indexOf( blockName );

	if ( at === -1 ) {
		return { insertAt: names.length };
	}

	const copies = names.flatMap( ( name, index ) =>
		index > at && name === blockName ? [ index ] : []
	);

	if ( copies.length > 0 ) {
		return { remove: copies };
	}

	return at === names.length - 1 ? null : { moveFrom: at + 1, moveTo: at };
}

/**
 * Takes a copy of the block out of a block's inner blocks, at any depth.
 *
 * @param {Object} block     A block, with its `name` and its `innerBlocks`.
 * @param {string} blockName The name of the Product data block.
 * @return {Object} The block itself when it holds no copy, else a block like it without the copies.
 */
function withoutCopiesInside( block, blockName ) {
	const inner = block.innerBlocks ?? [];
	const kept = inner
		.filter( ( child ) => child.name !== blockName )
		.map( ( child ) => withoutCopiesInside( child, blockName ) );

	return kept.length === inner.length &&
		kept.every( ( child, index ) => child === inner[ index ] )
		? block
		: { ...block, innerBlocks: kept };
}

/**
 * Takes out every copy of the block nested in another block, such as a marker typed inside a group.
 *
 * @param {Array<Object>} blocks    The root blocks, each with its `name` and its `innerBlocks`.
 * @param {string}        blockName The name of the Product data block.
 * @return {Array<Object>|null} The root blocks, those holding no copy unchanged and the others without their copies;
 *                              null when no copy is nested.
 */
export function withoutNestedCopies( blocks, blockName ) {
	const roots = blocks.map( ( block ) =>
		withoutCopiesInside( block, blockName )
	);

	return roots.some( ( block, index ) => block !== blocks[ index ] )
		? roots
		: null;
}
