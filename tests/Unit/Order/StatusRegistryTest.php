<?php
/**
 * Tests the order status registry: one table, complete, and read the same way by every question
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Order\Domain\PaymentStatus;

/**
 * The registry is the order state machine: these tests hold it complete and consistent.
 *
 * The enum names the statuses and the registry's table describes them, so the two are a pair of
 * lists; the first test is their set-equality check. The transitions an amount mismatch, a
 * decline and a stock shortfall need are pinned by name, because the payment and checkout
 * services depend on them.
 *
 * Planted violations, each shown red and removed:
 * - remove the `preorder` row from the table: the set-equality test fails;
 * - remove `on_hold` from `pending_payment`'s transitions: the named-edges test fails;
 * - set `is_paid` on `processing`: the paid-status test fails.
 *
 * @since 0.1.0
 */
final class StatusRegistryTest extends TestCase {

	/**
	 * Tests that the table describes every status of the enum, once, and names only statuses of the enum.
	 *
	 * @since 0.1.0
	 */
	public function test_the_table_describes_exactly_the_statuses_of_the_enum(): void {
		$table    = ( new OrderStatusRegistry() )->transitions();
		$statuses = array_map( static fn( OrderStatus $status ): string => $status->value, OrderStatus::cases() );

		$this->assertSame( $statuses, array_keys( $table ), 'The registry has one row per status, in the enum\'s order.' );

		foreach ( $table as $from => $to ) {
			$this->assertSame( array(), array_values( array_diff( $to, $statuses ) ), "{$from} changes to a status the enum does not name." );
			$this->assertNotContains( $from, $to, "{$from} changes to itself." );
			$this->assertSame( $to, array_values( array_unique( $to ) ), "{$from} lists a transition twice." );
		}
	}

	/**
	 * Tests that allowedFrom() is isAllowed() read the other way, for every pair.
	 *
	 * @since 0.1.0
	 */
	public function test_allowed_from_and_is_allowed_agree_for_every_pair(): void {
		$registry = new OrderStatusRegistry();

		foreach ( OrderStatus::cases() as $to ) {
			foreach ( OrderStatus::cases() as $from ) {
				$this->assertSame( $registry->isAllowed( $from, $to ), in_array( $from, $registry->allowedFrom( $to ), true ), "{$from->value} -> {$to->value}" );
			}
		}
	}

	/**
	 * Tests the transitions the payment and checkout services rely on, and that every order starts where nothing leads back.
	 *
	 * @since 0.1.0
	 */
	public function test_the_transitions_other_services_rely_on_exist(): void {
		$registry = new OrderStatusRegistry();
		$needed   = array(
			array( OrderStatus::PendingPayment, OrderStatus::Processing ),
			array( OrderStatus::PendingPayment, OrderStatus::Failed ),
			array( OrderStatus::PendingPayment, OrderStatus::OnHold ),
			array( OrderStatus::AwaitingReview, OrderStatus::OnHold ),
			array( OrderStatus::Processing, OrderStatus::OnHold ),
			array( OrderStatus::OnHold, OrderStatus::Processing ),
			array( OrderStatus::OnHold, OrderStatus::Cancelled ),
			array( OrderStatus::OnHold, OrderStatus::Failed ),
		);

		foreach ( $needed as $pair ) {
			$this->assertTrue( $registry->isAllowed( $pair[0], $pair[1] ), "{$pair[0]->value} -> {$pair[1]->value}" );
		}

		$this->assertSame( OrderStatus::PendingPayment, $registry->initial() );
		$this->assertSame( array(), $registry->allowedFrom( $registry->initial() ), 'Nothing leads back to the status every order starts in.' );
		$this->assertSame( array(), $registry->transitions()[ OrderStatus::Failed->value ], 'A failed order stays failed.' );
	}

	/**
	 * Tests that only `completed` claims payment, and what the paid claim requires of the payment status.
	 *
	 * @since 0.1.0
	 */
	public function test_only_completed_claims_payment(): void {
		$registry = new OrderStatusRegistry();
		$paid     = array();

		foreach ( OrderStatus::cases() as $status ) {
			if ( $registry->metadata( $status )->isPaid ) {
				$paid[] = $status;
			}
		}

		$this->assertSame( array( OrderStatus::Completed ), $paid, 'processing is entered on authorization, before any capture, so it cannot claim payment.' );
		$this->assertSame( array( PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded ), PaymentStatus::settled() );
	}

	/**
	 * Tests the metadata of the statuses an order ends in, and that each status has a label key.
	 *
	 * @since 0.1.0
	 */
	public function test_every_status_has_metadata_and_a_label(): void {
		$registry = new OrderStatusRegistry();
		$final    = array();

		foreach ( OrderStatus::cases() as $status ) {
			$metadata = $registry->metadata( $status );

			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $metadata->customerVisibleLabel );

			if ( $metadata->isFinal ) {
				$final[] = $status;
			}
		}

		$this->assertSame( array( OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Failed ), $final );
		$this->assertTrue( $registry->metadata( OrderStatus::Processing )->releasesFulfilment );
		$this->assertTrue( $registry->metadata( OrderStatus::Completed )->postsStockLedger );
		$this->assertTrue( $registry->metadata( OrderStatus::OnHold )->holdsAllocation );
	}
}
