<?php
/**
 * CartTestCase: the base of the tests that build and change carts against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Cart;

use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\Migrations\CreateCartTables;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\Migrations\CreateRateCountersMigration;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Platform\RateLimiter\TrustedClientIp;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\NoPromotions;
use SEOCart\Pricing\Infrastructure\Quotes\FixedRateTaxQuoter;
use SEOCart\Pricing\Infrastructure\Quotes\FlatRateShippingQuoter;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Locale;
use SEOCart\Support\Percentage;
use SEOCart\Support\SystemClock;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The base plants cart rows directly and reads them back through a second connection.

/**
 * A DatabaseTestCase with the cart, catalog and rate-counter tables, and the cart service wired as the kernel wires it.
 *
 * Owns one fact: how a cart test gets real tables, a service over them, and a second runner.
 * The tables are created by their own migrations in set_up() and dropped by the base
 * tear_down(), and every test takes fresh variant ids from a counter, so a test passes alone, in
 * any order and any number of times. The service counts new carts with the table rate limiter,
 * for one client at a documentation address, and its token seam is a FakeCartTokens the test
 * presents tokens through. It prices with the real calculator over the catalog's price table and
 * the two stub quoters, as the kernel does; a variant has a price only once a test gives it one,
 * so a line of any other variant is reported unpriced.
 *
 * No test reads a clock: a cart's expiry is set and compared by the database clock alone.
 *
 * The second runner is either connection B sending the repository's own statements, prepared
 * from its public constants (raw()), or a second service over a SecondDatabase, when B must run
 * the plugin's code.
 *
 * @since 0.1.0
 */
abstract class CartTestCase extends DatabaseTestCase {

	/**
	 * The currency every new cart is in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const CURRENCY = 'USD';

	/**
	 * The locale every new cart is started in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const LOCALE = 'en_US';

	/**
	 * A cart's life after a write, in seconds: the guest period of the carts policy, seven days.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected const TTL_SECONDS = 604800;

	/**
	 * The last variant id handed out, across every test of the run.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private static int $lastVariant = 5000;

	/**
	 * The repository over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlCartRepository
	 */
	protected MysqlCartRepository $repository;

	/**
	 * The token seam of the service over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var FakeCartTokens
	 */
	protected FakeCartTokens $tokens;

	/**
	 * The service over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var CartService
	 */
	protected CartService $service;

	/**
	 * Names the client every service of the test serves.
	 *
	 * @since 0.1.0
	 *
	 * @var ClientIdentities
	 */
	protected ClientIdentities $identities;

	/**
	 * The second databases this test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $secondDatabases = array();

	/**
	 * Creates the tables and the service.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateRateCountersMigration() )->up( $operations );
		( new CreateCatalogTables() )->up( $operations );
		( new CreateCartTables() )->up( $operations );

		$this->identities = new ClientIdentities( new TrustedClientIp( array( 'REMOTE_ADDR' => '192.0.2.10' ) ), static fn(): string => 'cart tests' );
		$this->repository = new MysqlCartRepository( $this->db );
		$this->tokens     = new FakeCartTokens();
		$this->service    = $this->serviceOver( $this->db, $this->tokens );
	}

	/**
	 * Closes the second databases.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->secondDatabases as $second ) {
			$second->close();
		}

		$this->secondDatabases = array();

		parent::tear_down();
	}

	/**
	 * Builds a cart service over a connection, as the kernel builds it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database       $db     The connection.
	 * @param FakeCartTokens $tokens The token seam.
	 * @return CartService The service.
	 */
	protected function serviceOver( Database $db, FakeCartTokens $tokens ): CartService {
		return new CartService(
			new MysqlCartRepository( $db ),
			$db,
			$tokens,
			new TableRateLimiter( $db ),
			$this->identities,
			static fn(): Currency => Currency::of( self::CURRENCY ),
			static fn(): Locale => Locale::of( self::LOCALE ),
			self::calculatorOver( $db )
		);
	}

