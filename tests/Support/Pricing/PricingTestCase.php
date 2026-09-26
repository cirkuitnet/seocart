<?php
/**
 * PricingTestCase: the base of the pricing integration tests, which price variants the catalog stored
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;

/**
 * Stores priced variants, adds prices in other currencies, and fingerprints the price table.
 *
 * Owns one fact: how a pricing integration test gets prices into the catalog's tables. A variant
 * is stored as a successful save leaves it, priced net in the base currency, USD; a price in
 * another currency is written by direct SQL, since a product save writes only the base price.
 *
 * @since 0.1.0
 */
abstract class PricingTestCase extends CatalogTestCase {

	/**
	 * Stores a whole product priced in the base currency, and returns its variant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sku    The SKU.
	 * @param string $amount The price in major units, net.
	 * @return int The variant's id.
	 */
	protected function pricedVariant( string $sku, string $amount ): int {
		$product = $this->boundProduct( $sku, Inputs::money( $amount, self::BASE_CURRENCY )->minorUnits() );

		$this->products->save( $product );
		$this->assertTrue( $this->products->leaveUpdating( (int) $product->id(), GenerationState::Complete ), 'The fixture product did not leave `updating`.' );

		return (int) $product->defaultVariant()?->id();
	}

	/**
	 * Adds a net price in another currency to a variant.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The variant.
	 * @param string $amount    The price in major units.
	 * @param string $currency  The ISO code.
	 */
	protected function addPrice( int $variantId, string $amount, string $currency ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, %s, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::VARIANT_PRICES ),
			$variantId,
			$currency,
			'net',
			Inputs::money( $amount, $currency )->minorUnits()
		);
	}

	/**
	 * Returns a fingerprint of every row of the price table.
	 *
	 * @since 0.1.0
	 *
	 * @return string The fingerprint.
	 */
	protected function pricesFingerprint(): string {
		return md5( (string) wp_json_encode( $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->catalogTable( CatalogTables::VARIANT_PRICES ) ) ) );
	}
}
