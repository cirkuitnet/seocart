<?php
/**
 * Tests a value of an option removed and brought back: a variant named again has its whole combination, on the wire and in storage
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Removing an option value deletes the rows of the selection that named it, and the variants under it stay, disabled: their combination hash stands for the whole combination, their selection reads what is left. The save that names such a variant gives it its whole selection again.
 *
 * Planted violation, shown failing and put back: in Product::applyVariants(), leave out
 * `->withSelection( $input->selection )`. The variant named again keeps one selection row, its read
 * is partial and refused when sent back, and a copy leaves it out.
 *
 * @since 0.2.0
 */
final class ProductOptionValuesTest extends ProductRestTestCase {

	use SavesVariantSets;

	/**
	 * Tests that a value removed and added back with every variant enabled gives each its two selection rows, its whole combination and label on the wire, a read that can be sent back, and its place in a copy.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_removed_and_added_back_keeps_the_whole_combination(): void {
		$b   = $this->secondConnection();
		$six = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'BACK' ) );

		$this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options( array( '250g' ) ),
				'variants' => VariantSets::variants( 'BACK', array( '250g' ) ),
			)
		);

		$this->assertSame( array( 'complete', 1, 6, 3 ), $this->committedGeneration( $b, $six->productId ), 'The variants of the value removed stay, disabled.' );

		$this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options(),
				'variants' => array_map( static fn( array $variant ): array => array( 'enabled' => true ) + $variant, VariantSets::variants( 'BACK' ) ),
			)
		);

		$this->assertSame( array( 'complete', 1, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertEveryVariantWhole( $b, $six );

		$this->put( $six->postId, array( 'variants' => self::sentBack( $this->edit( $six->postId )['variants'] ) ) );

		$this->assertEveryVariantWhole( $b, $six );

		$copy = $this->services->duplicate->duplicate( $six->productId, Actor::user( 1 ) );

		$this->trackPost( $copy->postId );

		$this->assertCount( 6, $copy->variantIds, 'A copy carries every variant.' );
		$this->assertSame( array( 'complete', 1, 6, 6 ), $this->committedGeneration( $b, $copy->productId ) );
	}

	/**
	 * Tests that a value added back with `enabled` left out keeps the variants under it disabled, as what a set leaves out keeps what is stored, and whole.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_added_back_without_enabled_leaves_its_variants_disabled_and_whole(): void {
		$b   = $this->secondConnection();
		$six = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'KEPT' ) );

		$this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options( array( '250g' ) ),
				'variants' => VariantSets::variants( 'KEPT', array( '250g' ) ),
			)
		);
		$this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options(),
				'variants' => VariantSets::variants( 'KEPT' ),
			)
		);

		$this->assertSame( array( 'complete', 1, 6, 3 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertEveryVariantWhole( $b, $six );
		$this->assertSame( array( true, true, true, false, false, false ), array_column( $this->edit( $six->postId )['variants'], 'enabled' ) );

		$this->put( $six->postId, array( 'variants' => self::sentBack( $this->edit( $six->postId )['variants'] ) ) );

		$this->assertSame( array( 'complete', 1, 6, 3 ), $this->committedGeneration( $b, $six->productId ), 'The read sent back changes nothing.' );
	}

	/**
	 * Tests that a variant an earlier save left with one selection row, sold under it, is whole again after the next save that names it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_damaged_row_heals_on_the_next_save_naming_it(): void {
		$b       = $this->secondConnection();
		$six     = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'HEAL' ) );
		$damaged = $six->variantIds[ VariantSets::hash(
			array(
				'size'  => '1kg',
				'grind' => 'filter',
			)
		) ];

		// As such a save left it: enabled, the value there, and the row that names it gone.
		$b->query(
			sprintf(
				"DELETE FROM `%s` WHERE variant_id = %d AND axis_id = ( SELECT id FROM `%s` WHERE product_id = %d AND axis_key = 'size' )",
				$this->db->table( CatalogTables::VARIANT_OPTION_VALUES ),
				$damaged,
				$this->db->table( CatalogTables::OPTION_AXES ),
				$six->productId
			)
		);

		$this->assertSame( 1, $this->selectionRows( $b, $damaged ) );
		$this->assertSame( array( 'grind' => 'filter' ), array_column( $this->edit( $six->postId )['variants'][4]['combination'], 'value', 'axis' ) );

		$this->put( $six->postId, array( 'variants' => VariantSets::bare( VariantSets::variants( 'HEAL' ) ) ) );

		$this->assertSame( array( 'complete', 1, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );
		$this->assertEveryVariantWhole( $b, $six );
	}

	/**
	 * Tests that options alone that drop an axis leave a variant that lost a value behind with its generation, as every other, and that a restore finds it whole.
	 *
	 * Planted violation: in SaveProduct::applyVariantSet(), leave out the condition on the selection's
	 * hash. The row's one value is admitted under the axis left, a new row is tried under the stored
	 * variant's UUID and SKU, and the save answers `catalog.sku_duplicate`.
	 *
	 * @since 0.2.0
	 */
	public function test_options_alone_that_drop_an_axis_leave_a_variant_that_lost_a_value_behind(): void {
		$b   = $this->secondConnection();
		$six = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'LEFT' ) );

		$this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options( array( '250g' ) ),
				'variants' => VariantSets::variants( 'LEFT', array( '250g' ) ),
			)
		);

		$before = $this->committedVariants( $b, $six->productId );

		$this->put( $six->postId, array( 'options' => array( VariantSets::options()[1] ) ) );

		$this->assertSame( array( 'incomplete', 2, 0, 0 ), $this->committedGeneration( $b, $six->productId ), 'The next generation has no variant yet.' );
		$this->assertSame( $before, $this->committedVariants( $b, $six->productId ), 'No row was written, and none was added.' );

		$this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options(),
				'variants' => VariantSets::variants( 'LEFT' ),
			)
		);

		$this->assertSame( array( 'complete', 3, 6, 3 ), $this->committedGeneration( $b, $six->productId ), 'The former axes restored find the rows, in the state they were left.' );
		$this->assertEveryVariantWhole( $b, $six );
	}

	/**
	 * Tests that options alone that remove the only axis leave a variant that lost its value behind, rather than taking it for the default variant.
	 *
	 * Planted violation: as the test above; the variant's empty selection is admitted under no axes.
	 *
	 * @since 0.2.0
	 */
	public function test_options_alone_that_remove_the_only_axis_leave_a_variant_that_lost_its_value_behind(): void {
		$b       = $this->secondConnection();
		$sizes   = array( VariantSets::options()[0] );
		$entries = array(
			array(
				'combination' => VariantSets::combination( array( 'size' => '250g' ) ),
				'sku'         => 'ONE-1',
			),
			array(
				'combination' => VariantSets::combination( array( 'size' => '1kg' ) ),
				'sku'         => 'ONE-2',
			),
		);
		$one     = $this->saveSet( null, $sizes, $entries );

		$this->put(
			$one->postId,
			array(
				'options'  => array( VariantSets::options( array( '250g' ) )[0] ),
				'variants' => array( $entries[0] ),
			)
		);

		$before = $this->committedVariants( $b, $one->productId );

		$this->put( $one->postId, array( 'options' => array() ) );

		$this->assertSame( array( 'incomplete', 2, 0, 0 ), $this->committedGeneration( $b, $one->productId ) );
		$this->assertSame( $before, $this->committedVariants( $b, $one->productId ), 'No row was written, and none was added.' );
	}

	/**
	 * Fails unless every variant of the six has its two selection rows, and the edit read gives it its whole combination and the label that agrees, in the set's order.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b   The second connection.
	 * @param SaveResult       $six The save that wrote the six variants.
	 */
	private function assertEveryVariantWhole( SecondConnection $b, SaveResult $six ): void {
		$expected = array();
		$read     = array();

		foreach ( VariantSets::combinations() as $pairs ) {
			$this->assertSame( 2, $this->selectionRows( $b, $six->variantIds[ VariantSets::hash( $pairs ) ] ), 'The selection rows of ' . (string) wp_json_encode( $pairs ) );

			ksort( $pairs );

			$expected[] = array( $pairs, VariantSets::LABELS[ $pairs['size'] ] . ' / ' . VariantSets::LABELS[ $pairs['grind'] ] );
		}

		foreach ( $this->edit( $six->postId )['variants'] as $variant ) {
			$pairs = array_column( $variant['combination'], 'value', 'axis' );

			ksort( $pairs );

			$read[] = array( $pairs, $variant['label'] );
		}

		$this->assertSame( $expected, $read );
	}

	/**
	 * Sends a save's commerce fields through the REST server, and fails unless it is answered 200 with nothing reported.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $postId   The post.
	 * @param array<string, mixed> $commerce The fields.
	 */
	private function put( int $postId, array $commerce ): void {
		$response = $this->request( 'PUT', '/' . $postId, array( ProductCommerceSchema::PROPERTY => $commerce ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $this->failures );
	}

	/**
	 * Returns the `seocart` object of the edit read of a post.
	 *
	 * @since 0.2.0
	 *
	 * @param int $postId The post.
	 * @return array<string, mixed> The object.
	 */
	private function edit( int $postId ): array {
		return (array) ( $this->request( 'GET', '/' . $postId, array(), array( 'context' => 'edit' ) )->get_data()[ ProductCommerceSchema::PROPERTY ] ?? array() );
	}

	/**
	 * Returns the variants of a read as a client sends them back: all it read, but the stock cells it does not change.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<string, mixed>> $variants The variants read.
	 * @return list<array<string, mixed>> The variants to send.
	 */
	private static function sentBack( array $variants ): array {
		return array_map(
			static function ( array $variant ): array {
				unset( $variant['stock'] );

				return $variant;
			},
			$variants
		);
	}

	/**
	 * Counts a variant's committed selection rows.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return int The rows.
	 */
	private function selectionRows( SecondConnection $b, int $variantId ): int {
		return (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE variant_id = %d', $this->db->table( CatalogTables::VARIANT_OPTION_VALUES ), $variantId ) );
	}
}
