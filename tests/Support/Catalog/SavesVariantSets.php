<?php
/**
 * SavesVariantSets: saves coffee with options and variants through SaveProduct, and reads what committed
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Tests\Support\SecondConnection;

/**
 * For a ProductWriteTestCase: a save of options and a variant set, and the committed rows a second connection reads.
 *
 * @since 0.2.0
 */
trait SavesVariantSets {

	/**
	 * Saves options and variants, creating a published post when none is named.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null                   $postId   The post, or null for a new one.
	 * @param list<array<string, mixed>> $options  The options.
	 * @param list<array<string, mixed>> $variants The variants.
	 * @return SaveResult The result.
	 */
	protected function saveSet( ?int $postId, array $options, array $variants ): SaveResult {
		return $this->save(
			$postId,
			null === $postId ? array(
				'post_title'  => 'Coffee',
				'post_status' => 'publish',
			) : array(),
			array(
				'options'  => $options,
				'variants' => $variants,
			)
		);
	}

	/**
	 * Reads a product's committed variants, of every generation, by id.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $productId The product.
	 * @return array<int, array{generation: int, enabled: int, sku: string, hash: string, label: string|null}> The variants.
	 */
	protected function committedVariants( SecondConnection $b, int $productId ): array {
		$variants = array();

		$b->query( 'SET SESSION group_concat_max_len = 1000000' );

		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT_WS( '|', id, generation, is_enabled, sku, combination_hash, IFNULL( label, '' ) ) ORDER BY id SEPARATOR '\\n' ) FROM `%s` WHERE product_id = %d", $this->db->table( CatalogTables::VARIANTS ), $productId ) );

		foreach ( '' === $rows ? array() : explode( "\n", $rows ) as $row ) {
			list( $id, $generation, $enabled, $sku, $hash, $label ) = explode( '|', $row );

			$variants[ (int) $id ] = array(
				'generation' => (int) $generation,
				'enabled'    => (int) $enabled,
				'sku'        => $sku,
				'hash'       => $hash,
				'label'      => '' === $label ? null : $label,
			);
		}

		return $variants;
	}

	/**
	 * Reads a variant's committed prices, by currency.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return list<string> Each price as `currency:basis:price`, followed by `:compare-at` when it has one.
	 */
	protected function committedPrices( SecondConnection $b, int $variantId ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT_WS( ':', currency, amount_basis, price_minor, compare_at_minor ) ORDER BY currency SEPARATOR ' ' ) FROM `%s` WHERE variant_id = %d", $this->db->table( CatalogTables::VARIANT_PRICES ), $variantId ) );

		return '' === $rows ? array() : explode( ' ', $rows );
	}

	/**
	 * Reads a variant's committed weight.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return string|null The weight in grams, as the server sends it, or null when it has none.
	 */
	protected function committedWeight( SecondConnection $b, int $variantId ): ?string {
		$weight = $b->fetchValue( sprintf( 'SELECT weight_grams FROM `%s` WHERE id = %d', $this->db->table( CatalogTables::VARIANTS ), $variantId ) );

		return null === $weight ? null : (string) $weight;
	}

	/**
	 * Saves a published product without options whose one variant has gross prices in two currencies.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sku     The SKU.
	 * @param bool   $enabled Optional. Whether the variant may be sold. Default true.
	 * @return SaveResult The result.
	 */
	protected function savedOnGrossPrices( string $sku, bool $enabled = true ): SaveResult {
		return $this->save(
			null,
			array(
				'post_title'  => 'Taxed coffee',
				'post_status' => 'publish',
			),
			array(
				'variants' => array(
					array(
						'combination'  => array(),
						'sku'          => $sku,
						'enabled'      => $enabled,
						'weight_grams' => 7,
						'prices'       => array(
							array(
								'currency'         => 'USD',
								'price_minor'      => 1234,
								'compare_at_minor' => 2000,
								'amount_basis'     => 'gross',
							),
							array(
								'currency'     => 'EUR',
								'price_minor'  => 999,
								'amount_basis' => 'gross',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Reads a product's committed generation and counts.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $productId The product.
	 * @return array{0: string, 1: int, 2: int, 3: int} The marker, the published generation, the variant count and the enabled count.
	 */
	protected function committedGeneration( SecondConnection $b, int $productId ): array {
		$row = $b->fetchRow( sprintf( 'SELECT generation_state, active_variant_generation, variant_count, enabled_variant_count FROM `%s` WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), $productId ) ) ?? array();

		return array( (string) ( $row['generation_state'] ?? '' ), (int) ( $row['active_variant_generation'] ?? 0 ), (int) ( $row['variant_count'] ?? 0 ), (int) ( $row['enabled_variant_count'] ?? 0 ) );
	}
}
