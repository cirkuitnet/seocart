<?php
/**
 * Tests refunds of every shape of tax an order stores: several rates, a compound rate, no rate, an exempt customer, free shipping
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A line's refund returns the line's own stored figures, with its tax the sum of its components' tax shares, whatever rates taxed it.
 *
 * A tax component's net is everything its rate was charged on, the whole of the line's net, so
 * adding components' nets up would count the line twice under two rates. The orders are priced
 * by the engine in EUR with a USD base, so every figure, and its base twin, is one an order
 * really stores.
 *
 * Planted violation, shown red and removed: in RefundAllocation::line(), take the line's share
 * as the sum of its components' shares, net, tax and gross: under two rates the line returns its
 * net twice.
 *
 * @since 0.1.0
 *
 * @group international
 */
final class RefundTaxShapesTest extends RefundTestCase {

	/**
	 * Tests that one unit and then the other two of a line taxed by several rates return exactly the stored line and each stored component, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider multiRateClasses
	 *
	 * @param string $taxClass The line's tax class.
	 */
	public function test_a_line_taxed_by_several_rates_returns_its_stored_figures_exactly( string $taxClass ): void {
		list( $order, $intent ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'kit', '33.33', 3, $taxClass ) ), null ) );
		list( $kit )            = $this->lineUuids( $order->id );
		$components             = $this->componentRows( $order->id, $kit );
		$stored                 = self::figures( $this->lineRow( $kit ), 'line_' );

		$this->assertCount( 2, $components, 'The line is taxed by two rates.' );

		$first = $this->refund( $order->uuid, array( $kit => 1 ) );
		$line  = $this->refundLine( $first->id );

		$this->assertSame( $line['net_minor'] + $line['tax_minor'], $line['gross_minor'] );
		$this->assertSame( $line['base_net_minor'] + $line['base_tax_minor'], $line['base_gross_minor'] );
		$this->assertSame( $first->total->minorUnits(), $line['gross_minor'] );
		$this->assertSame( $this->componentTax( $first->id ), array( $line['tax_minor'], $line['base_tax_minor'] ), 'The line\'s tax is what its components returned.' );
		$this->assertLessThan( $stored['net_minor'], 2 * $line['net_minor'], 'One unit of three returns about a third of the net, never the components\' nets added up.' );

		$this->refund( $order->uuid, array( $kit => 2 ) );

		$this->assertSame( $stored, array_diff_key( $this->returnedOfLine( $kit ), array( 'quantity' => 0 ) ), 'The line\'s refunds add up to its stored figures.' );

		foreach ( $components as $component ) {
			$this->assertSame( self::figures( $component ), $this->returnedOfComponent( (int) $component['id'] ), 'Each component is returned exactly.' );
		}

		$this->assertSame( 'refunded', $this->intentRow( $intent->uuid )['status'] );
	}

	/**
	 * Returns the tax classes that tax a line by two rates.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> The classes.
	 */
	public static function multiRateClasses(): array {
		return array(
			'two rates that add up'             => array( 'additive' ),
			'a rate compounding on another one' => array( 'compound' ),
		);
	}

	/**
	 * Tests that a line no rate taxes, shipped untaxed, returns its gross as its net and writes no component row.
	 *
	 * @since 0.1.0
	 */
	public function test_an_untaxed_line_returns_its_net_and_no_component(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '10.00', 3, 'untaxed' ) ), 'untaxed' ) );
		list( $gift )  = $this->lineUuids( $order->id );

		$refund = $this->refund( $order->uuid, array( $gift => 1 ), true );
		$line   = $this->refundLine( $refund->id );

		$this->assertSame( array( 1000, 0, 1000 ), array( $line['net_minor'], $line['tax_minor'], $line['gross_minor'] ), 'One of three untaxed 10.00 units returns 10.00, all of it net.' );
		$this->assertSame( array( 1499, 0, 499 ), array( $refund->total->minorUnits(), $refund->tax->minorUnits(), $refund->shipping->minorUnits() ), 'With the untaxed shipping, 4.99.' );
		$this->assertSame( 0, $this->componentCount(), 'Nothing was taxed, so nothing returns a component.' );
	}

	/**
	 * Tests that the order of a customer exempt from tax returns the net the customer paid, and no component.
	 *
	 * @since 0.1.0
	 */
	public function test_an_exempt_customers_order_returns_the_net_and_no_component(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.00', 2, 'standard' ) ), 'standard', exempt: true ) );
		list( $tee )   = $this->lineUuids( $order->id );

		$refund = $this->refund( $order->uuid, array( $tee => 1 ) );
		$line   = $this->refundLine( $refund->id );

		$this->assertSame( array( 1000, 0, 1000 ), array( $line['net_minor'], $line['tax_minor'], $line['gross_minor'] ), 'A 12.00 gross price, 20 % taken out, is 10.00 to an exempt customer.' );
		$this->assertSame( 0, $this->componentCount() );
	}

	/**
	 * Tests that free shipping returns nothing net: its shipping and its discount go back together, each component capped, and their taxes cancel.
	 *
	 * @since 0.1.0
	 */
	public function test_free_shipping_returns_nothing_net_and_its_components_cancel(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 2, 'standard' ) ), 'standard', freeShipping: true ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$shipping      = $this->componentRows( $order->id, null );

		$this->assertCount( 2, $shipping, 'The shipping and its discount each carry a component.' );
		$this->assertTrue( (int) $shipping[1]['gross_minor'] < 0, 'The discount\'s component is negative.' );

		$refund = $this->refund( $order->uuid, array( $tee => 1 ), true );

		$this->assertSame( 0, $refund->shipping->minorUnits(), 'Free shipping returns nothing.' );

		foreach ( $shipping as $component ) {
			$this->assertSame( self::figures( $component ), $this->returnedOfComponent( (int) $component['id'] ), 'Each shipping component goes back whole, the negative one too.' );
		}

		$this->assertSame( $this->refundLine( $refund->id )['gross_minor'], $refund->total->minorUnits(), 'The refund is the line\'s alone.' );
	}

	/**
	 * Reads a refund's one line.
	 *
	 * @since 0.1.0
	 *
	 * @param int $refundId The refund.
	 * @return array<string, int> Its figures and units, as ints.
	 */
	private function refundLine( int $refundId ): array {
		$row = (array) $this->db->fetchRow( 'SELECT quantity, net_minor, tax_minor, gross_minor, base_net_minor, base_tax_minor, base_gross_minor FROM %i WHERE refund_id = %d', $this->table( RefundTables::LINES ), $refundId );

		return array_map( static fn( mixed $value ): int => (int) $value, $row );
	}

	/**
	 * Adds up the tax a refund's component rows returned, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param int $refundId The refund.
	 * @return array{0: int, 1: int} The tax, and its base twin.
	 */
	private function componentTax( int $refundId ): array {
		$row = (array) $this->db->fetchRow( 'SELECT SUM( tax_minor ) AS tax, SUM( base_tax_minor ) AS base_tax FROM %i WHERE refund_id = %d', $this->table( RefundTables::COMPONENTS ), $refundId );

		return array( (int) $row['tax'], (int) $row['base_tax'] );
	}

	/**
	 * Counts every refund component row.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	private function componentCount(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( RefundTables::COMPONENTS ) );
	}
}
