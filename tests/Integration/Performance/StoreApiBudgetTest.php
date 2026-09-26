<?php
/**
 * Tests the query cost of a Store API write's request policy, with each rate-limiter adapter
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Application\CartTokens;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\RateLimiter\Migrations\CreateRateCountersMigration;
use SEOCart\Platform\RateLimiter\ObjectCacheRateLimiter;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Support\Clock;
use SEOCart\Tests\Fixtures\Operations\FixtureCartOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureCartService;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\ServesRequests;
use Spy_REST_Server;

/**
 * A Store API write that passes its policy costs one rate-limiter statement with the counter
 * table, the INSERT that also returns the count, and none with the object cache. The request is
 * served through the production wiring, with the fixture cart's write, as a guest with no cart
 * token.
 *
 * Planted violation: in TableRateLimiter::hit(), return `$this->peek( $bucket, $identity, $window )`
 * instead of the insert id. The table adapter then costs two statements.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class StoreApiBudgetTest extends DatabaseTestCase {

	use ServesRequests;

	/**
	 * Creates the counter table and saves the request globals.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->saveRequestGlobals();

		( new CreateRateCountersMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Restores the request globals and discards the server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->restoreRequestGlobals();

		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests the counter table's cost: one statement.
	 *
	 * @since 0.1.0
	 */
	public function test_a_passing_write_costs_one_statement_on_the_counter_table(): void {
		$this->assertCounterStatements( 1, fn( Container $c ): RateLimiter => new TableRateLimiter( $c->get( Database::class ) ) );
	}

	/**
	 * Tests the object cache's cost: no statement.
	 *
	 * @since 0.1.0
	 */
	public function test_a_passing_write_costs_no_statement_on_the_object_cache(): void {
		$this->assertCounterStatements( 0, fn( Container $c ): RateLimiter => new ObjectCacheRateLimiter( $c->get( Clock::class ), static fn(): RateLimiter => new TableRateLimiter( $c->get( Database::class ) ) ) );
	}

	/**
	 * Serves a passing write with a rate limiter and asserts how many statements reached the counter table.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $expected The statements the counter table may receive.
	 * @param \Closure $limiter  Builds the rate limiter from the container.
	 *
	 * @phpstan-param \Closure(Container): RateLimiter $limiter
	 */
	private function assertCounterStatements( int $expected, \Closure $limiter ): void {
		$registry = new OperationRegistry();

		FixtureCartOperation::register( $registry );

		$container = KernelContainer::build(
			$this->db,
			$this->reporter(),
			array(
				OperationRegistry::class  => static fn(): OperationRegistry => $registry,
				RateLimiter::class        => $limiter,
				CartTokenTransport::class => static fn( Container $c ): CartTokenTransport => new CartTokenTransport( $c->get( Clock::class ), static function (): void {} ),
				FixtureCartService::class => static fn( Container $c ): FixtureCartService => new FixtureCartService( $c->get( CartTokens::class ) ),
			)
		);

		add_filter( 'wp_rest_server_class', static fn(): string => Spy_REST_Server::class );
		KernelHooks::detach( 'rest_api_init', 'wp_abilities_api_categories_init', 'wp_abilities_api_init' );
		OperationSurfaces::discard();
		Modules::subscribe( $container );

		$server = rest_get_server();

		$this->assertInstanceOf( Spy_REST_Server::class, $server );

		$status = null;
		$log    = $this->captureQueries(
			function () use ( $server, &$status ): void {
				$status = $this->serve( $server, 'POST', '/' . RestBinding::STORE_NAMESPACE . FixtureCartOperation::LINES_ROUTE, array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE ) )['status'];
			}
		);

		$this->assertSame( 200, $status );
		$this->assertQueryCount( $expected, $log->forTable( $this->db->table( RateCountersTable::NAME ) ), 'The rate limiter of a Store API write' );

		fwrite( STDOUT, sprintf( "\nA passing Store API write with %s: %d statement(s) on the counter table, %d in the whole request.\n", 1 === $expected ? 'the counter table' : 'the object cache', $log->forTable( $this->db->table( RateCountersTable::NAME ) )->count(), $log->count() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}
}
