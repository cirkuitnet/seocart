<?php
/**
 * ReferenceCarts: the fixed carts the cart and placement budgets are measured on
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Performance;

use SEOCart\Cart\Domain\CartLine;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * The reference carts, so that a budget measured on one means the same thing on every run.
 *
 * Owns one fact: what each reference cart holds, and at what prices.
 *
 * - Cart A: one line, one unit, at 19.99.
 * - Cart B: ten lines across eight products: the first two products have two variants in the
 *   cart each, the other six one each; one to four units a line, at prices chosen so that tax
 *   lands on remainders.
 *
 * Neither has an add-on, a personalization or a promotion code yet, and a cart has no destination,
 * so its totals carry no shipping: the stub flat rate applies once an address is known, at
 * checkout. A cart is built from the variant ids a test gives, one per variant slot, in slot
 * order, so the same shape holds over any catalog the test stored; productOfSlot() says which
 * product each slot's variant must belong to. seedPrices() gives the variants their prices in the
 * base currency, straight into the price table, which is all the calculation reads; a test that
 * needs sellable products stores them through the catalog at unitPrice() instead.
 *
 * @since 0.1.0
 */
final class ReferenceCarts {

	/**
	 * How many variants Cart B needs.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CART_B_VARIANTS = 10;

	/**
	 * How many products Cart B's variants belong to.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CART_B_PRODUCTS = 8;

	/**
	 * The price of Cart A's line, net, in major units.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CART_A_PRICE = '19.99';

	/**
	 * Cart B's lines: for each variant slot, the product it belongs to, the units and the price, net, in major units.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{product: int, quantity: int, price: string}>
	 */
	private const CART_B = array(
		array(
			'product'  => 0,
			'quantity' => 1,
			'price'    => '19.99',
		),
		array(
			'product'  => 0,
			'quantity' => 2,
			'price'    => '4.95',
		),
		array(
			'product'  => 1,
			'quantity' => 3,
			'price'    => '1.15',
		),
		array(
			'product'  => 1,
			'quantity' => 1,
			'price'    => '99.00',
		),
		array(
			'product'  => 2,
			'quantity' => 1,
			'price'    => '0.35',
		),
		array(
			'product'  => 3,
			'quantity' => 4,
			'price'    => '2.49',
		),
		array(
			'product'  => 4,
			'quantity' => 1,
			'price'    => '12.05',
		),
		array(
			'product'  => 5,
			'quantity' => 2,
			'price'    => '7.45',
		),
		array(
			'product'  => 6,
			'quantity' => 1,
			'price'    => '24.00',
		),
		array(
			'product'  => 7,
			'quantity' => 3,
			'price'    => '3.10',
		),
	);

	/**
	 * Returns Cart A's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant of its one line.
	 * @return list<CartLine> One line of one unit.
	 */
	public static function cartA( int $variantId ): array {
		return array( CartLine::of( $variantId, 1 ) );
	}

	/**
	 * Returns Cart B's lines.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the ids are not CART_B_VARIANTS distinct variants.
	 *
	 * @param int[] $variantIds One variant per slot, in slot order; the variants of slots 0 and 1
	 *                          belong to one product, those of slots 2 and 3 to another, and each
	 *                          later slot's to a product of its own.
	 * @return list<CartLine> Ten lines.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public static function cartB( array $variantIds ): array {
		self::checkCartB( $variantIds );

		$lines = array();

		foreach ( self::CART_B as $slot => $line ) {
			$lines[] = CartLine::of( $variantIds[ $slot ], $line['quantity'] );
		}

		return $lines;
	}

	/**
	 * Returns the product, 0 to CART_B_PRODUCTS - 1, the variant of a Cart B slot belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $slot The slot, 0 to CART_B_VARIANTS - 1.
	 * @return int The product.
	 */
	public static function productOfSlot( int $slot ): int {
		return self::CART_B[ $slot ]['product'];
	}

	/**
	 * Returns the net price of a Cart B slot's variant, in major units.
	 *
	 * @since 0.1.0
	 *
	 * @param int $slot The slot, 0 to CART_B_VARIANTS - 1.
	 * @return string The price, such as `19.99`.
	 */
	public static function unitPrice( int $slot ): string {
		return self::CART_B[ $slot ]['price'];
	}

	/**
	 * Gives Cart A's variant and Cart B's variants their net prices in a currency, straight into the price table.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When Cart B's ids are not CART_B_VARIANTS distinct variants.
	 *
	 * @param Database $db          The connection; the catalog's tables must exist.
	 * @param int      $cartAVariant Cart A's variant.
	 * @param int[]    $cartBVariants Cart B's variants, one per slot, in slot order.
	 * @param string   $currency    The currency, the store's base currency.
	 *
	 * @phpstan-param list<int> $cartBVariants
	 */
	public static function seedPrices( Database $db, int $cartAVariant, array $cartBVariants, string $currency ): void {
		self::checkCartB( $cartBVariants );

		$prices = array( $cartAVariant => self::CART_A_PRICE );

		foreach ( self::CART_B as $slot => $line ) {
			$prices[ $cartBVariants[ $slot ] ] = $line['price'];
		}

		foreach ( $prices as $variantId => $price ) {
			$db->execute(
				"INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, 'net', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
				$db->table( CatalogTables::VARIANT_PRICES ),
				$variantId,
				$currency,
				Inputs::money( $price, $currency )->minorUnits()
			);
		}
	}

	/**
	 * Checks Cart B's variant ids.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When they are not CART_B_VARIANTS distinct variants.
	 *
	 * @param int[] $variantIds The ids.
	 */
	private static function checkCartB( array $variantIds ): void {
		if ( self::CART_B_VARIANTS !== count( $variantIds ) || self::CART_B_VARIANTS !== count( array_unique( $variantIds ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'Cart B needs %d distinct variants.', self::CART_B_VARIANTS ) );
		}
	}
}
