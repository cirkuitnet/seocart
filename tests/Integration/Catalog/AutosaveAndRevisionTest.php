<?php
/**
 * Tests that autosaves and revision restores of a product never touch its commerce rows
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;

/**
 * Core's autosave and revision controllers for the product are built from the plugin's
 * controller, and prepare a post with its prepare_item_for_database(), which knows no commerce
 * field; neither calls the product write. A revision restore is an editorial post update. The
 * product lifecycle is hooked, as on a site, and a draft's autosave and a revision restore reach
 * it as writes by another path to a bound post, which leave the product alone. So the four
 * catalog tables stay byte for byte as they were, and a live product stays on sale.
 *
 * The autosave requests carry a `seocart` object on purpose: the block editor sends none, and a
 * hand-made one must be ignored too.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In ProductPostsController, override prepare_item_for_database() to save the request's
 *   `seocart` object for the post it names, as a controller that wrote commerce data while
 *   preparing the post would: every autosave test sees a changed checksum.
 * - In PostLifecycle::boundPostWrittenElsewhere(), mark the post's product `updating`, as a rule
 *   that marked such a write's product `incomplete` would: the draft's autosave and the revision
 *   restore change a catalog row.
 *
 * @since 0.1.0
 */
final class AutosaveAndRevisionTest extends ProductRestTestCase {

	/**
	 * Tests that an autosave of a published product is a revision, and leaves every commerce row as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_an_autosave_of_a_published_product_changes_no_commerce_row(): void {
		$b       = $this->secondConnection();
		$created = $this->createdProduct( 'publish' );
		$before  = $this->catalogChecksums( $b );
		$saves   = $this->committedSavesOfAll( $b );

		$response = $this->autosave( $created, 'Autosaved title' );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'revision', get_post_type( (int) $response->get_data()['id'] ), 'An autosave of a published post is a revision.' );
		$this->assertSame( $before, $this->catalogChecksums( $b ), 'An autosave changed a catalog row.' );
		$this->assertSame( $saves, $this->committedSavesOfAll( $b ), 'An autosave saved the product.' );
		$this->assertSame( 'Created product', get_post_field( 'post_title', $created ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdictOfPost( $created ) );
	}

	/**
	 * Tests that an autosave of a draft by its author updates the post itself, and leaves every commerce row as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_an_autosave_of_a_draft_by_its_author_changes_no_commerce_row(): void {
		$b       = $this->secondConnection();
		$created = $this->createdProduct( 'draft' );
		$before  = $this->catalogChecksums( $b );
		$saves   = $this->committedSavesOfAll( $b );

		$response = $this->autosave( $created, 'Autosaved draft title' );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Autosaved draft title', get_post_field( 'post_title', $created ), 'An autosave of a draft by its author updates the post itself.' );
		$this->assertSame( $before, $this->catalogChecksums( $b ), 'An autosave changed a catalog row.' );
		$this->assertSame( $saves, $this->committedSavesOfAll( $b ), 'An autosave saved the product.' );
		$this->assertSame( SellabilityReason::NotPublished, $this->verdictOfPost( $created ) );
	}

	/**
	 * Tests that restoring a revision restores the title, leaves every commerce row as it was, and leaves the product on sale.
	 *
	 * @since 0.1.0
	 */
	public function test_a_revision_restore_changes_the_title_and_no_commerce_row(): void {
		$b       = $this->secondConnection();
		$created = $this->createdProduct( 'publish' );

		$this->request( 'PUT', '/' . $created, array( 'title' => 'Second title' ) );

		/*
		 * Written as core writes a revision. Core's own call on `wp_after_insert_post` does nothing
		 * once an autosave has defined DOING_AUTOSAVE, which lasts for the rest of the process.
		 */
		$second = _wp_put_post_revision( get_post( $created ) );

		$this->request( 'PUT', '/' . $created, array( 'title' => 'Third title' ) );

		$this->assertIsInt( $second, 'The revision of the second title was not written.' );
		$this->assertSame( 'Third title', get_post_field( 'post_title', $created ) );

		$before = $this->catalogChecksums( $b );
		$saves  = $this->committedSavesOfAll( $b );

		$this->assertNotFalse( wp_restore_post_revision( (int) $second ) );
		$this->assertSame( 'Second title', get_post_field( 'post_title', $created ) );
		$this->assertSame( $before, $this->catalogChecksums( $b ), 'A revision restore changed a catalog row.' );
		$this->assertSame( $saves, $this->committedSavesOfAll( $b ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdictOfPost( $created ) );
	}

	/**
	 * Creates a whole product through the endpoint, as the block editor's first save does, by the test's administrator.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status The post status.
	 * @return int The post's id.
	 */
	private function createdProduct( string $status ): int {
		$response = $this->request(
			'POST',
			'',
			array(
				'title'                         => 'Created product',
				'status'                        => $status,
				ProductCommerceSchema::PROPERTY => array(
					'sku'         => 'SKU-AUTO',
					'price_minor' => 1999,
				),
			)
		);

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (int) $response->get_data()['id'];
	}

	/**
	 * Sends an autosave of a product, with a `seocart` object the autosave must ignore.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId The product's post.
	 * @param string $title  The autosaved title.
	 * @return \WP_REST_Response The response.
	 */
	private function autosave( int $postId, string $title ): \WP_REST_Response {
		return $this->request(
			'POST',
			'/' . $postId . '/autosaves',
			array(
				'title'                         => $title,
				'content'                       => 'Autosaved content.',
				ProductCommerceSchema::PROPERTY => array( 'price_minor' => 1 ),
			)
		);
	}

	/**
	 * Returns the verdict on the default variant of a post's product.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return SellabilityReason|null The verdict, or null when the post has no product with a variant.
	 */
	private function verdictOfPost( int $postId ): ?SellabilityReason {
		$variant = $this->products->findByPost( $postId )?->defaultVariant()?->id();

		return null === $variant ? null : $this->verdict( $variant );
	}
}
