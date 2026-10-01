<?php
/**
 * PlacementTestCase: the base of the order placement tests, with every table a placement writes and the production wiring
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Pricing\Infrastructure\Migrations\CreateRateTables;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\RunningProbe;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The base plants catalog, stock and post rows directly and reads them back through a second connection.

/**
 * A CheckoutTestCase with every table an order placement writes, and the placement wired as the kernel wires it.
 *
 * Owns one fact: how a placement test gets a sellable product, a cart ready to be placed, and the
 * production services over a connection of its choosing, from PlacementKernel, whose publisher
 * wakes a RecordingWake that never drains. A product is planted row by row, its post included,
 * sellable, priced in the carts' currency and stocked; every row goes with the tables, and the
 * posts are deleted in tear_down().
 *
 * @since 0.1.0
 */
abstract class PlacementTestCase extends CheckoutTestCase {

	/**
	 * The payment token that approves.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const APPROVE = StubGateway::APPROVE;

	/**
	 * How long a probe may take to boot and reach the statement it waits on, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PROBE_WAIT_MS = 30000;

	/**
	 * The container over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var Container
	 */
	protected Container $kernel;

	/**
	 * The placement over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var PlaceOrder
	 */
	protected PlaceOrder $placement;

	/**
	 * The gateway of the placement over `$this->db`, which records the depth of each call.
	 *
	 * @since 0.1.0
	 *
	 * @var RecordingGateway
	 */
	protected RecordingGateway $gateway;

	/**
	 * The wake of every publisher of the test.
	 *
	 * @since 0.1.0
	 *
	 * @var RecordingWake
	 */
	protected RecordingWake $wake;

	/**
	 * The posts this test planted.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $posts = array();

	/**
	 * The second databases this test opened for its second runners.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $runners = array();

	/**
	 * Creates the tables and the placement.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $operations );
		( new CreateStockTablesMigration() )->up( $operations );
		( new CreateOrderTables() )->up( $operations );
		( new CreatePaymentTables() )->up( $operations );
		( new CreateRefundTables() )->up( $operations );
		( new CreateRateTables() )->up( $operations );

		$this->posts     = array();
		$this->wake      = new RecordingWake();
		$this->kernel    = $this->kernelOver( $this->db, $this->tokens );
		$this->placement = $this->kernel->get( PlaceOrder::class );
		$gateway         = $this->kernel->get( PaymentGateway::class );

		$this->assertInstanceOf( RecordingGateway::class, $gateway );

		$this->gateway = $gateway;
	}

	/**
	 * Closes the second runners and deletes the posts this test planted.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->runners as $runner ) {
			$runner->close();
		}

		$this->runners = array();

		foreach ( $this->posts as $postId ) {
			$wpdb->delete( $wpdb->posts, array( 'ID' => $postId ) );
		}

		parent::tear_down();
	}

	/**
	 * Builds the kernel's container over a connection, with the test's replacements.
	 *
	 * @since 0.1.0
	 *
	 * @param Database       $db     The connection.
	 * @param FakeCartTokens $tokens The cart token the request presents.
	 * @return Container The container.
	 */
	protected function kernelOver( Database $db, FakeCartTokens $tokens ): Container {
		return PlacementKernel::over( $db, $tokens, $this->identities, $this->wake, $this->reporter() );
	}

	/**
	 * Starts a placement in a process of its own, as another request of the client presenting the token.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken            $token          The cart token the request presents.
	 * @param array<string, mixed> $input          The placement's input.
	 * @param int                  $dieAfterCommit Optional. A count of COMMITs after which the
	 *                                             process is killed, right after the commit;
	 *                                             0 for none. Default 0.
	 * @return RunningProbe The running placement; its report names its answer or its refusal.
	 */
	protected function startPlacement( CartToken $token, array $input, int $dieAfterCommit = 0 ): RunningProbe {
		$request = array(
			'token'            => $token->value(),
			'input'            => $input,
			'die_after_commit' => $dieAfterCommit,
		);

		return ChildProcessProbe::start( __DIR__ . '/placement-probe.php', array( 'place', base64_encode( (string) wp_json_encode( $request ) ) ) );
	}

	/**
	 * Starts, in a process of its own, the settlement of an order with the stub gateway's answer for its intent, as a webhook delivers it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $orderUuid The order.
	 * @return RunningProbe The running settlement; its report names its outcome.
	 */
	protected function startSettlement( string $orderUuid ): RunningProbe {
		return ChildProcessProbe::start( __DIR__ . '/placement-probe.php', array( 'settle', $orderUuid ) );
	}

