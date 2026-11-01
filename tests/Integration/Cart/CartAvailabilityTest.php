<?php
/**
 * Tests that a cart's answers name the lines whose units are not for sale now, judged against the figure the store's mode sells from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\UnavailableLine;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Inventory\Application\Availability;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\QueryLog;

/**
 * The cart's service built as the kernel builds it, its figures from a real Availability over the
 * test's tables: L1, the store's default location, and L2 are active and ship online orders; L3 is
 * inactive; L4 is active and ships no online order. Every answer judges the lines the calculation
 * priced against the figure of the store's mode: the sum of L1 and L2 under `split` and
 * `transfer`, the most either holds under `refuse`; an untracked variant has no limit, and a
 * variant with no stock policy has nothing for sale. One statement reads the figures, whatever the
 * number of lines, and nothing is written.
 *
 * Planted violations, each shown red and removed:
 *
 * - in LocationDirectory::fulfilling(), count every location, active or not: L3's units are then
 *   counted, and a line of 6 under `split` is silent;
 * - in CartService::unavailableLines(), read the figures once per line: a ten-line cart then sends
 *   ten statements;
 * - in CartService::answered(), write each flagged line's reason to `cart_lines.unavailable_reason`:
 *   a read then writes;
 * - in CartService, keep the figures read for the first answer in a property and reuse them: the
 *   read after the drain is silent;
 * - in StockPolicy::sellsBeyondStock(), let the backorder policy `allow` sell beyond the units: the
 *   backorderable line is no longer flagged.
 *
 * @since 0.2.0
 *
 * @phpstan-import-type CartAnswer from CartService
 */
final class CartAvailabilityTest extends CartTestCase {

	/**
	 * Tests a line of 5 and a line of 6 against 3 units at L1 and 2 at L2, in each mode.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider modes
	 *
	 * @param FulfilmentMode $mode     The store's mode.
	 * @param string[]       $expected The lines flagged, as `name:reason:for_sale`.
	 *
	 * @phpstan-param list<string> $expected
	 */
	public function test_a_line_is_judged_against_the_units_its_mode_sells_from( FulfilmentMode $mode, array $expected ): void {
		$names = $this->stockedAcrossLocations( array( 'five', 'six' ), 3, 2 );

		$this->startCart( array_combine( array_keys( $names ), array( 5, 6 ) ) );

		$this->assertSame( $expected, self::flags( $this->serviceIn( $mode )->getCart( array(), self::guest() ), $names ) );
	}

	/**
	 * Returns each mode and the lines flagged of a line of 5 and a line of 6, against 3 units at L1 and 2 at L2.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: FulfilmentMode, 1: list<string>}> The cases.
	 */
	public static function modes(): array {
		return array(
			'split'    => array( FulfilmentMode::Split, array( 'six:insufficient_stock:5' ) ),
			'transfer' => array( FulfilmentMode::Transfer, array( 'six:insufficient_stock:5' ) ),
			'refuse'   => array( FulfilmentMode::Refuse, array( 'five:insufficient_stock:3', 'six:insufficient_stock:3' ) ),
		);
	}

	/**
	 * Tests each kind of variant in one cart: a row allocated beyond its units counts none, untracked is never flagged, no policy is out of stock, and a line without a price is named only as unpriced.
	 *
	 * @since 0.2.0
	 */
	public function test_each_kind_of_variant_is_judged_once(): void {
		$locations = $this->plantLocations();
		$short     = self::variant();
		$untracked = self::variant();
		$unstocked = self::variant();
		$unpriced  = self::variant();

		$this->price(
			array(
				$short     => 1000,
				$untracked => 1000,
				$unstocked => 1000,
			)
		);
		$this->plantItem( $short, 1, 4 );
		$this->plantItem( $short, 2, 0, 0, true, $locations['l2'] );
		$this->plantItem( $untracked, 0, 0, 0, false );

		$this->startCart(
			array(
				$short     => 3,
				$untracked => CartLine::MAX_QUANTITY,
				$unstocked => 1,
				$unpriced  => 1,
			)
		);

		$answer = $this->serviceIn( FulfilmentMode::Split )->getCart( array(), self::guest() );
		$names  = array(
			$short     => 'short',
			$untracked => 'untracked',
			$unstocked => 'unstocked',
			$unpriced  => 'unpriced',
		);

		$this->assertSame( array( 'short:insufficient_stock:2', 'unstocked:out_of_stock:0' ), self::flags( $answer, $names ), 'L1 counts none of its one unit, four of them allocated; L2 keeps its two.' );
		$this->assertSame( array( array( $unpriced, 'unknown_variant' ) ), array_map( static fn( array $line ): array => array( $line['variant_id'], $line['reason'] ), $answer['unpriced_lines'] ) );
	}

