<?php
/**
 * Tests what a new exchange-rate version changes: the cart's converted prices, and nothing already ordered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Order\Infrastructure\MysqlConversionContexts;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TotalsLine;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\Pricing\TotalsOrders;

/**
 * A cart in EUR is priced at rate version 1 and an order placed and paid from it; version 2 is saved; the cart is priced again.
 *
 * The calculator and the rate writer are the kernel's, on a site installed at the code's schema
 * head; the order is placed from the version-1 totals through the order service, which freezes
 * their rate, and paid through the stub gateway. Then:
 *
 * - the cart's converted line follows the new rate and names version 2, its explicit line does
 *   not move;
 * - the order is exactly as it was: every row of it, its frozen rate and its payment;
 * - version 1's rates are exactly as they were, so the version the order's rate names still holds
 *   that rate;
 * - and the order's base figures add up as its totals did: the lines and the adjustments outside
 *   them to the order's base totals, the tax components to its base tax, and its payment's base
 *   amounts to its base grand total.
 *
 * Planted violation, shown red and removed: in MysqlExchangeRates::saveVersion(), update the
 * stored rate of each currency to the new rate and version instead of inserting the new set. The
 * order's frozen rate then names version 1, which no longer exists, and the test fails on the
 * version-1 rows.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class RateVersionBumpTest extends PaymentTestCase {

	use PricesInCurrencies;

	/**
	 * The variant priced in EUR by the merchant.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const EXPLICIT = 701;

	/**
	 * The variant priced in USD alone, so converted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CONVERTED = 702;

	/**
	 * Creates the catalog's and the pricing tables, prices the two variants, enables EUR and installs the site.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateCatalogTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		$this->createRateTables();
		$this->plantBootRecord();
		$this->enableCurrency( 'EUR' );

		foreach ( array( array( self::EXPLICIT, 'USD', 2000 ), array( self::EXPLICIT, 'EUR', 1800 ), array( self::CONVERTED, 'USD', 1000 ) ) as list( $variant, $currency, $minor ) ) {
			$this->db->execute(
				"INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, 'net', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
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
	 * Tests that a new version re-prices the cart's converted line and leaves an order placed at the old one exactly as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_a_new_version_reprices_the_cart_and_leaves_the_order_as_it_was(): void {
		$kernel     = KernelContainer::build( $this->db, $this->reporter() );
		$calculator = $kernel->get( Calculator::class );
		$rates      = $kernel->get( ExchangeRates::class );

		$this->assertInstanceOf( Calculator::class, $calculator );
		$this->assertInstanceOf( ExchangeRates::class, $rates );
		$this->assertSame( 1, $rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) ) );
		$this->assertSame( 1, $this->storedRateVersion() );

		$request = new CalculationRequest( Currency::of( 'EUR' ), array( new LineRequest( 'explicit', self::EXPLICIT, 1 ), new LineRequest( 'converted', self::CONVERTED, 2 ) ), new Address( 'DE' ) );
		$first   = $calculator->calculate( $request )->totals;

		// 10.00 USD × 0.91230 = 9.123: 9.12 EUR.
		$this->assertSame( array( 1, 1800, 912 ), self::pricedAt( $first ) );

		list( $order, $intent ) = $this->placeWithIntent( TotalsOrders::document( $first ) );

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$placed     = $this->orderRows( $order->id );
		$versionOne = $this->rateRows( 1 );

		$this->assertSame( 2, $rates->saveVersion( array( self::rateTo( 'EUR', '0.95000' ) ), Actor::user( 0 ) ) );
		$this->assertSame( 2, $this->storedRateVersion() );

		// 10.00 USD × 0.95000 = 9.50 EUR; the explicit 18.00 EUR stays.
		$this->assertSame( array( 2, 1800, 950 ), self::pricedAt( $calculator->calculate( $request )->totals ) );

		$this->assertSame( $placed, $this->orderRows( $order->id ), 'A new rate version changed an order placed at the old one.' );
		$this->assertSame( $versionOne, $this->rateRows( 1 ), 'A new rate version changed the rates of the old one.' );

		$frozen = ( new MysqlConversionContexts( new OrderStatements( $this->db ), $this->ids ) )->find( $order->conversionContextId );

		$this->assertNotNull( $frozen );
		$this->assertSame( $first->conversionContext->fingerprint(), $frozen->fingerprint(), 'The order references the rate it was priced at.' );
		$this->assertSame( $frozen->fingerprint(), $this->versionOneContext()->fingerprint(), 'The version the order\'s rate names no longer holds that rate.' );
	}

	/**
	 * Tests that the base figures of an order placed in EUR add up to its base totals, and that its payment carries its base grand total.
	 *
	 * @since 0.1.0
	 */
	public function test_the_base_figures_of_an_order_add_up_and_its_payment_carries_them(): void {
		$kernel     = KernelContainer::build( $this->db, $this->reporter() );
		$calculator = $kernel->get( Calculator::class );
		$rates      = $kernel->get( ExchangeRates::class );

		$this->assertInstanceOf( Calculator::class, $calculator );
		$this->assertInstanceOf( ExchangeRates::class, $rates );

		$rates->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );

		$totals = $calculator->calculate( new CalculationRequest( Currency::of( 'EUR' ), array( new LineRequest( 'explicit', self::EXPLICIT, 3 ), new LineRequest( 'converted', self::CONVERTED, 7 ) ), new Address( 'DE' ) ) )->totals;

		list( $order, $intent ) = $this->placeWithIntent( TotalsOrders::document( $totals ) );

		$header = $this->db->fetchRow( 'SELECT base_subtotal_minor, base_discount_total_minor, base_shipping_total_minor, base_fee_total_minor, base_tax_total_minor, base_grand_total_minor FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );
		$lines  = $this->db->fetchRow( 'SELECT SUM( base_line_net_minor ) AS net, SUM( base_line_tax_minor ) AS tax, SUM( base_line_gross_minor ) AS gross FROM %i WHERE order_id = %d', $this->table( OrderTables::LINES ), $order->id );
		$others = $this->db->fetchRow( "SELECT COALESCE( SUM( base_net_minor ), 0 ) AS net, COALESCE( SUM( base_tax_minor ), 0 ) AS tax, COALESCE( SUM( base_gross_minor ), 0 ) AS gross, COALESCE( SUM( CASE WHEN type = 'shipping' THEN base_net_minor END ), 0 ) AS shipping FROM %i WHERE order_id = %d AND scope <> 'line'", $this->table( OrderTables::ADJUSTMENTS ), $order->id );
		$taxes  = (int) $this->db->fetchValue( 'SELECT SUM( base_tax_minor ) FROM %i WHERE order_id = %d', $this->table( OrderTables::TAX_COMPONENTS ), $order->id );

		$this->assertNotNull( $header );
		$this->assertNotNull( $lines );
		$this->assertNotNull( $others );
		$this->assertGreaterThan( 0, (int) $others['shipping'], 'The order is shipped, so the base sums include an adjustment.' );

		$this->assertSame( (int) $header['base_tax_total_minor'], (int) $lines['tax'] + (int) $others['tax'], 'The lines\' and the adjustments\' base tax add up to the order\'s.' );
		$this->assertSame( (int) $header['base_tax_total_minor'], $taxes, 'The tax components\' base tax adds up to the order\'s.' );
		$this->assertSame( (int) $header['base_grand_total_minor'], (int) $lines['gross'] + (int) $others['gross'], 'The lines\' and the adjustments\' base gross add up to the order\'s base grand total.' );
		$this->assertSame( (int) $header['base_shipping_total_minor'], (int) $others['shipping'], 'The shipping adjustment\'s base net is the order\'s base shipping.' );
		$this->assertSame(
			(int) $header['base_subtotal_minor'] + (int) $header['base_discount_total_minor'] + (int) $header['base_shipping_total_minor'] + (int) $header['base_fee_total_minor'],
			(int) $lines['net'] + (int) $others['net'],
			'The base subtotal, discounts, shipping and fees add up to the base net of the lines and the adjustments.'
		);
		$this->assertSame( $totals->summary->baseGrand->minorUnits(), (int) $header['base_grand_total_minor'], 'The order\'s base grand total is the calculation\'s.' );

		$this->assertSame( (string) $header['base_grand_total_minor'], (string) $this->intentRow( $intent->uuid )['base_amount_minor'], 'The intent was created for the order\'s base grand total.' );

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$this->assertSame( (string) $header['base_grand_total_minor'], (string) $this->intentRow( $intent->uuid )['base_authorized_minor'], 'The authorization moved the intent by the order\'s base grand total.' );
		$this->assertSame( (string) $header['base_grand_total_minor'], (string) $this->orderRow( $order->id )['base_authorized_minor'] );
	}

	/**
	 * Describes a calculation by the rate version it read, the explicit line's unit price and the converted line's.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return array{0: int, 1: int, 2: int} The version, then the two unit prices in minor units.
	 */
	private static function pricedAt( Totals $totals ): array {
		$units = array();

		foreach ( $totals->lines as $line ) {
			$units[ $line->line->key ] = $line;
		}

		$calculation = array_values( array_filter( $totals->trace->toArray(), static fn( array $entry ): bool => 'calculation' === ( $entry['data']['record'] ?? null ) ) );

		self::assertSame( $totals->conversionContext->sourceVersion(), $calculation[0]['data']['rate_version'] ?? null, 'The trace names the rate version the calculation read.' );
		self::assertSame( array( 'explicit', 'converted' ), array_map( static fn( TotalsLine $line ): string => $line->line->priceSource->value, array( $units['explicit'], $units['converted'] ) ) );

		return array( $totals->conversionContext->sourceVersion(), $units['explicit']->line->unitPrice->amount->minorUnits(), $units['converted']->line->unitPrice->amount->minorUnits() );
	}

	/**
	 * Reads every row of an order, its frozen rate and its payment, in a fixed order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return array<string, list<array<string, mixed>>> The rows, by table.
	 */
	private function orderRows( int $orderId ): array {
		$rows = array(
			OrderTables::ORDERS => $this->db->fetchAll( 'SELECT * FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ),
		);

		foreach ( array( OrderTables::LINES, OrderTables::ADJUSTMENTS, OrderTables::TAX_COMPONENTS, OrderTables::TOTALS ) as $table ) {
			$rows[ $table ] = $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( $table ), $orderId );
		}

		$rows[ OrderTables::CONVERSION_CONTEXTS ] = $this->db->fetchAll( 'SELECT * FROM %i WHERE id = %d', $this->table( OrderTables::CONVERSION_CONTEXTS ), (int) $rows[ OrderTables::ORDERS ][0]['conversion_context_id'] );
		$rows[ PaymentTables::INTENTS ]           = $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::INTENTS ), $orderId );

		return $rows;
	}

	/**
	 * Reads the rates of one version.
	 *
	 * @since 0.1.0
	 *
	 * @param int $version The version.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rateRows( int $version ): array {
		return $this->db->fetchAll( 'SELECT * FROM %i WHERE version = %d ORDER BY id', $this->db->table( PricingTables::EXCHANGE_RATES ), $version );
	}

	/**
	 * Builds the conversion context the stored version-1 rate to EUR stands for, as a calculation at that version reads it.
	 *
	 * @since 0.1.0
	 *
	 * @return ConversionContext The context.
	 */
	private function versionOneContext(): ConversionContext {
		$row = $this->db->fetchRow( "SELECT rate, rate_scale, source, version, created_at FROM %i WHERE version = 1 AND quote_currency = 'EUR'", $this->db->table( PricingTables::EXCHANGE_RATES ) );

		$this->assertNotNull( $row, 'Version 1 has no rate to EUR any more.' );

		$scale = (int) $row['rate_scale'];

		return new ConversionContext( Currency::of( 'USD' ), Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( (string) $row['rate'] )->rescale( $scale, RoundingMode::TowardZero ), $scale, (string) $row['source'], (int) $row['version'], new \DateTimeImmutable( (string) $row['created_at'], new \DateTimeZone( 'UTC' ) ) );
	}
}
