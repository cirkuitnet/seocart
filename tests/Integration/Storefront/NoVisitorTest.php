<?php
/**
 * Tests that a product's page carries nothing of its visitor
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Storefront;

use SEOCart\Inventory\Domain\StockTarget;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;
use SEOCart\Tests\Support\CreatesUsers;

/**
 * The three product blocks, and what their page sends the Interactivity API's stores, are the same
 * bytes for a guest and for a logged-in subscriber: no nonce, no user, no cart and no count of
 * units, so a page cache may serve one copy to every visitor.
 *
 * Planted violations, each shown red and removed: print `wp_create_nonce( 'wp_rest' )` into the
 * store's configuration (the bytes differ); print the units for sale into a variant's state (the
 * counts' test finds them).
 *
 * @since 0.2.0
 */
final class NoVisitorTest extends ProductWriteTestCase {

	use CreatesUsers;
	use RendersProductBlocks;
	use SavesVariantSets;

	/**
	 * The units of the first variant at the default location; each next variant has two more.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const UNITS = 4173;

	/**
	 * Puts the kernel's blocks back, and removes the users.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->restoreProductBlocks();
		wp_set_current_user( 0 );
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that the page is the same bytes for a guest and a subscriber, and holds no nonce, no user and no count of units.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the test's services were built without the stock unit.
	 */
	public function test_the_page_is_the_same_for_a_guest_and_a_subscriber(): void {
		$post    = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'VISITOR', VariantSets::SIZES, VariantSets::GRINDS, array(), false ) )->postId;
		$targets = array();

		foreach ( $this->products->findByPost( $post )?->variants() ?? array() as $index => $variant ) {
			$targets[] = new StockTarget( (int) $variant->id(), $this->defaultLocation, self::UNITS + 2 * $index, 0 );
		}

		( $this->services->stockLevels ?? throw new \LogicException( 'The services were built without the stock unit.' ) )->apply( $targets, Actor::user( 1 ) );

		wp_set_current_user( 0 );

		$guest = $this->page( $post );

		wp_set_current_user( $this->createUser( 'subscriber' ) );

		$subscriber = $this->page( $post );

		$this->assertStringContainsString( '"products"', $guest, 'The page printed no product state, so equal bytes would prove nothing.' );
		$this->assertStringContainsString( 'nonceUrl', $guest, 'The page printed no store configuration, so equal bytes would prove nothing.' );
		$this->assertSame( $guest, $subscriber, 'A guest and a subscriber are sent different pages.' );
		$this->assertStringNotContainsString( wp_create_nonce( 'wp_rest' ), $subscriber, 'The page holds the subscriber\'s nonce.' );
		$this->assertStringNotContainsStringIgnoringCase( 'user', $subscriber, 'The page names a user.' );
		$this->assertStringNotContainsString( 'cart_token', $subscriber );

		foreach ( range( self::UNITS, self::UNITS + 10, 2 ) as $units ) {
			$this->assertStringNotContainsString( (string) $units, $subscriber, 'The page holds a count of units for sale.' );
		}

		$this->assertSame( 6, substr_count( $guest, '"inStock":true' ), 'A variant is not in stock, so a page without its count would prove nothing.' );
	}

	/**
	 * Renders a product's page as a visitor: the three blocks over a container of their own, and what the page sends the stores.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post The product's post.
	 * @return string The blocks' markup, then the stores' data as JSON.
	 */
	private function page( int $post ): string {
		$this->renderOver( $this->db, $this->reporter() );

		return $this->renderBlocks( $post, 'seocart/product-price', 'seocart/variant-picker', 'seocart/add-to-cart' ) . $this->interactivityData();
	}
}
