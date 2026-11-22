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

use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\AccountsAtCheckout;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\ClaimsOrders;
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
 * Where the store requires an account, both guests are refused alike, by who they are: the same
 * statements, up to the read of the checkout's rules, and the same refusal.
 *
 * Planted violations: in PlaceOrder::placeInside(), look the session's email up with
 * `get_user_by( 'email', … )` and, when it finds a user, place the order for that user's record.
 * The account's placement then reads the user, and its order has a customer. In
 * PlaceOrder::refuseWithoutAccount(), decide by the session's email instead of the actor, refusing
 * only an address no user has (`get_user_by( 'email', … )`): the account's address is placed and
 * the fresh one refused.
 *
 * A signed-in user's refused claims of an order, whatever failed (the key, the address, the key's
 * time, the order's existence, its being another customer's), are one answer at two statements of
 * one shape each: a claim never tells which test an order failed.
 *
 * @since 0.2.0
 */
final class EmailPathsTest extends PlacementTestCase {

	use ClaimsOrders;
	use CreatesUsers;
	use CustomerRecords;
	use ServesStoreApi;

	/**
	 * Shuts the spy server a test booted, and deletes the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		if ( isset( $this->server ) ) {
			wp_set_current_user( 0 );
			$this->shutStoreApi();
		}

		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a signed-in user's five refused claims are the same answer, in status, bytes and headers, at two statements of one shape each: the user's customer, and the order.
	 *
	 * The refusals: the order placed with the account's address, with another order's key; an order
	 * placed with another address, with its own key; the account's address, with the order's
	 * expired key; a uuid no order has; another customer's order placed with the account's address,
	 * with its key.
	 *
	 * Planted violation: in OrderAccessPolicy::claim(), refuse an actor who has no customer record
	 * before the order is read: each refusal then costs one statement, not two.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signed_in_users_refused_claims_are_one_answer_at_two_statements_each(): void {
		global $wpdb;

		$this->bootStoreApi();

		$user    = $this->createUser( 'subscriber' );
		$account = get_userdata( $user );

		$this->assertInstanceOf( \WP_User::class, $account );

		$email    = (string) $account->user_email;
		$keyed    = $this->placeWithEmail( self::guest(), $email, 'claim-keyed' );
		$wrong    = $this->placeWithEmail( self::guest(), $email, 'claim-wrong' );
		$another  = $this->placeWithEmail( self::guest(), 'someone-else@example.org', 'claim-another' );
		$expired  = $this->placeWithEmail( self::guest(), $email, 'claim-expired' );
		$theirs   = $this->placeWithEmail( Actor::user( $this->createUser( 'subscriber' ) ), $email, 'claim-theirs' );
		$refusals = array(
			'the account\'s address, a wrong key'         => array( $keyed['uuid'], $wrong['key'] ),
			'another address, the order\'s key'           => array( $another['uuid'], $another['key'] ),
			'the account\'s address, the expired key'     => array( $expired['uuid'], $expired['key'] ),
			'a uuid no order has'                         => array( SequentialIdGenerator::nth( 999999 ), $keyed['key'] ),
			'another customer\'s order, the same address' => array( $theirs['uuid'], $theirs['key'] ),
		);
		$plugin   = '/' . preg_quote( $wpdb->prefix . 'seocart_', '/' ) . '/';
		$counter  = '/' . preg_quote( $this->table( 'rate_counters' ), '/' ) . '/';
		$answers  = array();
		$counts   = array();
		$shapes   = array();

		$this->db->execute( 'UPDATE %i SET access_key_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $expired['uuid'] );

		foreach ( $refusals as $refusal => list( $uuid, $key ) ) {
			$answer = array();
			$log    = $this->captureQueries(
				function () use ( $uuid, $key, $user, &$answer ): void {
					$answer = $this->claim( $uuid, $this->logIn( $user ) + self::keyHeader( $key ) );
				}
			);
			$own    = array_values( array_filter( $log->sqls(), static fn( string $sql ): bool => 1 === preg_match( $plugin, $sql ) && 1 !== preg_match( $counter, $sql ) ) );

			$this->assertSame( 404, $answer['status'], ucfirst( $refusal ) . ': ' . (string) wp_json_encode( $answer['body'] ) );
			$this->assertSame( 'order.not_found', $answer['body']['code'] ?? null );

			$correlation         = (string) $answer['body']['data']['correlation_id'];
			$answers[ $refusal ] = array(
				'status'  => $answer['status'],
				'bytes'   => str_replace( $correlation, '', (string) $this->server->sent_body ),
				'headers' => array_map( static fn( string $value ): string => str_replace( $correlation, '', $value ), $answer['headers'] ),
			);
			$counts[ $refusal ]  = count( $own );
			$shapes[ $refusal ]  = array_map( array( StatementShapes::class, 'shape' ), $own );
		}

		$lines = array();

		foreach ( $shapes as $refusal => $statements ) {
			$lines[] = sprintf( '%-46s %d: %s', $refusal, $counts[ $refusal ], implode( ' | ', array_map( static fn( string $shape ): string => substr( $shape, 0, 90 ), $statements ) ) );
		}

		fwrite( STDOUT, "\nA signed-in user's refused claims, the claim's statements in shape:\n  " . implode( "\n  ", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Printed for the report.

		$this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $answers ) ), "The refused claims can be told apart:\n" . (string) wp_json_encode( $answers, JSON_PRETTY_PRINT ) );
		$this->assertSame( array_fill_keys( array_keys( $refusals ), 2 ), $counts, 'Each refused claim reads the user\'s customer and the order.' );
		$this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $shapes ) ), "The refused claims' statements differ in shape:\n" . (string) wp_json_encode( $shapes, JSON_PRETTY_PRINT ) );
		$this->assertSame( array(), array_filter( $this->customerRows(), static fn( array $row ): bool => $user === $row['user_id'] ), 'No refused claim left the claimant a record.' );
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
	 * Tests that where the store requires an account, a guest's placement with an account's address is refused as one with a fresh address: the same statements, the same refusal, and nothing written.
	 *
	 * @since 0.2.0
	 */
	public function test_where_an_account_is_required_an_accounts_address_is_refused_as_a_fresh_one(): void {
		$account = $this->createUser( 'subscriber' );
		$user    = get_userdata( $account );

		$this->assertInstanceOf( \WP_User::class, $user );

		// The account has a customer record and an order, placed before the store required an account.
		$this->readyCart( array( $this->sellable() => 1 ) );
		$this->placement->place( $this->placeInput( 'the account\'s own order' ), Actor::user( $account ) );
		$this->assertCount( 1, $this->customerRows(), 'The account has a customer record.' );

		$this->chooseAccounts( AccountsAtCheckout::Required );

		$mug   = $this->sellable( 5 );
		$known = $this->guestPath( $mug, (string) $user->user_email );
		$fresh = $this->guestPath( $mug, 'nobody-' . strtolower( wp_generate_password( 10, false ) ) . '@example.org' );

		$this->printSideBySide( $known['placement_statements'], $fresh['placement_statements'] );

		$this->assertSame( 'checkout.account_required', $known['answer']['refused'] ?? null, 'The guest with an account\'s address is refused.' );
		$this->assertSame( $fresh['session_statements'], $known['session_statements'], 'The session write sends the same statements whatever the address.' );
		$this->assertSame( $fresh['session'], $known['session'], 'The session write answers the same whatever the address.' );
		$this->assertSame( $fresh['placement_statements'], $known['placement_statements'], 'The refused placement sends the same statements whatever the address.' );
		$this->assertSame( $fresh['answer'], $known['answer'], 'The refusal is the same whatever the address.' );

		foreach ( array( $known, $fresh ) as $path ) {
			$this->assertSame( array(), preg_grep( '/^(INSERT|UPDATE|DELETE|REPLACE)\b/', $path['placement_sql'] ), 'A refused placement writes nothing.' );
			$this->assertCount( 1, preg_grep( '/seocart_checkout_accounts.*seocart_checkout_field_presence|seocart_checkout_field_presence.*seocart_checkout_accounts/s', $path['placement_sql'] ), 'The choice is read once, with the checkout\'s rules.' );
		}

		$this->assertSame( AccountsAtCheckout::Required->value, ( new SettingsStore( Settings::registry(), $this->db ) )->values( CheckoutSettings::settings() )[ CheckoutSettings::ACCOUNTS ], 'The store\'s choice is as it was written.' );
		$this->assertCount( 1, $this->customerRows(), 'No record was made or used for an address.' );
	}

