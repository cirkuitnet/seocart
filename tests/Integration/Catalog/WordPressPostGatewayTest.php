<?php
/**
 * Tests WordPressPostGateway: the product post's one write, inside a transaction, and the reads the catalog needs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Infrastructure\WordPressPostGateway;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;

/**
 * An insert is always a product post, written without `wp_after_insert_post`, which waits for
 * fireAfterInsert(); an update of any other post is refused; WordPress's refusal is
 * `catalog.post_rejected` with its own code; and when a `save_post_seocart_product` listener
 * throws inside core's call, the rolled-back insert or update is gone from the database and from
 * the object cache.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In WordPressPostGateway::write(), move `$this->forgetOnRollback( $id );` after the call to
 *   wp_update_post(): the listener ends the window before anything is registered, the rolled-back
 *   title stays in the object cache, and test_an_update_a_listener_rolls_back_leaves_no_cached_copy fails.
 * - In WordPressPostGateway::insert(), remove the add_action() of the one-shot listener: the
 *   phantom post stays in the object cache, and test_an_insert_a_listener_rolls_back_leaves_no_post_and_no_cached_copy fails.
 * - In the same method, remove wp_slash(): the stored title loses its backslash, and
 *   test_an_insert_is_a_product_post_and_defers_the_after_insert_hook fails.
 * - In the same method, pass `true` as the third argument of wp_insert_post(): the after-insert
 *   hook fires inside the write, and test_an_insert_is_a_product_post_and_defers_the_after_insert_hook fails.
 *
 * @since 0.1.0
 */
final class WordPressPostGatewayTest extends CatalogTestCase {

	/**
	 * The gateway under test, over this test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @var WordPressPostGateway
	 */
	private WordPressPostGateway $gateway;

	/**
	 * The posts `wp_after_insert_post` fired for, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: int, 1: bool}>
	 */
	private array $afterInsert = array();

	/**
	 * Builds the gateway and listens on `wp_after_insert_post`.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->gateway     = new WordPressPostGateway( $this->db );
		$this->afterInsert = array();

		add_action(
			'wp_after_insert_post',
			function ( $postId, $post, $update ): void {
				$this->afterInsert[] = array( (int) $postId, (bool) $update );
			},
			10,
			3
		);
	}

	/**
	 * Tests that an insert writes a product post, whatever type it is given, and defers the after-insert hook.
	 *
	 * @since 0.1.0
	 */
	public function test_an_insert_is_a_product_post_and_defers_the_after_insert_hook(): void {
		$postId = $this->gateway->write(
			array(
				'post_type'   => 'page',
				'post_title'  => 'A "quoted" title with a back\\slash',
				'post_status' => 'draft',
			)
		);

		$this->trackPost( $postId );

		$this->assertSame( ProductCapabilities::POST_TYPE, $this->gateway->postTypeOf( $postId ) );
		$this->assertSame( 'draft', $this->gateway->statusOf( $postId ) );
		$this->assertSame( 'A "quoted" title with a back\\slash', get_post( $postId )?->post_title, 'The fields are slashed the way core expects, so they are stored as given.' );
		$this->assertSame( array(), $this->afterInsert, 'wp_after_insert_post fired inside the write.' );

		$this->gateway->fireAfterInsert( $postId, false, null );

		$this->assertSame( array( array( $postId, false ) ), $this->afterInsert );
	}

