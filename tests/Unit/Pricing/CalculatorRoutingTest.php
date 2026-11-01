<?php
/**
 * Tests that the calculation asks the fulfilment router only when asked to, and quotes from the plan's destination
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;
use SEOCart\Tests\Support\Doubles\FakeFulfilmentRouter;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\PoisonedQuoters;
use SEOCart\Tests\Support\Pricing\Calculators;
use SEOCart\Tests\Support\Pricing\Inputs;

defined( 'ABSPATH' ) || exit;

/**
 * The router is a quote: asked between the phases, for the first phase's lines, only by a request with a routing scope that knows where the order goes.
 *
 * @since 0.2.0
 */
final class CalculatorRoutingTest extends TestCase {

	/**
	 * Tests that a request without a scope, or with one but nowhere to send the order, plans nothing and quotes from no origin.
	 *
	 * @since 0.2.0
	 */
	public function test_the_router_is_not_asked_without_a_scope_or_without_somewhere_to_send_the_order(): void {
		$router  = new FakeFulfilmentRouter( FakeFulfilmentRouter::transferOf( 11 ) );
		$quoters = self::quoters();
		$unasked = array(
			'no scope'                => new CalculationRequest( Currency::of( 'USD' ), self::lines(), new Address( 'US' ) ),
			'a scope, no destination' => new CalculationRequest( Currency::of( 'USD' ), self::lines(), null, array(), null, RoutingScope::checkout() ),
		);

		foreach ( $unasked as $case => $request ) {
			$calculation = Calculators::over( self::prices(), $quoters, new FakeTransactionManager(), router: $router )->calculate( $request );

			$this->assertNull( $calculation->routingProposal(), $case );
		}

		$this->assertSame( array(), $router->asked, 'The router was asked by a request that did not ask for a plan, or had nowhere to send the order.' );
		$this->assertSame( array( null, null ), $quoters->shippingOrigins );
		$this->assertSame( array( null, null ), $quoters->taxOrigins );
	}

	/**
	 * Tests that a scope with a destination, or with a pickup location alone, asks the router once, with the first phase's units by variant.
	 *
	 * @since 0.2.0
	 */
	public function test_the_router_is_asked_with_a_scope_and_a_destination_or_a_pickup_location(): void {
		$router = new FakeFulfilmentRouter( FakeFulfilmentRouter::transferOf( 11 ) );
		$pickup = new RoutingScope( null, 7 );

		Calculators::over( self::prices(), self::quoters(), new FakeTransactionManager(), router: $router )->calculate( self::scoped() );
		Calculators::over( self::prices(), self::quoters(), new FakeTransactionManager(), router: $router )->calculate( new CalculationRequest( Currency::of( 'USD' ), self::lines(), null, array(), null, $pickup ) );

		$this->assertCount( 2, $router->asked );
		$this->assertSame( array( 11 => 3 ), $router->asked[0]['units'], 'The units are summed by variant over the first phase\'s lines.' );
		$this->assertSame( 7, $router->asked[1]['scope']->pickupLocationId );
	}

	/**
	 * Tests that both quotes are asked from the plan's destination, and the trace records the plan once, which the calculation reads back.
	 *
	 * @since 0.2.0
	 */
	public function test_the_origin_reaches_both_quotes_and_the_trace_records_the_proposal(): void {
		$proposal    = FakeFulfilmentRouter::transferOf( 11 );
		$quoters     = self::quoters();
		$calculation = Calculators::over( self::prices(), $quoters, new FakeTransactionManager(), router: new FakeFulfilmentRouter( $proposal ) )->calculate( self::scoped() );

		$this->assertSame( array( 7 ), $quoters->shippingOrigins, 'The shipping quote was not asked from the plan\'s destination.' );
		$this->assertSame( array( 7 ), $quoters->taxOrigins, 'The tax quote was not asked from the plan\'s destination.' );

		$entries = array_values( array_filter( $calculation->totals->trace->entries, static fn( TraceEntry $entry ): bool => TraceEntry::QUOTE === $entry->kind && 'routing' === ( $entry->data['record'] ?? null ) ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( array( 'record' => 'routing' ) + $proposal->toArray(), $entries[0]->data );
		$this->assertSame( $proposal->toArray(), $calculation->routingProposal()?->toArray() );
		$this->assertSame( $proposal->fingerprint(), $calculation->routingProposal()->fingerprint() );
	}

	/**
	 * Tests that the router's refusal reaches the caller unchanged, before any quote is taken.
	 *
	 * @since 0.2.0
	 */
	public function test_a_routing_refusal_reaches_the_caller_unchanged_before_any_quote(): void {
		$failure = CodedException::because(
			InventoryError::RoutingRefused,
			array(),
			null,
			array(
				'reason'      => 'quantity',
				'variant_ids' => array( 11 ),
				'available'   => 1,
			)
		);
		$quoters = self::quoters();

		try {
			Calculators::over( self::prices(), $quoters, new FakeTransactionManager(), router: new FakeFulfilmentRouter( FakeFulfilmentRouter::transferOf( 11 ), $failure ) )->calculate( self::scoped() );
			$this->fail( 'A calculation returned although the router refused the order.' );
		} catch ( CodedException $thrown ) {
			$this->assertSame( $failure, $thrown );
		}

		$this->assertSame( array( 0, 0 ), array( $quoters->shippingCalls, $quoters->taxCalls ) );
	}

	/**
	 * Tests that a calculator given no router refuses a request that asks for a plan, rather than quoting without one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_scoped_request_to_a_calculator_without_a_router_is_a_programming_error(): void {
		$this->expectException( \LogicException::class );

		Calculators::over( self::prices(), self::quoters(), new FakeTransactionManager() )->calculate( self::scoped() );
	}

	/**
	 * Returns a scoped request for three units of variant 11 over two lines, shipped to the United States.
	 *
	 * @since 0.2.0
	 *
	 * @return CalculationRequest The request.
	 */
	private static function scoped(): CalculationRequest {
		return new CalculationRequest( Currency::of( 'USD' ), self::lines(), new Address( 'US' ), array(), null, RoutingScope::checkout() );
	}

	/**
	 * Returns two lines of variant 11: two units and one.
	 *
	 * @since 0.2.0
	 *
	 * @return list<LineRequest> The lines.
	 */
	private static function lines(): array {
		return array( new LineRequest( 'a', 11, 2 ), new LineRequest( 'b', 11, 1 ) );
	}

	/**
	 * Returns prices: variant 11 at 10.00 USD.
	 *
	 * @since 0.2.0
	 *
	 * @return FixedFactsRepository The prices.
	 */
	private static function prices(): FixedFactsRepository {
		$prices         = new FixedFactsRepository();
		$prices->prices = array( Calculators::price( 11, '10.00' ) );

		return $prices;
	}

	/**
	 * Returns quoters answering a flat 5.00 and 20 % on `standard`.
	 *
	 * @since 0.2.0
	 *
	 * @return PoisonedQuoters The quoters.
	 */
	private static function quoters(): PoisonedQuoters {
		return new PoisonedQuoters( array( Inputs::shippingRate( 'flat', '5.00' ) ), Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '20' ) ) ) ) );
	}
}
