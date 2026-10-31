<?php
/**
 * StockSummaryProjector: keeps each product's stock summary in step with its stock and its saves
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Inventory\Application\Availability;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An event the projector does not hear is a wiring error for the developer, never HTML.

/**
 * Recomputes the stock summary of every product an event concerns, after the event's commit, and writes the ones that moved.
 *
 * Owns one fact: when a summary is computed. It hears the stock movements the outbox delivers (an
 * adjustment of on hand, an allocation to an order, an expired hold reclaimed) and every product
 * save, which can change the product's variants. For each event it finds the products concerned,
 * reads their enabled variants of the published generation and, with one call for them all, the
 * units each has for sale (Availability::forSale(), Inventory's public figure, over every active
 * fulfilling location), applies StockSummary::of(), and writes the products whose summary differs.
 * An event's own numbers are never used: the figure is read when the event is delivered, so a late
 * or repeated delivery writes the current value, and two deliveries in either order end at the
 * same one.
 *
 * It does not hear a checkout's hold or the hold given back (StockReserved,
 * StockReservationReleased): both are delivered right after their commit, inside the shopper's
 * checkout request, which its statements would slow. So a product whose last units a checkout
 * holds is still summarized in stock while the hold lasts; the paid order's allocation is what
 * takes it out of stock. And when anything else recomputes the summary during a hold (an
 * adjustment, a save, another order's allocation), it reads out of stock, and stays so after the
 * hold is given back (a declined or abandoned payment), until the product's next event or
 * `wp seocart catalog summarize-stock`. The summary is a guide for listings; a placement checks
 * what is for sale again.
 *
 * Nothing runs in a transaction: each write is one conditional UPDATE. A summary write touches no
 * `updated_at` and no `generation_state`, so it never makes a concurrent repair of the generation
 * marker miss. A failure is thrown to the hook bridge, which reports it; the summary stays as it
 * was until the next event or `wp seocart catalog summarize-stock`.
 *
 * The hook bridge puts it on its actions before the first dispatch of a process
 * (Modules::EVENT_LISTENERS), so a drain in any process delivers to it.
 *
 * @since 0.2.0
 */
final class StockSummaryProjector {

	/**
	 * The summaries.
	 *
	 * @since 0.2.0
	 *
	 * @var StockSummaries
	 */
	private StockSummaries $summaries;

	/**
	 * The units of each variant for sale.
	 *
	 * @since 0.2.0
	 *
	 * @var Availability
	 */
	private Availability $availability;

	/**
	 * Creates the projector. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param StockSummaries $summaries    The summaries.
	 * @param Availability   $availability The units of each variant for sale.
	 */
	public function __construct( StockSummaries $summaries, Availability $availability ) {
		$this->summaries    = $summaries;
		$this->availability = $availability;
	}

	/**
	 * Recomputes the summaries of the products an event concerns: the listener.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the event is not one the projector hears.
	 *
	 * @param DomainEvent   $event    The event.
	 * @param EventEnvelope $envelope Its delivery.
	 */
	public function on( DomainEvent $event, EventEnvelope $envelope ): void {
		unset( $envelope );

		$this->project( $event instanceof ProductSaved ? array( $event->productId ) : $this->summaries->productsOfVariants( self::variantsOf( $event ) ) );
	}

	/**
	 * Recomputes the summaries of some products and writes those that moved.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return int How many summaries were written.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function project( array $productIds ): int {
		if ( array() === $productIds ) {
			return 0;
		}

		$products = $this->summaries->enabledVariants( $productIds );
		$variants = array_merge( ...array_values( array_map( static fn( array $product ): array => $product['variants'], $products ) ) );
		$forSale  = array() === $variants ? array() : $this->availability->forSale( $variants, null );
		$written  = 0;

		foreach ( $products as $productId => $product ) {
			$summary = StockSummary::of( array_intersect_key( $forSale, array_flip( $product['variants'] ) ) );

			if ( $summary !== $product['summary'] && $this->summaries->write( $productId, $summary ) ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Returns the variants a stock event moved.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the event is not a stock event the projector hears.
	 *
	 * @param DomainEvent $event The event.
	 * @return list<int> The variants.
	 */
	private static function variantsOf( DomainEvent $event ): array {
		if ( $event instanceof StockAdjusted || $event instanceof StockHoldExpired ) {
			return array( $event->variantId );
		}

		if ( $event instanceof StockAllocated ) {
			return $event->variantIds;
		}

		throw new \LogicException( sprintf( 'The stock summary projector does not hear %s; list it in Modules::EVENT_LISTENERS only with a case here.', get_class( $event ) ) );
	}
}
