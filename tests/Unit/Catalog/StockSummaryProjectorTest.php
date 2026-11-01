<?php
/**
 * Tests which variants the stock summary projector reads for each event it hears, over doubles
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Catalog\Application\Projection\StockSummary;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Inventory\Application\Availability;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\Event\StockTransferred;
use SEOCart\Inventory\Domain\Event\StockTransferShort;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Doubles\FakeLocationRepository;
use SEOCart\Tests\Support\Doubles\FakeStockRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;

/**
 * StockSummaryProjector::on() over a recording StockSummaries and Inventory's Availability over its doubles: each event it hears asks for its own variants, and the summaries written follow the units for sale.
 *
 * Variant 7 has three units for sale and variant 9 none; each variant is the one enabled variant
 * of its own product, 70 and 90.
 *
 * Planted violation, shown red and removed: in StockSummaryProjector::variantsOf(), remove the
 * StockTransferred case: both phases of a transfer are refused as an event the projector does not
 * hear.
 *
 * @since 0.2.0
 */
final class StockSummaryProjectorTest extends TestCase {

	/**
	 * The instant every event happened at.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const AT = '2026-10-31 12:00:00';

	/**
	 * A hold's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const HOLD = '018f4e2a-7b3c-7d1e-9a2b-3c4d5e6f7a8b';

	/**
	 * Returns one event of each kind the projector hears, with the variants it moved; a product's save names its product.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: DomainEvent, 1: list<int>}> The cases.
	 */
	public static function heard(): array {
		$at = new \DateTimeImmutable( self::AT, new \DateTimeZone( 'UTC' ) );

		return array(
			'an adjustment'       => array( new StockAdjusted( 7, 1, -3, 6, 3, 2, -1, 'damaged', 'user', 5, 41, $at ), array( 7 ) ),
			'a hold'              => array( new StockReserved( self::HOLD, 3, null, array( 7, 9 ), array( 2, 1 ), array( 7, 9 ), array( 1, 1 ), array( 2, 1 ), '2026-10-31 12:15:00', $at ), array( 7, 9 ) ),
			'an allocation'       => array( new StockAllocated( 31, array( 9 ), array( 1 ), array( 9 ), array( 1 ), array( 1 ), $at ), array( 9 ) ),
			'a hold given back'   => array( new StockReservationReleased( self::HOLD, 'payment_declined', array( 7 ), array( 2 ), array( 7 ), array( 1 ), array( 2 ), $at ), array( 7 ) ),
			'an expired hold'     => array( new StockHoldExpired( self::HOLD, 9, 1, 1, '2026-10-31 11:55:00', $at ), array( 9 ) ),
			'a transfer sent'     => array( new StockTransferred( 12, 1, 2, StockTransferred::SENT, array( 7, 9 ), array( 3, 1 ), $at ), array( 7, 9 ) ),
			'a transfer received' => array( new StockTransferred( 12, 2, 1, StockTransferred::RECEIVED, array( 9 ), array( 1 ), $at ), array( 9 ) ),
			'a save of a product' => array( new ProductSaved( 70, 700, array( 'post' ), null, null, null, $at ), array() ),
		);
	}

	/**
	 * Tests that the cases cover every event the projector is listed as hearing, and no other.
	 *
	 * @since 0.2.0
	 */
	public function test_the_cases_are_every_event_the_projector_hears(): void {
		$classes = array_values( array_unique( array_map( static fn( array $heard ): string => get_class( $heard[0] ), self::heard() ) ) );

		$this->assertEqualsCanonicalizing( Modules::EVENT_LISTENERS[ StockSummaryProjector::class ], $classes );
	}

	/**
	 * Tests that an event the projector hears asks for exactly its own variants, and writes the summaries of their products from the units for sale.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider heard
	 *
	 * @param DomainEvent $event    The event.
	 * @param int[]       $variants The variants it moved; none for a product's save.
	 *
	 * @phpstan-param list<int> $variants
	 */
	public function test_an_event_asks_for_its_own_variants( DomainEvent $event, array $variants ): void {
		$summaries = self::summaries();

		self::projector( $summaries )->on( $event, EventEnvelope::inMemory( $event, 'correlation' ) );

		$products = $event instanceof ProductSaved ? array( $event->productId ) : array_map( static fn( int $variant ): int => 10 * $variant, $variants );
		$expected = array(
			70 => StockSummary::IN_STOCK,
			90 => StockSummary::OUT_OF_STOCK,
		);

		$this->assertSame( array() === $variants ? array() : array( $variants ), $summaries->asked, 'The variants asked for.' );
		$this->assertSame( array_intersect_key( $expected, array_flip( $products ) ), $summaries->written, 'The summaries written.' );
	}

