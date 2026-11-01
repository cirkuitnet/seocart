<?php
/**
 * MysqlPriceProjections: the products' sort prices, on MySQL
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the prices a product sorts by and writes its rows, each statement a constant judged by the query-plan run.
 *
 * Owns one fact: how the price projections are stored. PROJECTION_SOURCE reads a page of products'
 * enabled variants of the published generation, lowest position first, with every price of each,
 * in one statement by primary and leading keys; PROJECTIONS_OF_PRODUCTS their stored rows by
 * `product_currency`. A row is inserted, rewritten or deleted alone, by its own statement: no
 * transaction holds a product's rows, since each statement is whole on its own, and nothing here
 * touches a product's row, so the generation marker's compare-and-set never sees a projection as a
 * change. UPDATE_PROJECTION rewrites a row only when one of its values differs, so a row another
 * writer already brought to these values is not rewritten. A converted price's rate version is
 * sent as 0 for none, and stored as NULL.
 *
 * The job's and doctor's reads find the converted prices behind a version by `stale_converted`,
 * and doctor's count of the products without a base-currency row reads the complete products.
 *
 * @since 0.2.0
 */
final class MysqlPriceProjections implements PriceProjections {

	/**
	 * Some products' enabled variants of the published generation, lowest position first, each with every price it has.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROJECTION_SOURCE = 'SELECT v.product_id, v.id AS variant_id, vp.currency, vp.price_minor, vp.amount_basis'
		. ' FROM {products} p JOIN {variants} v ON v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1'
		. ' LEFT JOIN {variant_prices} vp ON vp.variant_id = v.id'
		. ' WHERE p.id IN ({list}) ORDER BY v.product_id, v.position, v.id';

	/**
	 * Some products' stored rows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROJECTIONS_OF_PRODUCTS = 'SELECT id, product_id, currency, sort_price_minor, amount_basis, has_explicit_price, rate_version FROM {product_price_projections} WHERE product_id IN ({list})';

	/**
	 * A product's row in a currency it has none in; a rate version of 0 is stored as NULL.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_PROJECTION = 'INSERT INTO {product_price_projections} ( product_id, currency, sort_price_minor, amount_basis, has_explicit_price, rate_version, created_at, updated_at )'
		. ' VALUES ( %d, %s, %d, %s, %d, NULLIF( %d, 0 ), UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )';

	/**
	 * A stored row's new values, written only when one of them differs from what it holds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_PROJECTION = 'UPDATE {product_price_projections} SET sort_price_minor = %d, amount_basis = %s, has_explicit_price = %d, rate_version = NULLIF( %d, 0 ), updated_at = UTC_TIMESTAMP(6)'
		. ' WHERE id = %d AND ( sort_price_minor <> %d OR amount_basis <> %s OR has_explicit_price <> %d OR NOT ( rate_version <=> NULLIF( %d, 0 ) ) )';

	/**
	 * A stored row the rule no longer gives.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_PROJECTION = 'DELETE FROM {product_price_projections} WHERE id = %d';

	/**
	 * Every row of a product.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_OF_PRODUCT = 'DELETE FROM {product_price_projections} WHERE product_id = %d';

	/**
	 * Some products with a converted price behind a version, by `stale_converted`; a page leaves the set as it is written.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STALE_PROJECTIONS = 'SELECT DISTINCT product_id FROM {product_price_projections} WHERE has_explicit_price = 0 AND rate_version < %d LIMIT %d';

	/**
	 * The converted prices behind a version, by `stale_converted`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STALE_PRICES = 'SELECT COUNT(*) AS stale FROM {product_price_projections} WHERE has_explicit_price = 0 AND rate_version < %d';

	/**
	 * The complete products with an enabled variant, whose listed variant has a price in the base currency, that have no row in it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNPROJECTED = 'SELECT COUNT(*) AS products FROM {products} p WHERE p.generation_state = %s AND p.enabled_variant_count > 0'
		. ' AND EXISTS ( SELECT 1 FROM {variant_prices} vp WHERE vp.currency = %s AND vp.variant_id = ( SELECT v.id FROM {variants} v WHERE v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1 ORDER BY v.position, v.id LIMIT 1 ) )'
		. ' AND NOT EXISTS ( SELECT 1 FROM {product_price_projections} pr WHERE pr.product_id = p.id AND pr.currency = %s )';

	/**
	 * Sends the catalog's statements.
	 *
	 * @since 0.2.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the store. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->statements = new ModuleStatements( $db, 'Catalog', array_map( static fn( TableDefinition $table ): string => $table->name(), CatalogTables::all() ) );
	}

	/**
	 * Returns the variants each product is priced from, with PROJECTION_SOURCE.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, list<array{variant_id: int, prices: array<string, array{price_minor: int, amount_basis: string}>}>> By product id.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function sources( array $productIds ): array {
		$sources = array();

		if ( array() === $productIds ) {
			return $sources;
		}

		$at = array();

		foreach ( $this->statements->rows( self::PROJECTION_SOURCE, $productIds ) as $row ) {
			$productId = (int) $row['product_id'];
			$variantId = (int) $row['variant_id'];

			// The rows of a variant come together, its variants lowest position first: a new variant opens an entry.
			if ( ( $at[ $productId ] ?? null ) !== $variantId ) {
				$sources[ $productId ][] = array(
					'variant_id' => $variantId,
					'prices'     => array(),
				);
				$at[ $productId ]        = $variantId;
			}

			if ( null !== $row['currency'] ) {
				$sources[ $productId ][ count( $sources[ $productId ] ) - 1 ]['prices'][ (string) $row['currency'] ] = array(
					'price_minor'  => (int) $row['price_minor'],
					'amount_basis' => (string) $row['amount_basis'],
				);
			}
		}

		return $sources;
	}

	/**
	 * Returns the stored rows of some products, with PROJECTIONS_OF_PRODUCTS.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, array<string, array{id: int, sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null}>> By product id, then currency code.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function stored( array $productIds ): array {
		$stored = array();

		if ( array() === $productIds ) {
			return $stored;
		}

		foreach ( $this->statements->rows( self::PROJECTIONS_OF_PRODUCTS, $productIds ) as $row ) {
			$stored[ (int) $row['product_id'] ][ (string) $row['currency'] ] = array(
				'id'                 => (int) $row['id'],
				'sort_price_minor'   => (int) $row['sort_price_minor'],
				'amount_basis'       => (string) $row['amount_basis'],
				'has_explicit_price' => 1 === (int) $row['has_explicit_price'],
				'rate_version'       => null === $row['rate_version'] ? null : (int) $row['rate_version'],
			);
		}

		return $stored;
	}

	/**
	 * Stores a product's row in a currency, with INSERT_PROJECTION.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $currency  The currency's code.
	 * @param array  $row       The row.
	 *
	 * @phpstan-param array{sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null} $row
	 */
	public function insert( int $productId, string $currency, array $row ): void {
		$this->statements->execute( self::INSERT_PROJECTION, $productId, $currency, $row['sort_price_minor'], $row['amount_basis'], $row['has_explicit_price'] ? 1 : 0, (int) $row['rate_version'] );
	}

