<?php
/**
 * Tests a translation group's reconciliation racing a link of the same two products
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\Doubles\SeveralLocales;

/**
 * A reconciliation of a translation group and a link of one of its posts, each on its own connection, lock the same two products in one order and end in the one state either would reach alone.
 *
 * A German post has an `incomplete` product of its own, which holds nothing and has the lower id;
 * a product with commerce data, the higher id, is saved through its source post. The multilingual
 * setup puts the German post in the source post's group, and this process reconciles the source
 * post's group, as the lifecycle does when the setup tells it: the German post is to join the
 * product with commerce data, and its own product gives way. Just as the reconciliation locks one
 * of the two products, a probe process starts linking the German post to the product with
 * commerce data, which locks the same two, the lower first.
 *
 * The reconciliation locks every product it may change before its first change, the lower id
 * first, so neither side ever waits for the other while holding a lock the other waits for: no
 * deadlock, no retry on either side. However they interleave, the German post presents the
 * product with commerce data in its locale, the empty product is deleted once, and nothing is
 * reported.
 *
 * The products a reconciliation locks are the ones its reading of the group named. When a post of
 * the group is bound, between that reading and the locks, to a product it did not name and whose
 * id is lower than one it locked, the reconciliation starts again before its first change, in a
 * unit of work of its own, and locks what it reads then, in order. The probe binds the German
 * post that way, to the product of a third post, with the lowest id, just as the reconciliation
 * takes its first lock; no transaction of the reconciliation ever takes a product lock below one
 * it holds, and the German post, which now presents a product holding another post, is left
 * there as a conflict.
 *
 * Planted violations, each confirmed to fail the test:
 * - In TranslationGroups::reconcile(), have the plan name no product (`array()` in place of
 *   `$facts['touches']`): the reconciliation locks the product with commerce data as it links the
 *   source post, then the empty product as it links the German post, out of order; the probe,
 *   holding the empty product and waiting for the other, deadlocks with it, and the test that
 *   starts the probe at the lower product fails, the link run again.
 * - In TranslationGroups::facts(), leave the group's posts' products out of the products a
 *   reconciliation may change: the reading under the locks finds the German post on a product
 *   the plan never names, the reconciliation gives up before it locks that product, and the tests
 *   that start the probe at the lower product and of a binding moved off the plan both fail.
 * - In TranslationBindings::changeTogether(), never read the bindings again under the locks: the
 *   reconciliation goes on with the plan it read, and its one transaction locks the lowest product
 *   after the others; the test of a binding moved off the plan fails.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class TranslationGroupsConcurrencyTest extends ProductWriteTestCase {

	/**
	 * The languages the services see.
	 *
	 * @since 0.1.0
	 *
	 * @var SeveralLocales
	 */
	private SeveralLocales $locales;

	/**
	 * Hooks the product lifecycle in the kernel's place.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->services->attach();
	}

	/**
	 * Returns the languages the services are built over.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales The languages.
	 */
	protected function locales(): PostLocales {
		$this->locales = new SeveralLocales();

		return $this->locales;
	}

	/**
	 * Returns the moments the link starts at: as the reconciliation locks the lower product, or the higher one.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: bool}> Whether the link starts at the lower product.
	 */
	public static function moments(): array {
		return array(
			'the link starts as the reconciliation locks the lower product'  => array( true ),
			'the link starts as the reconciliation locks the higher product' => array( false ),
		);
	}

	/**
	 * Tests that a reconciliation of a translation group and a link of one of its posts to the same product never deadlock, and leave the post on that product with the empty one gone.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider moments
	 *
	 * @param bool $atLower Whether the link starts as the reconciliation locks the lower product, else the higher one.
	 */
	public function test_a_reconciliation_and_a_link_of_the_same_products_never_deadlock( bool $atLower ): void {
		$b      = $this->secondConnection();
		$german = $this->post();
		$empty  = (int) $this->products->findByPost( $german )?->id();
		$saved  = $this->savedProduct();
		$probe  = null;

		$this->assertGreaterThan( 0, $empty, 'The German post was not given a product of its own.' );
		$this->assertGreaterThan( $empty, $saved->productId );

		$this->locales->assign( $german, Locale::of( 'de_DE' ), $saved->postId );

		$first = $atLower ? $empty : $saved->productId;
		$other = $atLower ? $saved->productId : $empty;
		$raced = $this->beforeStatement(
			'/^' . preg_quote( $this->productLock( $first ), '/' ) . '$/',
			function () use ( &$probe, $saved, $german, $other ): void {
				$probe = ChildProcessProbe::start( dirname( __DIR__, 2 ) . '/Support/product-link-probe.php', array( (string) $saved->productId, (string) $german, 'de_DE' ) );

				// The probe may run to its end, or wait for the other product, which this process holds or is about to.
				$this->awaitProbeWaitingOrEnd( $probe, $this->productLock( $other ), 'statistics' );
			}
		);

		$this->services->lifecycle->translationsChanged( $saved->postId );

		$this->assertTrue( $raced->fired, 'The reconciliation never locked the product the link starts at.' );
		$this->assertNotNull( $probe );

		$report = $probe->finish();

		$this->assertSame(
			array(
				'outcome' => 'linked',
				'retries' => 0,
				'reports' => array(),
			),
			$report,
			'The link failed, or was run again after a deadlock.'
		);
		$this->assertSame( array(), $this->sleeps, 'The reconciliation was run again after a deadlock.' );
		$this->assertSame( array(), $this->reports );

		$product = $this->products->findByPost( $german );

		$this->assertInstanceOf( Product::class, $product );
		$this->assertSame( $saved->productId, $product->id(), 'The German post does not present the product with commerce data.' );
		$this->assertSame( $saved->postId, $product->sourcePostId() );
		$this->assertTrue( $product->bindingOf( $german )?->locale()->equals( Locale::of( 'de_DE' ) ) ?? false, 'The German post is not bound in its locale.' );
		$this->assertCount( 2, $product->bindings() );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $empty ) ), 'The empty product was kept.' );
		$this->assertSame( 1, $this->committedCount( $b, OutboxTable::NAME, sprintf( "event_name = '%s' AND aggregate_id = '%d'", ProductDeleted::eventName(), $empty ) ), 'The empty product was not deleted exactly once.' );
	}

	/**
	 * Tests that a reconciliation whose group post was bound, between its reading of the group and its locks, to a lower product it did not name starts again, and never locks a product below one it holds.
	 *
	 * @since 0.1.0
	 */
	public function test_a_binding_moved_off_the_plan_restarts_the_reconciliation_in_order(): void {
		$b        = $this->secondConnection();
		$third    = $this->post();
		$lowest   = (int) $this->products->findByPost( $third )?->id();
		$german   = $this->post();
		$empty    = (int) $this->products->findByPost( $german )?->id();
		$saved    = $this->savedProduct();
		$report   = null;
		$acquired = array();

		$this->assertGreaterThan( 0, $lowest );
		$this->assertGreaterThan( $lowest, $empty );
		$this->assertGreaterThan( $empty, $saved->productId );

		$this->locales->assign( $german, Locale::of( 'de_DE' ), $saved->postId );

		$pattern = '/^SELECT id, uuid, source_post_id, generation_state, active_variant_generation FROM `' . preg_quote( $this->catalogTable( CatalogTables::PRODUCTS ), '/' ) . '` WHERE id = (\d+) FOR UPDATE$/';

		// The product locks each of this process's transactions takes, in the order it first takes them.
		add_filter(
			'query',
			static function ( string $query ) use ( $pattern, &$acquired ): string {
				if ( 'START TRANSACTION' === $query ) {
					$acquired[] = array();
				} elseif ( array() !== $acquired && 1 === preg_match( $pattern, $query, $match ) && ! in_array( (int) $match[1], $acquired[ count( $acquired ) - 1 ], true ) ) {
					$acquired[ count( $acquired ) - 1 ][] = (int) $match[1];
				}

				return $query;
			}
		);

		$raced = $this->beforeStatement(
			'/^' . preg_quote( $this->productLock( $empty ), '/' ) . '$/',
			static function () use ( &$report, $lowest, $german ): void {
				$report = ChildProcessProbe::run( dirname( __DIR__, 2 ) . '/Support/product-link-probe.php', array( (string) $lowest, (string) $german, 'de_DE' ) );
			}
		);

		$this->services->lifecycle->translationsChanged( $saved->postId );

		$this->assertTrue( $raced->fired, 'The reconciliation never locked the product it read the German post on.' );
		$this->assertSame(
			array(
				'outcome' => 'linked',
				'retries' => 0,
				'reports' => array(),
			),
			$report
		);
		$this->assertSame(
			array( array( $empty, $saved->productId ), array( $lowest, $saved->productId ) ),
			array_values( array_filter( $acquired ) ),
			'The reconciliation did not start again, or locked a product below one it held.'
		);
		$this->assertSame( array(), $this->sleeps, 'The reconciliation was run again after a deadlock.' );
		$this->assertSame( $lowest, $this->products->findByPost( $german )?->id(), 'The German post left the product it was bound to meanwhile.' );
		$this->assertSame( array( $saved->postId ), array_map( static fn( $binding ): int => $binding->postId(), $this->products->findByPost( $saved->postId )?->bindings() ?? array() ) );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $empty ) ) );
		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::TranslationConflict->value,
					'context' => array(
						'post_id'   => $saved->postId,
						'conflicts' => array( $german => 'catalog.post_bound_elsewhere' ),
					),
				),
			),
			$this->reports
		);
	}

	/**
	 * Returns the statement that locks a product's row as a product is loaded under its lock, exactly as the server receives it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product.
	 * @return string The statement.
	 */
	private function productLock( int $productId ): string {
		global $wpdb;

		return (string) $wpdb->prepare( 'SELECT id, uuid, source_post_id, generation_state, active_variant_generation FROM %i WHERE id = %d FOR UPDATE', $this->catalogTable( CatalogTables::PRODUCTS ), $productId );
	}
}