	/**
	 * Tests that an update writes the product post it names.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_writes_the_product_post(): void {
		$postId            = $this->post( 'draft' );
		$this->afterInsert = array();

		$this->assertSame(
			$postId,
			$this->gateway->write(
				array(
					'ID'          => $postId,
					'post_title'  => 'Renamed',
					'post_status' => 'publish',
				)
			)
		);
		$this->assertSame( 'publish', $this->gateway->statusOf( $postId ) );
		$this->assertSame( 'Renamed', get_post( $postId )?->post_title );
		$this->assertSame( array(), $this->afterInsert );
	}

	/**
	 * Tests that an update of a post that is not a product post, or of no post, is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_of_anything_but_a_product_post_is_refused(): void {
		$page = $this->post( 'publish', 'page' );

		foreach ( array( $page, $page + 1000 ) as $postId ) {
			try {
				$this->gateway->write(
					array(
						'ID'         => $postId,
						'post_title' => 'Taken over',
					)
				);
				$this->fail( "Post {$postId} was written." );
			} catch ( CodedException $refused ) {
				$this->assertSame( CatalogError::PostNotProduct, $refused->errorCode() );
				$this->assertSame( array( 'post_id' => $postId ), $refused->context() );
			}
		}

		$this->assertSame( 'Fixture product', get_post( $page )?->post_title );
		$this->assertNull( $this->gateway->statusOf( $page + 1000 ) );
		$this->assertNull( $this->gateway->postTypeOf( $page + 1000 ) );
	}

	/**
	 * Tests that WordPress's refusal is `catalog.post_rejected`, carrying WordPress's code.
	 *
	 * @since 0.1.0
	 */
	public function test_wordpress_refusing_the_post_is_post_rejected(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		try {
			$this->gateway->write( array( 'post_title' => '' ) );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::PostRejected, $refused->errorCode() );
			$this->assertSame( array( 'wordpress_code' => 'empty_content' ), $refused->context() );

			return;
		}

		$this->fail( 'WordPress refused the post and the write went on.' );
	}

	/**
	 * Tests that an insert a `save_post_seocart_product` listener rolls back leaves no post, in the database or in the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_an_insert_a_listener_rolls_back_leaves_no_post_and_no_cached_copy(): void {
		$written = $this->failInsideCore(
			fn(): int => $this->gateway->write(
				array(
					'post_title'  => 'Never was',
					'post_status' => 'draft',
				)
			)
		);

		$this->assertNull( get_post( $written ), 'The rolled-back post is still in the object cache.' );
		$this->assertNull( $this->db->fetchValue( 'SELECT ID FROM %i WHERE ID = %d', $this->db->prefix() . 'posts', $written ), 'The rolled-back post is still in the database.' );
	}

	/**
	 * Tests that an update a `save_post_seocart_product` listener rolls back leaves the stored post, in the database and in the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_a_listener_rolls_back_leaves_no_cached_copy(): void {
		$postId = $this->post( 'draft' );

		$this->assertSame( 'Fixture product', get_post( $postId )?->post_title, 'The stored post is not cached as stored.' );

		$written = $this->failInsideCore(
			fn(): int => $this->gateway->write(
				array(
					'ID'         => $postId,
					'post_title' => 'Rolled back',
				)
			)
		);

		$after = get_post( $postId );

		$this->assertSame( $postId, $written );
		$this->assertInstanceOf( \WP_Post::class, $after, 'The stored post is gone.' );
		$this->assertSame( 'Fixture product', $after->post_title, 'The rolled-back title is still in the object cache.' );
		$this->assertSame( 'Fixture product', $this->db->fetchValue( 'SELECT post_title FROM %i WHERE ID = %d', $this->db->prefix() . 'posts', $postId ) );
	}

	/**
	 * Runs a write in a transaction, with a `save_post_seocart_product` listener that throws inside core's call.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $write Writes through the gateway.
	 * @return int The id of the post the listener saw, cached by core before it fired.
	 *
	 * @phpstan-param callable(): int $write
	 */
	private function failInsideCore( callable $write ): int {
		$seen     = 0;
		$listener = static function ( $postId ) use ( &$seen ): void {
			$seen = (int) $postId;

			throw new \RuntimeException( 'A listener failed.' );
		};

		$failure = null;

		add_action( 'save_post_' . ProductCapabilities::POST_TYPE, $listener );

		try {
			$this->db->transaction( $write );
		} catch ( \RuntimeException $failed ) {
			$failure = $failed;
		} finally {
			remove_action( 'save_post_' . ProductCapabilities::POST_TYPE, $listener );
		}

		$this->assertSame( 'A listener failed.', $failure?->getMessage(), 'The listener did not fail the write.' );
		$this->assertGreaterThan( 0, $seen, 'The listener never ran.' );
		$this->assertSame( 0, $this->db->depth(), 'The transaction is still open.' );

		return $seen;
	}
}
