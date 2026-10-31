<?php
/**
 * Tests the kernel's listener of a transfer's shortage: an order left short is put on hold once, and only from a status that allows it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Domain\Event\StockTransferShort;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\Order\OrderTestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The action is the plugin's own, named by the kernel's constant.

/**
 * The one listener the kernel adds for the plugin's own events, run as the event bridge runs it: on the event's action, with the event.
 *
 * The kernel's own listener is taken off the action, and the production wiring is subscribed
 * again over the test's container, whose order service is the test's over real order tables.
 * Every hook is put back after the test.
 *
 * Planted violation, shown red and removed: in Modules::inventorySubscribe(), let every coded
 * refusal through (drop the rethrow): an order that is not found is then passed over silently.
 *
 * @since 0.2.0
 */
final class ShortTransferListenerTest extends OrderTestCase {

	/**
	 * Takes the kernel's listener off and subscribes the production wiring over the test's container.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$container = KernelContainer::build(
			$this->db,
			$this->reporter(),
			array( Orders::class => fn(): Orders => $this->orders )
		);

		KernelHooks::detach( Modules::TRANSFER_SHORT_HOOK );
		Modules::subscribe( $container );
	}

	/**
	 * Tests that an order left short is put on hold once: the event delivered again, or a second shortage while it is on hold, changes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_left_short_is_put_on_hold_once(): void {
		$order = $this->place()->id;
		$b     = $this->secondConnection();

		$this->plantStatus( $order, OrderStatus::Processing, PaymentStatus::Paid );

		$short = new StockTransferShort( $order, 41, 410, 4100, 1, new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) );

		do_action( Modules::TRANSFER_SHORT_HOOK, $short );

		$this->assertSame( 'on_hold', $this->committedStatus( $b, $order )['status'] ?? null );
		$this->assertSame( 1, $this->outboxRows( OrderStatusChanged::eventName() ) );

		do_action( Modules::TRANSFER_SHORT_HOOK, $short );
		do_action( Modules::TRANSFER_SHORT_HOOK, new StockTransferShort( $order, 41, 411, 4101, 2, new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) ) );

		$this->assertSame( 'on_hold', $this->committedStatus( $b, $order )['status'] ?? null );
		$this->assertSame( 1, $this->outboxRows( OrderStatusChanged::eventName() ), 'A redelivery and a second shortage changed nothing.' );
		$this->assertCount( 1, preg_grep( '/:processing>on_hold:stock_unavailable$/', $this->eventsOf( $order ) ), implode( "\n", $this->eventsOf( $order ) ) );
	}

	/**
	 * Tests that a shortage of an order that has ended changes nothing, and that any other refusal is not passed over: it is the event bridge's to report.
	 *
	 * @since 0.2.0
	 */
	public function test_a_shortage_of_an_ended_order_changes_nothing_and_another_failure_goes_up(): void {
		$order = $this->place()->id;
		$b     = $this->secondConnection();

		$this->plantStatus( $order, OrderStatus::Completed, PaymentStatus::Paid );

		do_action( Modules::TRANSFER_SHORT_HOOK, new StockTransferShort( $order, 42, 420, 4200, 1, new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) ) );

		$this->assertSame( 'completed', $this->committedStatus( $b, $order )['status'] ?? null );

		try {
			do_action( Modules::TRANSFER_SHORT_HOOK, new StockTransferShort( $order + 1000, 42, 421, 4201, 1, new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) ) );
			$this->fail( 'The shortage of an order that does not exist was passed over.' );
		} catch ( CodedException $failed ) {
			$this->assertSame( OrderError::NotFound, $failed->errorCode() );
		}
	}
}
