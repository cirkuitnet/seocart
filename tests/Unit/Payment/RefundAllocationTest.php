<?php
/**
 * Tests how a refund's shares are allocated from what an order stored
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\RefundableLine;
use SEOCart\Order\Domain\StoredAmount;
use SEOCart\Order\Domain\StoredTaxComponent;
use SEOCart\Payment\Domain\Refund\ComponentPortion;
use SEOCart\Payment\Domain\Refund\LinePortion;
use SEOCart\Payment\Domain\Refund\RefundAllocation;
use SEOCart\Payment\Domain\Refund\Share;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

/**
 * RefundAllocation shares out an order's stored figures: the last units take exactly what remains, and a line's tax is its components' tax.
 *
 * The figures are an order's in EUR with their USD base twins, written out as an order stores
 * them. Each refund is allocated from what the earlier ones left, as the refund service reads it.
 *
 * Planted violation, shown red and removed: in RefundAllocation, allocate each refund from the
 * stored figures and the line's whole quantity instead of from what remains (`$stored->amount`
 * for `$stored->amount->subtract( $returned->amount )`, and the same for the base and the
 * components, with `$line->quantity` for the units left): refunded a unit at a time, a line loses
 * or gains a minor unit.
 *
 * @since 0.1.0
 */
final class RefundAllocationTest extends TestCase {

	/**
	 * Tests that a line refunded in parts returns exactly its stored figures and its component's, in both currencies, and that every share adds up.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider splits
	 *
	 * @param int[] $split The units of each refund.
	 *
	 * @phpstan-param list<int> $split
	 */
	public function test_a_line_refunded_in_parts_returns_exactly_its_stored_figures( array $split ): void {
		$line      = self::line( 3, 0, AmountBasis::Gross, array( 3086, 617 ), array( 2815, 563 ) );
		$component = self::component( 11, 1, AmountBasis::Gross, array( 3086, 617 ), array( 2815, 563 ) );

		list( $returned, $returnedComponents ) = self::refundInParts( $line, array( $component ), $split );

		$this->assertTrue( self::stored( $line->stored )->amount->equals( $returned->amount ), 'The line returned exactly its stored figures.' );
		$this->assertTrue( self::stored( $line->stored )->base->equals( $returned->base ), 'And its base twins.' );
		$this->assertTrue( $component->stored->amount->equals( $returnedComponents[11]->amount ) && $component->stored->base->equals( $returnedComponents[11]->base ), 'Its component returned exactly what it stored.' );
	}

	/**
	 * Returns the ways a three-unit line is refunded.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: list<int>}> The splits.
	 */
	public static function splits(): array {
		return array(
			'one at a time'    => array( array( 1, 1, 1 ) ),
			'two and then one' => array( array( 2, 1 ) ),
			'one and then two' => array( array( 1, 2 ) ),
			'all at once'      => array( array( 3 ) ),
		);
	}