	/**
	 * Tests that a transfer's shortfall, which moves no unit a location counts, is refused as an event the projector does not hear.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_shortfall_is_not_heard(): void {
		$event = new StockTransferShort( 31, 12, 120, 7, 1, new \DateTimeImmutable( self::AT, new \DateTimeZone( 'UTC' ) ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( StockTransferShort::class );

		self::projector( self::summaries() )->on( $event, EventEnvelope::inMemory( $event, 'correlation' ) );
	}

	/**
	 * Builds the projector over the summaries and Inventory's figure over its doubles: variant 7 with three units for sale, variant 9 with none.
	 *
	 * @since 0.2.0
	 *
	 * @param StockSummaries $summaries The summaries.
	 * @return StockSummaryProjector The projector.
	 */
	private static function projector( StockSummaries $summaries ): StockSummaryProjector {
		$stock = new FakeStockRepository( new FakeTransactionManager() );

		$stock->plantItem( 7, 3 );
		$stock->plantItem( 9, 0 );

		return new StockSummaryProjector( $summaries, new Availability( new LocationDirectory( new FakeLocationRepository() ), $stock, static fn(): FulfilmentMode => FulfilmentMode::Split ) );
	}

	/**
	 * Returns summaries that record the variants asked for and the summaries written: each variant the one enabled variant of product ten times its id, each summary unknown.
	 *
	 * @since 0.2.0
	 *
	 * @return StockSummaries&object{asked: list<list<int>>, written: array<int, string>} The summaries.
	 */
	private static function summaries(): StockSummaries {
		return new class() implements StockSummaries {

			/**
			 * The variants of each call to productsOfVariants(), in order.
			 *
			 * @since 0.2.0
			 *
			 * @var list<list<int>>
			 */
			public array $asked = array();

			/**
			 * The summaries written, by product.
			 *
			 * @since 0.2.0
			 *
			 * @var array<int, string>
			 */
			public array $written = array();

			/**
			 * Records the variants and returns their products.
			 *
			 * @since 0.2.0
			 *
			 * @param int[] $variantIds The variants.
			 * @return list<int> The products, ascending.
			 */
			public function productsOfVariants( array $variantIds ): array {
				$this->asked[] = array_values( $variantIds );

				return array_map( static fn( int $variant ): int => 10 * $variant, array_values( $variantIds ) );
			}

			/**
			 * Returns each product's one enabled variant, its id a tenth of the product's, and a summary unknown.
			 *
			 * @since 0.2.0
			 *
			 * @param int[] $productIds The products.
			 * @return array<int, array{summary: string, variants: list<int>}> By product.
			 */
			public function enabledVariants( array $productIds ): array {
				$products = array();

				foreach ( $productIds as $productId ) {
					$products[ $productId ] = array(
						'summary'  => StockSummary::UNKNOWN,
						'variants' => array( intdiv( $productId, 10 ) ),
					);
				}

				return $products;
			}

			/**
			 * Records a summary written.
			 *
			 * @since 0.2.0
			 *
			 * @param int    $productId The product.
			 * @param string $summary   The summary.
			 * @return bool True.
			 */
			public function write( int $productId, string $summary ): bool {
				$this->written[ $productId ] = $summary;

				return true;
			}

			/**
			 * Lists no product: the command's read, not the projector's.
			 *
			 * @since 0.2.0
			 *
			 * @param int $afterId The cursor.
			 * @param int $limit   The page.
			 * @return list<int> None.
			 */
			public function completeProductIds( int $afterId, int $limit ): array {
				unset( $afterId, $limit );

				return array();
			}

			/**
			 * Counts none: doctor's read, not the projector's.
			 *
			 * @since 0.2.0
			 *
			 * @return int 0.
			 */
			public function unsummarizedCount(): int {
				return 0;
			}
		};
	}
}