	/**
	 * Returns once the server shows a probe's statement waiting, and fails the test otherwise.
	 *
	 * Like awaitProbeWaiting(), but the statement is known by how it begins: a placement's
	 * statements carry values the test does not know, such as the time a hold expires. Between two
	 * looks it waits on the probe's output, never on a pause; a probe that ends first was never
	 * blocked, and fails the test with what it printed.
	 *
	 * @since 0.1.0
	 *
	 * @param RunningProbe $probe  The probe.
	 * @param string       $prefix How the statement begins, as the server shows it.
	 * @param string       $state  The process-list state of the wait: `updating` for an UPDATE
	 *                             waiting for a row lock.
	 */
	protected function awaitProbeSending( RunningProbe $probe, string $prefix, string $state ): void {
		global $wpdb;

		$observer = $this->secondConnection();
		$query    = $wpdb->remove_placeholder_escape( (string) $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND = %s AND STATE = %s AND INFO LIKE %s', 'Query', $state, $wpdb->esc_like( $prefix ) . '%' ) );
		$deadline = hrtime( true ) + self::PROBE_WAIT_MS * 1000000;

		while ( true ) {
			$waiting = '0' !== $observer->fetchValue( $query );

			if ( $probe->watch( 5 ) ) {
				$this->fail( sprintf( "The probe ended before the server showed it waiting (%s) on: %s\nIts report: %s\nIts output:\n%s", $state, $prefix, $probe->reportSoFar(), $probe->output() ) );
			}

			if ( $waiting ) {
				return;
			}

			if ( hrtime( true ) >= $deadline ) {
				$this->fail( sprintf( 'The server did not show the probe waiting (%1$s) on %2$s within %3$d ms.', $state, $prefix, self::PROBE_WAIT_MS ) );
			}
		}
	}

	/**
	 * Opens a second runner of the plugin's code: the container over a connection of its own, presenting a token of its own. It is closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @param FakeCartTokens $tokens The second runner's token seam.
	 * @return Container The container.
	 */
	protected function secondKernel( FakeCartTokens $tokens ): Container {
		$runner          = new SecondDatabase( $this->reporter() );
		$this->runners[] = $runner;

		return $this->kernelOver( $runner->db(), $tokens );
	}

	/**
	 * Plants a sellable variant, priced in the carts' currency and stocked; of a new product, published, unless it joins an existing one.
	 *
	 * A new product gets its post, published, the product, complete at generation 1, and its
	 * binding in the carts' locale; its id is the id of its first variant.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $onHand     Optional. The units on hand. Default 5.
	 * @param int    $priceMinor Optional. The net price, in minor units. Default 1000.
	 * @param string $title      Optional. The new product's post title. Default `Mug`.
	 * @param int    $product    Optional. The product the variant joins, the id its first variant
	 *                           returned; 0 for a new product. Default 0.
	 * @return int The variant's id.
	 */
	protected function sellable( int $onHand = 5, int $priceMinor = 1000, string $title = 'Mug', int $product = 0 ): int {
		$variant = self::variant();

		if ( 0 === $product ) {
			$product = $variant;

			$this->plantProduct( $product, $title );
		}

		$this->db->execute(
			'INSERT INTO %i ( id, uuid, product_id, sku, combination_hash, generation, is_enabled, created_at, updated_at ) VALUES ( %d, %s, %d, %s, %s, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->table( CatalogTables::VARIANTS ),
			$variant,
			self::uuidOf( $variant, 'b' ),
			$product,
			'SKU-' . $variant,
			hash( 'sha256', 'variant ' . $variant )
		);
		$this->price( array( $variant => $priceMinor ) );
		$this->db->execute(
			'INSERT INTO %i ( variant_id, on_hand, updated_at ) VALUES ( %d, %d, UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::ITEMS ),
			$variant,
			$onHand
		);

		return $variant;
	}

	/**
	 * Plants a product and its post, published and bound in the carts' locale.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $productId The product's id.
	 * @param string $title     The post's title.
	 */
	private function plantProduct( int $productId, string $title ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_title'            => $title,
				'post_name'             => 'placement-' . $productId,
				'post_status'           => 'publish',
				'post_type'             => ProductCapabilities::POST_TYPE,
				'post_date'             => '2026-09-01 00:00:00',
				'post_date_gmt'         => '2026-09-01 00:00:00',
				'post_modified'         => '2026-09-01 00:00:00',
				'post_modified_gmt'     => '2026-09-01 00:00:00',
				'post_content'          => '',
				'post_excerpt'          => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			)
		);

		$postId        = (int) $wpdb->insert_id;
		$this->posts[] = $postId;