	/**
	 * Tests that a gross-authored line keeps its gross: one unit of three returns a third of the gross, by largest remainder, and the net is derived.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_priced_gross_keeps_its_gross_and_derives_its_net(): void {
		$line    = self::line( 3, 0, AmountBasis::Gross, array( 3086, 617 ), array( 2815, 563 ) );
		$portion = RefundAllocation::line( $line, 1, false, self::nothing(), array( array( self::component( 11, 1, AmountBasis::Gross, array( 3086, 617 ), array( 2815, 563 ) ), self::nothing() ) ) );

		// 3703 gross over [1, 2] is 1234.33 and 2468.67: 1234; 617 tax is 205.67 and 411.33: 206.
		$this->assertSame( array( 1028, 206, 1234 ), self::minor( $portion->share->amount ) );
		// 3378 base gross: 1126; 563 base tax: 187.67 and 375.33, so 188.
		$this->assertSame( array( 938, 188, 1126 ), self::minor( $portion->share->base ) );
	}

	/**
	 * Tests that a line taxed by two rates returns its net once and its tax as its components' taxes added up.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider multiRateLines
	 *
	 * @param array<int, array{0: array{int, int}, 1: array{int, int}}> $components Each component's net and tax, and their base twins, by id.
	 * @param array{0: array{int, int}, 1: array{int, int}}             $line       The line's net and tax, and their base twins.
	 */
	public function test_a_line_taxed_by_several_rates_returns_its_net_once( array $components, array $line ): void {
		$refundable = self::line( 4, 0, AmountBasis::Net, $line[0], $line[1] );
		$stored     = array();

		foreach ( $components as $id => list( $amount, $base ) ) {
			$stored[] = self::component( $id, 1, AmountBasis::Net, $amount, $base );
		}

		$first = RefundAllocation::line( $refundable, 1, false, self::nothing(), array_map( static fn( StoredTaxComponent $component ): array => array( $component, self::nothing() ), $stored ) );

		$this->assertSame( intdiv( $line[0][0], 4 ), $first->share->amount->net()->minorUnits(), 'One unit of four returns a quarter of the net, never the components\' nets added up.' );
		$this->assertTrue( self::taxOf( $first->components, false )->equals( $first->share->amount->tax() ), 'The line\'s tax is its components\' tax.' );
		$this->assertTrue( self::taxOf( $first->components, true )->equals( $first->share->base->tax() ), 'And in the base currency.' );

		list( $returned, $returnedComponents ) = self::refundInParts( $refundable, $stored, array( 1, 3 ) );

		$this->assertTrue( self::stored( $refundable->stored )->amount->equals( $returned->amount ) && self::stored( $refundable->stored )->base->equals( $returned->base ), 'The line returned exactly its stored figures.' );

		foreach ( $stored as $component ) {
			$this->assertTrue( $component->stored->amount->equals( $returnedComponents[ $component->id ]->amount ) && $component->stored->base->equals( $returnedComponents[ $component->id ]->base ), "Component {$component->id} returned exactly what it stored." );
		}
	}

	/**
	 * Returns lines taxed by two rates: two that add up, and one compounding on the other.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: array<int, array{0: array{int, int}, 1: array{int, int}}>, 1: array{0: array{int, int}, 1: array{int, int}}}> The components and the line.
	 */
	public static function multiRateLines(): array {
		return array(
			'5 % and 7 % on the net'                  => array(
				array(
					21 => array( array( 10001, 500 ), array( 8001, 400 ) ),
					22 => array( array( 10001, 700 ), array( 8001, 561 ) ),
				),
				array( array( 10001, 1200 ), array( 8001, 961 ) ),
			),
			'5 %, and 9.975 % on the net and the 5 %' => array(
				array(
					23 => array( array( 10001, 500 ), array( 8001, 400 ) ),
					24 => array( array( 10501, 1047 ), array( 8401, 838 ) ),
				),
				array( array( 10001, 1547 ), array( 8001, 1238 ) ),
			),
		);
	}

