<?php
/**
 * Tests the query cost of the stock list, the read of the product editor's stock grid
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\QueryLog;

/**
 * A page of the stock list costs the same few statements whatever its length: the stock list,
 * the figure for sale, and the list of locations when the object cache does not hold it.
 *
 * Each page is read with the list of locations out of the object cache, the dearest case: at
 * most BUDGET statements for 50 variants and for 200, the most a request may list, and the
 * 200-row page at most two more than the 50-row page. The store has the default location and a
 * second one, where one variant in five has an item.
 *
 * Planted violation, shown red and removed: in StockService::listStock(), ask Availability for
 * each variant's figure on its own. The 200-row page then costs a statement per variant.
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class StockListBudgetTest extends StockTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The most statements a page of the stock list may cost.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const BUDGET = 4;

	/**
	 * How much more the longest page may cost than a 50-row page.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const GROWTH = 2;

	/**
	 * Deletes the users.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a 50-row and a 200-row page each cost at most the budget, and the longer at most two more.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_costs_the_same_few_statements_whatever_its_length(): void {
		$second   = $this->plantLocation();
		$variants = array();

		for ( $index = 0; $index < LocationOperations::MOST_LISTED_VARIANTS; $index++ ) {
			$variant    = self::variant();
			$variants[] = $variant;

			$this->plantItem( $variant, $index % 7 );

			if ( 0 === $index % 5 ) {
				$this->plantItem( $variant, 1, locationId: $second );
			}
		}

		$actor = $this->userGranted( 'seocart_manage_inventory' );

		// A first read loads the user the capability check asks about; the pages measure the list alone.
		$this->service->listStock( array( 'variant_ids' => (string) $variants[0] ), $actor );

		$short = $this->page( array_slice( $variants, 0, 50 ), $actor );
		$long  = $this->page( $variants, $actor );

		$this->assertQueryCountAtMost( self::BUDGET, $short, 'A 50-row page of the stock list' );
		$this->assertQueryCountAtMost( self::BUDGET, $long, 'A 200-row page of the stock list' );
		$this->assertLessThanOrEqual( count( $short ) + self::GROWTH, count( $long ), 'The 200-row page costs more than two statements over the 50-row page: ' . $long->describe() );

		fwrite( STDOUT, sprintf( "\nThe stock list: %d statement(s) for 50 variants, %d for 200, the list of locations read each time.\n", count( $short ), count( $long ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}

	/**
	 * Reads one page of the stock list with the list of locations out of the cache, and returns its statements.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @param Actor $actor      Who reads.
	 * @return QueryLog The statements the page sent.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	private function page( array $variantIds, Actor $actor ): QueryLog {
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$answer = null;
		$log    = $this->captureQueries(
			function () use ( $variantIds, $actor, &$answer ): void {
				$answer = $this->service->listStock( array( 'variant_ids' => implode( ',', $variantIds ) ), $actor );
			}
		);

		$this->assertCount( count( $variantIds ), $answer['variants'] ?? array(), 'Every variant of the page was listed.' );

		return $log;
	}
}
