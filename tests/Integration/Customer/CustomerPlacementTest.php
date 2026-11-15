<?php
/**
 * Tests the customer an order placed while logged in belongs to, and who a kept placement's answer is given back to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Customer;

use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Customer\Application\Customers;
use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;
use SEOCart\Tests\Support\StatementShapes;

/**
 * A logged-in user's placement: the record it creates or finds, the record nothing a client sends can change, the record a rollback leaves out, and the kept answer given back to that customer alone.
 *
 * The production placement over the test's connection and real WordPress users. The customer
 * comes from the actor and from nothing else; a guest's order has none, and its kept answer is
 * given to whoever presents the cart's token and the key, as before.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class CustomerPlacementTest extends PlacementTestCase {

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
	 * Tests that a user's first order creates their record, linked at their registration time, and their next order, from another cart, finds it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_first_order_creates_one_record_and_the_next_reuses_it(): void {
		$user = $this->createUser( 'subscriber' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$first = $this->placement->place( $this->placeInput( 'first' ), Actor::user( $user ) );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$second  = $this->placement->place( $this->placeInput( 'second' ), Actor::user( $user ) );
		$records = $this->customerRows();

		$this->assertSame( array( 'approved', 'approved' ), array( $first['outcome'], $second['outcome'] ) );
		$this->assertCount( 1, $records, 'One record for the user.' );
		$this->assertSame(
			array(
				'user_id'            => $user,
				'user_registered_at' => self::registeredAt( $user ),
			),
			reset( $records )
		);
		$this->assertSame( array( (int) key( $records ), (int) key( $records ) ), array( $this->customerOfOrder( $first['order_uuid'] ), $this->customerOfOrder( $second['order_uuid'] ) ), 'Both orders belong to the record.' );
		$this->assertArrayNotHasKey( PlaceOrder::KEPT_FOR, $first, 'No answer says whom it is kept for.' );
		$this->assertSame( array( 'order_uuid', 'order_number', 'order_key', 'cart_version', 'outcome', 'status', 'payment_status', 'transfer_days', 'next_action', 'intent_uuid', 'payment_method_label', 'payment_instructions' ), array_keys( $first ), 'A customer\'s answer has a guest\'s fields, the order key among them.' );
	}

	/**
	 * Tests that a user whose id a deleted user had reads none of the deleted user's orders, and that their first order unlinks the old record and begins one of their own.
	 *
	 * The deleted user's record is planted for the live user's id at another registration time:
	 * what the store holds once a deleted user's id has come back for someone else.
	 *
	 * Planted violation: in Customers::customerOf(), compare the link by the user's id alone. The
	 * new user then reads the old order, and their order joins the old record.
	 *
	 * @since 0.2.0
	 */
	public function test_a_user_given_a_deleted_users_id_reads_none_of_their_orders_and_begins_a_record(): void {
		$user = $this->createUser( 'subscriber' );
		$old  = $this->plantCustomer( $user, '2001-02-03 04:05:06' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$theirs = $this->placement->place( $this->placeInput( 'theirs' ), self::guest() );

		$this->db->execute( 'UPDATE %i SET customer_id = %d WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $old, $theirs['order_uuid'] );
		$this->assertNotRead( $theirs['order_uuid'], Actor::user( $user ), 'The new user, before ordering' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$mine    = $this->placement->place( $this->placeInput( 'mine' ), Actor::user( $user ) );
		$records = $this->customerRows();
		$new     = $this->customerOfOrder( $mine['order_uuid'] );

		$this->assertNotSame( $old, $new, 'The new user\'s order begins a record of their own.' );
		$this->assertSame(
			array(
				$old => array(
					'user_id'            => null,
					'user_registered_at' => null,
				),
				$new => array(
					'user_id'            => $user,
					'user_registered_at' => self::registeredAt( $user ),
				),
			),
			$records,
			'The old record is unlinked and kept; the new one is linked at the user\'s own time.'
		);
		$this->assertSame( $old, $this->customerOfOrder( $theirs['order_uuid'] ), 'The old order keeps naming its record.' );
		$this->assertNotRead( $theirs['order_uuid'], Actor::user( $user ), 'The new user, after ordering' );
		$this->assertSame( $mine['order_uuid'], $this->policy()->authorize( $mine['order_uuid'], Actor::user( $user ), null )->uuid, 'The new user reads their own order without a key.' );
	}

	/**
	 * Tests that a customer id a client adds to the placement reaches nothing: the order belongs to the actor's record.
	 *
	 * Planted violation: in PlaceOrder::placeInside(), take the customer from
	 * `$input['customer_id']` when it is there. The order then belongs to the other customer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_customer_id_in_the_request_reaches_nothing(): void {
		$other  = $this->createUser( 'subscriber' );
		$theirs = $this->plantCustomer( $other, self::registeredAt( $other ) );
		$user   = $this->createUser( 'subscriber' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$answer = $this->placement->place( $this->placeInput() + array( 'customer_id' => $theirs ), Actor::user( $user ) );
		$mine   = $this->customerOfOrder( $answer['order_uuid'] );

		$this->assertNotSame( $theirs, $mine, 'The order went to the customer the client named.' );
		$this->assertSame( $user, $this->customerRows()[ $mine ]['user_id'] ?? null, 'The order belongs to the actor\'s own record.' );
	}

	/**
	 * Tests that a first order whose first unit of work rolls back leaves no record behind.
	 *
	 * Another checkout takes the last units after the plan was made and before the hold: the hold
	 * is refused, the unit rolls back, the fresh plan finds nothing, and the placement is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_that_rolls_back_leaves_no_record(): void {
		$user    = $this->createUser( 'subscriber' );
		$mug     = $this->sellable( 1 );
		$b       = $this->secondConnection();
		$barrier = $this->beforeStatement(
			'/^' . preg_quote( sprintf( 'UPDATE `%s` SET held = held + ', $this->table( InventoryTables::ITEMS ) ), '/' ) . '/',
			function () use ( $b, $mug ): void {
				$b->query( sprintf( 'UPDATE `%s` SET on_hand = 0 WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $mug ) );
			}
		);

		$this->readyCart( array( $mug => 1 ) );

		$created = $this->captureQueries(
			function () use ( $user ): void {
				try {
					$this->placement->place( $this->placeInput(), Actor::user( $user ) );
					$this->fail( 'The placement went through without the unit it planned.' );
				} catch ( CodedException $refused ) {
					unset( $refused );
				}
			}
		)->matching( '/^INSERT INTO `[^`]+customers`/' );

		$this->assertTrue( $barrier->fired );
		$this->assertSame( 1, $created->count(), 'The first unit created the record before it was refused.' );
		$this->assertSame( 0, $this->committedCount( $b, CustomerTables::CUSTOMERS ), 'The record went with the rollback.' );
		$this->assertSame( 0, $this->committedCount( $b, OrderTables::ORDERS ) );
	}

	/**
	 * Tests that a placement's answer, lost and asked for again with its key, is given back to the customer who placed it, and to nobody else on the same cart: another customer and a visitor are answered as for a key never seen, with the same statements, and get neither the order's number nor its key.
	 *
	 * The cart's token and the key are the browser's: a shopper who signs out, and another who
	 * signs in on the same browser, send them both. What a key never seen gets depends on the cart:
	 * once the payment is approved the cart is converted and the token finds no cart,
	 * `cart.not_found`; while the shopper must still act the cart is placing, and the refusal is
	 * the cart's own, `cart.not_open`, which names the order's uuid and status to any request with
	 * the cart's token, as before.
	 *
	 * Planted violation: in PlaceOrder::keptAnswer(), give the answer back whoever asks (drop
	 * `null === $for || $this->isKeptFor( … ) ?`). Another customer is then handed the order's
	 * number and key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_customers_kept_answer_is_given_back_to_that_customer_alone(): void {
		$placer  = $this->createUser( 'subscriber' );
		$another = $this->createUser( 'subscriber' );
		$admin   = $this->createUser( 'administrator' );
		$others  = array(
			'another customer' => Actor::user( $another ),
			'an administrator' => Actor::user( $admin ),
			'a visitor'        => self::guest(),
		);

		$this->plantCustomer( $another, self::registeredAt( $another ) );

		$carts = array(
			'approved'        => array( self::APPROVE, 'cart.not_found' ),
			'requires_action' => array( StubGateway::REQUIRES_ACTION, 'cart.not_open' ),
		);

		foreach ( $carts as $outcome => list( $paymentToken, $neverSeen ) ) {
			$this->readyCart( array( $this->sellable() => 1 ) );

			$input  = $this->placeInput( "the placer's attempt, {$outcome}", $paymentToken );
			$placed = $this->placement->place( $input, Actor::user( $placer ) );
			$again  = $this->placement->place( $input, Actor::user( $placer ) );

			$this->assertSame( $outcome, $placed['outcome'] );
			$this->assertSame( array( $placed['order_uuid'], $placed['order_number'], $placed['order_key'] ), array( $again['order_uuid'], $again['order_number'], $again['order_key'] ?? null ), "{$outcome}: the placer is given their answer back, their order key included." );
			$this->assertEqualsCanonicalizing( array_keys( $placed ), array_keys( $again ), "{$outcome}: the answer given back has the fields of the answer sent, and no more." );

			$changed = array( 'grand_total_minor' => (int) $input['grand_total_minor'] + 1 ) + $input;

			$this->assertSame( 'checkout.idempotency_key_reused', $this->refusal( $changed, Actor::user( $placer ) )['answer']['code'] ?? null, "{$outcome}: the placer, with their key and another body, is refused by the key." );

			foreach ( $others as $who => $actor ) {
				foreach ( array(
					'the same body' => $input,
					'another body'  => $changed,
				) as $body => $sent ) {
					$this->assertNeverSeen( $this->refusal( $sent, $actor ), $this->refusal( array( 'idempotency_key' => 'unseen-' . substr( md5( "{$outcome}, {$who}, {$body}" ), 0, 12 ) ) + $sent, $actor ), $placed, "{$outcome}: {$who}, with the placer's key and {$body}" );
				}
			}
		}

		// The key of the placement still waiting for the shopper, as it is while its placement is under way: claimed, its order not written, and the user it is placing for kept.
		$waiting = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $placed['order_uuid'] );

		$this->db->execute( "UPDATE %i SET state = 'claimed', order_id = NULL, response_json = %s WHERE order_id = %d", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), PlaceOrder::claimedFor( Actor::user( $placer ) ), $waiting );

		$this->assertSame( 'checkout.placement_in_progress', $this->refusal( $input, Actor::user( $placer ) )['answer']['code'] ?? null, 'The placer is told their placement is under way.' );

		foreach ( $others as $who => $actor ) {
			$this->assertNeverSeen( $this->refusal( $input, $actor ), $this->refusal( array( 'idempotency_key' => 'unseen-' . substr( md5( "under way, {$who}" ), 0, 12 ) ) + $input, $actor ), $placed, "Under way: {$who}, with the placer's key" );
		}
	}

	/**
	 * Tests that a user given the placer's id, registered at another time, holding the placer's cart and key, is answered as for a key never seen: being the placer's user id is not being the placer's customer.
	 *
	 * The placer's registration time is moved, as a new user given a deleted user's id has another.
	 *
	 * Planted violation: in PlaceOrder::isKeptFor(), answer the placer's user id without asking whether it is still the order's
	 * customer (replace `$customer === $this->customers->customerOf( $actor )` with `true`): the new user is handed the order's
	 * number and key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_user_given_the_placers_id_is_answered_as_for_a_key_never_seen(): void {
		$placer = $this->createUser( 'subscriber' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( 'the placer\'s attempt' );
		$placed = $this->placement->place( $input, Actor::user( $placer ) );

		$this->db->execute( 'UPDATE %i SET user_registered = %s WHERE ID = %d', $GLOBALS['wpdb']->users, '2001-02-03 04:05:06', $placer );
		clean_user_cache( $placer );

		$refused = $this->refusal( $input, Actor::user( $placer ) );
		$unknown = $this->refusal( array( 'idempotency_key' => 'a key never seen' ) + $input, Actor::user( $placer ) );

		$this->assertSame( $unknown['answer'], $refused['answer'], 'The user given the placer\'s id is answered as for a key never seen.' );
		$this->assertStringNotContainsString( $placed['order_number'], (string) wp_json_encode( $refused['answer'] ) );
		$this->assertStringNotContainsString( $placed['order_key'], (string) wp_json_encode( $refused['answer'] ) );
	}

	/**
	 * Asserts that a request with another actor's key was answered exactly as one with a key never seen: the same answer, the same statements in shape, and nothing of the order but what a key never seen names.
	 *
	 * @since 0.2.0
	 *
	 * @param array{answer: array<string, mixed>, statements: list<string>} $refused What the request with the key got.
	 * @param array{answer: array<string, mixed>, statements: list<string>} $unknown What the request with a key never seen got.
	 * @param array<string, mixed>                                          $placed  The placement's answer.
	 * @param string                                                        $who     Who asked, for the messages.
	 */
	private function assertNeverSeen( array $refused, array $unknown, array $placed, string $who ): void {
		$said = (string) wp_json_encode( $refused['answer'] );

		$this->assertSame( $unknown['answer'], $refused['answer'], "{$who}: answered as for a key never seen." );
		$this->assertSame( $unknown['statements'], $refused['statements'], "{$who}: the statements of a key never seen." );
		$this->assertContains( $refused['answer']['code'] ?? null, array( 'cart.not_found', 'cart.not_open' ), $who );
		$this->assertStringNotContainsString( (string) $placed['order_uuid'], $said, "{$who}: told the order's uuid." );
		$this->assertStringNotContainsString( (string) $placed['order_number'], $said, "{$who}: told the order's number." );
		$this->assertStringNotContainsString( (string) $placed['order_key'], $said, "{$who}: told the order's key." );
	}

	/**
	 * Tests that a guest's kept answer is given back, as before, to whoever presents the cart's token and the key: a guest, and a user who signed in since.
	 *
	 * @since 0.2.0
	 */
	public function test_a_guests_kept_answer_is_given_back_as_before(): void {
		$user = $this->createUser( 'subscriber' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( 'the guest\'s attempt' );
		$placed = $this->placement->place( $input, self::guest() );
		$kept   = (string) $this->db->fetchValue( 'SELECT response_json FROM %i ORDER BY id DESC LIMIT 1', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) );

		$this->assertNull( $this->customerOfOrder( $placed['order_uuid'] ), 'A guest\'s order has no customer.' );
		$this->assertStringNotContainsString( PlaceOrder::KEPT_FOR, $kept, 'A guest\'s answer is kept as it was before.' );

		foreach ( array(
			'the guest'              => self::guest(),
			'a user signed in since' => Actor::user( $user ),
		) as $who => $actor ) {
			$again = $this->placement->place( $input, $actor );

			$this->assertSame( array( $placed['order_uuid'], $placed['order_number'], $placed['order_key'] ), array( $again['order_uuid'], $again['order_number'], $again['order_key'] ?? null ), "{$who} is given the answer back." );
		}

		// The key as it is while a visitor's placement is under way: claimed, keeping nothing. Whoever holds the cart's token is told so, as before.
		$this->db->execute( "UPDATE %i SET state = 'claimed', order_id = NULL, response_json = NULL ORDER BY id DESC LIMIT 1", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) );

		foreach ( array(
			'the guest'              => self::guest(),
			'a user signed in since' => Actor::user( $user ),
		) as $who => $actor ) {
			$this->assertSame( 'checkout.placement_in_progress', $this->refusal( $input, $actor )['answer']['code'] ?? null, "{$who} is told the placement is under way." );
		}

		$this->assertSame( array(), $this->customerRows(), 'Nobody became a customer.' );
	}

	/**
	 * Tests that, on a network, a user who buys on one site has a record there and none on another site of the network.
	 *
	 * Tables are per site: the record is the site's, and the user is no customer of a site they
	 * have not bought from. Runs on a network only: set WP_MULTISITE=1.
	 *
	 * @since 0.2.0
	 */
	public function test_on_a_network_a_user_has_a_record_on_the_site_they_bought_from_alone(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs on a network only: set WP_MULTISITE=1.' );
		}

		$user  = $this->createUser( 'subscriber' );
		$other = wp_insert_site(
			array(
				'domain' => (string) get_network()?->domain,
				'path'   => '/customer-records-other/',
			)
		);

		$this->assertIsInt( $other );
		$this->readyCart( array( $this->sellable() => 1 ) );

		$placed = $this->placement->place( $this->placeInput(), Actor::user( $user ) );
		$here   = $this->customerRows();

		switch_to_blog( $other );

		try {
			$this->createCustomerTable();

			$there    = $this->customerRows();
			$customer = $this->kernel->get( Customers::class )->customerOf( Actor::user( $user ) );

			$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( CustomerTables::CUSTOMERS ) );
		} finally {
			restore_current_blog();
			wp_delete_site( $other );
		}

		$this->assertSame( 'approved', $placed['outcome'] );
		$this->assertCount( 1, $here, 'A record on the site the user bought from.' );
		$this->assertSame( $user, reset( $here )['user_id'] ?? null );
		$this->assertSame( array(), $there, 'No record on the other site.' );
		$this->assertNull( $customer, 'The user is no customer of the other site.' );
	}

	/**
	 * Places with an input the cart cannot take, and returns the refusal as the wire would carry it, with the statements it cost in shape.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The placement's input.
	 * @param Actor                $actor Who sends it.
	 * @return array{answer: array<string, mixed>, statements: list<string>} The refusal's code, message, context and details, and the statements' shapes.
	 */
	private function refusal( array $input, Actor $actor ): array {
		$answer = array();
		$log    = $this->captureQueries(
			function () use ( $input, $actor, &$answer ): void {
				try {
					$answer = array( 'answered' => $this->placement->place( $input, $actor ) );
				} catch ( CodedException $refused ) {
					$answer = array(
						'code'    => $refused->errorCode()->value,
						'context' => $refused->context(),
						'details' => $refused->details(),
					);
				}
			}
		);

		return array(
			'answer'     => $answer,
			'statements' => StatementShapes::of( $log ),
		);
	}

	/**
	 * Asserts that the policy refuses an order to an actor without a key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid  The order.
	 * @param Actor  $actor Who asks.
	 * @param string $who   Who asks, for the message.
	 */
	private function assertNotRead( string $uuid, Actor $actor, string $who ): void {
		try {
			$this->policy()->authorize( $uuid, $actor, null );
			$this->fail( "{$who} read the order without a key." );
		} catch ( CodedException $refused ) {
			$this->assertSame( 'order.not_found', $refused->errorCode()->value, $who );
		}
	}

	/**
	 * Returns the customer an order belongs to.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order.
	 * @return int|null The customer record, or null for a guest's order.
	 */
	private function customerOfOrder( string $uuid ): ?int {
		$customer = $this->db->fetchValue( 'SELECT customer_id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $uuid );

		return null === $customer ? null : (int) $customer;
	}

	/**
	 * Returns the production access policy.
	 *
	 * @since 0.2.0
	 *
	 * @return OrderAccessPolicy The policy.
	 */
	private function policy(): OrderAccessPolicy {
		return $this->kernel->get( OrderAccessPolicy::class );
	}
}