	/**
	 * Tests that a line no rate taxed returns its figures with no tax and no component.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_no_rate_taxed_returns_no_tax(): void {
		$portion = RefundAllocation::line( self::line( 3, 0, AmountBasis::Gross, array( 3000, 0 ), array( 2400, 0 ) ), 1, true, self::nothing(), array() );

		$this->assertSame( array( 1000, 0, 1000 ), self::minor( $portion->share->amount ) );
		$this->assertSame( array( 800, 0, 800 ), self::minor( $portion->share->base ) );
		$this->assertSame( array(), $portion->components );
		$this->assertTrue( $portion->restock );
	}

	/**
	 * Tests that the shipping is returned whole, its free-shipping discount netting it to nothing and its negative component returned with it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_shipping_is_returned_whole_with_its_negative_component(): void {
		$shipping = self::component( 31, null, AmountBasis::Net, array( 499, 100 ), array( 399, 80 ) );
		$discount = self::component( 32, null, AmountBasis::Net, array( -499, -100 ), array( -399, -80 ) );
		$portion  = RefundAllocation::shipping( self::taxed( 0, 0, 'EUR' ), self::taxed( 0, 0, 'USD' ), self::eur( 0 ), self::usd( 0 ), array( array( $shipping, self::nothing() ), array( $discount, self::nothing() ) ) );

		$this->assertSame( array( 0, 0, 0 ), self::minor( $portion->share->amount ), 'Free shipping returns nothing.' );
		$this->assertSame( array( 499, 100, 599 ), self::minor( $portion->components[0]->share->amount ) );
		$this->assertSame( array( -499, -100, -599 ), self::minor( $portion->components[1]->share->amount ), 'The discount\'s component is returned whole, negative.' );
		$this->assertSame( array( -399, -80, -479 ), self::minor( $portion->components[1]->share->base ) );

		$taxed   = RefundAllocation::shipping( self::taxed( 499, 100, 'EUR' ), self::taxed( 399, 80, 'USD' ), self::eur( 0 ), self::usd( 0 ), array( array( $shipping, self::nothing() ) ) );
		$already = RefundAllocation::shipping( self::taxed( 499, 100, 'EUR' ), self::taxed( 399, 80, 'USD' ), self::eur( 499 ), self::usd( 399 ), array( array( $shipping, new Share( self::taxed( 499, 100, 'EUR' ), self::taxed( 399, 80, 'USD' ) ) ) ) );

		$this->assertSame( array( 499, 100, 599 ), self::minor( $taxed->share->amount ), 'Shipping never returned is returned whole.' );
		$this->assertTrue( $already->share->isNothing(), 'Shipping returned before has nothing left.' );
		$this->assertTrue( $already->components[0]->share->isNothing() );
	}

	/**
	 * Tests that a share fits what is left of a stored figure only in its direction and only up to what remains.
	 *
	 * @since 0.1.0
	 */
	public function test_a_share_fits_only_towards_its_figure_and_up_to_what_remains(): void {
		$this->assertTrue( RefundAllocation::fits( self::eur( 100 ), self::eur( 60 ), self::eur( 40 ) ) );
		$this->assertFalse( RefundAllocation::fits( self::eur( 100 ), self::eur( 60 ), self::eur( 41 ) ), 'Past what remains.' );
		$this->assertFalse( RefundAllocation::fits( self::eur( 100 ), self::eur( 0 ), self::eur( -1 ) ), 'Against a positive figure.' );
		$this->assertTrue( RefundAllocation::fits( self::eur( -100 ), self::eur( -60 ), self::eur( -40 ) ) );
		$this->assertFalse( RefundAllocation::fits( self::eur( -100 ), self::eur( -60 ), self::eur( -41 ) ), 'Past what remains of a negative figure.' );
		$this->assertFalse( RefundAllocation::fits( self::eur( -100 ), self::eur( -100 ), self::eur( -100 ) ), 'A negative figure returned in full takes no more.' );
		$this->assertFalse( RefundAllocation::fits( self::eur( -100 ), self::eur( 0 ), self::eur( 1 ) ), 'Against a negative figure.' );
		$this->assertTrue( RefundAllocation::fits( self::eur( 0 ), self::eur( 0 ), self::eur( 0 ) ) );
		$this->assertFalse( RefundAllocation::fits( self::eur( 0 ), self::eur( 0 ), self::eur( 1 ) ), 'Nothing stored, nothing to return.' );
	}