	/**
	 * Builds the calculator over a connection, as the kernel builds it: the catalog's prices, the two stub quoters, no promotions.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 * @return Calculator The calculator.
	 */
	protected static function calculatorOver( Database $db ): Calculator {
		$base = static fn(): Currency => Currency::of( self::CURRENCY );

		return new Calculator(
			new PriceResolver( new MysqlProductRepository( $db, $base ) ),
			new FlatRateShippingQuoter( new SequentialIdGenerator( 1 ), Decimal::of( FlatRateShippingQuoter::RATE ), AmountBasis::Net, FlatRateShippingQuoter::TAX_CLASS ),
			new FixedRateTaxQuoter( Percentage::fromString( FixedRateTaxQuoter::RATE ), FixedRateTaxQuoter::JURISDICTION ),
			new NoPromotions(),
			$db,
			new SystemClock(),
			$base
		);
	}

	/**
	 * Gives variants a net price in the carts' currency, straight into the catalog's price table.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $pricesMinor The price of each variant, in minor units, by variant id.
	 */
	protected function price( array $pricesMinor ): void {
		foreach ( $pricesMinor as $variantId => $priceMinor ) {
			$this->db->execute(
				"INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, 'net', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
				$this->table( 'variant_prices' ),
				$variantId,
				self::CURRENCY,
				$priceMinor
			);
		}
	}

	/**
	 * Opens a second runner of the plugin's code: a service over its own connection, presenting a token of its own. It is closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @param FakeCartTokens $tokens The second runner's token seam.
	 * @return CartService The service.
	 */
	protected function secondService( FakeCartTokens $tokens ): CartService {
		$second                  = new SecondDatabase( $this->reporter() );
		$this->secondDatabases[] = $second;

		return $this->serviceOver( $second->db(), $tokens );
	}

	/**
	 * Asserts that an answer is the empty cart: version 0, no line, and zero totals in the carts' currency.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $answer  The answer, by wire name.
	 * @param string               $message Optional. What is being checked. Default empty.
	 */
	protected function assertEmptyCart( array $answer, string $message = '' ): void {
		$this->assertSame( array( 'version', 'lines', 'totals', 'unpriced_lines' ), array_keys( $answer ), $message );
		$this->assertSame( array( 0, array(), array() ), array( $answer['version'], $answer['lines'], $answer['unpriced_lines'] ), $message );
		$this->assertSame( array( self::CURRENCY, array(), array(), 0, 0 ), array( $answer['totals']['currency'], $answer['totals']['lines'], $answer['totals']['adjustments'], $answer['totals']['summary']['grand_minor'], $answer['totals']['amount_due_minor'] ), $message );
	}

	/**
	 * Returns a variant id no earlier test of the run used.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	protected static function variant(): int {
		return ++self::$lastVariant;
	}

	/**
	 * Returns the actor of a guest's request.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor A visitor who is not logged in.
	 */
	protected static function guest(): Actor {
		return Actor::user( 0 );
	}

	/**
	 * Starts a cart through the service with plain lines, as a client with no token, and presents its token from then on.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $quantities Units by variant id.
	 * @return Cart The new cart, at version 1.
	 */
	protected function startCart( array $quantities ): Cart {
		$this->tokens->presented = null;

		$cart = $this->service->add( self::lines( $quantities ), 0, self::guest() );

		$this->tokens->keepIssued();

		return $cart;
	}

	/**
	 * Builds plain lines.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $quantities Units by variant id.
	 * @return list<CartLine> The lines, in that order.
	 */
	protected static function lines( array $quantities ): array {
		$lines = array();

		foreach ( $quantities as $variantId => $quantity ) {
			$lines[] = CartLine::of( $variantId, $quantity );
		}

		return $lines;
	}

	/**
	 * Writes a cart's lines as `variant:quantity`, in their order.
	 *
	 * @since 0.1.0
	 *
	 * @param Cart $cart The cart.
	 * @return list<string> The lines.
	 */
	protected static function summary( Cart $cart ): array {
		return array_map( static fn( CartLine $line ): string => $line->variantId . ':' . $line->quantity, $cart->lines );
	}

