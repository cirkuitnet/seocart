<?php
/**
 * OrderStockLines: what an order's lines sell, and where the order is fulfilled from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * An order's lines as its stock is allocated, with the routing facts the order recorded at placement.
 *
 * Owns one fact: what the settlement of a placement needs of the order to allocate its units:
 * each line's id, variant and units, the market the order was placed in and the one location it
 * is fulfilled from. Both ids are NULL for an order that recorded none, such as one placed before
 * orders recorded where they are fulfilled from.
 *
 * @since 0.2.0
 */
final readonly class OrderStockLines {

	/**
	 * Holds the lines and the order's facts.
	 *
	 * @since 0.2.0
	 *
	 * @param array    $lines                The lines, by variant and then by id.
	 * @param int|null $marketId             The market the order was placed in, or null for none.
	 * @param int|null $fulfilmentLocationId The location the order is fulfilled from, or null when none was recorded.
	 *
	 * @phpstan-param list<array{orderLineId: int, variantId: int, quantity: int}> $lines
	 */
	public function __construct(
		public array $lines,
		public ?int $marketId,
		public ?int $fulfilmentLocationId
	) {
	}

	/**
	 * Returns the answer for an order that does not exist: no line, and no facts.
	 *
	 * @since 0.2.0
	 *
	 * @return self The empty answer.
	 */
	public static function none(): self {
		return new self( array(), null, null );
	}
}