	/**
	 * Tests that the refund's total adds its lines' and its shipping's figures, in both currencies.
	 *
	 * @since 0.1.0
	 */
	public function test_the_total_adds_the_lines_and_the_shipping(): void {
		$line     = RefundAllocation::line( self::line( 3, 0, AmountBasis::Gross, array( 3000, 0 ), array( 2400, 0 ) ), 1, false, self::nothing(), array() );
		$shipping = RefundAllocation::shipping( self::taxed( 499, 100, 'EUR' ), self::taxed( 399, 80, 'USD' ), self::eur( 0 ), self::usd( 0 ), array() );
		$total    = RefundAllocation::total( array( $line ), $shipping, Currency::of( 'EUR' ), Currency::of( 'USD' ) );

		$this->assertSame( array( 1499, 0, 1499 ), self::minor( $total->amount ), 'The shipping without a component returns its net, with no tax.' );
		$this->assertSame( array( 1199, 0, 1199 ), self::minor( $total->base ) );
		$this->assertTrue( RefundAllocation::total( array(), null, Currency::of( 'EUR' ), Currency::of( 'USD' ) )->isNothing() );
	}

	/**
	 * Tests that a line is never asked for more units than it has left.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_is_never_asked_for_more_units_than_it_has_left(): void {
		$this->expectException( \InvalidArgumentException::class );

		RefundAllocation::line( self::line( 3, 2, AmountBasis::Gross, array( 3000, 0 ), array( 2400, 0 ) ), 2, false, self::nothing(), array() );
	}

	/**
	 * Refunds a line in parts, each from what the earlier ones left, and adds up what they returned.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundableLine       $line       The line, before any refund.
	 * @param StoredTaxComponent[] $components Its components.
	 * @param int[]                $split      The units of each refund.
	 * @return array{0: Share, 1: array<int, Share>} What the line returned, and what each component did, by id.
	 *
	 * @phpstan-param list<StoredTaxComponent> $components
	 * @phpstan-param list<int>                $split
	 */
	private static function refundInParts( RefundableLine $line, array $components, array $split ): array {
		$returned           = self::nothing();
		$returnedComponents = array();
		$refunded           = $line->refundedQuantity;

		foreach ( $components as $component ) {
			$returnedComponents[ $component->id ] = self::nothing();
		}

		foreach ( $split as $units ) {
			$now     = new RefundableLine( $line->id, $line->lineUuid, $line->quantity, $refunded, $line->stored );
			$portion = RefundAllocation::line( $now, $units, false, $returned, array_map( static fn( StoredTaxComponent $component ): array => array( $component, $returnedComponents[ $component->id ] ), $components ) );

			self::assertAddsUp( $portion );

			$returned  = self::plus( $returned, $portion->share );
			$refunded += $units;

			foreach ( $portion->components as $part ) {
				$returnedComponents[ $part->component->id ] = self::plus( $returnedComponents[ $part->component->id ], $part->share );
			}
		}

		return array( $returned, $returnedComponents );
	}

	/**
	 * Fails unless a line's share keeps net + tax = gross and its tax is its components', in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param LinePortion $portion The share.
	 */
	private static function assertAddsUp( LinePortion $portion ): void {
		foreach ( array( false, true ) as $base ) {
			$share = $base ? $portion->share->base : $portion->share->amount;

			self::assertTrue( $share->net()->add( $share->tax() )->equals( $share->gross() ) );
			self::assertTrue( array() === $portion->components || self::taxOf( $portion->components, $base )->equals( $share->tax() ), 'A line\'s tax is its components\' tax.' );
		}
	}

	/**
	 * Adds two shares, as the test's own sum of what was returned.
	 *
	 * @since 0.1.0
	 *
	 * @param Share $a A share.
	 * @param Share $b Another.
	 * @return Share Their sum.
	 */
	private static function plus( Share $a, Share $b ): Share {
		return new Share( $a->amount->add( $b->amount ), $a->base->add( $b->base ) );
	}

	/**
	 * Adds up some components' tax, in one currency.
	 *
	 * @since 0.1.0
	 *
	 * @param ComponentPortion[] $portions The components' shares.
	 * @param bool               $base     Whether to add the base twins.
	 * @return Money The tax.
	 *
	 * @phpstan-param list<ComponentPortion> $portions
	 */
	private static function taxOf( array $portions, bool $base ): Money {
		$tax = $base ? self::usd( 0 ) : self::eur( 0 );

		foreach ( $portions as $portion ) {
			$tax = $tax->add( ( $base ? $portion->share->base : $portion->share->amount )->tax() );
		}

		return $tax;
	}

