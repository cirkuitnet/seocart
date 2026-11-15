<?php
/**
 * Tests whom a cart that is not open names its order to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Customer;

use SEOCart\Cart\Application\CartService;
use SEOCart\Checkout\Application\ChangeCartCurrency;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;

/**
 * A write to a cart that is placing an order is refused `cart.not_open`, which names the order, by its uuid and status, only to someone who may read it: anyone holding the cart's token for a guest's order, and the customer alone for a customer's.
 *
 * Both writes that name the order are asked, the placement with a key never seen and the switch
 * of the cart's currency, by the order's customer, another customer, an administrator who is no
 * customer, and a visitor, all holding the cart's token, as people signed in one after another on
 * one browser do. The table of what each was told is printed.
 *
 * Planted violation: in NotOpenCart::mayName(), answer true whoever asks: another customer, the
 * administrator and the visitor are told the customer's order.
 *
 * @since 0.2.0
 */
final class NotOpenNamingTest extends PlacementTestCase {

	use CreatesUsers;
	use CustomerRecords;

	/**
	 * Deletes the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a cart placing a customer's order names it to that customer alone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cart_placing_a_customers_order_names_it_to_that_customer_alone(): void {
		$owner   = $this->createUser( 'subscriber' );
		$another = $this->createUser( 'subscriber' );

		$this->plantCustomer( $another, self::registeredAt( $another ) );

		$this->assertTold(
			Actor::user( $owner ),
			array(
				'the customer'     => array( Actor::user( $owner ), true ),
				'another customer' => array( Actor::user( $another ), false ),
				'an administrator' => array( Actor::user( $this->createUser( 'administrator' ) ), false ),
				'a visitor'        => array( self::guest(), false ),
			),
			'A customer\'s order'
		);
	}

	/**
	 * Tests that a cart placing a guest's order names it to whoever holds the cart's token, as before.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cart_placing_a_guests_order_names_it_to_whoever_holds_its_token(): void {
		$user = $this->createUser( 'subscriber' );

		$this->plantCustomer( $user, self::registeredAt( $user ) );

		$this->assertTold(
			self::guest(),
			array(
				'the guest'        => array( self::guest(), true ),
				'a customer'       => array( Actor::user( $user ), true ),
				'an administrator' => array( Actor::user( $this->createUser( 'administrator' ) ), true ),
			),
			'A guest\'s order'
		);
	}

	/**
	 * Places a cart, its payment left waiting for the shopper so the cart stays placing, then has each requester send both writes, and asserts whom the order was named to.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor                                   $placer     Who places.
	 * @param array<string, array{0: Actor, 1: bool}> $requesters Each requester, and whether the order may be named to them.
	 * @param string                                  $kind       The order's kind, for the messages.
	 */
	private function assertTold( Actor $placer, array $requesters, string $kind ): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( 'the placement', StubGateway::REQUIRES_ACTION );
		$placed = $this->placement->place( $input, $placer );
		$named  = array(
			'order_uuid'   => $placed['order_uuid'],
			'order_status' => 'pending_payment',
		);
		$table  = array();

		$this->assertSame( 'requires_action', $placed['outcome'], 'The cart stays placing while the shopper must act.' );

		foreach ( $requesters as $who => list( $actor, $mayRead ) ) {
			$writes = array(
				'checkout.place_order'     => fn() => $this->placement->place( array( 'idempotency_key' => "a key never seen, {$who}" ) + $input, $actor ),
				'checkout.change_currency' => fn() => $this->kernel->get( ChangeCartCurrency::class )->change(
					array(
						'currency'     => 'USD',
						'cart_version' => (int) $this->kernel->get( CartService::class )->current()?->version,
					),
					$actor
				),
			);

			foreach ( $writes as $operation => $write ) {
				$refused = self::refusalOf( $write );

				$table[ $who ][ $operation ] = $refused['code'] . ( array() === $refused['details'] ? '' : ' naming the order' );

				$this->assertSame( 'cart.not_open', $refused['code'], "{$kind}, {$who}, {$operation}" );
				$this->assertSame( array( 'status' => 'placing' ), $refused['context'], "{$kind}, {$who}, {$operation}: the cart's status is told to all." );
				$this->assertSame( $mayRead ? $named : array(), $refused['details'], "{$kind}, {$who}, {$operation}: the order is named to whoever may read it, and to nobody else." );
			}
		}

		$lines = array();

		foreach ( $table as $who => $operations ) {
			$lines[] = sprintf( '%-18s %-30s %s', $who, $operations['checkout.place_order'], $operations['checkout.change_currency'] );
		}

		fwrite( STDOUT, sprintf( "\n%s, the cart placing it, as each requester was told (checkout.place_order, checkout.change_currency):\n  %s\n", $kind, implode( "\n  ", $lines ) ) );
	}

	/**
	 * Runs a write that must be refused, and returns the refusal.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $write The write.
	 * @return array{code: string, context: array<string, mixed>, details: array<string, mixed>} The refusal.
	 */
	private static function refusalOf( callable $write ): array {
		try {
			$write();
		} catch ( CodedException $refused ) {
			return array(
				'code'    => $refused->errorCode()->value,
				'context' => $refused->context(),
				'details' => $refused->details(),
			);
		}

		self::fail( 'The write to a cart that is placing was not refused.' );
	}
}
