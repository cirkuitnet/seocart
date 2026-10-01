<?php
/**
 * UsageClaim: one use of a promotion that an order claims
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Application;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages report a caller's programming error to the developer; they are never rendered.

/**
 * A use of a promotion by an order, with the net discount the promotion gave it.
 *
 * Owns one fact: what a `promotion_usage` row records. The discount, before tax, and its
 * base-currency twin are the calculation's own figures for the promotion's source
 * (Totals::discountOf()), signed as every discount is: zero or negative. The caller never adds
 * them up itself. The customer and the hash of the cart's token are what a per-customer limit
 * will count; either may be absent.
 *
 * @since 0.1.0
 */
final readonly class UsageClaim {

	/**
	 * Checks and holds the claim.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an id is not positive, the discount is positive, or the hash is not a SHA-256 in hexadecimal.
	 *
	 * @param int         $promotionId   The promotion.
	 * @param int         $orderId       The order that uses it.
	 * @param Money       $amount        The net discount it gave the order, zero or negative.
	 * @param Money       $base          The same net discount in the base currency.
	 * @param int|null    $customerId    Optional. The customer; null for a guest. Default null.
	 * @param string|null $cartTokenHash Optional. The SHA-256 of the cart's token, as `carts.token_hash` holds it; null for none. Default null.
	 */
	public function __construct(
		public int $promotionId,
		public int $orderId,
		public Money $amount,
		public Money $base,
		public ?int $customerId = null,
		public ?string $cartTokenHash = null
	) {
		if ( $promotionId < 1 || $orderId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A usage claim names a promotion and an order by their ids; %d and %d are not ids.', $promotionId, $orderId ) );
		}

		foreach ( array( $amount, $base ) as $discount ) {
			if ( $discount->compare( Money::zero( $discount->currency() ) ) > 0 ) {
				throw new \InvalidArgumentException( 'A promotion\'s discount is zero or negative, as Totals::discountOf() gives it.' );
			}
		}

		if ( null !== $cartTokenHash && 1 !== preg_match( '/^[0-9a-f]{64}\z/', $cartTokenHash ) ) {
			throw new \InvalidArgumentException( 'A cart token hash is the SHA-256 of the token, in lower-case hexadecimal.' );
		}
	}
}
