<?php
/**
 * Tests what a client is answered when another writer commits between a save's check and its window, or inside its window
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Database\ServerKind;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;
use SEOCart\Tests\Support\SecondConnection;
use WP_REST_Response;

/**
 * A save through `wp/v2/seocart-products` that loses a race is refused with its own code: nothing of it stands, the product's marker is put back, and nothing is reported as an internal error.
 *
 * Each race is a real one, by barriers on the statements: the other writer commits at a named
 * point of the save, either before the window, as a save on this connection whose checks came
 * first, or inside it, from a second connection, as a hold does.
 *
 * Planted violations, each failing a test here:
 * - In SaveProduct::applyFlatFields(), leave out the refusal of a product with options: a save that
 *   gives a SKU answers 500, one that gives none `catalog.sku_invalid`.
 * - In SaveProduct::updateInTurn(), run the window with
 *   `$this->transactions->transaction( ..., RetryPolicy::none() )` again: on MariaDB the save answers
 *   503 `database.transaction_retryable`, reported as an internal error. On MySQL nothing changes:
 *   its upsert goes on over the hold's row, and the plant does not fail there.
 *
 * @group concurrency
 *
 * @since 0.2.0
 */
final class ProductPostsConcurrencyTest extends ProductRestTestCase {

	use SavesVariantSets;

	/**
	 * What the first statement of a variant's stock item looks like: its stock policy.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const STOCK_POLICY = '/^INSERT INTO `?[a-z0-9_]*stock_policies/i';

	/**
	 * What the read of the rows of the combinations a save names looks like.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ROWS_OF_COMBINATIONS = '/v\.combination_hash IN \(/';

	/**
	 * Tests that the single variant's fields are refused as ambiguous when a save that gave the product options commits after their check, whichever fields they are; nothing of them is written.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider flatFields
	 *
	 * @param array<string, mixed> $flat The fields.
	 */
	public function test_flat_fields_after_a_save_that_gave_the_product_options_are_ambiguous( array $flat ): void {
		$b     = $this->secondConnection();
		$saved = $this->save(
			null,
			array(
				'post_title'  => 'Flat coffee',
				'post_status' => 'publish',
			),
			array(
				'sku'         => 'FLAT',
				'price_minor' => 100,
			)
		);
		$sums  = array();
		$raced = $this->beforeStatement(
			'/GET_LOCK|^INSERT INTO `?[a-z0-9_]*locks/i',
			function () use ( $b, $saved, &$sums ): void {
				$this->saveSet(
					$saved->postId,
					array( VariantSets::options()[0] ),
					array(
						array(
							'combination' => VariantSets::combination( array( 'size' => '250g' ) ),
							'sku'         => 'FLAT-250G',
							'prices'      => array(
								array(
									'currency'    => 'USD',
									'price_minor' => 900,
								),
							),
						),
					)
				);

				$sums = self::withoutProducts( $this->catalogChecksums( $b ) );
			}
		);

		$response = $this->put( $saved->postId, $flat );

		$this->assertTrue( $raced->fired, 'The save that gives options never committed, so nothing raced this one.' );
		$this->assertSame( array( 400, CatalogError::VariantsAmbiguous->value ), array( $response->get_status(), $response->get_data()['code'] ?? null ), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $this->failures, 'Reported as an internal error.' );
		$this->assertSame( $sums, self::withoutProducts( $this->catalogChecksums( $b ) ), 'The refused save wrote a row.' );
		$this->assertSame( array( 'complete', 2, 1, 1 ), $this->committedGeneration( $b, $saved->productId ), 'The marker was not put back.' );
		$this->assertSame( array(), $this->sleeps, 'The window ran again.' );
	}

	/**
	 * Returns the fields of a flat save: with a SKU, the one that would create a variant, and without one.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: array<string, mixed>}> The fields, by what they are.
	 */
	public function flatFields(): array {
		return array(
			'a SKU'    => array( array( 'sku' => 'FLAT-2' ) ),
			'a price'  => array( array( 'price_minor' => 300 ) ),
			'a weight' => array( array( 'weight_grams' => 5 ) ),
		);
	}

