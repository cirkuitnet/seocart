<?php
/**
 * Tests the word index: the documents a save writes, through the production wiring, and the write by another path the post lifecycle publishes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Lifecycle\DeleteProduct;
use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Application\Projection\SearchDocumentProjector;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Doctor\OrphanCommerceRowCheck;
use SEOCart\Catalog\Infrastructure\MysqlCatalogSearch;
use SEOCart\Catalog\Infrastructure\MysqlPriceProjections;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\KernelContainer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads the documents back as the database holds them, and disables a variant as a later save of several variants will.

/**
 * `product_search_index` holds one document per post that presents a product, its title, excerpt and SKUs, written after each save, including a write to the post by any other path, which the post lifecycle publishes, and each change of the product's bindings.
 *
 * Products are saved through SaveProduct over the test's connection, which stores ProductSaved in
 * the outbox; a drain through the kernel's own wiring delivers it to the search document projector.
 *
 * Planted violations, each shown red and removed:
 * - in PostLifecycle::boundPostWrittenElsewhere(), publish nothing (drop `$this->publishSave( $postId );`):
 *   the title written by another path stays out of the index;
 * - in DeleteProduct::delete(), leave out the search's cascade: the deleted product's documents
 *   stay, and orphan_commerce_rows names them;
 * - in MysqlCatalogSearch::index(), rewrite every document whether or not it changed (drop
 *   `$old !== $text` and the guard of UPDATE_DOCUMENT): the second projection rewrites it;
 * - in TranslationBindings::publishSave(), record no save (drop the `markSaved()` call): the
 *   linked post stays out of the index, and the deleted translation's and the handed-over source
 *   post's documents stay.
 *
 * @since 0.2.0
 */
final class SearchDocumentTest extends ProductWriteTestCase {
	/**
	 * The kernel's container, built on first use.
	 *
	 * @since 0.2.0
	 *
	 * @var Container|null
	 */
	private ?Container $kernel = null;


	/**
	 * Tests that a save writes one document per post of the product, in its locale and whatever its status: its title and excerpt, without markup, and the SKUs.
	 *
	 * @since 0.2.0
	 */
	public function test_a_save_indexes_each_post_of_the_product(): void {
		$saved      = $this->save(
			null,
			array(
				'post_title'   => 'Linen shirt',
				'post_excerpt' => '<p>Woven from <b>flax</b> &amp; cut loose.</p>',
				'post_status'  => 'publish',
			),
			array(
				'sku'         => 'SHIRT-LINEN-M',
				'price_minor' => 1999,
			)
		);
		$translated = $this->translation( $saved->productId, 'Leinenhemd', 'de_DE', 'draft' );

		$this->assertSame( array(), $this->documents( $saved->productId ), 'Nothing is indexed before the drain.' );

		$this->resave( $saved->postId );
		$this->drain();

		$this->assertSame(
			array(
				'de_DE' => 'Leinenhemd SHIRT-LINEN-M',
				'en_US' => 'Linen shirt Woven from flax & cut loose. SHIRT-LINEN-M',
			),
			$this->documents( $saved->productId ),
			'A translation\'s document holds its own title, a draft\'s too: the listing reads the status as it is.'
		);
		$this->assertGreaterThan( 0, $translated );
	}

