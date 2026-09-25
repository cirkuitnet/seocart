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
use SEOCart\Catalog\Domain\ReportCode;
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
	 * Tests that, under WP_DEBUG, a callback defined in an own directory named through a symbolic link is not reported, while another plugin's is, and WordPress's are not.
	 *
	 * PHP names the file a callback is defined in with every link resolved, while an own path
	 * may be named through a link, as ABSPATH may name WordPress's directory. The gateway is
	 * given the own directory through a link, and the listener in it is required through the same
	 * link.
	 *
	 * Planted violation: in WordPressPostGateway::realPath(), return the path normalized without
	 * resolving it: the linked listener is reported as another plugin's, and on a server whose
	 * ABSPATH names WordPress's directory through a link, so are WordPress's own callbacks.
	 *
	 * @since 0.1.0
	 */
	public function test_an_own_directory_named_through_a_link_is_recognised(): void {
		$real = (string) tempnam( sys_get_temp_dir(), 'seocart-own-' );
		$link = $real . '-link';

		// phpcs:disable WordPress.WP.AlternativeFunctions -- The test builds a directory and a link to it in the temporary directory, and removes them.
		unlink( $real );
		mkdir( $real, 0700 );
		file_put_contents( $real . '/listener.php', "<?php\nreturn static function (): void {};\n" );

		$this->assertTrue( symlink( $real, $link ), 'The link could not be made.' );

		try {
			$linked  = require $link . '/listener.php';
			$line    = __LINE__ + 1;
			$foreign = static function (): void {};
			$gateway = new WordPressPostGateway( $this->db, $this->reporter(), true, array( $link . '/' ) );

			add_action( 'save_post_' . ProductCapabilities::POST_TYPE, $linked );
			add_action( 'save_post_' . ProductCapabilities::POST_TYPE, $foreign );

			$this->trackPost( $gateway->write( array( 'post_title' => 'Watched' ) ) );
		} finally {
			unlink( $link );
			unlink( $real . '/listener.php' );
			rmdir( $real );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$reported = array_values( array_filter( $this->reports, static fn( array $report ): bool => ReportCode::ForeignSaveListener->value === $report['code'] ) );
		$listed   = (string) ( $reported[0]['context']['callbacks'] ?? '' );

		$this->assertCount( 1, $reported );
		$this->assertStringContainsString( basename( __FILE__ ) . ':' . $line, $listed, 'Another plugin\'s callback was not reported.' );
		$this->assertStringNotContainsString( 'listener.php', $listed, 'The callback of the own directory named through a link was reported.' );
		$this->assertStringNotContainsString( 'wp-includes/', $listed, 'WordPress\'s own callbacks were reported.' );
	}

	/**
	 * Tests that, under WP_DEBUG, an own file is matched by its whole path: a callback in a file whose name only begins with an own file's is reported as another plugin's.
	 *
	 * The gateway is given one own file, `own.php`; a listener is defined in it, and another in
	 * `own.php-copy.php` beside it, whose path begins with the own file's.
	 *
	 * Planted violation: in WordPressPostGateway::foreignCallback(), match every own path by
	 * prefix, a file's as a directory's: the copy's listener is taken for the plugin's own and not
	 * reported.
	 *
	 * @since 0.1.0
	 */
	public function test_an_own_file_is_matched_by_its_whole_path(): void {
		$directory = (string) tempnam( sys_get_temp_dir(), 'seocart-own-' );
		$listener  = "<?php\nreturn static function (): void {};\n";

		// phpcs:disable WordPress.WP.AlternativeFunctions -- The test writes two files in the temporary directory, and removes them.
		unlink( $directory );
		mkdir( $directory, 0700 );
		file_put_contents( $directory . '/own.php', $listener );
		file_put_contents( $directory . '/own.php-copy.php', $listener );

		try {
			$own     = require $directory . '/own.php';
			$copy    = require $directory . '/own.php-copy.php';
			$gateway = new WordPressPostGateway( $this->db, $this->reporter(), true, array( $directory . '/own.php' ) );

			add_action( 'save_post_' . ProductCapabilities::POST_TYPE, $own );
			add_action( 'save_post_' . ProductCapabilities::POST_TYPE, $copy );

			$this->trackPost( $gateway->write( array( 'post_title' => 'Watched' ) ) );
		} finally {
			unlink( $directory . '/own.php' );
			unlink( $directory . '/own.php-copy.php' );
			rmdir( $directory );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		$reported = array_values( array_filter( $this->reports, static fn( array $report ): bool => ReportCode::ForeignSaveListener->value === $report['code'] ) );
		$listed   = (string) ( $reported[0]['context']['callbacks'] ?? '' );

		$this->assertCount( 1, $reported );
		$this->assertStringContainsString( '/own.php-copy.php:2', $listed, 'A file whose path begins with an own file\'s was taken for the plugin\'s.' );
		$this->assertStringNotContainsString( '/own.php:', $listed, 'The own file\'s listener was reported.' );
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
