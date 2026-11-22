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

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Customer\Application\Customers;
use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Customer\Infrastructure\MysqlCustomerRepository;
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
 * given to whoever presents the cart's token and the key, as before. A signed-in person's key is
 * that person's: anyone else who sends it, on the cart as it was or as it opened again after a
 * declined payment, is answered as for a key never seen, with its statements.
 *
 * Every user whose requests are compared is read before the first is sent, as WordPress's
 * authentication reads the user a request is made by.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class CustomerPlacementTest extends PlacementTestCase {

	use CreatesUsers;
	use CustomerRecords;

	/**
	 * The fields of a placement's answer that name its order: two placements of the same work differ in these alone.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const IDENTIFIERS = array( 'order_uuid', 'order_number', 'order_key' );

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
	 * the cart's token, as before. The statements of the placer's own replay are held by
	 * test_a_customers_replay_of_their_placement_reads_the_key_and_the_link_alone().
	 *
	 * Two lines hold this test, each enough on its own: another person's request reads under their
	 * own hash, where the placer's key is not, and the key's decider hides a key that names the
	 * placer from anyone else. Planted violation, both parts together: in PlaceOrder::place(), hash
	 * the key without the person (`IdempotencyClaim::keyHash( $token->hash(), $key )` for everyone),
	 * and in PlaceOrder::keptAnswer(), drop the decider (pass null to replay()). Another customer is
	 * then handed the order's number and key. Either part alone leaves this test green: the first
	 * alone is red in
	 * test_another_person_or_a_visitor_with_the_placers_key_at_the_reopened_cart_is_answered_as_for_a_key_never_seen(),
	 * the second alone in test_a_key_under_the_carts_own_hash_that_names_a_user_is_answered_to_that_user_alone()
	 * and in test_a_customers_replay_of_their_placement_reads_the_key_and_the_link_alone().
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

		// WordPress's authentication reads the user a request is made by before the placement runs.
		foreach ( array( $placer, $admin ) as $user ) {
			self::registeredAt( $user );
		}

		$carts = array(
			'approved'        => array( self::APPROVE, 'cart.not_found' ),
			'requires_action' => array( StubGateway::REQUIRES_ACTION, 'cart.not_open' ),
		);

		foreach ( $carts as $outcome => list( $paymentToken, $neverSeen ) ) {
			$this->readyCart( array( $this->sellable() => 1 ) );

			$input  = $this->placeInput( "the placer's attempt, {$outcome}", $paymentToken );
			$placed = $this->placement->place( $input, Actor::user( $placer ) );
			$replay = $this->refusal( $input, Actor::user( $placer ) );
			$again  = (array) ( $replay['answer']['answered'] ?? array() );

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
	 * Tests that a customer's replay of their own placement reads the key and the link, and nothing else: the key under the placer's own hash and the cart's in one statement, then the link, which says whether the placer is still the order's customer.
	 *
	 * The shape of the replay PlacementBudgetTest counts as REPLAY_CUSTOMER, on a converted cart and
	 * on a cart still placing. The placer is read first, as WordPress's authentication reads the
	 * user a request is made by.
	 *
	 * Planted violation: the second part of the two-part plant on
	 * test_a_customers_kept_answer_is_given_back_to_that_customer_alone() alone, in
	 * PlaceOrder::keptAnswer(), drop the decider (pass null to replay()): the replay no longer reads
	 * the link. The first part alone, the hash without the person, turns it red too: the placer's
	 * own hash is then the cart's, and the key is read by the one-hash statement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_customers_replay_of_their_placement_reads_the_key_and_the_link_alone(): void {
		$placer = $this->createUser( 'subscriber' );

		// WordPress's authentication reads the user a request is made by before the placement runs.
		self::registeredAt( $placer );

		$shapes = array(
			StatementShapes::shape( $this->raw( MysqlIdempotencyKeys::FIND_EITHER, $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), IdempotencyClaim::PLACE_ORDER_SCOPE, 'its own hash', 'its cart hash' ) ),
			StatementShapes::shape( $this->raw( MysqlCustomerRepository::LINK_OF, $this->table( CustomerTables::CUSTOMERS ), $placer ) ),
		);

		foreach ( array(
			'approved'        => self::APPROVE,
			'requires_action' => StubGateway::REQUIRES_ACTION,
		) as $outcome => $paymentToken ) {
			$this->readyCart( array( $this->sellable() => 1 ) );

			$input  = $this->placeInput( "the placer's attempt, {$outcome}", $paymentToken );
			$placed = $this->placement->place( $input, Actor::user( $placer ) );
			$replay = $this->refusal( $input, Actor::user( $placer ) );

			$this->assertSame( $outcome, $placed['outcome'] );
			$this->assertSame( $placed['order_uuid'], $replay['answer']['answered']['order_uuid'] ?? null, "{$outcome}: the placer is given their placement back." );
			$this->assertSame( $shapes, $replay['statements'], "{$outcome}: the placer's replay reads the key and the link, and nothing else." );
		}
	}

	/**
	 * Tests that a user given the placer's id, registered at another time, holding the placer's cart and key, is answered as for a key never seen, with its statements: being the placer's user id is not being the placer.
	 *
	 * The placer's registration time is moved, as a new user given a deleted user's id has another.
	 * On the placer's converted cart that user is refused as for a key never seen, places nothing and
	 * asks the gateway nothing; on the placer's carts opened again by a declined payment, the
	 * placer's key places as a key never seen does.
	 *
	 * Planted violation: in Customers::personOf(), answer the user's id alone, without the time they
	 * registered: the user given the placer's id then reads the placer's keys, pays the link's read
	 * on the converted cart, and is refused by the placer's key on the cart opened again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_user_given_the_placers_id_is_answered_as_for_a_key_never_seen(): void {
		$placer = $this->createUser( 'subscriber' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$converted = $this->tokens->presented;
		$input     = $this->placeInput( 'the placer\'s attempt' );
		$placed    = $this->placement->place( $input, Actor::user( $placer ) );
		$reopened  = $this->declinedCarts( Actor::user( $placer ), 'the placer\'s declined attempt' );

		$this->db->execute( 'UPDATE %i SET user_registered = %s WHERE ID = %d', $GLOBALS['wpdb']->users, '2001-02-03 04:05:06', $placer );
		clean_user_cache( $placer );

		// WordPress's authentication reads the user a request is made by before the placement runs.
		self::registeredAt( $placer );

		$b      = $this->secondConnection();
		$orders = $this->committedCount( $b, OrderTables::ORDERS );
		$calls  = $this->gateway->calls;

		$this->tokens->presented = $converted;

		$refused = $this->refusal( $input, Actor::user( $placer ) );
		$unknown = $this->refusal( array( 'idempotency_key' => 'a key never seen' ) + $input, Actor::user( $placer ) );

		$this->assertSame( $unknown['answer'], $refused['answer'], 'The user given the placer\'s id is answered as for a key never seen.' );
		$this->assertSame( $unknown['statements'], $refused['statements'], 'The user given the placer\'s id sends the statements of a key never seen.' );
		$this->assertStringNotContainsString( $placed['order_number'], (string) wp_json_encode( $refused['answer'] ) );
		$this->assertStringNotContainsString( $placed['order_key'], (string) wp_json_encode( $refused['answer'] ) );
		$this->assertSame( array( $orders, $calls ), array( $this->committedCount( $b, OrderTables::ORDERS ), $this->gateway->calls ), 'Nothing is placed again, and the gateway is not asked.' );

		// The user given the placer's id places once, so their own record exists before the requests compared next: that order unlinks the placer's record.
		$this->readyCart( array( $this->sellable() => 1 ) );
		$this->assertSame( 'approved', $this->placement->place( $this->placeInput( 'their first order' ), Actor::user( $placer ) )['outcome'] ?? null );

		$this->tokens->presented = $reopened['x']['token'];

		$sent = $this->refusal( $this->placeInput( (string) $reopened['x']['input']['idempotency_key'] ), Actor::user( $placer ) );

		$this->tokens->presented = $reopened['y']['token'];

		$this->assertPlacedAsNeverSeen( $sent, $this->refusal( $this->placeInput( 'a key never seen at the cart opened again' ), Actor::user( $placer ) ), 'The user given the placer\'s id, with the placer\'s key at the cart opened again' );
	}

	/**
	 * Tests that after the placer's payment is declined and their cart opens again, another customer, an administrator and a visitor holding the cart and the placer's key are answered as for a key never seen: refused by the cart with the placer's body, and placed with a body of the cart opened again; and that the placer's key goes on answering the placer alone.
	 *
	 * Each round sets up two carts alike, the placer's payment declined on both: on one the other
	 * actor sends the placer's key, on the other a key never seen, after the same requests. A
	 * visitor's request is the cart's, so the visitor's order is kept under the cart's own hash, where
	 * the placer's retry reads it after the placer's own.
	 *
	 * Planted violations: in PlaceOrder::place(), hash the key without the person
	 * (`IdempotencyClaim::keyHash( $token->hash(), $key )` for everyone): each is refused
	 * `checkout.idempotency_key_reused` at the cart opened again; in MysqlIdempotencyKeys::replay(),
	 * judge the row of the second hash first: in the visitor's round the placer's retry is refused by
	 * the visitor's key.
	 *
	 * @since 0.2.0
	 */
	public function test_another_person_or_a_visitor_with_the_placers_key_at_the_reopened_cart_is_answered_as_for_a_key_never_seen(): void {
		$placer  = $this->createUser( 'subscriber' );
		$another = $this->createUser( 'subscriber' );
		$admin   = $this->createUser( 'administrator' );
		$others  = array(
			'another customer' => array( Actor::user( $another ), $this->plantCustomer( $another, self::registeredAt( $another ) ) ),
			'an administrator' => array( Actor::user( $admin ), $this->plantCustomer( $admin, self::registeredAt( $admin ) ) ),
			'a visitor'        => array( self::guest(), null ),
		);

		foreach ( $others as $who => list( $actor, $record ) ) {
			$carts = $this->declinedCarts( Actor::user( $placer ), "the placer's key, {$who}" );

			// The placer's request, sent by the other actor: the cart moved on, so it is refused by the cart, as a key never seen is.
			foreach ( $carts as $name => $cart ) {
				$this->tokens->presented = $cart['token'];

				$stale   = $this->refusal( $cart['input'], $actor );
				$unknown = $this->refusal( array( 'idempotency_key' => "a key never seen, {$who}, cart {$name}" ) + $cart['input'], $actor );

				$this->assertSame( 'cart.version_stale', $stale['answer']['code'] ?? null, "{$who}, cart {$name}, the placer's request." );
				$this->assertSame( self::withoutTime( $unknown['answer'] ), self::withoutTime( $stale['answer'] ), "{$who}, cart {$name}: the placer's request is answered as for a key never seen." );
				$this->assertSame( $unknown['statements'], $stale['statements'], "{$who}, cart {$name}: the placer's request sends the statements of a key never seen." );
			}

			$this->tokens->presented = $carts['x']['token'];

			$kept  = $this->keyOfOrder( $carts['x']['order'] );
			$again = $this->placeInput( (string) $carts['x']['input']['idempotency_key'] );
			$sent  = $this->refusal( $again, $actor );

			$this->tokens->presented = $carts['y']['token'];

			$this->assertPlacedAsNeverSeen( $sent, $this->refusal( $this->placeInput( "a key never seen at the cart opened again, {$who}" ), $actor ), "{$who}, with the placer's key at the cart opened again" );

			$this->tokens->presented = $carts['x']['token'];

			$theirs  = (array) $sent['answer']['answered'];
			$replay  = (array) ( $this->refusal( $again, $actor )['answer']['answered'] ?? array() );
			$retry   = $this->refusal( $carts['x']['input'], Actor::user( $placer ) )['answer'];
			$changed = array( 'grand_total_minor' => (int) $carts['x']['input']['grand_total_minor'] + 1 ) + $carts['x']['input'];

			$this->assertSame( $record, $this->customerOfOrder( (string) $theirs['order_uuid'] ), "{$who}: the order belongs to them." );
			$this->assertSame( $kept, $this->keyOfOrder( $carts['x']['order'] ), "{$who}: the placer's key is as it was." );
			$this->assertSame( array_intersect_key( $theirs, array_flip( self::IDENTIFIERS ) ), array_intersect_key( $replay, array_flip( self::IDENTIFIERS ) ), "{$who}: their request again is answered their own order." );
			$this->assertSame( array( $carts['x']['order'], 'declined' ), array( $retry['answered']['order_uuid'] ?? null, $retry['answered']['outcome'] ?? null ), "{$who}: the placer's request again is answered the placer's declined placement, not " . wp_json_encode( $retry ) );
			$this->assertSame( 'checkout.idempotency_key_reused', $this->refusal( $changed, Actor::user( $placer ) )['answer']['code'] ?? null, "{$who}: the placer's key with another total is refused by the key." );
		}
	}

	/**
	 * Tests that the placer, once their record is unlinked, is answered for their own key as for a key never seen: no longer the order's customer, they are not given its answer.
	 *
	 * The answers are compared, not the statements: asking whether the placer is still the order's
	 * customer costs the link's read, on the placer's own key, which tells them nothing they do not
	 * know.
	 *
	 * Planted violation: in PlaceOrder::isKeptFor(), answer the placer's user without asking whether
	 * it is still the order's customer (replace `$customer === $this->customers->customerOf( $actor )`
	 * with `true`): the placer is handed the order's number and key.
	 *
	 * @since 0.2.0
	 */
	public function test_the_placer_after_their_record_is_unlinked_is_answered_as_for_a_key_never_seen(): void {
		$placer = $this->createUser( 'subscriber' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$input  = $this->placeInput( 'the placer\'s attempt' );
		$placed = $this->placement->place( $input, Actor::user( $placer ) );

		$this->assertTrue( $this->kernel->get( Customers::class )->unlink( (int) $this->customerOfOrder( $placed['order_uuid'] ) ) );

		$refused = $this->refusal( $input, Actor::user( $placer ) );
		$unknown = $this->refusal( array( 'idempotency_key' => 'a key never seen' ) + $input, Actor::user( $placer ) );

		$this->assertSame( $unknown['answer'], $refused['answer'], 'The placer whose record was unlinked is answered as for a key never seen.' );
		$this->assertSame( 'cart.not_found', $refused['answer']['code'] ?? null );
		$this->assertStringNotContainsString( $placed['order_number'], (string) wp_json_encode( $refused['answer'] ) );
		$this->assertStringNotContainsString( $placed['order_key'], (string) wp_json_encode( $refused['answer'] ) );
	}

	/**
	 * Tests that a guest's key sent at the cart its declined payment opened again is refused by the key, to the guest and to a user signed in since, who place nothing; and that the guest's own request is answered the guest's declined placement, to both.
	 *
	 * A guest's key is the cart's: a signed-in request reads it under the cart's own hash, after its
	 * own. With test_a_guests_kept_answer_is_given_back_as_before() it holds that read from both
	 * sides: a guest's answer given back, and a guest's key never placed with twice.
	 *
	 * Planted violation: in PlaceOrder::place(), pass no second hash for a person (null in place of
	 * the cart's own hash): the user signed in since places a second order with the guest's key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_guests_key_at_the_reopened_cart_is_refused_to_the_guest_and_to_a_user_signed_in_since(): void {
		$user = $this->createUser( 'subscriber' );

		// WordPress's authentication reads the user a request is made by before the placement runs.
		self::registeredAt( $user );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$input    = $this->placeInput( 'the guest\'s attempt', StubGateway::DECLINE );
		$declined = $this->declined( $input, self::guest() );
		$again    = $this->placeInput( (string) $input['idempotency_key'] );
		$b        = $this->secondConnection();
		$orders   = $this->committedCount( $b, OrderTables::ORDERS );
		$calls    = $this->gateway->calls;

		foreach ( array(
			'the guest'              => self::guest(),
			'a user signed in since' => Actor::user( $user ),
		) as $who => $actor ) {
			$reused = $this->refusal( $again, $actor )['answer'];
			$retry  = $this->refusal( $input, $actor )['answer'];

			$this->assertSame( 'checkout.idempotency_key_reused', $reused['code'] ?? null, "{$who}, with the guest's key at the cart opened again, is refused by the key, not " . wp_json_encode( $reused ) );
			$this->assertSame( array( $declined, 'declined' ), array( $retry['answered']['order_uuid'] ?? null, $retry['answered']['outcome'] ?? null ), "{$who}, with the guest's request, is answered the guest's declined placement." );
		}

		$this->assertSame( array( $orders, $calls ), array( $this->committedCount( $b, OrderTables::ORDERS ), $this->gateway->calls ), 'No order is added, and the gateway is not asked.' );
	}

	/**
	 * Tests that a signed-in request that loses its cart to a guest's request with the same key and body is answered the guest's placement: the same order, number and key, one order, and no call to the gateway of its own.
	 *
	 * The guest's request is made and committed by another runner, a process of its own presenting
	 * the same cart's token, just before the signed-in request claims the cart in its unit of work;
	 * the signed-in request is then refused the cart, and reads the key again, under its own hash
	 * and the cart's.
	 *
	 * Planted violation: in PlaceOrder::answerRefusedClaim(), pass no second hash on to keptAnswer()
	 * (null): the signed-in request is refused by the cart.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signed_in_request_that_loses_the_cart_to_a_guests_request_with_the_same_key_is_answered_the_guests_placement(): void {
		$user = $this->createUser( 'subscriber' );

		// WordPress's authentication reads the user a request is made by before the placement runs.
		self::registeredAt( $user );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$token   = $this->tokens->presented;
		$input   = $this->placeInput( 'the shared attempt' );
		$report  = array();
		$barrier = $this->beforeStatement(
			'/^' . preg_quote( sprintf( "UPDATE `%s` SET version = version + 1, status = 'placing'", $this->table( CartTables::CARTS ) ), '/' ) . '/',
			function () use ( $token, $input, &$report ): void {
				$this->assertNotNull( $token );

				$report = $this->startPlacement( $token, $input )->finish();
			}
		);
		$calls   = $this->gateway->calls;
		$mine    = $this->refusal( $input, Actor::user( $user ) )['answer'];
		$theirs  = (array) json_decode( (string) ( $report['answer_json'] ?? '' ), true );

		$this->assertTrue( $barrier->fired, 'The guest\'s request was not made before the cart was claimed.' );
		$this->assertSame( 'approved', $theirs['outcome'] ?? null, 'The guest\'s request placed the order: ' . wp_json_encode( $report ) );
		$this->assertSame( array_intersect_key( $theirs, array_flip( self::IDENTIFIERS ) ), array_intersect_key( (array) ( $mine['answered'] ?? array() ), array_flip( self::IDENTIFIERS ) ), 'The signed-in request is answered the guest\'s placement, not ' . wp_json_encode( $mine ) );
		$this->assertSame( 1, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ), 'One order.' );
		$this->assertSame( $calls, $this->gateway->calls, 'The signed-in request did not ask the gateway.' );
	}

	/**
	 * Tests that a key kept under the cart's own hash that names a user, as a key claimed before a person's keys were their own keeps it, is answered to that user alone: a visitor, another customer and an administrator are answered as for a key never seen, with its statements, and the user is told the placement is under way.
	 *
	 * A signed-in request reads the cart's own hash after its own, and what it finds there is judged
	 * as its own is, by whom the key is kept for.
	 *
	 * Planted violations: in MysqlIdempotencyKeys::replay(), ask the decider of the first candidate
	 * alone: another customer is told the placement is under way; in PlaceOrder::isKeptFor(), drop
	 * `$actor->userId() !== $user ||`: the visitor is told so.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_under_the_carts_own_hash_that_names_a_user_is_answered_to_that_user_alone(): void {
		$placer  = $this->createUser( 'subscriber' );
		$another = $this->createUser( 'subscriber' );
		$admin   = $this->createUser( 'administrator' );

		$this->plantCustomer( $another, self::registeredAt( $another ) );

		// WordPress's authentication reads the user a request is made by before the placement runs.
		self::registeredAt( $admin );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$token  = $this->tokens->presented;
		$input  = $this->placeInput( 'the placer\'s attempt' );
		$placed = $this->placement->place( $input, Actor::user( $placer ) );
		$order  = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $placed['order_uuid'] );

		$this->assertNotNull( $token );

		// The placer's key as a claim under the cart's own hash keeps it: claimed, its order not written, and the user it is placing for kept.
		$this->db->execute( "UPDATE %i SET key_hash = %s, state = 'claimed', order_id = NULL, response_json = %s WHERE order_id = %d", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), IdempotencyClaim::keyHash( $token->hash(), (string) $input['idempotency_key'] ), PlaceOrder::claimedFor( Actor::user( $placer ) ), $order );

		foreach ( array(
			'a visitor'        => self::guest(),
			'another customer' => Actor::user( $another ),
			'an administrator' => Actor::user( $admin ),
		) as $who => $actor ) {
			$this->assertNeverSeen( $this->refusal( $input, $actor ), $this->refusal( array( 'idempotency_key' => "a key never seen, {$who}" ) + $input, $actor ), $placed, "{$who}, with the placer's key under the cart's own hash" );
		}

		$this->assertSame( 'checkout.placement_in_progress', $this->refusal( $input, Actor::user( $placer ) )['answer']['code'] ?? null, 'The placer is told their placement is under way.' );
	}

	/**
	 * Asserts that a request with another person's key placed an order as a request with a key never seen did: the same answer but for the order it names, and the same statements in shape.
	 *
	 * @since 0.2.0
	 *
	 * @param array{answer: array<string, mixed>, statements: list<string>} $sent    What the request with the key got.
	 * @param array{answer: array<string, mixed>, statements: list<string>} $unknown What the request with a key never seen got.
	 * @param string                                                        $who     Who asked, for the messages.
	 */
	private function assertPlacedAsNeverSeen( array $sent, array $unknown, string $who ): void {
		$this->assertSame( 'approved', $unknown['answer']['answered']['outcome'] ?? null, "{$who}: a key never seen placed, not " . wp_json_encode( $unknown['answer'] ) );
		$this->assertSame( 'approved', $sent['answer']['answered']['outcome'] ?? null, "{$who}: placed, not " . wp_json_encode( $sent['answer'] ) );
		$this->assertSame( self::withoutIdentifiers( $unknown['answer'] ), self::withoutIdentifiers( $sent['answer'] ), "{$who}: answered as a key never seen is, but for the order." );
		$this->assertSame( $unknown['statements'], $sent['statements'], "{$who}: the statements of a key never seen." );
	}

	/**
	 * Returns a placement's answer without the fields that name its order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $answer What refusal() returned as the answer.
	 * @return array<string, mixed> The placement's answer, its order's uuid, number and key left out.
	 */
	private static function withoutIdentifiers( array $answer ): array {
		return array_diff_key( (array) ( $answer['answered'] ?? array() ), array_flip( self::IDENTIFIERS ) );
	}

	/**
	 * Returns a refusal that names the cart's totals without the time they were calculated at, which two requests never share.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $answer What refusal() returned as the answer.
	 * @return array<string, mixed> The answer, its totals' `calculated_at` left out.
	 */
	private static function withoutTime( array $answer ): array {
		unset( $answer['details']['totals']['calculated_at'] );

		return $answer;
	}

	/**
	 * Sets up two carts alike, `x` and `y`, with a placement on each whose payment the gateway declines, so each cart opens again.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor  $actor Who places.
	 * @param string $key   What the keys begin with: each cart's key adds the cart's name.
	 * @return array<string, array{token: \SEOCart\Cart\Domain\CartToken|null, input: array<string, mixed>, order: string}> Each cart's token, the declined request, and its order's uuid.
	 */
	private function declinedCarts( Actor $actor, string $key ): array {
		$carts = array();

		foreach ( array( 'x', 'y' ) as $name ) {
			$this->readyCart( array( $this->sellable() => 1 ) );

			$input          = $this->placeInput( "{$key}, cart {$name}", StubGateway::DECLINE );
			$carts[ $name ] = array(
				'token' => $this->tokens->presented,
				'input' => $input,
				'order' => $this->declined( $input, $actor ),
			);
		}

		return $carts;
	}

	/**
	 * Places a request whose payment the gateway declines, and returns the order the refusal names; the cart opens again.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The request.
	 * @param Actor                $actor Who sends it.
	 * @return string The declined order's uuid.
	 */
	private function declined( array $input, Actor $actor ): string {
		$refused = $this->refusal( $input, $actor )['answer'];

		$this->assertSame( 'checkout.payment_declined', $refused['code'] ?? null, (string) wp_json_encode( $refused ) );

		return (string) ( $refused['details']['order_uuid'] ?? '' );
	}

	/**
	 * Reads the key an order was placed with: its hash, its state, its order, the request's fingerprint, the answer it keeps, and when it expires.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order.
	 * @return array<string, mixed>|null The key's row, or null.
	 */
	private function keyOfOrder( string $uuid ): ?array {
		return $this->db->fetchRow( 'SELECT k.id, k.key_hash, k.state, k.order_id, k.request_fingerprint, k.response_json, k.expires_at FROM %i k JOIN %i o ON o.id = k.order_id WHERE o.uuid = %s', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $this->table( OrderTables::ORDERS ), $uuid );
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
	 * Planted violation: in PlaceOrder::place(), pass no second hash for a person (null in place of
	 * the cart's own hash): the user signed in since reads their own hash alone, and is answered as
	 * for a key never seen.
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
