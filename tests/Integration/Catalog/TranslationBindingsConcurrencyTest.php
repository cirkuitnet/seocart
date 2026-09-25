<?php
/**
 * Tests a promotion of a product's source post racing the delete of another of its posts
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\Event\ProductBindingPromoted;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Two deletes of one product's posts, each on its own connection, end in the one state they would reach one after the other.
 *
 * The product has three posts: its source post, then a British and a German one, both
 * published, linked in that order. This process deletes the source post: the British post is
 * promoted, and just before the source post's binding goes, a probe process starts deleting the
 * British post and waits on the product's row lock. When this process commits, the probe reads
 * the product under the lock: the British post is the source now, so it is handed over to the
 * German one. Whatever either read before the lock, the product ends with the German post as its
 * source, and two promotions recorded. A deadlock would be retried by the unit of work; a wrong
 * source may not happen.
 *
 * Planted violation, confirmed to fail the test: in MysqlProductRepository::promoteSource(),
 * choose the next source with a plain SELECT, then UPDATE the product without the condition on
 * its source: the probe's SELECT reads the snapshot it took before it waited, names the deleted
 * source post as the next source, and the probe's delete is refused (`catalog.delete_refused`)
 * when the promotion finds no binding for it.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class TranslationBindingsConcurrencyTest extends ProductWriteTestCase {

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
	 * Tests that a promotion and the delete of another post of the product, racing, leave the product with the one right source.
	 *
	 * @since 0.1.0
	 */
	public function test_a_promotion_and_the_delete_of_another_post_end_in_one_right_state(): void {
		global $wpdb;

		$b       = $this->secondConnection();
		$saved   = $this->savedProduct();
		$british = $this->unboundPost();
		$german  = $this->unboundPost();
		$probe   = null;

		$this->services->bindings->link( $saved->productId, $british, Locale::of( 'en_GB' ) );
		$this->services->bindings->link( $saved->productId, $german, Locale::of( 'de_DE' ) );

		// The probe locks the product first, with the statement that loads it.
		$lock = (string) $wpdb->prepare( 'SELECT id, uuid, source_post_id, generation_state, active_variant_generation FROM %i WHERE id = %d FOR UPDATE', $this->catalogTable( CatalogTables::PRODUCTS ), $saved->productId );

		$raced = $this->beforeStatement(
			'/^DELETE pp FROM /',
			function () use ( &$probe, $british, $lock ): void {
				$probe = ChildProcessProbe::start( dirname( __DIR__, 2 ) . '/Support/product-delete-probe.php', array( (string) $british ) );

				$this->awaitProbeWaiting( $probe, $lock, 'statistics' );
			}
		);

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $saved->postId, true ) );
		$this->assertTrue( $raced->fired, 'The source post was deleted without a hand-over.' );
		$this->assertNotNull( $probe );

		$report = $probe->finish();

		$this->assertSame( 'deleted', $report['outcome'] ?? null, (string) wp_json_encode( $report ) );
		$this->assertSame( array(), $report['reports'] ?? null );

		$product = $this->products->findByPost( $german );

		$this->assertInstanceOf( Product::class, $product );
		$this->assertSame( $german, $product->sourcePostId(), 'The product was left with a source post that is gone.' );
		$this->assertSame( array( $german ), array_map( static fn( $binding ): int => $binding->postId(), $product->bindings() ) );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $saved->productId ) ) );
		$this->assertSame(
			array( array( $saved->postId, $british ), array( $british, $german ) ),
			$this->promotions( $b )
		);
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Returns the moves of the source the committed ProductBindingPromoted events record, in order: from, then to.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b The second connection.
	 * @return list<array{0: int, 1: int}> The moves.
	 *
	 * @phpstan-impure
	 */
	private function promotions( SecondConnection $b ): array {
		$moves = array();
		$id    = 0;

		while ( true ) {
			$row = $b->fetchRow( sprintf( "SELECT id, payload_json FROM `%s` WHERE event_name = '%s' AND id > %d ORDER BY id LIMIT 1", $this->db->table( OutboxTable::NAME ), ProductBindingPromoted::eventName(), $id ) );

			if ( null === $row ) {
				return $moves;
			}

			$id      = (int) $row['id'];
			$payload = (array) ( json_decode( (string) $row['payload_json'], true )['p'] ?? array() );
			$moves[] = array( (int) ( $payload['from_post_id'] ?? 0 ), (int) ( $payload['to_post_id'] ?? 0 ) );
		}
	}
}