		$this->db->execute(
			"INSERT INTO %i ( id, uuid, source_post_id, generation_state, active_variant_generation, variant_count, enabled_variant_count, created_at, updated_at ) VALUES ( %d, %s, %d, 'complete', 1, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )",
			$this->table( CatalogTables::PRODUCTS ),
			$productId,
			self::uuidOf( $productId, 'a' ),
			$postId
		);
		$this->db->execute(
			'INSERT INTO %i ( post_id, product_id, locale, linked_at, created_at ) VALUES ( %d, %d, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$postId,
			$productId,
			self::LOCALE
		);
	}

	/**
	 * Starts a cart with lines of sellable variants and gives it a complete checkout: both addresses, an e-mail address and the stub payment method.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $quantities Units by variant id.
	 * @param string[]        $codes      Optional. Promotion codes to apply first, each planted. Default none.
	 * @return Cart The cart, with its lines, at the version its checkout's write left.
	 *
	 * @phpstan-param list<string> $codes
	 */
	protected function readyCart( array $quantities, array $codes = array() ): Cart {
		$cart = $this->startCart( $quantities );

		foreach ( $codes as $code ) {
			$cart = $this->service->applyPromotionCode( $code, $cart->version, self::guest() );
		}

		$this->checkout->update(
			array(
				'cart_version'       => $cart->version,
				'billing_address'    => array(
					'country'    => 'US',
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'line1'      => '1 Main Street',
					'city'       => 'Austin',
					'postcode'   => '78701',
					'email'      => 'ada@example.com',
				),
				'shipping_address'   => array(
					'country'  => 'US',
					'line1'    => '1 Main Street',
					'city'     => 'Austin',
					'postcode' => '78701',
				),
				'payment_method_key' => StubGateway::ID,
			),
			self::guest()
		);

		$ready = $this->service->current();

		$this->assertNotNull( $ready );

		return $ready;
	}

	/**
	 * Returns a placement's input for the request's cart as it is now: its version, its grand total and currency, a key and a payment token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   Optional. The idempotency key. Default `attempt-1`.
	 * @param string $token Optional. The payment token. Default APPROVE.
	 * @return array<string, mixed> The input.
	 */
	protected function placeInput( string $key = 'attempt-1', string $token = self::APPROVE ): array {
		$carts = $this->kernel->get( CartService::class );
		$cart  = $carts->current();

		$this->assertNotNull( $cart );

		$grand = $carts->calculation( $cart )->totals->summary->grand;

		return array(
			'idempotency_key'   => $key,
			'cart_version'      => $cart->version,
			'grand_total_minor' => $grand->minorUnits(),
			'currency'          => $grand->currency()->code(),
			'payment_data'      => array( 'payment_token' => $token ),
		);
	}

	/**
	 * Reads an order's committed status, payment status and hold, by uuid, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param string           $uuid The order's uuid.
	 * @return array{id: int, status: string, payment_status: string, hold_group: string|null, has_unreconciled_money: int}|null The order, or null.
	 */
	protected function committedOrder( SecondConnection $b, string $uuid ): ?array {
		$row = $b->fetchRow( sprintf( "SELECT id, status, payment_status, hold_group, has_unreconciled_money FROM `%s` WHERE uuid = '%s'", $this->table( OrderTables::ORDERS ), $uuid ) );

		return null === $row ? null : array(
			'id'                     => (int) $row['id'],
			'status'                 => (string) $row['status'],
			'payment_status'         => (string) $row['payment_status'],
			'hold_group'             => null === $row['hold_group'] ? null : (string) $row['hold_group'],
			'has_unreconciled_money' => (int) $row['has_unreconciled_money'],
		);
	}

	/**
	 * Reads an item's committed counters, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param int              $variantId The item.
	 * @return array{0: int, 1: int, 2: int} on_hand, allocated and held.
	 */
	protected function committedStock( SecondConnection $b, int $variantId ): array {
		$row = $b->fetchRow( sprintf( 'SELECT on_hand, allocated, held FROM `%s` WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $variantId ) );

		return null === $row ? array( -1, -1, -1 ) : array( (int) $row['on_hand'], (int) $row['allocated'], (int) $row['held'] );
	}

	/**
	 * Counts committed rows of a table, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     Connection B.
	 * @param string           $table The table's unprefixed name.
	 * @param string           $where Optional. A condition. Default every row.
	 * @return int The count.
	 */
	protected function committedCount( SecondConnection $b, string $table, string $where = '1 = 1' ): int {
		return (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE %s', $this->table( $table ), $where ) );
	}

	/**
	 * Counts committed outbox rows of an event, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $eventName The event's name.
	 * @return int The count.
	 */
	protected function committedEventRows( SecondConnection $b, string $eventName ): int {
		return $this->committedCount( $b, OutboxTable::NAME, sprintf( "event_name = '%s'", $eventName ) );
	}

	/**
	 * Builds a uuid from a number, unique per test run and kind.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $number The number.
	 * @param string $kind   One hexadecimal letter that tells products from variants.
	 * @return string The uuid.
	 */
	private static function uuidOf( int $number, string $kind ): string {
		return sprintf( '0192a4b3-7c5d-7e8f-9a0b-%s%011d', $kind, $number );
	}
}
