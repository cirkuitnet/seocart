<?php
/**
 * Tests the statements and transactions of a product save
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;
use SEOCart\Tests\Support\QueryLog;

/**
 * What a product save costs: the update of a product without options stays at what it cost before variants had options; a set of variants and its stock cells cost the same few statements whatever their number, but for the stock items each variant is given and the conditional update and event each cell is.
 *
 * A statement is counted when it names one of the plugin's tables, takes or frees the save's
 * named lock, or controls a transaction; WordPress's own reads and its write of the post are
 * printed beside it and not counted. Each save is measured from a cold object cache.
 *
 * Planted violation, shown failing and put back: in MysqlProductRepository::writeSelections(),
 * send one insert per variant; the 24-variant save then costs more than the 6-variant one beyond
 * the stock items, and VARIANT_GROWTH fails.
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class ProductSaveBudgetTest extends ProductRestTestCase {

	use SavesVariantSets;

	/**
	 * The statements of an update of a product without options whose price changes, through SaveProduct: the figure measured before variants had options.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const ONE_VARIANT_UPDATE = 32;

	/**
	 * The statements of the PUT of six variants of two axes, prices in two currencies and twelve stock cells at two locations, its edit-context response included.
	 *
	 * As measured: 37 in the window (3 of them WordPress's post write), 23 in the stock unit, 12 to
	 * read the record back for the response (4 of them WordPress's), 11 to resolve the request
	 * before the window, 7 to mark the product, and 4 more around them: the named lock and its
	 * release, the verdict read and WordPress's revision slug check.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const SIX_VARIANTS_PUT = 94;

	/**
	 * The statements of the first save of a product with six variants, its post created with it, the figure a set's growth is measured from.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const FIRST_SAVE_OF_SIX = 37;

	/**
	 * The statements of each save that reads the rows of the combinations it names, as measured: a value added at generation 2, whose read finds nothing; the former axes restored with their prices and without them; a flat save that brings back the default variant.
	 *
	 * Exact, as nothing else here measures these paths: a statement more, or one fewer, is seen and explained.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const POSSIBLE_RESTORES = array(
		'a value added at generation 2' => 60,
		'a restore with its prices'     => 60,
		'a restore without prices'      => 60,
		'a flat save that brings back'  => 36,
	);

	/**
	 * How many statements a variant costs above the set's own: its stock policy and its stock item, until they are written in one statement each.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const VARIANT_GROWTH = 2;

	/**
	 * How many statements a stock cell costs above the unit's own: its conditional update. The unit's events are one outbox statement.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CELL_GROWTH = 1;

	/**
	 * How many statements more a measure may cost besides what grows with it: one, the check of the slug of the second of two posts with one title, as each of them is measured.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const GROWTH = 1;

	/**
	 * What a counted statement looks like.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const COUNTED = '/seocart_|^(SET TRANSACTION|START TRANSACTION|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK|COMMIT)\b|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK/';

	/**
	 * What the read of the rows of the combinations a save names looks like.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ROWS_OF_COMBINATIONS = '/v\.combination_hash IN \(/';

	/**
	 * Tests that the update of a product without options, its price changed, costs what it cost before.
	 *
	 * @since 0.2.0
	 */
	public function test_the_update_of_a_product_without_options_costs_what_it_did(): void {
		$saved = $this->savedProduct();
		$log   = $this->measured( fn() => $this->save( $saved->postId, array(), array( 'price_minor' => 2499 ) ) );

		$this->report( 'The update of a product without options, its price changed', $log );
		$this->assertQueryCountAtMost( self::ONE_VARIANT_UPDATE, $log->matching( self::COUNTED ), 'The one-variant update' );
	}

	/**
	 * Tests the done-when's PUT: six variants, prices in two currencies, twelve stock cells at two locations, in three transactions, no outbound call inside one.
	 *
	 * @since 0.2.0
	 */
	public function test_the_put_of_six_variants_with_stock_at_two_locations(): void {
		$post      = $this->emptyProduct();
		$locations = $this->twoLocations();
		$outbound  = $this->outboundInsideTransactions();
		$log       = $this->measured( fn() => $this->put( $post, VariantSets::options(), VariantSets::withStock( VariantSets::variants( 'BUDGET' ), $locations ) ) );

		$this->report( 'The PUT of six variants, two currencies, twelve stock cells', $log );
		$this->assertSame( 3, $log->matching( '/^START TRANSACTION$/' )->count(), 'Three transactions: the mark, the window, the stock unit.' );
		$this->assertSame( array(), $outbound(), 'No outbound call inside a transaction.' );
		$this->assertQueryCountAtMost( self::SIX_VARIANTS_PUT, $log->matching( self::COUNTED ), 'The six-variant PUT' );
	}

	/**
	 * Tests that a restore or a possible one reads the rows of the combinations it names, once, and that nothing else does.
	 *
	 * Nothing tells a restore from a save that could be one until the rows are read: a product past
	 * its first generation given a combination outside the one it publishes pays the read, and finds
	 * nothing when the combination is new.
	 *
	 * Planted violations, each failing here: in Product::possiblySuperseded(), leave out the bound on
	 * the generation; the six-variant PUT and a value added at generation 1 send the read. In
	 * SaveProduct::findSuperseded(), send no read; the restores do not. In
	 * MysqlProductRepository::variantsOfCombinations(), send one statement more; POSSIBLE_RESTORES fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_restore_or_a_possible_one_reads_the_rows_and_nothing_else_does(): void {
		$locations = $this->twoLocations();
		$sizes     = array( '250g', '1kg', '2kg' );
		$roasts    = array( 'dark' );
		$dark      = VariantSets::options( VariantSets::SIZES, VariantSets::GRINDS, $roasts );
		$roasted   = VariantSets::variants( 'ROAST', VariantSets::SIZES, VariantSets::GRINDS, $roasts );
		$more      = array( '250g', '1kg', '5kg' );
		$saved     = null;
		$none      = array();
		$one       = array();
		$empty     = $this->emptyProduct();
		$flat      = $this->savedProduct();
		$gross     = $this->savedOnGrossPrices( 'BUDGET-G' );

		$none['the first save'] = $this->measured(
			function () use ( &$saved ): void {
				$saved = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'FIRST' ) );
			}
		);

		$postId = (int) $saved?->postId;

		$none['the six-variant PUT']           = $this->measured( fn() => $this->put( $empty, VariantSets::options(), VariantSets::withStock( VariantSets::variants( 'BUDGET' ), $locations ) ) );
		$none['the one-variant update']        = $this->measured( fn() => $this->save( $flat->postId, array(), array( 'price_minor' => 2499 ) ) );
		$none['cells at generation 1']         = $this->measured( fn() => $this->put( $postId, VariantSets::options(), VariantSets::withStock( VariantSets::variants( 'FIRST' ), $locations ) ) );
		$none['a value added at generation 1'] = $this->measured( fn() => $this->saveSet( $postId, VariantSets::options( $sizes ), VariantSets::variants( 'FIRST', $sizes ) ) );
		$none['an axis added at generation 1'] = $this->measured( fn() => $this->saveSet( $postId, $dark, $roasted ) );
		$none['the same set at generation 2']  = $this->measured( fn() => $this->saveSet( $postId, $dark, $roasted ) );
		$one['a value added at generation 2']  = $this->measured( fn() => $this->saveSet( $postId, VariantSets::options( $more, VariantSets::GRINDS, $roasts ), VariantSets::variants( 'ROAST', $more, VariantSets::GRINDS, $roasts ) ) );
		$one['a restore with its prices']      = $this->measured( fn() => $this->saveSet( $postId, VariantSets::options( $sizes ), VariantSets::variants( 'FIRST', $sizes ) ) );
		$none['the same set at generation 3']  = $this->measured( fn() => $this->saveSet( $postId, VariantSets::options( $sizes ), VariantSets::variants( 'FIRST', $sizes ) ) );

		$this->saveSet( $postId, VariantSets::options( $more, VariantSets::GRINDS, $roasts ), VariantSets::variants( 'ROAST', $more, VariantSets::GRINDS, $roasts ) );

		$one['a restore without prices'] = $this->measured( fn() => $this->saveSet( $postId, VariantSets::options( $sizes ), VariantSets::withoutPrices( VariantSets::variants( 'FIRST', $sizes ) ) ) );

		$this->saveSet( $gross->postId, VariantSets::options(), VariantSets::variants( 'BUDGET-X' ) );
		$this->saveSet( $gross->postId, array(), array() );

		$one['a flat save that brings back'] = $this->measured( fn() => $this->save( $gross->postId, array(), array( 'sku' => 'BUDGET-G2' ) ) );
		$none['a flat save at generation 3'] = $this->measured( fn() => $this->save( $gross->postId, array(), array( 'weight_grams' => 8 ) ) );

		$this->report( 'A value added at generation 2, a possible restore that finds nothing', $one['a value added at generation 2'] );
		$this->report( 'The former axes restored with their prices', $one['a restore with its prices'] );
		$this->report( 'The former axes restored without prices', $one['a restore without prices'] );
		$this->report( 'A flat save that brings back the default variant', $one['a flat save that brings back'] );

		foreach ( $none as $name => $log ) {
			$this->assertSame( 0, $log->matching( self::ROWS_OF_COMBINATIONS )->count(), $name . ': ' . $log->describe() );
		}

		foreach ( $one as $name => $log ) {
			$this->assertSame( 1, $log->matching( self::ROWS_OF_COMBINATIONS )->count(), $name . ': ' . $log->describe() );
		}

		$this->assertSame( self::POSSIBLE_RESTORES, array_map( static fn( QueryLog $log ): int => $log->matching( self::COUNTED )->count(), $one ), 'The statements of a restore or a possible one.' );
		$this->assertGreaterThan( 0, $one['a value added at generation 2']->matching( '/^INSERT INTO `[^`]+seocart_variants` \( uuid,/' )->count(), 'The read found nothing: the new combinations are inserted.' );
		$this->assertLessThanOrEqual( $one['a restore with its prices']->matching( self::COUNTED )->count(), $one['a restore without prices']->matching( self::COUNTED )->count(), 'Leaving the prices out costs no more than giving them.' );
	}

	/**
	 * Tests that a set of 24 variants costs no more than one of 6 but for the stock items of the 18 more.
	 *
	 * @since 0.2.0
	 */
	public function test_a_set_grows_by_its_stock_items_alone(): void {
		$six        = $this->measured( fn() => $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'SIX' ) ) );
		$sizes      = array( 's1', 's2', 's3', 's4' );
		$grinds     = array( 'g1', 'g2', 'g3', 'g4', 'g5', 'g6' );
		$twentyFour = $this->measured( fn() => $this->saveSet( null, VariantSets::options( $sizes, $grinds ), VariantSets::variants( 'TWENTYFOUR', $sizes, $grinds ) ) );

		$this->report( 'The first save of 6 variants', $six );
		$this->report( 'The first save of 24 variants', $twentyFour );
		$this->assertQueryCountAtMost( self::FIRST_SAVE_OF_SIX, $six->matching( self::COUNTED ), 'The first save of six variants' );
		$this->assertLessThanOrEqual( $six->matching( self::COUNTED )->count() + self::VARIANT_GROWTH * 18 + self::GROWTH, $twentyFour->matching( self::COUNTED )->count(), 'VARIANT_GROWTH: ' . $twentyFour->describe() );
	}

	/**
	 * Tests that 48 stock cells cost no more than 12 but for the conditional update and the event of the 36 more.
	 *
	 * @since 0.2.0
	 */
	public function test_cells_grow_by_their_update_and_event_alone(): void {
		$sizes     = array( 's1', 's2', 's3', 's4' );
		$grinds    = array( 'g1', 'g2', 'g3', 'g4', 'g5', 'g6' );
		$locations = $this->twoLocations();
		$first     = $this->saveSet( null, VariantSets::options( $sizes, $grinds ), VariantSets::variants( 'CELLS-A', $sizes, $grinds ) );
		$second    = $this->saveSet( null, VariantSets::options( $sizes, $grinds ), VariantSets::variants( 'CELLS-B', $sizes, $grinds ) );
		$some      = VariantSets::variants( 'CELLS-A', $sizes, $grinds );

		foreach ( array_slice( VariantSets::withStock( $some, $locations ), 0, 6 ) as $index => $variant ) {
			$some[ $index ] = $variant;
		}

		$twelve     = $this->measured( fn() => $this->put( $first->postId, VariantSets::options( $sizes, $grinds ), $some ) );
		$fortyEight = $this->measured( fn() => $this->put( $second->postId, VariantSets::options( $sizes, $grinds ), VariantSets::withStock( VariantSets::variants( 'CELLS-B', $sizes, $grinds ), $locations ) ) );

		$this->report( 'The PUT of 24 variants with 12 cells', $twelve );
		$this->report( 'The PUT of 24 variants with 48 cells', $fortyEight );
		$this->assertLessThanOrEqual( $twelve->matching( self::COUNTED )->count() + self::CELL_GROWTH * 36 + self::GROWTH, $fortyEight->matching( self::COUNTED )->count(), 'CELL_GROWTH: ' . $fortyEight->describe() );
	}

	/**
	 * Tests that the edit-context read of a product with 50 variants costs at most one statement more than one with 6.
	 *
	 * @since 0.2.0
	 */
	public function test_the_edit_read_is_flat_in_the_variants(): void {
		$this->twoLocations();

		$six   = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'READ-SIX' ) );
		$sizes = array( 's1', 's2', 's3', 's4', 's5' );
		$fifty = $this->saveSet( null, VariantSets::options( $sizes, array( 'g0', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9' ) ), VariantSets::variants( 'READ-FIFTY', $sizes, array( 'g0', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9' ) ) );
		$small = $this->measured( fn() => $this->request( 'GET', '/' . $six->postId, array(), array( 'context' => 'edit' ) ) );
		$large = $this->measured( fn() => $this->request( 'GET', '/' . $fifty->postId, array(), array( 'context' => 'edit' ) ) );

		$this->report( 'The edit read of 6 variants', $small );
		$this->report( 'The edit read of 50 variants', $large );
		$this->assertLessThanOrEqual( $small->matching( self::COUNTED )->count() + self::GROWTH, $large->matching( self::COUNTED )->count(), 'EDIT_READ: ' . $large->describe() );
	}

	/**
	 * Saves a published product post with no commerce data, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @return int The post.
	 */
	private function emptyProduct(): int {
		return $this->save(
			null,
			array(
				'post_title'  => 'Budget coffee',
				'post_status' => 'publish',
			)
		)->postId;
	}

	/**
	 * Sends a PUT of options and variants through the REST server, and fails the test unless it is answered 200.
	 *
	 * @since 0.2.0
	 *
	 * @param int                        $postId   The post.
	 * @param list<array<string, mixed>> $options  The options.
	 * @param list<array<string, mixed>> $variants The variants.
	 */
	private function put( int $postId, array $options, array $variants ): void {
		$response = $this->request(
			'PUT',
			'/' . $postId,
			array(
				ProductCommerceSchema::PROPERTY => array(
					'options'  => $options,
					'variants' => $variants,
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Returns the UUIDs of the default location and a second one, created.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The two UUIDs.
	 */
	private function twoLocations(): array {
		$this->db->execute(
			'INSERT INTO %i ( uuid, name, active, fulfils_online, offers_pickup, priority, created_at, updated_at ) VALUES ( %s, %s, 1, 1, 0, 5, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			$this->db->table( 'locations' ),
			wp_generate_uuid4(),
			'Second warehouse'
		);

		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT uuid FROM %i ORDER BY id', $this->db->table( 'locations' ) ), 'uuid' ) );
	}

	/**
	 * Records every outbound HTTP request made while a transaction is open, and returns what reads them.
	 *
	 * @since 0.2.0
	 *
	 * @return \Closure(): list<string> Returns the URLs requested inside a transaction.
	 */
	private function outboundInsideTransactions(): \Closure {
		$inside = array();

		add_filter(
			'pre_http_request',
			function ( mixed $response, mixed $args, mixed $url ) use ( &$inside ): mixed {
				if ( $this->db->depth() > 0 ) {
					$inside[] = (string) $url;
				}

				return $response;
			},
			10,
			3
		);

		return static function () use ( &$inside ): array {
			return $inside;
		};
	}

	/**
	 * Runs a save from a cold object cache and returns every statement it sent.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $save The save.
	 * @return QueryLog The statements.
	 */
	private function measured( callable $save ): QueryLog {
		wp_cache_flush();
		wp_load_alloptions();

		return $this->captureQueries( $save );
	}

	/**
	 * Prints a save's statements, counted and not, each part between the transactions apart, for the report.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name What was saved.
	 * @param QueryLog $log  The statements.
	 */
	private function report( string $name, QueryLog $log ): void {
		$lines = array( sprintf( "\n%s: %d counted statements, %d in all.", $name, $log->matching( self::COUNTED )->count(), $log->count() ) );

		foreach ( $log->sqls() as $index => $sql ) {
			$mark    = 1 === preg_match( self::COUNTED, $sql ) ? '*' : ' ';
			$lines[] = sprintf( '  %3d %s %s', $index + 1, $mark, substr( $sql, 0, 200 ) );
		}

		fwrite( STDOUT, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}
}