	/**
	 * Tests that a title written by another path, as Quick Edit, bulk edit or `wp post update` write it, publishes the product's save and is in the index after the drain.
	 *
	 * @since 0.2.0
	 */
	public function test_a_write_by_another_path_reindexes_the_post(): void {
		$saved = $this->save(
			null,
			array(
				'post_title'  => 'Linen shirt',
				'post_status' => 'publish',
			),
			array(
				'sku'         => 'SHIRT-LINEN-M',
				'price_minor' => 1999,
			)
		);

		$this->drain();
		$this->assertSame( array( 'en_US' => 'Linen shirt SHIRT-LINEN-M' ), $this->documents( $saved->productId ) );

		$this->services->attach();

		$this->assertSame(
			$saved->postId,
			wp_update_post(
				array(
					'ID'         => $saved->postId,
					'post_title' => 'Hemp shirt',
				),
				true
			)
		);

		$stored = $this->db->fetchAll( 'SELECT payload_json FROM %i WHERE event_name = %s AND state = %s', $this->db->table( 'outbox' ), 'product_saved', 'pending' );

		$this->assertCount( 1, $stored, 'The write by another path stored the product\'s save.' );
		$this->assertSame( array( 'post' ), Outbox::decode( (string) $stored[0]['payload_json'] )['p']['changed_fields'] ?? null );

		$this->drain();

		$this->assertSame( array( 'en_US' => 'Hemp shirt SHIRT-LINEN-M' ), $this->documents( $saved->productId ), 'The new title is in the index after the drain.' );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $saved->postId ) );
		$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE event_name = %s AND state = %s', $this->db->table( 'outbox' ), 'product_saved', 'pending' ), 'A move to the trash is a step of the lifecycle, and publishes no save.' );
	}

	/**
	 * Tests that a disabled variant's SKU leaves the document, a deleted product's documents go with it, and a second projection writes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_documents_follow_the_variants_and_go_with_the_product(): void {
		$saved     = $this->save(
			null,
			array(
				'post_title'  => 'Linen shirt',
				'post_status' => 'publish',
			),
			array(
				'sku'         => 'SHIRT-LINEN-M',
				'price_minor' => 1999,
			)
		);
		$projector = $this->kernel()->get( SearchDocumentProjector::class );

		$this->assertSame( 1, $projector->project( array( $saved->productId ) ) );

		$written = null;
		$log     = $this->captureQueries(
			function () use ( $projector, $saved, &$written ): void {
				$written = $projector->project( array( $saved->productId ) );
			}
		);

		$this->assertSame( 0, $written, 'An unchanged document is not written again.' );
		$this->assertQueryCount( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE' ), 'Writes of a second projection' );

		$this->db->execute( 'UPDATE %i SET is_enabled = 0 WHERE product_id = %d', $this->db->table( CatalogTables::VARIANTS ), $saved->productId );
		$this->resave( $saved->postId );
		$this->drain();

		$this->assertSame( array( 'en_US' => 'Linen shirt' ), $this->documents( $saved->productId ), 'A disabled variant\'s SKU leaves the document.' );

		$this->kernel()->get( DeleteProduct::class )->delete( $saved->productId, Actor::user( 1 ) );

		$this->assertSame( array(), $this->documents( $saved->productId ), 'Deleting the product deletes its documents in the same transaction.' );
		$this->assertTrue( ( new OrphanCommerceRowCheck( $this->products, new MysqlPriceProjections( $this->db ), new MysqlCatalogSearch( $this->db ) ) )->run()->passed );
	}

	/**
	 * Tests that a post linked to a product is indexed in its locale after the drain, and that a move of its binding to another locale moves its document.
	 *
	 * @since 0.2.0
	 */
	public function test_a_linked_post_is_indexed_in_its_locale(): void {
		$saved = $this->linenShirt();

		$this->drain();

		$linked = $this->unboundPost();

		wp_update_post(
			array(
				'ID'         => $linked,
				'post_title' => 'Leinenhemd',
			)
		);
		$this->services->bindings->link( $saved->productId, $linked, Locale::of( 'de_DE' ) );
		$this->drain();

		$this->assertSame(
			array(
				'de_DE' => 'Leinenhemd SHIRT-LINEN-M',
				'en_US' => 'Linen shirt SHIRT-LINEN-M',
			),
			$this->documents( $saved->productId ),
			'The linked post is indexed after the drain.'
		);

		$this->services->bindings->link( $saved->productId, $linked, Locale::of( 'fr_FR' ) );
		$this->drain();

		$this->assertSame(
			array(
				'en_US' => 'Linen shirt SHIRT-LINEN-M',
				'fr_FR' => 'Leinenhemd SHIRT-LINEN-M',
			),
			$this->documents( $saved->productId ),
			'A binding moved to another locale takes its document with it.'
		);
	}

	/**
	 * Tests that deleting a translation's post, while the source post stays, removes that locale's document after the drain.
	 *
	 * @since 0.2.0
	 */
	public function test_a_deleted_translation_takes_its_document_with_it(): void {
		$saved      = $this->linenShirt();
		$translated = $this->translation( $saved->productId, 'Leinenhemd', 'de_DE', 'publish' );

		$this->resave( $saved->postId );
		$this->drain();
		$this->assertSame( array( 'de_DE', 'en_US' ), array_keys( $this->documents( $saved->productId ) ) );

		$this->services->attach();

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $translated, true ) );
		$this->assertNotNull( $this->products->find( $saved->productId ), 'The product stays in its other locale.' );

		$this->drain();

		$this->assertSame( array( 'en_US' => 'Linen shirt SHIRT-LINEN-M' ), $this->documents( $saved->productId ), 'The deleted translation\'s document goes after the drain.' );
	}

	/**
	 * Tests that deleting the source post, while a translation's post stays, hands the source over and removes the source post's document after the drain.
	 *
	 * @since 0.2.0
	 */
	public function test_a_handed_over_source_takes_its_document_with_it(): void {
		$saved      = $this->linenShirt();
		$translated = $this->translation( $saved->productId, 'Leinenhemd', 'de_DE', 'publish' );

		$this->resave( $saved->postId );
		$this->drain();
		$this->assertSame( array( 'de_DE', 'en_US' ), array_keys( $this->documents( $saved->productId ) ) );

		$this->services->attach();

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $saved->postId, true ) );
		$this->assertSame( $translated, $this->products->find( $saved->productId )?->sourcePostId(), 'The translation\'s post is the source now.' );

		$this->drain();

		$this->assertSame( array( 'de_DE' => 'Leinenhemd SHIRT-LINEN-M' ), $this->documents( $saved->productId ), 'The handed-over source post\'s document goes after the drain.' );
	}

	/**
	 * Saves a published product, the linen shirt with one SKU.
	 *
	 * @since 0.2.0
	 *
	 * @return SaveResult The save.
	 */
	private function linenShirt(): SaveResult {
		return $this->save(
			null,
			array(
				'post_title'  => 'Linen shirt',
				'post_status' => 'publish',
			),
			array(
				'sku'         => 'SHIRT-LINEN-M',
				'price_minor' => 1999,
			)
		);
	}

	/**
	 * Binds a second post, in another locale, to a product.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $title     The post's title.
	 * @param string $locale    The post's locale.
	 * @param string $status    The post's status.
	 * @return int The post's id.
	 */
	private function translation( int $productId, string $title, string $locale, string $status ): int {
		$postId = $this->unboundPost( $status );

		wp_update_post(
			array(
				'ID'         => $postId,
				'post_title' => $title,
			)
		);
		$this->products->addBinding( $productId, new ProductPostBinding( $postId, Locale::of( $locale ), new \DateTimeImmutable( '2026-10-31 10:00:00', new \DateTimeZone( 'UTC' ) ) ) );

		return $postId;
	}

	/**
	 * Saves a product again through the service, with nothing changed: the save is published all the same.
	 *
	 * @since 0.2.0
	 *
	 * @param int $postId The product's post.
	 */
	private function resave( int $postId ): void {
		$this->save( $postId, array( 'post_title' => (string) get_post_field( 'post_title', $postId, 'raw' ) ) );
	}

	/**
	 * Delivers what the outbox holds, as the end of the request does.
	 *
	 * @since 0.2.0
	 */
	private function drain(): void {
		$this->kernel()->get( OutboxDrainer::class )->drain( DrainOptions::command( 30 ) );
	}

	/**
	 * Returns a product's documents as stored, by locale.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return array<string, string> The words, by locale.
	 */
	private function documents( int $productId ): array {
		$documents = array();

		foreach ( $this->db->fetchAll( 'SELECT locale, search_document FROM %i WHERE product_id = %d ORDER BY locale', $this->db->table( CatalogTables::SEARCH_INDEX ), $productId ) as $row ) {
			$documents[ (string) $row['locale'] ] = (string) $row['search_document'];
		}

		return $documents;
	}

	/**
	 * Builds the kernel's container as a request builds it, with the plain connection as its unit of work and a wake that records.
	 *
	 * @since 0.2.0
	 *
	 * @return Container The container.
	 */
	private function kernel(): Container {
		return $this->kernel ??= KernelContainer::build(
			$this->db,
			$this->reporter(),
			array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ),
				EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher( $c->get( TransactionManager::class ), $c->get( Outbox::class ), $c->get( HookBridge::class ), $c->get( EventCatalog::class ), $c->get( CorrelationId::class ), new RecordingWake() ),
			)
		);
	}
}
