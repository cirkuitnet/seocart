<?php
/**
 * PriceProjector: keeps each product's sort prices in step with its saves and the exchange rates
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An event the projector does not hear is a wiring error for the developer, never HTML.

/**
 * Recomputes a product's sort prices after each save, and queues their repricing after each new exchange-rate version.
 *
 * Owns one fact: when a sort price is computed. It hears every product save (ProductSaved), which
 * can change a product's variants and prices, and recomputes that product's rows at once. It hears
 * a new version of the exchange rates (pricing's `exchange_rate_version_changed`, named here by its
 * event name, since the catalog imports nothing of pricing) and queues one job for the version,
 * REPRICE_JOB, which rewrites the converted prices behind it a page at a time. A delivery of the
 * same version again queues nothing more.
 *
 * project() reads a page of products' variants with their prices, and their stored rows, then
 * writes only what differs from PriceProjection's rule: a row inserted for a currency without one,
 * a row rewritten whose values moved, a row deleted the rule no longer gives. A second run over the
 * same products writes nothing. Prices are read when the event is delivered, never taken from its
 * payload, so a late or repeated delivery writes the current rows. Nothing runs in a transaction
 * and no product row is written: each row's statement is whole on its own, and the generation
 * marker's compare-and-set never sees a projection as a change of the product.
 *
 * The hook bridge puts it on its actions before the first dispatch of a process
 * (Modules::EVENT_LISTENERS), so a drain in any process delivers to it.
 *
 * @since 0.2.0
 */
final class PriceProjector {

	/**
	 * The name of the job that reprices the converted prices behind the current exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPRICE_JOB = 'catalog.reprice_projections';

	/**
	 * The name of pricing's event of a new exchange-rate version: ExchangeRateVersionChanged::eventName(), spelled out, as a test holds it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RATE_VERSION_CHANGED = 'exchange_rate_version_changed';

	/**
	 * The sort prices.
	 *
	 * @since 0.2.0
	 *
	 * @var PriceProjections
	 */
	private PriceProjections $projections;

	/**
	 * The currencies offered, and the conversion.
	 *
	 * @since 0.2.0
	 *
	 * @var CurrencyTerms
	 */
	private CurrencyTerms $terms;

	/**
	 * Returns the job queue, resolved only when a rate change asks for it.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): JobQueue
	 */
	private \Closure $queue;

	/**
	 * Creates the projector. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param PriceProjections $projections The sort prices.
	 * @param CurrencyTerms    $terms       The currencies offered, and the conversion.
	 * @param \Closure         $queue       Returns the job queue (JobQueue).
	 *
	 * @phpstan-param \Closure(): JobQueue $queue
	 */
	public function __construct( PriceProjections $projections, CurrencyTerms $terms, \Closure $queue ) {
		$this->projections = $projections;
		$this->terms       = $terms;
		$this->queue       = $queue;
	}

	/**
	 * Recomputes a saved product's sort prices, or queues the repricing of a new rate version: the listener.
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

		if ( $event instanceof ProductSaved ) {
			$this->project( array( $event->productId ) );

			return;
		}

		if ( self::RATE_VERSION_CHANGED === $event::eventName() ) {
			$version = (int) ( $event->toPayload()['version'] ?? 0 );

			( $this->queue )()->enqueue( new Job( self::REPRICE_JOB, array( 'rate_version' => $version ), 'rate_version:' . $version ) );

			return;
		}

		throw new \LogicException( sprintf( 'The price projector does not hear %s; list it in Modules::EVENT_LISTENERS only with a case here.', get_class( $event ) ) );
	}

	/**
	 * Recomputes the sort prices of some products and writes what differs.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return int How many rows were inserted, rewritten or deleted.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function project( array $productIds ): int {
		if ( array() === $productIds ) {
			return 0;
		}

		$sources = $this->projections->sources( $productIds );
		$stored  = $this->projections->stored( $productIds );
		$written = 0;

		foreach ( $productIds as $productId ) {
			$have = $stored[ $productId ] ?? array();

			foreach ( PriceProjection::rowsOf( $sources[ $productId ] ?? array(), $this->terms ) as $currency => $row ) {
				$old = $have[ $currency ] ?? null;

				unset( $have[ $currency ] );

				if ( null === $old ) {
					$this->projections->insert( $productId, $currency, $row );
					++$written;
				} elseif ( self::differs( $old, $row ) && $this->projections->update( $old['id'], $row ) ) {
					++$written;
				}
			}

			foreach ( $have as $gone ) {
				if ( $this->projections->delete( $gone['id'] ) ) {
					++$written;
				}
			}
		}

		return $written;
	}

	/**
	 * Tells whether a stored row holds other values than the rule gives.
	 *
	 * @since 0.2.0
	 *
	 * @param array $old The stored row.
	 * @param array $row The row the rule gives.
	 * @return bool True when a value differs.
	 *
	 * @phpstan-param array{id: int, sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null} $old
	 * @phpstan-param array{sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null}          $row
	 */
	private static function differs( array $old, array $row ): bool {
		unset( $old['id'] );

		return $old !== $row;
	}
}