	/**
	 * Starts a guest's cart of one unit, writes its checkout with an address, places it, and returns what each request sent and answered, or the placement's refusal.
	 *
	 * Each request is cold: a container of its own, and no object cache but the options WordPress
	 * loads at boot. The address and the identifiers each request was given are set aside in the
	 * answers.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variant The variant the cart holds one unit of.
	 * @param string $email   The address typed.
	 * @return array{session: array<string, mixed>, session_statements: list<string>, answer: array<string, mixed>, placement_statements: list<string>, placement_sql: list<string>, customer: int|null}
	 *         What happened, the placement's statements as sent besides their shapes; a refused
	 *         placement's answer is its code, message and details.
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
				try {
					$answer = $placement->place( $input, self::guest() );
				} catch ( CodedException $refused ) {
					$answer = array(
						'refused' => $refused->errorCode()->value,
						'message' => $refused->getMessage(),
						'details' => $refused->details(),
					);
				}
			}
		);
		$customer = isset( $answer['order_uuid'] ) ? $this->db->fetchValue( 'SELECT customer_id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] ) : null;

		return array(
			'session'              => self::setAside( $session, $email ),
			'session_statements'   => StatementShapes::of( $written ),
			'answer'               => self::setAside( $answer, $email ),
			'placement_statements' => StatementShapes::of( $placed ),
			'placement_sql'        => $placed->sqls(),
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