	/**
	 * Tests that a ten-line cart's answer reads the figures of its lines in one statement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_ten_line_cart_reads_its_figures_once(): void {
		$quantities = array();

		for ( $line = 0; $line < 10; $line++ ) {
			$quantities[ self::variant() ] = 2;
		}

		$this->price( array_fill_keys( array_keys( $quantities ), 1000 ) );

		foreach ( array_keys( $quantities ) as $variantId ) {
			$this->plantItem( $variantId, 1 );
		}

		$this->startCart( $quantities );

		$service = $this->serviceIn( FulfilmentMode::Split );
		$answer  = array();
		$log     = $this->captureQueries(
			function () use ( $service, &$answer ): void {
				$answer = $service->getCart( array(), self::guest() );
			}
		);

		$this->assertCount( 10, $answer['unavailable_lines'] );
		$this->assertQueryCount( 1, $log->matching( self::SALE_FIGURES ), 'The reads of the figures of a ten-line cart' );
	}

	/**
	 * Tests that the kernel's figure of 24 and of 96 variants is one statement of stock each, and prints its statements on a cold and a warm object cache.
	 *
	 * @since 0.2.0
	 */
	public function test_the_figure_of_a_batch_of_variants_is_one_statement_of_stock(): void {
		$availability = KernelContainer::build( $this->db, $this->reporter() )->get( Availability::class );
		$report       = array();

		foreach ( array( 24, 96 ) as $count ) {
			$variants = array();

			for ( $index = 0; $index < $count; $index++ ) {
				$variants[] = self::variant();

				$this->plantItem( $variants[ $index ], 1 + $index % 4 );
			}

			wp_cache_flush();
			wp_load_alloptions();

			$cold = $this->captureQueries( static fn(): array => $availability->forSale( $variants, null ) );
			$warm = $this->captureQueries( static fn(): array => $availability->forSale( $variants, null ) );

			$this->assertQueryCount( 1, $cold->matching( self::SALE_FIGURES ), sprintf( 'The figures of %d variants, cold', $count ) );
			$this->assertQueryCount( 1, $warm->matching( self::SALE_FIGURES ), sprintf( 'The figures of %d variants, warm', $count ) );

			$report[] = sprintf( "Availability::forSale() of %d variants: %d statements cold, %d warm.\nCold:\n%s\nWarm:\n%s", $count, $cold->count(), $warm->count(), self::statements( $cold ), self::statements( $warm ) );
		}

		fwrite( STDOUT, "\n" . implode( "\n", $report ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}

	/**
	 * Tests that a line drained by another connection is flagged on the next read, and cleared as units come back, while the reads write nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_drained_line_is_flagged_on_the_next_read_and_cleared_by_a_restock(): void {
		$mug = self::variant();

		$this->price( array( $mug => 1000 ) );
		$this->plantItem( $mug, 2 );

		$cart    = $this->startCart( array( $mug => 2 ) );
		$service = $this->serviceIn( FulfilmentMode::Split );
		$b       = $this->secondConnection();
		$flags   = array();
		$log     = $this->captureQueries(
			function () use ( $service, $b, $mug, &$flags ): void {
				foreach ( array( 2, 0, 1, 5 ) as $onHand ) {
					$b->query( sprintf( 'UPDATE `%s` SET on_hand = %d WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $onHand, $mug, $this->defaultLocation ) );

					$flags[] = self::flags( $service->getCart( array(), self::guest() ), array( $mug => 'mug' ) );
				}
			}
		);

		$this->assertSame( array( array(), array( 'mug:out_of_stock:0' ), array( 'mug:insufficient_stock:1' ), array() ), $flags, 'Two units, drained, one back, five back.' );
		$this->assertSame( $cart->version, $this->service->current()?->version, 'A read moved the cart\'s version on.' );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE unavailable_reason IS NOT NULL', $this->table( CartTables::LINES ) ), 'A read wrote a line\'s reason.' );
		$this->assertQueryCount( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' ), 'The reads\' writes' );
	}

	/**
	 * Tests that a tracked variant whose backorder policy is `allow` is sold from its units only, so its line is flagged, while an untracked one is not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_backorderable_tracked_variant_is_flagged_like_any_tracked_one(): void {
		$none      = self::variant();
		$three     = self::variant();
		$untracked = self::variant();

		$this->price( array_fill_keys( array( $none, $three, $untracked ), 1000 ) );

		foreach ( array( $none, $three ) as $variantId ) {
			$this->plantPolicy( $variantId, true, 'allow' );
		}

		$this->plantItem( $three, 3 );
		$this->plantPolicy( $untracked, false, 'allow' );

		$this->startCart(
			array(
				$none      => 1,
				$three     => 5,
				$untracked => 5,
			)
		);

		$this->assertSame(
			array( 'none:out_of_stock:0', 'three:insufficient_stock:3' ),
			self::flags(
				$this->serviceIn( FulfilmentMode::Split )->getCart( array(), self::guest() ),
				array(
					$none      => 'none',
					$three     => 'three',
					$untracked => 'untracked',
				)
			)
		);
	}

	/**
	 * Builds the cart's service with the figures of a real Availability in a mode, presenting the test's token.
	 *
	 * @since 0.2.0
	 *
	 * @param FulfilmentMode $mode The store's mode.
	 * @return CartService The service.
	 */
	private function serviceIn( FulfilmentMode $mode ): CartService {
		return $this->serviceOver( $this->db, $this->tokens, null, $this->availabilityOver( $this->db, $mode ) );
	}

	/**
	 * Plants L2, L3 and L4 beside the default location, L1.
	 *
	 * @since 0.2.0
	 *
	 * @return array{l2: int, l3: int, l4: int} Their ids.
	 */
	private function plantLocations(): array {
		return array(
			'l2' => $this->plantLocation( 1 ),
			'l3' => $this->plantLocation( 2, false ),
			'l4' => $this->plantLocation( 3, true, false, true ),
		);
	}

	/**
	 * Plants priced variants, each with units at L1 and L2, and 50 at L3 and L4, which sell none.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $names The variants' names.
	 * @param int      $atL1  The units of each at L1.
	 * @param int      $atL2  The units of each at L2.
	 * @return array<int, string> Each name, by its variant's id.
	 *
	 * @phpstan-param list<string> $names
	 */
	private function stockedAcrossLocations( array $names, int $atL1, int $atL2 ): array {
		$locations = $this->plantLocations();
		$variants  = array();

		foreach ( $names as $name ) {
			$variantId              = self::variant();
			$variants[ $variantId ] = $name;

			$this->price( array( $variantId => 1000 ) );
			$this->plantItem( $variantId, $atL1 );
			$this->plantItem( $variantId, $atL2, 0, 0, true, $locations['l2'] );
			$this->plantItem( $variantId, 50, 0, 0, true, $locations['l3'] );
			$this->plantItem( $variantId, 50, 0, 0, true, $locations['l4'] );
		}

		return $variants;
	}

	/**
	 * Writes an answer's flagged lines as `name:reason:for_sale`, in their order, after checking each is the line it names.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $answer The answer, by wire name.
	 * @param array<int, string>   $names  Each variant's name, by id.
	 * @return list<string> The flags.
	 *
	 * @phpstan-param CartAnswer $answer
	 */
	private static function flags( array $answer, array $names ): array {
		$identities = array_column( $answer['lines'], 'line_identity', 'variant_id' );
		$flags      = array();

		foreach ( $answer['unavailable_lines'] as $line ) {
			self::assertSame( $identities[ $line['variant_id'] ] ?? null, $line['line_identity'], 'A flag names another line than its variant\'s.' );
			self::assertContains( $line['reason'], array( UnavailableLine::OUT_OF_STOCK, UnavailableLine::INSUFFICIENT_STOCK ) );

			$flags[] = $names[ $line['variant_id'] ] . ':' . $line['reason'] . ':' . $line['for_sale'];
		}

		return $flags;
	}

	/**
	 * Writes a log's statements, one per line.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The statements.
	 * @return string The statements.
	 */
	private static function statements( QueryLog $log ): string {
		return '  ' . implode( "\n  ", $log->sqls() );
	}
}
