<?php
/**
 * Tests that an address typed at checkout does the same work, and gets the same answers, whether or not it is an account's
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;
use SEOCart\Tests\Support\StatementShapes;

/**
 * A guest who types the address of an account, one with a customer record and orders, and a guest who types a fresh address do the same work and get the same answers, and both orders are a guest's.
 *
 * The enumeration this guards against needs only a difference: an answer, or the work a request
 * does, that changes with whether an address has an account. Two guests' identical carts are
 * written to their checkout with the two addresses and placed, each from a cold cache and a
 * container of its own, as two requests: the statements each sends are equal in count, order and
 * shape (StatementShapes), and the answers are equal once the identifiers each request was given
 * and the address it sent are set aside. Time is not measured; equal statements are the proof.
 *
 * Planted violation: in PlaceOrder::placeInside(), look the session's email up with
 * `get_user_by( 'email', … )` and, when it finds a user, place the order for that user's record.
 * The account's placement then reads the user, and its order has a customer.
 *
 * @since 0.2.0
 */
final class EmailPathsTest extends PlacementTestCase {

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
	 * Tests that a guest's session write and placement with an account's address send what they send with a fresh address, answer the same, and leave a guest's order.
	 *
	 * @since 0.2.0
	 */
	public function test_an_accounts_address_works_as_a_fresh_one(): void {
		$account = $this->createUser( 'subscriber' );
		$user    = get_userdata( $account );

		$this->assertInstanceOf( \WP_User::class, $user );

		// The account has a customer record and an order.
		$this->readyCart( array( $this->sellable() => 1 ) );
		$this->placement->place( $this->placeInput( 'the account\'s own order' ), Actor::user( $account ) );
		$this->assertCount( 1, $this->customerRows(), 'The account has a customer record.' );

		$mug   = $this->sellable( 5 );
		$known = $this->guestPath( $mug, (string) $user->user_email );
		$fresh = $this->guestPath( $mug, 'nobody-' . strtolower( wp_generate_password( 10, false ) ) . '@example.org' );

		$this->printSideBySide( $known['placement_statements'], $fresh['placement_statements'] );

		$this->assertSame( $fresh['session_statements'], $known['session_statements'], 'The session write sends the same statements whatever the address.' );
		$this->assertSame( $fresh['session'], $known['session'], 'The session write answers the same whatever the address.' );
		$this->assertSame( $fresh['placement_statements'], $known['placement_statements'], 'The placement sends the same statements whatever the address.' );
		$this->assertSame( $fresh['answer'], $known['answer'], 'The placement answers the same whatever the address.' );
		$this->assertSame( array( null, null ), array( $known['customer'], $fresh['customer'] ), 'Both orders are a guest\'s.' );
		$this->assertCount( 1, $this->customerRows(), 'No record was made or used for an address.' );
	}

	/**
	 * Starts a guest's cart of one unit, writes its checkout with an address, places it, and returns what each request sent and answered.
	 *
	 * Each request is cold: a container of its own, and no object cache but the options WordPress
	 * loads at boot. The address and the identifiers each request was given are set aside in the
	 * answers.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variant The variant the cart holds one unit of.
	 * @param string $email   The address typed.
	 * @return array{session: array<string, mixed>, session_statements: list<string>, answer: array<string, mixed>, placement_statements: list<string>, customer: int|null} What happened.
	 */
	private function guestPath( int $variant, string $email ): array {
		$cart    = $this->startCart( array( $variant => 1 ) );
		$update  = $this->kernelOver( $this->db, $this->tokens )->get( UpdateCheckoutSession::class );
		$session = array();

		wp_cache_flush();
		wp_load_alloptions();

		$written = $this->captureQueries(
			function () use ( $update, $cart, $email, &$session ): void {
				$session = $update->update(
					array(
						'cart_version'       => $cart->version,
						'billing_address'    => self::wireAddress( Carrier::BILLING, 'US', $email ),
						'shipping_address'   => self::wireAddress( Carrier::SHIPPING, 'US' ),
						'payment_method_key' => StubGateway::ID,
					),
					self::guest()
				);
			}
		);

		$input     = $this->placeInput( 'a guest\'s attempt' );
		$placement = $this->kernelOver( $this->db, $this->tokens )->get( PlaceOrder::class );
		$answer    = array();

		wp_cache_flush();
		wp_load_alloptions();

		$placed   = $this->captureQueries(
			function () use ( $placement, $input, &$answer ): void {
				$answer = $placement->place( $input, self::guest() );
			}
		);
		$customer = $this->db->fetchValue( 'SELECT customer_id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );

		return array(
			'session'              => self::setAside( $session, $email ),
			'session_statements'   => StatementShapes::of( $written ),
			'answer'               => self::setAside( $answer, $email ),
			'placement_statements' => StatementShapes::of( $placed ),
			'customer'             => null === $customer ? null : (int) $customer,
		);
	}

	/**
	 * Returns an answer with what differs by request set aside: the address sent, the order's identifiers and key, every uuid, and every time.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $answer The answer.
	 * @param string       $email  The address the request sent.
	 * @return array<mixed> The answer, those values replaced by what they are.
	 */
	private static function setAside( array $answer, string $email ): array {
		foreach ( $answer as $field => $value ) {
			if ( is_array( $value ) ) {
				$answer[ $field ] = self::setAside( $value, $email );
			} elseif ( in_array( $field, array( 'order_number', 'order_key' ), true ) ) {
				$answer[ $field ] = '{' . $field . '}';
			} elseif ( is_string( $field ) && str_ends_with( $field, '_at' ) ) {
				$answer[ $field ] = '{time}';
			} elseif ( $email === $value ) {
				$answer[ $field ] = '{email}';
			} elseif ( is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ) {
				$answer[ $field ] = '{uuid}';
			}
		}

		return $answer;
	}

	/**
	 * Prints two statement logs side by side.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $known The statements of the account's address.
	 * @param string[] $fresh The statements of the fresh address.
	 *
	 * @phpstan-param list<string> $known
	 * @phpstan-param list<string> $fresh
	 */
	private function printSideBySide( array $known, array $fresh ): void {
		$lines = array( sprintf( "\nA guest's placement, with an account's address and with a fresh one: %d and %d statements.", count( $known ), count( $fresh ) ) );

		foreach ( $known as $nth => $statement ) {
			$lines[] = sprintf( '  %2d %s %s', $nth + 1, ( $fresh[ $nth ] ?? '' ) === $statement ? '=' : '!', substr( $statement, 0, 120 ) );
		}

		fwrite( STDOUT, implode( "\n", $lines ) . "\n" );
	}
}
