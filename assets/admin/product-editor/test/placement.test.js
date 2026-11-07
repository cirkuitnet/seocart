/**
 * Internal dependencies
 */
import { placementOf, withoutNestedCopies } from '../placement';

const AREA = 'seocart/product-data';

/**
 * Builds a block as the block editor's list holds it.
 *
 * @param {string}        name        Its name.
 * @param {Array<Object>} innerBlocks Its inner blocks.
 * @return {Object} The block.
 */
const block = ( name, innerBlocks = [] ) => ( {
	clientId: `${ name }-${ innerBlocks.length }`,
	name,
	attributes: {},
	innerBlocks,
} );

describe( 'placementOf', () => {
	it( 'inserts an absent block at the end', () => {
		expect( placementOf( [], AREA ) ).toEqual( { insertAt: 0 } );
		expect( placementOf( [ 'core/paragraph' ], AREA ) ).toEqual( {
			insertAt: 1,
		} );
	} );

	it( 'leaves a block that is last alone', () => {
		expect( placementOf( [ AREA ], AREA ) ).toBeNull();
		expect( placementOf( [ 'core/paragraph', AREA ], AREA ) ).toBeNull();
	} );

	it( 'takes out every copy after the first, before anything is moved', () => {
		expect( placementOf( [ 'core/paragraph', AREA, AREA ], AREA ) ).toEqual(
			{ remove: [ 2 ] }
		);
		expect(
			placementOf( [ AREA, 'core/paragraph', AREA, AREA ], AREA )
		).toEqual( { remove: [ 2, 3 ] } );
	} );

	it( 'moves the blocks after it to where it stands', () => {
		expect( placementOf( [ AREA, 'core/paragraph' ], AREA ) ).toEqual( {
			moveFrom: 1,
			moveTo: 0,
		} );
		expect(
			placementOf(
				[ 'core/heading', AREA, 'core/paragraph', 'core/list' ],
				AREA
			)
		).toEqual( { moveFrom: 2, moveTo: 1 } );
	} );
} );

describe( 'withoutNestedCopies', () => {
	it( 'takes a copy out of a group, at any depth, and keeps everything else as it was', () => {
		const paragraph = block( 'core/paragraph' );
		const heading = block( 'core/heading' );
		const columns = block( 'core/columns', [
			block( 'core/column', [ block( AREA ), heading ] ),
		] );
		const group = block( 'core/group', [ paragraph, block( AREA ) ] );
		const root = block( AREA );
		const roots = withoutNestedCopies( [ group, columns, root ], AREA );

		expect( roots ).toEqual( [
			{ ...group, innerBlocks: [ paragraph ] },
			{
				...columns,
				innerBlocks: [
					{ ...columns.innerBlocks[ 0 ], innerBlocks: [ heading ] },
				],
			},
			root,
		] );
		expect( roots[ 0 ].innerBlocks[ 0 ] ).toBe( paragraph );
		expect( roots[ 2 ] ).toBe( root );
	} );

	it( 'says when no copy is nested, whether the block is at the root or not there at all', () => {
		const group = block( 'core/group', [ block( 'core/paragraph' ) ] );

		expect(
			withoutNestedCopies( [ group, block( AREA ) ], AREA )
		).toBeNull();
		expect( withoutNestedCopies( [ group ], AREA ) ).toBeNull();
		expect( withoutNestedCopies( [], AREA ) ).toBeNull();
	} );
} );
