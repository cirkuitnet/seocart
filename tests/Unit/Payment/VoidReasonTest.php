<?php
/**
 * Tests the reasons a void is asked for, and which of them a person may give
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\VoidReason;

/**
 * A merchant voids for any reason but the end of a shopper's time to act, which only the store gives; the list a merchant may choose from is the void operation's.
 *
 * Planted violation, shown red and removed: in VoidReason::askedByAPerson(), answer true for every
 * reason: a merchant may give the end of a shopper's time to act.
 *
 * @since 0.2.0
 */
final class VoidReasonTest extends TestCase {

	/**
	 * Tests that a merchant may give every reason but the end of a shopper's time to act and a void the provider reports on its own, in the enum's order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_merchant_gives_every_reason_but_the_end_of_a_shoppers_time(): void {
		$this->assertSame(
			array( 'customer_request', 'duplicate_order', 'fraud', 'out_of_stock', 'other' ),
			array_map( static fn( VoidReason $reason ): string => $reason->value, VoidReason::merchant() )
		);
	}

	/**
	 * Tests that only the end of a shopper's time to act, the store's own reason, and a void the provider reports on its own were asked by nobody of the store.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_end_of_a_shoppers_time_and_a_providers_own_void_are_nobodys_ask(): void {
		$stores = array_filter( VoidReason::cases(), static fn( VoidReason $reason ): bool => ! $reason->askedByAPerson() );

		$this->assertSame( array( VoidReason::ActionWindowEnded, VoidReason::VoidedExternally ), array_values( $stores ) );
	}
}