	/**
	 * Tests a hold that commits inside the window of a flat save: MySQL saves beside it; MariaDB refuses with a write conflict, writes nothing and puts the marker back, and the save sent again stands. The hold stands either way.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_committing_inside_the_window_of_a_flat_save(): void {
		$b         = $this->secondConnection();
		$saved     = $this->save(
			null,
			array(
				'post_title'  => 'Held coffee',
				'post_status' => 'publish',
			),
			array(
				'sku'         => 'HELD',
				'price_minor' => 100,
			)
		);
		$variantId = (int) $saved->variantId;
		$raced     = $this->holdCommitsBefore( self::STOCK_POLICY, $b, $variantId );
		$response  = $this->put( $saved->postId, array( 'price_minor' => 200 ) );

		$this->assertTrue( $raced->fired, 'The hold never committed, so nothing raced the save.' );
		$this->assertSame( 1, $this->held( $b, $variantId ), 'The hold was lost.' );
		$this->assertSame( array(), $this->failures, 'Reported as an internal error.' );
		$this->assertSame( array(), $this->sleeps, 'The window ran again.' );
		$this->assertSame( array( 'complete', 1, 1, 1 ), $this->committedGeneration( $b, $saved->productId ), 'The marker was not put back.' );

		if ( ServerKind::MariaDB !== $this->db->server()->kind() ) {
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$this->assertSame( array( 'USD:net:200' ), $this->committedPrices( $b, $variantId ) );

			return;
		}

		$this->assertSame( array( 409, CatalogError::WriteConflict->value ), array( $response->get_status(), $response->get_data()['code'] ?? null ), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( 'USD:net:100' ), $this->committedPrices( $b, $variantId ), 'The refused save wrote the price.' );

		$this->assertSame( 200, $this->put( $saved->postId, array( 'price_minor' => 200 ) )->get_status(), 'The save sent again.' );
		$this->assertSame( array( 'USD:net:200' ), $this->committedPrices( $b, $variantId ) );
		$this->assertSame( 1, $this->held( $b, $variantId ) );
	}

	/**
	 * Tests a hold on a variant a restore brings back that commits inside its window: MySQL restores beside it; MariaDB refuses with a write conflict, every variant and the generation as they were. The hold stands either way.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_committing_inside_the_window_of_a_restore(): void {
		$b         = $this->secondConnection();
		$six       = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'HELD' ) );
		$variantId = array_values( $six->variantIds )[0];

		$this->saveSet( $six->postId, VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ), VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, array( 'dark' ) ) );

		$sums     = self::withoutProducts( $this->catalogChecksums( $b ) );
		$raced    = $this->holdCommitsBefore( self::ROWS_OF_COMBINATIONS, $b, $variantId );
		$response = $this->put(
			$six->postId,
			array(
				'options'  => VariantSets::options(),
				'variants' => VariantSets::withoutPrices( VariantSets::variants( 'HELD' ) ),
			)
		);

		$this->assertTrue( $raced->fired, 'The hold never committed, so nothing raced the save.' );
		$this->assertSame( 1, $this->held( $b, $variantId ), 'The hold was lost.' );
		$this->assertSame( array(), $this->failures, 'Reported as an internal error.' );
		$this->assertSame( array(), $this->sleeps, 'The window ran again.' );

		if ( ServerKind::MariaDB !== $this->db->server()->kind() ) {
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$this->assertSame( array( 'complete', 3, 6, 6 ), $this->committedGeneration( $b, $six->productId ) );

			return;
		}

		$this->assertSame( array( 409, CatalogError::WriteConflict->value ), array( $response->get_status(), $response->get_data()['code'] ?? null ), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( 'complete', 2, 6, 6 ), $this->committedGeneration( $b, $six->productId ), 'The generation and the marker were not put back.' );
		$this->assertSame( $sums, self::withoutProducts( $this->catalogChecksums( $b ) ), 'The refused save wrote a row.' );
	}

	/**
	 * Sends a PUT of commerce fields through the REST server.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $postId   The post.
	 * @param array<string, mixed> $commerce The fields.
	 * @return WP_REST_Response The response.
	 */
	private function put( int $postId, array $commerce ): WP_REST_Response {
		return $this->request( 'PUT', '/' . $postId, array( ProductCommerceSchema::PROPERTY => $commerce ) );
	}

	/**
	 * Has a hold of one unit of a variant commit from the second connection just before a statement leaves.
	 *
	 * @since 0.2.0
	 *
	 * @param string           $statement What the statement looks like.
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return object The barrier; its `fired` tells whether the hold committed.
	 */
	private function holdCommitsBefore( string $statement, SecondConnection $b, int $variantId ): object {
		$items = $this->db->table( InventoryTables::ITEMS );

		$b->query( sprintf( 'UPDATE `%s` SET on_hand = 5 WHERE variant_id = %d', $items, $variantId ) );

		return $this->beforeStatement(
			$statement,
			static function () use ( $b, $items, $variantId ): void {
				$b->query( sprintf( 'UPDATE `%s` SET held = held + 1 WHERE variant_id = %d', $items, $variantId ) );
			}
		);
	}

	/**
	 * Reads how many units of a variant are committed as held.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return int The units.
	 */
	private function held( SecondConnection $b, int $variantId ): int {
		return (int) $b->fetchValue( sprintf( 'SELECT held FROM `%s` WHERE variant_id = %d', $this->db->table( InventoryTables::ITEMS ), $variantId ) );
	}

	/**
	 * Leaves out the products table of the checksums: a mark and its restore move a timestamp there, which is what they do.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $sums The checksums, by table.
	 * @return array<string, string> The others.
	 */
	private static function withoutProducts( array $sums ): array {
		return array_diff_key( $sums, array( CatalogTables::PRODUCTS => true ) );
	}
}