	/**
	 * Rewrites a stored row whose values differ, with UPDATE_PROJECTION.
	 *
	 * @since 0.2.0
	 *
	 * @param int   $id  The stored row's id.
	 * @param array $row The values.
	 * @return bool True when the row changed.
	 *
	 * @phpstan-param array{sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null} $row
	 */
	public function update( int $id, array $row ): bool {
		$values = array( $row['sort_price_minor'], $row['amount_basis'], $row['has_explicit_price'] ? 1 : 0, (int) $row['rate_version'] );

		return 1 === $this->statements->execute( self::UPDATE_PROJECTION, ...array_merge( $values, array( $id ), $values ) );
	}

	/**
	 * Deletes a stored row, with DELETE_PROJECTION.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id The stored row's id.
	 * @return bool True when it was deleted.
	 */
	public function delete( int $id ): bool {
		return 1 === $this->statements->execute( self::DELETE_PROJECTION, $id );
	}

	/**
	 * Deletes every row of a product, with DELETE_OF_PRODUCT.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return int How many rows were deleted.
	 */
	public function deleteOf( int $productId ): int {
		return $this->statements->execute( self::DELETE_OF_PRODUCT, $productId );
	}

	/**
	 * Returns some products with a converted price behind a version, with STALE_PROJECTIONS.
	 *
	 * @since 0.2.0
	 *
	 * @param int $version The current version.
	 * @param int $limit   The most products to return.
	 * @return list<int> The products.
	 */
	public function staleProductIds( int $version, int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['product_id'], $this->statements->rows( self::STALE_PROJECTIONS, $version, $limit ) );
	}

	/**
	 * Counts the converted prices behind a version, with STALE_PRICES.
	 *
	 * @since 0.2.0
	 *
	 * @param int $version The current version.
	 * @return int The count.
	 */
	public function staleCount( int $version ): int {
		return (int) ( $this->statements->rows( self::STALE_PRICES, $version )[0]['stale'] ?? 0 );
	}

	/**
	 * Counts the complete products without a row in the base currency, with UNPROJECTED.
	 *
	 * @since 0.2.0
	 *
	 * @param string $baseCurrency The store's base currency's code.
	 * @return int The count.
	 */
	public function unprojectedCount( string $baseCurrency ): int {
		return (int) ( $this->statements->rows( self::UNPROJECTED, GenerationState::Complete->value, $baseCurrency, $baseCurrency )[0]['products'] ?? 0 );
	}
}
