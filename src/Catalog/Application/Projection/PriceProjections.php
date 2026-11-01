<?php
/**
 * PriceProjections: the reads and writes of the products' sort prices
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what a product's sort prices are computed from, and keeps the stored ones.
 *
 * Owns one fact: where the sort prices are kept and how they are found. The projector, the job
 * that reprices after a rate change, the command that projects the whole catalog, doctor's lines
 * and a product's deletion read and write through it; MysqlPriceProjections is the implementation.
 * No write touches a product's own row.
 *
 * @since 0.2.0
 */
interface PriceProjections {

	/**
	 * Returns the variants each product is priced from: its enabled variants of the published generation, lowest position first, with their prices.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, list<array{variant_id: int, prices: array<string, array{price_minor: int, amount_basis: string}>}>> By product id;
	 *         a product with no enabled variant, or that no longer exists, is absent.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function sources( array $productIds ): array;

	/**
	 * Returns the stored rows of some products.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, array<string, array{id: int, sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null}>> By
	 *         product id, then by currency code.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function stored( array $productIds ): array;

	/**
	 * Stores a product's row in a currency it has none in.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $currency  The currency's code.
	 * @param array  $row       The row, as PriceProjection::rowsOf() gives it.
	 *
	 * @phpstan-param array{sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null} $row
	 */
	public function insert( int $productId, string $currency, array $row ): void;

	/**
	 * Rewrites a stored row with other values, unless it holds them already.
	 *
	 * @since 0.2.0
	 *
	 * @param int   $id  The stored row's id.
	 * @param array $row The values, as PriceProjection::rowsOf() gives them.
	 * @return bool True when the row changed.
	 *
	 * @phpstan-param array{sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null} $row
	 */
	public function update( int $id, array $row ): bool;

	/**
	 * Deletes a stored row the rule no longer gives.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id The stored row's id.
	 * @return bool True when it was deleted.
	 */
	public function delete( int $id ): bool;

	/**
	 * Deletes every row of a product, as its deletion does.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return int How many rows were deleted.
	 */
	public function deleteOf( int $productId ): int;

	/**
	 * Returns some products with a converted price behind an exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @param int $version The current version.
	 * @param int $limit   The most products to return.
	 * @return list<int> The products, each once, in no order.
	 */
	public function staleProductIds( int $version, int $limit ): array;

	/**
	 * Counts the converted prices behind an exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @param int $version The current version.
	 * @return int The count.
	 */
	public function staleCount( int $version ): int;

	/**
	 * Counts the complete products whose listed variant has a base price and that have no row in the base currency.
	 *
	 * @since 0.2.0
	 *
	 * @param string $baseCurrency The store's base currency's code.
	 * @return int The count.
	 */
	public function unprojectedCount( string $baseCurrency ): int;
}
