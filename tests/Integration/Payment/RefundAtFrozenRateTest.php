<?php
/**
 * Tests a partial refund after the exchange rate moved twice: the order's own rate and allocation, and base figures that still add up
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Application\ShippingRateQuoter;
use SEOCart\Pricing\Application\TaxQuoter;
use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\Address;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\Pricing\TotalsOrders;

/**
 * An order placed in EUR at rate version 1 is refunded in part after versions 2 and 3: at version 1's rate, by version 1's allocation.
 *
 * The cart is priced by the kernel's calculator, its stub tax and shipping quoters, and a ten
 * percent promotion: three tees whose EUR price the merchant entered, two mugs whose USD price is
 * converted, both tax-inclusive, and the flat shipping, shipped to Germany. The order is placed
 * from those totals, and its payment authorized and captured through the stub. Two more rate
 * versions are saved. Then one tee and the shipping are refunded, and:
 *
 * - the refund names the order's conversion context, and every component row names one of the
 *   order's stored components and returns no more than it, in its direction;
 * - its base figures are the allocation of the order's stored base figures, worked out here by hand
 *   from the stored rows with Money::allocate(): one unit of the tee's three, and all the shipping;
 * - the report, the orders' base totals less the refunds', does not move with the rate: not
 *   before the refund, and not after it;
 * - no statement of the refund names `exchange_rates`, `currencies` or any pricing or catalog
 *   table.
 *
 * Planted violation, shown red and removed: in RefundService::plan(), read the latest rate from
 * `exchange_rates` and state the refund's base total as its total converted at that rate. The
 * statement scan names `exchange_rates`, and the base total no longer matches the hand-derived
 * allocation.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class RefundAtFrozenRateTest extends RefundTestCase {

	use PricesInCurrencies;

	/**
	 * The variant priced in EUR by the merchant: the tee.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const EXPLICIT = 711;

	/**
	 * The variant priced in USD alone, so converted: the mug.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CONVERTED = 712;

	/**
	 * Creates the catalog's and the pricing tables, prices the two variants tax-inclusive, enables EUR and installs the site.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateCatalogTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		$this->createRateTables();
		$this->plantBootRecord();
		$this->enableCurrency( 'EUR' );

		foreach ( array( array( self::EXPLICIT, 'USD', 2000 ), array( self::EXPLICIT, 'EUR', 1899 ), array( self::CONVERTED, 'USD', 1095 ) ) as list( $variant, $currency, $minor ) ) {
			$this->db->execute(
				"INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, 'gross', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
				$this->db->table( CatalogTables::VARIANT_PRICES ),
				$variant,
				$currency,
				$minor
			);
		}
	}

	/**
	 * Removes the installation record.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that a refund of one tee and the shipping, after two more rate versions, is at the order's rate and allocation, and moves the report by exactly its own base total.
	 *
	 * @since 0.1.0
	 */
	public function test_a_partial_refund_after_rate_changes_keeps_the_orders_rate_and_allocation(): void {
		$kernel = KernelContainer::build( $this->db, $this->reporter() );
		$rates  = $kernel->get( ExchangeRates::class );

		$this->assertInstanceOf( ExchangeRates::class, $rates );
		$this->assertSame( 1, $rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) ) );

		$totals = $this->calculator( $kernel )->calculate( new CalculationRequest( Currency::of( 'EUR' ), array( new LineRequest( 'tee', self::EXPLICIT, 3 ), new LineRequest( 'mug', self::CONVERTED, 2 ) ), new Address( 'DE' ) ) )->totals;

		$this->assertTheOrderHasTheShapeTested( $totals );

		list( $order )  = $this->placePaid( TotalsOrders::document( $totals ) );
		list( $tee )    = $this->lineUuids( $order->id );
		$report         = $this->report();
		$teeRow         = $this->lineRow( $tee );
		$teeComponents  = $this->componentRows( $order->id, $tee );
		$shipComponents = $this->componentRows( $order->id, null );
		$storedShipping = $this->storedShipping( $order->id );

		$this->assertSame( 2, $rates->saveVersion( array( self::rateTo( 'EUR', '0.95000' ) ), Actor::user( 0 ) ) );
		$this->assertSame( 3, $rates->saveVersion( array( self::rateTo( 'EUR', '0.88000' ) ), Actor::user( 0 ) ) );
		$this->assertSame( $report, $this->report(), 'The report does not move with the rate.' );

		$refund = null;
		$log    = $this->captureQueries(
			function () use ( $order, $tee, &$refund ): void {
				$refund = $this->refund( $order->uuid, array( $tee => 1 ), true );
			}
		);

		$this->assertNotNull( $refund );
		$this->assertSame( array(), $this->pricingOrCatalogTablesNamed( $log->describe() ), 'The refund read no rate, currency, price or catalog row.' );

		$row = $this->refundRows( $order->id )[0];

		$this->assertSame( (string) $order->conversionContextId, (string) $row['conversion_context_id'], 'The refund is at the order\'s own conversion context.' );

		// The base figures, worked out by hand from the stored rows: one unit of the tee's three, gross and tax allocated and net derived (it was priced gross), and all the shipping.
		$componentBaseTax = 0;

		foreach ( $teeComponents as $component ) {
			$baseGross = self::firstOfThree( (int) $component['base_gross_minor'] );
			$baseTax   = self::firstOfThree( (int) $component['base_tax_minor'] );

			$this->assertSame( array( $baseGross - $baseTax, $baseTax, $baseGross ), $this->returnedBase( (int) $component['id'] ), 'A tee component\'s base share is its stored base, allocated.' );

			$componentBaseTax += $baseTax;
		}

		$teeBaseGross = self::firstOfThree( (int) $teeRow['base_line_gross_minor'] );
		$line         = $this->returnedOfLine( $tee );

		$this->assertSame( array( $teeBaseGross - $componentBaseTax, $componentBaseTax, $teeBaseGross ), array( $line['base_net_minor'], $line['base_tax_minor'], $line['base_gross_minor'] ), 'The tee\'s base share is its stored base gross allocated, with its components\' base tax.' );

		$shippingBaseTax = 0;

		foreach ( $shipComponents as $component ) {
			$this->assertSame( array( (int) $component['base_net_minor'], (int) $component['base_tax_minor'], (int) $component['base_gross_minor'] ), $this->returnedBase( (int) $component['id'] ), 'The shipping\'s components go back whole.' );

			$shippingBaseTax += (int) $component['base_tax_minor'];
		}

		$expectedBaseTotal = $teeBaseGross + $storedShipping['base_net_minor'] + $shippingBaseTax;

		$this->assertSame( $storedShipping['base_net_minor'], (int) $row['base_shipping_minor'] );
		$this->assertSame( $expectedBaseTotal, (int) $row['base_total_minor'], 'The refund\'s base total is the hand-derived allocation, at the order\'s rate.' );
		$this->assertSame( $componentBaseTax + $shippingBaseTax, (int) $row['base_tax_minor'] );

		$this->assertEveryComponentRowNamesAStoredComponentAndFitsIt( $order->id );

		$this->assertSame( $report - $expectedBaseTotal, $this->report(), 'The report moves by the refund\'s own base total.' );
		$this->assertSame( 4, $rates->saveVersion( array( self::rateTo( 'EUR', '0.80000' ) ), Actor::user( 0 ) ) );
		$this->assertSame( $report - $expectedBaseTotal, $this->report(), 'A refund recorded does not move with the rate either.' );
	}

	/**
	 * Builds the kernel's calculator with a promotion of ten percent off every line, at fixed-gross and per-line rounding.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $kernel The kernel's container.
	 * @return Calculator The calculator.
	 */
	private function calculator( Container $kernel ): Calculator {
		$tenPercentOff = new class() implements PromotionEvaluator {

			/**
			 * Takes ten percent off every line.
			 *
			 * @param PhaseAView $view The lines.
			 * @return list<\SEOCart\Pricing\Domain\Intent\PromotionIntent> The intent.
			 */
			public function evaluate( PhaseAView $view ): array {
				return array( new DiscountLines( Source::promotion( '01928c3e-0000-7000-8000-0000000000c1' ), Percentage::fromString( '10' ) ) );
			}
		};

		return new Calculator(
			$kernel->get( PriceResolver::class ),
			$kernel->get( ShippingRateQuoter::class ),
			$kernel->get( TaxQuoter::class ),
			$tenPercentOff,
			$kernel->get( TransactionManager::class ),
			$kernel->get( Clock::class ),
			static fn(): Currency => Currency::of( 'USD' ),
			static fn(): CrossZonePolicy => CrossZonePolicy::FixedGross,
			static fn(): TaxRoundingMode => TaxRoundingMode::PerLine,
			$kernel->get( PresentmentCurrencies::class )
		);
	}

	/**
	 * Fails unless the totals are the shape the test is about: an explicit and a converted price, both tax-inclusive, a discount and shipping, at version 1.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 */
	private function assertTheOrderHasTheShapeTested( Totals $totals ): void {
		$sources = array();

		foreach ( $totals->lines as $line ) {
			$sources[ $line->line->key ] = array( $line->line->priceSource->value, $line->line->unitPrice->basis->value );
		}

		$this->assertSame(
			array(
				'tee' => array( 'explicit', 'gross' ),
				'mug' => array( 'converted', 'gross' ),
			),
			$sources
		);
		$this->assertSame( 1, $totals->conversionContext->sourceVersion() );
		$this->assertFalse( $totals->summary->discountTotal->isZero(), 'The promotion took something off.' );
		$this->assertFalse( $totals->summary->shippingTotal->isZero(), 'The order is shipped.' );
	}

	/**
	 * Fails unless every refund component row names one of its order's stored components and returns no more than it, never against its sign.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	private function assertEveryComponentRowNamesAStoredComponentAndFitsIt( int $orderId ): void {
		$rows = $this->db->fetchAll(
			'SELECT rc.gross_minor, rc.tax_minor, rc.base_gross_minor, rc.base_tax_minor, c.id AS stored_id, c.order_id, c.gross_minor AS stored_gross, c.tax_minor AS stored_tax, c.base_gross_minor AS stored_base_gross, c.base_tax_minor AS stored_base_tax '
			. 'FROM %i rc LEFT JOIN %i c ON c.id = rc.order_tax_component_id',
			$this->table( RefundTables::COMPONENTS ),
			$this->table( OrderTables::TAX_COMPONENTS )
		);

		$this->assertNotSame( array(), $rows );

		foreach ( $rows as $row ) {
			$this->assertNotNull( $row['stored_id'], 'A refund component names a stored component.' );
			$this->assertSame( (string) $orderId, (string) $row['order_id'] );

			foreach ( array( 'gross', 'tax', 'base_gross', 'base_tax' ) as $figure ) {
				$returned = (int) $row[ $figure . '_minor' ];
				$stored   = (int) $row[ 'stored_' . $figure ];

				$this->assertTrue( abs( $returned ) <= abs( $stored ) && ( 0 === $returned || ( $returned < 0 ) === ( $stored < 0 ) ), "A component's {$figure} returns no more than it stored, in its direction." );
			}
		}
	}

	/**
	 * Returns the share of one unit of three: the first part of the allocation of an amount by largest remainder, worked out with Money::allocate().
	 *
	 * @since 0.1.0
	 *
	 * @param int $minor The stored amount, in minor units of the base currency.
	 * @return int The share.
	 */
	private static function firstOfThree( int $minor ): int {
		return Money::of( $minor, Currency::of( 'USD' ) )->allocate( array( 1, 2 ) )[0]->minorUnits();
	}

	/**
	 * Reads what the refunds returned of a component, in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @param int $componentId The component.
	 * @return array{0: int, 1: int, 2: int} Net, tax and gross.
	 */
	private function returnedBase( int $componentId ): array {
		$returned = $this->returnedOfComponent( $componentId );

		return array( $returned['base_net_minor'], $returned['base_tax_minor'], $returned['base_gross_minor'] );
	}

	/**
	 * Adds up, in SQL, the order's stored shipping adjustments, in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return array<string, int> The base net.
	 */
	private function storedShipping( int $orderId ): array {
		return array( 'base_net_minor' => (int) $this->db->fetchValue( "SELECT SUM( base_net_minor ) FROM %i WHERE order_id = %d AND scope = 'shipping'", $this->table( OrderTables::ADJUSTMENTS ), $orderId ) );
	}

	/**
	 * Works out the report the rate must never move: the orders' base grand totals less the refunds' base totals.
	 *
	 * @since 0.1.0
	 *
	 * @return int The report, in minor units of the base currency.
	 */
	private function report(): int {
		return (int) $this->db->fetchValue( 'SELECT ( SELECT COALESCE( SUM( base_grand_total_minor ), 0 ) FROM %i ) - ( SELECT COALESCE( SUM( base_total_minor ), 0 ) FROM %i )', $this->table( OrderTables::ORDERS ), $this->table( RefundTables::REFUNDS ) );
	}

	/**
	 * Lists the pricing and catalog tables a log names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $described The log, described.
	 * @return list<string> The tables named.
	 */
	private function pricingOrCatalogTablesNamed( string $described ): array {
		$named = array();

		foreach ( array_merge( PricingTables::names(), array_map( static fn( TableDefinition $table ): string => $table->name(), CatalogTables::all() ) ) as $name ) {
			if ( 1 === preg_match( '/' . preg_quote( $this->table( $name ), '/' ) . '\b/', $described ) ) {
				$named[] = $name;
			}
		}

		return $named;
	}
}