	/**
	 * Builds a line of the order.
	 *
	 * @since 0.1.0
	 *
	 * @param int                   $quantity Units sold.
	 * @param int                   $refunded Units refunded so far.
	 * @param AmountBasis           $basis    The basis it was priced in.
	 * @param array{0: int, 1: int} $amount Its net and tax in EUR.
	 * @param array{0: int, 1: int} $base   Its net and tax in USD.
	 * @return RefundableLine The line.
	 */
	private static function line( int $quantity, int $refunded, AmountBasis $basis, array $amount, array $base ): RefundableLine {
		return new RefundableLine( 1, '01928c3e-0000-7000-8000-0000000000f1', $quantity, $refunded, new StoredAmount( $basis, self::taxed( $amount[0], $amount[1], 'EUR' ), self::taxed( $base[0], $base[1], 'USD' ) ) );
	}

	/**
	 * Builds a stored tax component.
	 *
	 * @since 0.1.0
	 *
	 * @param int                   $id     The component's id.
	 * @param int|null              $lineId Its line, or null for the shipping.
	 * @param AmountBasis           $basis  Its owner's basis.
	 * @param array{0: int, 1: int} $amount Its net and tax in EUR.
	 * @param array{0: int, 1: int} $base   Its net and tax in USD.
	 * @return StoredTaxComponent The component.
	 */
	private static function component( int $id, ?int $lineId, AmountBasis $basis, array $amount, array $base ): StoredTaxComponent {
		return new StoredTaxComponent( $id, $lineId, new StoredAmount( $basis, self::taxed( $amount[0], $amount[1], 'EUR' ), self::taxed( $base[0], $base[1], 'USD' ) ) );
	}

	/**
	 * Returns a stored amount as a share: all of it.
	 *
	 * @since 0.1.0
	 *
	 * @param StoredAmount $stored The stored amount.
	 * @return Share The share.
	 */
	private static function stored( StoredAmount $stored ): Share {
		return new Share( $stored->amount, $stored->base );
	}

	/**
	 * Returns nothing returned, in EUR and USD.
	 *
	 * @since 0.1.0
	 *
	 * @return Share Zero in both.
	 */
	private static function nothing(): Share {
		return new Share( TaxedMoney::zero( Currency::of( 'EUR' ) ), TaxedMoney::zero( Currency::of( 'USD' ) ) );
	}

	/**
	 * Builds net, tax and gross.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $net      The net.
	 * @param int    $tax      The tax.
	 * @param string $currency The currency.
	 * @return TaxedMoney The figures.
	 */
	private static function taxed( int $net, int $tax, string $currency ): TaxedMoney {
		return new TaxedMoney( Money::of( $net, Currency::of( $currency ) ), Money::of( $tax, Currency::of( $currency ) ), Money::of( $net + $tax, Currency::of( $currency ) ) );
	}

	/**
	 * Returns an amount in EUR.
	 *
	 * @since 0.1.0
	 *
	 * @param int $minor The amount.
	 * @return Money The amount.
	 */
	private static function eur( int $minor ): Money {
		return Money::of( $minor, Currency::of( 'EUR' ) );
	}

	/**
	 * Returns an amount in USD.
	 *
	 * @since 0.1.0
	 *
	 * @param int $minor The amount.
	 * @return Money The amount.
	 */
	private static function usd( int $minor ): Money {
		return Money::of( $minor, Currency::of( 'USD' ) );
	}

	/**
	 * Returns net, tax and gross in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $figures The figures.
	 * @return list<int> Net, tax and gross.
	 */
	private static function minor( TaxedMoney $figures ): array {
		return array( $figures->net()->minorUnits(), $figures->tax()->minorUnits(), $figures->gross()->minorUnits() );
	}
}