	/**
	 * Reads a cart's row as connection B sees it, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param int              $cartId The cart.
	 * @return array{version: int, status: string, order_id: int|null, line_count: int, item_count: int}|null The row, or null.
	 *
	 * @phpstan-impure
	 */
	protected function committedCart( SecondConnection $b, int $cartId ): ?array {
		$row = $b->fetchRow( sprintf( 'SELECT version, status, order_id, line_count, item_count FROM `%s` WHERE id = %d', $this->table( CartTables::CARTS ), $cartId ) );

		return null === $row ? null : array(
			'version'    => (int) $row['version'],
			'status'     => (string) $row['status'],
			'order_id'   => null === $row['order_id'] ? null : (int) $row['order_id'],
			'line_count' => (int) $row['line_count'],
			'item_count' => (int) $row['item_count'],
		);
	}

	/**
	 * Reads a cart's lines as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param int              $cartId The cart.
	 * @return list<string> Each line as `variant:quantity`, by id.
	 *
	 * @phpstan-impure
	 */
	protected function committedLines( SecondConnection $b, int $cartId ): array {
		$lines = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( variant_id, ':', quantity ) ORDER BY id SEPARATOR ',' ) FROM `%s` WHERE cart_id = %d", $this->table( CartTables::LINES ), $cartId ) );

		return '' === $lines ? array() : explode( ',', $lines );
	}

	/**
	 * Counts the rows of a cart table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table CartTables::CARTS or CartTables::LINES.
	 * @return int The rows.
	 */
	protected function rowsOf( string $table ): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( $table ) );
	}

	/**
	 * Moves a cart's expiry into the past by the database clock, as time passing would.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId  The cart.
	 * @param int $seconds Optional. How long ago it expired. Default 1.
	 */
	protected function expire( int $cartId, int $seconds = 1 ): void {
		$this->db->execute( 'UPDATE %i SET expires_at = UTC_TIMESTAMP() - INTERVAL %d SECOND WHERE id = %d', $this->table( CartTables::CARTS ), $seconds, $cartId );
	}

	/**
	 * Sets a cart's status and order directly, as order placement would have.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $cartId  The cart.
	 * @param CartStatus $status  The status.
	 * @param int        $orderId The order.
	 */
	protected function plantStatus( int $cartId, CartStatus $status, int $orderId ): void {
		$this->db->execute( 'UPDATE %i SET status = %s, order_id = %d WHERE id = %d', $this->table( CartTables::CARTS ), $status->value, $orderId, $cartId );
	}

	/**
	 * Returns a repository statement prepared for connection B, from the repository's own constant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A MysqlCartRepository constant, or what forRows() or forIds() made of one.
	 * @param mixed  ...$values Its values, table names included.
	 * @return string The statement, ready to send.
	 */
	protected function raw( string $statement, mixed ...$values ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the repository's constant; this is its prepare step.
		return (string) $wpdb->prepare( $statement, ...$values );
	}

	/**
	 * Returns a pattern matching exactly the statements a repository constant produces.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A MysqlCartRepository constant, or what forRows() or forIds() made of one.
	 * @return string A regular expression over the whole statement.
	 */
	protected static function shapeOf( string $statement ): string {
		$pattern = (string) preg_replace_callback(
			'/%[dsi]|[^%]+|%/',
			static function ( array $part ): string {
				return match ( $part[0] ) {
					'%d'    => '-?\d+',
					'%s'    => "'[^']*'",
					'%i'    => '`[^`]+`',
					default => preg_quote( $part[0], '/' ),
				};
			},
			$statement
		);

		return '/^' . $pattern . '$/';
	}

	/**
	 * Returns a plugin table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The unprefixed name.
	 * @return string The full name.
	 */
	protected function table( string $name ): string {
		return $this->db->table( $name );
	}
}
