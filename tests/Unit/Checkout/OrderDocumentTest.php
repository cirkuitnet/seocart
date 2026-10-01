<?php
/**
 * Tests the order a placement writes: its figures copied from the totals, its words from the sale
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Checkout\Application\OrderDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Address;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * OrderDocument copies every figure from the totals and takes each line's product, SKU and title from the facts its sale was judged on.
 *
 * Planted violation: in OrderDocument::line(), take the SKU from the line's variant id
 * (`'SKU-' . $input->variantId`): the order then keeps a SKU the catalog never had.
 *
 * @since 0.1.0
 */
final class OrderDocumentTest extends TestCase {

	/**
	 * The stock hold the order was placed with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HOLD_GROUP = '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b';

	/**
	 * Tests that the order is the storefront's, in the cart's locale, for the billing e-mail, with the hold and the customer given, and each line's words from its sale.
	 *
	 * @since 0.1.0
	 */
	public function test_the_order_copies_the_totals_and_takes_its_words_from_the_sale(): void {
		$totals = self::totals( AmountBasis::Net, AmountBasis::Net );
		$order  = OrderDocument::of( $totals, Locale::of( 'fr_FR' ), self::details(), self::sold(), self::HOLD_GROUP, 12 );

		$this->assertSame( array( OrderChannel::Storefront, 'ada@example.com', 12, self::HOLD_GROUP, 'fr_FR' ), array( $order->channel, $order->email, $order->customerId, $order->holdGroup, $order->locale->toString() ) );
		$this->assertSame( 'Austin', $order->shippingAddress?->city() );
		$this->assertSame(
			array(
				array( 7, 70, 'MUG-7', 'Tasse', '', 2 ),
				array( 9, 90, 'TEE-9', 'T-shirt', '', 1 ),
			),
			array_map( static fn( $line ): array => array( $line->variantId, $line->productId, $line->sku, $line->title, $line->variantLabel, $line->quantity ), $order->lines )
		);
		$this->assertTrue( $totals->summary->grand->equals( $order->totals->grandTotal ), 'The grand total is the calculation\'s.' );
		$this->assertTrue( $totals->lines[0]->amount->gross()->equals( $order->lines[0]->lineTotal ), 'A line\'s total is the calculation\'s.' );
		$this->assertSame( 'net', $order->totals->priceEntryMode );
		$this->assertSame( array( 'entries' => $totals->trace->toArray() ), $order->totals->trace );
	}

	/**
	 * Tests that lines priced in two bases record the entry as mixed.
	 *
	 * @since 0.1.0
	 */
	public function test_lines_of_two_bases_record_a_mixed_entry(): void {
		$order = OrderDocument::of( self::totals( AmountBasis::Net, AmountBasis::Gross ), Locale::of( 'en_US' ), self::details(), self::sold(), null, null );

		$this->assertSame( OrderDocument::MIXED_ENTRY, $order->totals->priceEntryMode );
	}

	/**
	 * Tests that a checkout without a billing address, and a line whose sale was not judged, are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_without_a_billing_address_or_a_judged_sale_is_refused(): void {
		$totals = self::totals( AmountBasis::Net, AmountBasis::Net );

		foreach (
			array(
				'no billing address' => static fn() => OrderDocument::of( $totals, Locale::of( 'en_US' ), new CheckoutDetails( null, null, null, null ), self::sold(), null, null ),
				'a line not judged'  => static fn() => OrderDocument::of( $totals, Locale::of( 'en_US' ), self::details(), array_slice( self::sold(), 0, 1, true ), null, null ),
			) as $case => $build
		) {
			try {
				$build();
				$this->fail( 'An order was built with ' . $case . '.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertNotSame( '', $refused->getMessage(), $case );
			}
		}
	}

	/**
	 * Calculates two lines, two of variant 7 at 10.00 and one of variant 9 at 5.00, shipped at a flat 5.00.
	 *
	 * @since 0.1.0
	 *
	 * @param AmountBasis $first  The first line's price basis.
	 * @param AmountBasis $second The second line's price basis.
	 * @return Totals The totals.
	 */
	private static function totals( AmountBasis $first, AmountBasis $second ): Totals {
		return Inputs::calculate(
			Inputs::input(
				array(
					Inputs::line( 'mug', '10.00', 2, $first, variantId: 7 ),
					Inputs::line( 'tee', '5.00', 1, $second, variantId: 9 ),
				)
			),
			array( Inputs::shippingRate( 'flat', '5.00' ) )
		);
	}

	/**
	 * Returns a checkout with both addresses.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckoutDetails The checkout.
	 */
	private static function details(): CheckoutDetails {
		return new CheckoutDetails(
			new Address( 'US', first_name: 'Ada', last_name: 'Lovelace', line1: '1 Main Street', city: 'Austin', postcode: '78701', email: 'ada@example.com' ),
			new Address( 'US', line1: '1 Main Street', city: 'Austin', postcode: '78701' ),
			null,
			'stub'
		);
	}

	/**
	 * Returns the facts each line's sale was judged on, by variant id.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, SellabilityFacts> The facts.
	 */
	private static function sold(): array {
		return array(
			7 => new SellabilityFacts( 7, 70, GenerationState::Complete, 1, 1, true, 700, 700, 'publish', true, true, 'MUG-7', 'Tasse' ),
			9 => new SellabilityFacts( 9, 90, GenerationState::Complete, 1, 1, true, 900, 900, 'publish', true, true, 'TEE-9', 'T-shirt' ),
		);
	}
}
