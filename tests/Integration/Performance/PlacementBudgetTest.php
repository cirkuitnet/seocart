<?php
/**
 * Tests the statements and transactions of placing the reference carts, step by step
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Checkout\Domain\AccountsAtCheckout;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Payment\StubWebhooks;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A placement of Cart A, of Cart B, and of Cart B in a presentment currency: every statement it sends, attributed to the step that sent it.
 *
 * The numbers are the baseline of a placement's cost, as measured: the statements before the
 * first transaction, in each of the two, and the gateway's read between them. Each is asserted at
 * most the baseline, so a placement that grows fails here and names the step that grew. The step
 * of a statement is the first placement step its caller passed through; a transaction's own
 * control statements are counted apart. The session is read once, before the transaction; a
 * cart's hold rows are one insert, and so are its allocation rows and the outbox rows of one
 * publish; the hold stores its reservation in the outbox with one insert of the first unit, and
 * nothing the placement sends reads or writes a product's stock summary, whose projector hears the
 * reservation after the response; the allocation reads the hold's rows, item by item, once. The calculation plans where
 * the units are held: it reads the locations, the units each has, and the store's routing mode
 * with the days a transfer adds (one read of their group, which the checkout's rules make with
 * their own, before the calculation), each once, cold; the first unit
 * records the location the order ships from, and the second reads the mode again only when a
 * unit is allocated away from it, warm in the same request.
 *
 * Planted violations: in PlaceOrder::placeInside(), read the session again before storing its
 * quotes: the first unit's count grows by one, and the step is named; and in
 * StockService::allocateInside(), read the items' configuration twice: the second unit's count
 * grows by one, under the allocation; in StockService::allocateInside(), read the hold's rows
 * twice: the second unit's count grows by one, under the allocation; and in PlaceOrder::priced(),
 * price the cart without the session just read: the part before the transaction grows by one,
 * under the calculation; in StockReserved::deliveryMode(), answer AfterCommit: the hold step
 * stores no reservation, and the projector, delivered the reservation in the request, reads and
 * writes stock summaries between the two units; in StockService::holdInside(), publish each held
 * line's reservation on its own: Cart B's hold step sends one outbox insert per line. In
 * PlaceOrder::placeInside(), ask ActorCustomers::customerFor() a second time: a logged-in
 * placement's first unit grows by one, under the customer; and in the kernel's closure of a user's
 * registration time, clean the user's cache before reading it: the customer's step reads the user.
 * The checkout's rules read their settings group in the statement that primes the routing mode's
 * group, which the calculation read cold before: in CheckoutRules::current(), read the `checkout`
 * group in a values() call of its own: the part before the first unit grows by one (Cart A's to 11),
 * under the checkout's rules.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class PlacementBudgetTest extends PlacementTestCase {

	use CreatesUsers;
	use PricesInCurrencies;
	use RoutedPlacements;

	/**
	 * The steps of a placement, each with the caller that marks it, the most specific first.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const STEPS = array(
		'replay: the key, read'            => 'MysqlIdempotencyKeys->replay',
		'declines: the cart\'s count'      => 'PlaceOrder->refuseAfterDeclines',
		'cart: its row and lines'          => 'CartService->current',
		'the checkout\'s rules'            => 'CheckoutRules->current',
		'calculation'                      => 'CartService->calculation',
		'session: its read'                => 'MysqlCheckoutSessions->find',
		'a. the cart\'s claim'             => 'CartService->claimForPlacement',
		'b. the key\'s claim'              => 'MysqlIdempotencyKeys->claim',
		'the customer'                     => 'Customers->customerFor',
		'c. the sale decision'             => 'Sellability->forSale',
		'd. the stock hold'                => 'StockService->hold',
		'e. the order'                     => 'Orders->insert',
		'f. the promotions\' uses'         => 'PromotionUsage->claim',
		'g. the intent'                    => 'PaymentService->createIntent',
		'h. the order bound to the cart'   => 'CartService->bindOrder',
		'h. the quotes kept'               => 'MysqlCheckoutSessions->storeQuotes',
		'i. the key completed'             => 'MysqlIdempotencyKeys->complete',
		'gateway: the intent read'         => 'PaymentService->authorize',
		'2. the gateway\'s answer applied' => 'PaymentService->applyGatewayResult',
		'2. the order settled as paid'     => 'PaymentService->settleNothingDue',
		'2. the order\'s lines read'       => 'Orders->stockLines',
		'2. the allocation'                => 'StockService->allocate',
		'2. the uses committed'            => 'PromotionUsage->commit',
		'2. the cart settled'              => 'CartService->settleOrder',
		'2. the kept answer settled'       => 'MysqlIdempotencyKeys->settleAnswer',
	);

	/**
	 * The most statements each part of a placement of Cart A may send.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A = array(
		'before'  => 10,
		'first'   => 33,
		'between' => 1,
		'second'  => 34,
	);

	/**
	 * The statements of each part of a placement of Cart A by a logged-in customer who has a record: Cart A's, and the customer's step, the link read.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_CUSTOMER = array(
		'before'  => 10,
		'first'   => 34,
		'between' => 1,
		'second'  => 34,
	);

	/**
	 * The statements of each part of a logged-in user's first placement, of Cart A: Cart A's, and the customer's step, the link read and the record created.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_FIRST_ORDER = array(
		'before'  => 10,
		'first'   => 35,
		'between' => 1,
		'second'  => 34,
	);

	/**
	 * The most statements each part of a placement of Cart B may send.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_B = array(
		'before'  => 11,
		'first'   => 46,
		'between' => 1,
		'second'  => 52,
	);

	/**
	 * The most statements each part of a placement of Cart B in a presentment currency may send: its calculation sends one more read before the transaction.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_B_PRESENTMENT = array(
		'before'  => 12,
		'first'   => 46,
		'between' => 1,
		'second'  => 52,
	);

	/**
	 * The most statements each part of a placement of Cart A may send when its codes leave nothing to pay: no intent, and no gateway between the two units.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_NOTHING_DUE = array(
		'before'  => 11,
		'first'   => 35,
		'between' => 0,
		'second'  => 30,
	);

	/**
	 * The statements of each part of a placement of Cart A whose shopper the gateway asks to act: the second unit is the wait, which keeps the next action sealed.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_ACTING = array(
		'before'  => 10,
		'first'   => 33,
		'between' => 1,
		'second'  => 15,
	);

	/**
	 * The statements of each part of a placement of Cart A paid by bank transfer: Cart A's, and the method's settings document, read once on a cold cache before the first unit, which the answer's name and instructions then find cached.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_OFFLINE = array(
		'before'  => 11,
		'first'   => 33,
		'between' => 1,
		'second'  => 34,
	);

	/**
	 * The statements of each part of a placement of Cart B whose plan brings one line to the order's location: that line's units are all at a warehouse that ships no online orders, the other nine lines' at the default location, the order's. One source: one transfer requested, and the order's record of it.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_B_TRANSFER = array(
		'before'  => 11,
		'first'   => 46,
		'between' => 1,
		'second'  => 57,
	);

	/**
	 * The statements of each part of a placement of Cart A whose units are all at a warehouse that ships no online orders, the default location, the order's, holding none: the first unit locks that location's row in share mode, and the second requests one transfer and records it.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_TRANSFER = array(
		'before'  => 10,
		'first'   => 34,
		'between' => 1,
		'second'  => 39,
	);

	/**
	 * The statements of each part of a placement of Cart A whose checkout holds two values of the custom fields, the order note and an extension's field: Cart A's, and the order's one insert of both rows.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_FIELD_VALUES = array(
		'before'  => 10,
		'first'   => 34,
		'between' => 1,
		'second'  => 34,
	);

	/**
	 * The statements a delivered approval costs beyond the second unit of work it runs: the receipt read, the receipt claimed, the intent read, the lookup of the provider's reference the intent does not hold yet, the receipt settled and the line logged, and the savepoint pair of the second unit, which runs in the receiver's transaction.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const WEBHOOK_EXTRA = 8;

	/**
	 * The statement a settlement in a request of its own adds when it allocates a unit away from the order's location: the store's routing mode, read cold, to know whether to request a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const COLD_MODE_READ = 1;

	/**
	 * The most statements a placement of Cart B in a presentment currency may send beyond one in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRESENTMENT_EXTRA = 2;

	/**
	 * The statements of a cold replay of a placement: the read of its key, whose kept answer is the answer, and nothing before it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const REPLAY = 1;

	/**
	 * The statements of a cold placement by a visitor where the store requires an account, refused, by step: the key's read, the cart's, the count of its declines and the checkout's rules, which hold the store's choice; nothing after, and no transaction.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const ACCOUNT_REQUIRED_REFUSED = array(
		'replay: the key, read'       => 1,
		'cart: its row and lines'     => 2,
		'declines: the cart\'s count' => 1,
		'the checkout\'s rules'       => 1,
	);

	/**
	 * The statements of the last placement measured, by part and step.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $lastSteps = array();

	/**
	 * Plants the installation record every store that takes orders has, autoloaded: the placement reads it, for the payment gateways' switches, at no cost.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plantBootRecord();
	}

	/**
	 * Restores the boot record the test planted.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->removeBootRecord();
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that placing Cart A stays within its baseline, in two transactions.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_a_stays_within_its_baseline(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$parts = $this->measure( 'Cart A' );

		$this->assertWithin( self::CART_A, $parts, 'Cart A' );
	}

	/**
	 * Tests that a cold replay of Cart A's placement reads the key alone and answers what the placement answered: nothing is read before the kept answer, the checkout's rules included.
	 *
	 * Planted violation: in PlaceOrder::place(), read the checkout's rules before the kept answer:
	 * the replay reads their settings too.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cold_replay_reads_the_kept_answer_alone(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		// The request measure() places, sent again as it was.
		$input = $this->placeInput( 'budget-Cart A', self::APPROVE );

		$this->measure( 'Cart A' );

		// A request starts cold: a container of its own, and no object cache but the options WordPress loads at boot.
		$replay = $this->kernelOver( $this->db, $this->tokens )->get( PlaceOrder::class );
		$answer = array();

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			function () use ( $replay, $input, &$answer ): void {
				$answer = $replay->place( $input, self::guest() );
			}
		);

		fwrite( STDOUT, sprintf( "\nReplaying Cart A, cold: %d statements.\n  %s\n", $log->count(), implode( "\n  ", $log->sqls() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.

		$this->assertSame( 'approved', $answer['outcome'] ?? null, 'The replay answers what the placement answered.' );
		$this->assertSame( self::REPLAY, $log->count(), 'A cold replay of Cart A\'s placement.' );
	}

	/**
	 * Tests that placing Cart A as a logged-in user costs Cart A's statements and the customer's step alone: the link read and the record created for a first order, the link read once the record exists.
	 *
	 * The user is logged in after the cold flush, as WordPress authenticates a request before the
	 * placement runs, so the user's own row is in the request's cache and costs the placement
	 * nothing. Every other step sends what a guest's placement of Cart A sends.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_as_a_customer_costs_the_customers_step_alone(): void {
		$user    = $this->createUser( 'subscriber' );
		$standIn = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $standIn ) ) );

		$guest      = $this->measure( 'Cart A' );
		$guestSteps = $this->lastSteps;
		$customers  = array(
			'a first order' => array( self::CART_A_FIRST_ORDER, 2 ),
			'a customer'    => array( self::CART_A_CUSTOMER, 1 ),
		);

		foreach ( $customers as $kind => list( $pins, $customerStep ) ) {
			$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

			$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

			$parts = $this->measure( "Cart A, {$kind}", self::APPROVE, 'approved', Actor::user( $user ) );
			$steps = $this->lastSteps;

			$this->assertSame( $customerStep, $steps['first']['the customer'] ?? 0, "Cart A, {$kind}: the customer's step." );
			$this->assertWithin( $pins, $parts, "Cart A, {$kind}" );
			$this->assertSame( array( 0, $customerStep, 0, 0 ), array_map( static fn( int $customer, int $guestPart ): int => $customer - $guestPart, $parts, $guest ), "Cart A, {$kind}: the customer's step, and nothing more than a guest's placement." );

			unset( $steps['first']['the customer'] );

			$this->assertSame( $guestSteps, $steps, "Cart A, {$kind}: every other step sends what a guest's placement sends." );
		}
	}

	/**
	 * Tests that where the store requires an account, a customer's placement of Cart A costs what it costs anywhere: the store's choice is read with the checkout's rules, in the statement the placement sends anyway.
	 *
	 * Planted violation: in CheckoutRules::accounts(), read the choice in a values() call of its
	 * own: the part before the first unit grows by one, under the checkout's rules.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_as_a_customer_where_an_account_is_required_costs_what_it_costs_anywhere(): void {
		$user    = $this->createUser( 'subscriber' );
		$standIn = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		// The user's first order, which creates their record, before the store requires an account.
		$this->readyCart( self::quantities( ReferenceCarts::cartA( $standIn ) ) );
		$this->measure( 'Cart A, a first order', self::APPROVE, 'approved', Actor::user( $user ) );
		$this->chooseAccounts( AccountsAtCheckout::Required );

		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$parts = $this->measure( 'Cart A, a customer, an account required', self::APPROVE, 'approved', Actor::user( $user ) );

		$this->assertSame( self::CART_A_CUSTOMER, $parts, 'Cart A, a customer, an account required' );
		$this->assertSame( 1, $this->lastSteps['before']['the checkout\'s rules'] ?? 0, 'The store\'s choice is read with the checkout\'s rules.' );
	}

	/**
	 * Tests that where the store requires an account, a visitor's placement, cold, is refused once the checkout's rules are read: the steps before them, and nothing written.
	 *
	 * Planted violation: in PlaceOrder::place(), ask the store's choice after the session is read:
	 * the refusal reads the session too.
	 *
	 * @since 0.2.0
	 */
	public function test_a_visitors_placement_where_an_account_is_required_is_refused_at_the_checkouts_rules(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );
		$this->chooseAccounts( AccountsAtCheckout::Required );

		$input     = $this->placeInput( 'budget-refused' );
		$placement = $this->kernelOver( $this->db, $this->tokens )->get( PlaceOrder::class );
		$refusal   = null;

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			function () use ( $placement, $input, &$refusal ): void {
				try {
					$placement->place( $input, self::guest() );
				} catch ( CodedException $refused ) {
					$refusal = $refused->errorCode()->value;
				}
			}
		);

		$steps = array();

		foreach ( $log->entries() as $entry ) {
			$step           = self::stepOf( $entry );
			$steps[ $step ] = ( $steps[ $step ] ?? 0 ) + 1;
		}

		fwrite( STDOUT, sprintf( "\nRefusing a visitor's placement of Cart A where an account is required, cold: %d statements.\n", $log->count() ) . implode( '', array_map( static fn( string $step, int $count ): string => sprintf( "    %-36s %d\n", $step, $count ), array_keys( $steps ), $steps ) ) . '  ' . implode( "\n  ", $log->sqls() ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.

		$this->assertSame( 'checkout.account_required', $refusal );
		$this->assertSame( self::ACCOUNT_REQUIRED_REFUSED, $steps, 'The refusal\'s statements, by step.' );
	}

	/**
	 * Tests that placing Cart A paid by bank transfer costs Cart A's statements and the method's settings, read once, cold.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_paid_by_bank_transfer_costs_one_read_more(): void {
		$standIn = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $standIn ) ) );

		$cartA   = $this->measure( 'Cart A' );
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );
		$cart    = $this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$this->writeCheckout( $cart->version, OfflineMethod::BankTransfer->value );

		$parts = $this->measure( 'Cart A, bank transfer' );

		$this->assertSame( self::CART_A_OFFLINE, $parts, 'Cart A, bank transfer' );
		$this->assertSame( array( 1, 0, 0, 0 ), array_map( static fn( int $offline, int $standIn ): int => $offline - $standIn, $parts, $cartA ), 'The method\'s settings, read once before the first unit, and nothing more.' );
	}

	/**
	 * Tests that placing Cart A with two values of the custom fields costs Cart A's statements and one insert of both rows, under the order; with an extension's fields registered and no value given, Cart A's alone.
	 *
	 * Planted violation: in MysqlOrderRepository::insertFieldValues(), insert each row on its own:
	 * the first unit grows by one more, under the order.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_with_two_field_values_costs_one_insert_more(): void {
		AcmeCheckoutFields::listen();

		try {
			$standIn = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

			$this->readyCart( self::quantities( ReferenceCarts::cartA( $standIn ) ) );

			$cartA      = $this->measure( 'Cart A, an extension\'s fields registered' );
			$cartASteps = $this->lastSteps;
			$variant    = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );
			$cart       = $this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

			$this->writeCheckout(
				$cart->version,
				StubGateway::ID,
				array(
					'order_note'                  => 'Please leave it with the porter.',
					AcmeCheckoutFields::PO_NUMBER => 'PO-1234',
				)
			);

			$parts = $this->measure( 'Cart A, two field values' );
		} finally {
			AcmeCheckoutFields::forget();
		}

		$this->assertSame( self::CART_A, $cartA, 'Cart A, an extension\'s fields registered and no value given: Cart A\'s statements.' );
		$this->assertSame( self::CART_A_FIELD_VALUES, $parts, 'Cart A, two field values' );
		$this->assertSame( ( $cartASteps['first']['e. the order'] ?? 0 ) + 1, $this->lastSteps['first']['e. the order'] ?? 0, 'The order\'s step sends one insert more, whatever the number of values.' );
		$this->assertSame( '2', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $this->table( OrderTables::FIELD_VALUES ), $this->lastOrder() ) );
	}

	/**
	 * Tests that placing Cart A with codes that leave nothing to pay, every line's price and the shipping taken off, stays within its baseline, in two transactions with nothing between them.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_a_with_nothing_due_stays_within_its_baseline(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->plantPromotion(
			'BUDGET-FULL',
			array(
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 100000000,
			)
		);
		$this->plantPromotion(
			'BUDGET-SHIP',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);
		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ), array( 'BUDGET-FULL', 'BUDGET-SHIP' ) );

		$parts = $this->measure( 'Cart A, nothing due' );

		$this->assertWithin( self::CART_A_NOTHING_DUE, $parts, 'Cart A, nothing due' );
	}

	/**
	 * Tests that placing Cart A whose shopper the gateway asks to act stays within its baseline, in two transactions, the second the wait.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_whose_shopper_must_act_stays_within_its_baseline(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$parts = $this->measure( 'Cart A, the shopper to act', StubGateway::REQUIRES_ACTION, 'requires_action' );

		$this->assertWithin( self::CART_A_ACTING, $parts, 'Cart A, the shopper to act' );
	}

	/**
	 * Tests that placing Cart B, with its two promotion codes, stays within its baseline, in two transactions.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_stays_within_its_baseline(): void {
		$this->readyCartB();

		$parts = $this->measure( 'Cart B' );

		$this->assertWithin( self::CART_B, $parts, 'Cart B' );
	}

	/**
	 * Tests that placing Cart B with one line brought from a warehouse stays within its baseline, in two transactions.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_b_with_one_line_brought_from_a_warehouse_stays_within_its_baseline(): void {
		$variants  = $this->readyCartB();
		$warehouse = $this->plantLocation( 5, true, false );

		// The first line's units are all at the warehouse; the other nine lines' at the default location, the order's.
		$this->stockAt( $variants[0], $this->defaultLocation(), 0 );
		$this->stockAt( $variants[0], $warehouse, 10 );
		$this->routeIn( FulfilmentMode::Transfer );

		$parts = $this->measure( 'Cart B, transfer' );

		$this->assertWithin( self::CART_B_TRANSFER, $parts, 'Cart B, transfer' );
		$this->assertSame( array( 'requested:' . $warehouse . '>' . $this->defaultLocation() ), $this->transfersOf( $this->lastOrder() ) );
	}

	/**
	 * Tests that placing Cart A whose units are all at a warehouse stays within its baseline, in two transactions.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_from_a_warehouse_stays_within_its_baseline(): void {
		$variant   = $this->sellable( 0, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );
		$warehouse = $this->plantLocation( 5, true, false );

		// The default location, the order's, holds none of the units: the warehouse has them all.
		$this->stockAt( $variant, $warehouse, 5 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$parts = $this->measure( 'Cart A, transfer' );

		$this->assertWithin( self::CART_A_TRANSFER, $parts, 'Cart A, transfer' );
		$this->assertSame( array( 'requested:' . $warehouse . '>' . $this->defaultLocation() ), $this->transfersOf( $this->lastOrder() ) );
	}

	/**
	 * Returns the newest order's id.
	 *
	 * @since 0.2.0
	 *
	 * @return int The order.
	 */
	private function lastOrder(): int {
		return (int) $this->db->fetchValue( 'SELECT MAX( id ) FROM %i', $this->table( OrderTables::ORDERS ) );
	}

	/**
	 * Tests that settling Cart A and Cart B by a delivered approval, for placements the gateway never answered, costs the second unit of work and WEBHOOK_EXTRA statements, exactly.
	 *
	 * @since 0.2.0
	 */
	public function test_settling_by_webhook_costs_the_second_unit_and_the_receipt(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );
		$this->assertSame( self::CART_A['second'] + self::WEBHOOK_EXTRA, $this->settledByWebhook( 'Cart A' ), 'Cart A, settled by a delivered approval.' );

		$this->readyCartB();
		$this->assertSame( self::CART_B['second'] + self::WEBHOOK_EXTRA, $this->settledByWebhook( 'Cart B' ), 'Cart B, settled by a delivered approval.' );

		// Cart A again, its units all at a warehouse: the settlement requests the transfer, reading the mode cold.
		$away      = $this->sellable( 0, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );
		$warehouse = $this->plantLocation( 5, true, false );

		$this->stockAt( $away, $warehouse, 5 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( self::quantities( ReferenceCarts::cartA( $away ) ) );
		$this->assertSame( self::CART_A_TRANSFER['second'] + self::WEBHOOK_EXTRA + self::COLD_MODE_READ, $this->settledByWebhook( 'Cart A, transfer' ), 'Cart A, transfer, settled by a delivered approval.' );
		$this->assertSame( array( 'requested:' . $warehouse . '>' . $this->defaultLocation() ), $this->transfersOf( $this->lastOrder() ) );
	}

	/**
	 * Places the request's cart with a gateway that does not answer, then settles it by the stand-in's delivered approval, in a request of its own, cold, and returns the delivery's statements, after printing them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The cart's name.
	 * @return int The delivery's statements.
	 */
	private function settledByWebhook( string $name ): int {
		try {
			$this->placement->place( $this->placeInput( 'webhook-' . $name, StubGateway::THROW ), self::guest() );
		} catch ( CodedException $unanswered ) {
			unset( $unanswered );
		}

		$intent   = (array) $this->db->fetchRow( 'SELECT uuid, amount_minor, currency FROM %i ORDER BY id DESC LIMIT 1', $this->table( PaymentTables::INTENTS ) );
		$uuid     = (string) $intent['uuid'];
		$approval = StubWebhooks::of( new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $uuid, Money::of( (int) $intent['amount_minor'], Currency::of( (string) $intent['currency'] ) ), 'stub-ch-' . $uuid, 'stub-pi-approve-' . $uuid ) );
		$receiver = $this->kernelOver( $this->db, $this->tokens )->get( ReceiveWebhook::class );
		$envelope = $approval->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		$results  = array();

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			static function () use ( $receiver, $envelope, &$results ): void {
				$results[] = $receiver->receive( $envelope );
			}
		);

		$this->assertSame( array( ReceiptResult::Applied ), $results, $name );
		$this->assertSame( 'processing', $this->db->fetchValue( 'SELECT o.status FROM %i o JOIN %i i ON i.order_id = o.id WHERE i.uuid = %s', $this->table( OrderTables::ORDERS ), $this->table( PaymentTables::INTENTS ), $uuid ), $name );

		fwrite( STDOUT, sprintf( "\nSettling %s by a delivered approval: %d statements.\n  %s\n", $name, $log->count(), implode( "\n  ", array_map( static fn( string $sql ): string => substr( (string) preg_replace( '/\s+/', ' ', $sql ), 0, 110 ), $log->sqls() ) ) ) );

		return $log->count();
	}

	/**
	 * Tests that placing Cart B in a presentment currency stays within its baseline, and costs at most PRESENTMENT_EXTRA statements more than in the base currency.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_in_a_presentment_currency_costs_at_most_two_statements_more(): void {
		$this->readyCartB();

		$base = array_sum( $this->measure( 'Cart B, USD' ) );

		$this->enableCurrency( 'EUR' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		// Building the placement read the boot record; a request that starts now reads it as planted.
		$this->placement = $this->freshPlacement();

		$this->readyCartB();
		$this->assertSame( 1, $this->db->execute( "UPDATE %i SET currency = 'EUR' WHERE status = 'open'", $this->table( CartTables::CARTS ) ), 'One cart is open.' );

		$parts = $this->measure( 'Cart B, EUR' );

		$this->assertWithin( self::CART_B_PRESENTMENT, $parts, 'Cart B, EUR' );
		$this->assertLessThanOrEqual( $base + self::PRESENTMENT_EXTRA, array_sum( $parts ), 'Placing Cart B in EUR, against placing it in USD.' );
	}

	/**
	 * Places the request's cart, approved unless another token is given, and returns its statements by part, after printing them by step.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $name    The cart's name, for the report.
	 * @param string     $token   Optional. The payment token. Default APPROVE.
	 * @param string     $outcome Optional. What the placement comes to. Default `approved`.
	 * @param Actor|null $actor   Optional. The logged-in user who places. Default null, a visitor.
	 * @return array{before: int, first: int, between: int, second: int} The statements of each part.
	 */
	private function measure( string $name, string $token = self::APPROVE, string $outcome = 'approved', ?Actor $actor = null ): array {
		$input = $this->placeInput( 'budget-' . $name, $token );

		// A request starts cold: a container of its own, and no object cache but the options WordPress loads at boot.
		$placement = $this->kernelOver( $this->db, $this->tokens )->get( PlaceOrder::class );
		$answer    = array();

		wp_cache_flush();
		wp_load_alloptions();

		// WordPress authenticates a logged-in shopper's request before the placement runs: the user is in its cache.
		if ( null !== $actor ) {
			wp_set_current_user( $actor->userId() );
		}

		$log = $this->captureQueries(
			function () use ( $placement, $input, $actor, &$answer ): void {
				$answer = $placement->place( $input, $actor ?? self::guest() );
			}
		);

		wp_set_current_user( 0 );

		$this->assertSame( $outcome, $answer['outcome'] ?? null, $name );
		$this->assertSame( 2, $log->matching( '/^START TRANSACTION$/' )->count(), $name . ': two transactions.' );

		$parts        = array(
			'before'  => 0,
			'first'   => 0,
			'between' => 0,
			'second'  => 0,
		);
		$steps        = array();
		$order        = array( 'before', 'first', 'between', 'second' );
		$part         = 0;
		$outbox       = '/^INSERT INTO `' . preg_quote( $this->table( OutboxTable::NAME ), '/' ) . '`/';
		$hold         = array();
		$reservations = 0;
		$summaries    = array();

		foreach ( $log->entries() as $entry ) {
			// Each unit of work begins by asking for its isolation level, and ends with its COMMIT.
			if ( str_starts_with( $entry['sql'], 'SET TRANSACTION' ) ) {
				++$part;
			}

			$key = $order[ min( $part, 3 ) ];

			++$parts[ $key ];

			$step = self::stepOf( $entry );

			$steps[ $key ][ $step ] = ( $steps[ $key ][ $step ] ?? 0 ) + 1;

			if ( 'd. the stock hold' === $step ) {
				$hold[] = $key . ': ' . substr( (string) preg_replace( '/\s+/', ' ', $entry['sql'] ), 0, 110 );

				if ( 'first' === $key && 1 === preg_match( $outbox, $entry['sql'] ) ) {
					++$reservations;
				}
			}

			if ( str_contains( $entry['sql'], 'stock_summary' ) ) {
				$summaries[] = $key . ': ' . $entry['sql'];
			}

			if ( 'COMMIT' === $entry['sql'] ) {
				++$part;
			}
		}

		$lines = array( sprintf( "\nPlacing %s: %d statements, %d transactions.", $name, $log->count(), 2 ) );

		foreach ( $order as $key ) {
			$lines[] = sprintf( '  %s: %d', $key, $parts[ $key ] );

			foreach ( $steps[ $key ] ?? array() as $step => $count ) {
				$lines[] = sprintf( '    %-36s %d', $step, $count );
			}
		}

		$lines[] = '  the stock hold\'s statements:';

		foreach ( $hold as $statement ) {
			$lines[] = '    ' . $statement;
		}

		fwrite( STDOUT, implode( "\n", $lines ) . "\n" );

		$this->assertSame( 1, $reservations, $name . ': the stock hold stores its reservation with one outbox insert, in the first unit.' );
		$this->assertSame( array(), $summaries, $name . ': no statement of the placement reads or writes a stock summary.' );

		$this->lastSteps = $steps;

		return $parts;
	}

	/**
	 * Asserts that each part of a placement stays within its baseline.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $baseline The most statements of each part.
	 * @param array<string, int> $parts    The statements of each part.
	 * @param string             $name     The cart's name.
	 */
	private function assertWithin( array $baseline, array $parts, string $name ): void {
		foreach ( $baseline as $key => $most ) {
			$this->assertLessThanOrEqual( $most, $parts[ $key ], sprintf( '%1$s: the %2$s part of the placement.', $name, $key ) );
		}
	}

	/**
	 * Returns the step a statement belongs to: a transaction's control, or the first placement step its caller passed through.
	 *
	 * @since 0.1.0
	 *
	 * @param array{sql: string, caller: string} $entry The statement.
	 * @return string The step.
	 */
	private static function stepOf( array $entry ): string {
		if ( 1 === preg_match( '/^(SET TRANSACTION|START TRANSACTION|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT|COMMIT)\b/', $entry['sql'] ) ) {
			return 'transaction control';
		}

		$best = 'other';
		$at   = PHP_INT_MAX;

		foreach ( self::STEPS as $step => $marker ) {
			$position = strpos( $entry['caller'], $marker );

			if ( false !== $position && $position < $at ) {
				$best = $step;
				$at   = $position;
			}
		}

		return $best;
	}
}
