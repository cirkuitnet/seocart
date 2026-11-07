<?php
/**
 * Tests the image of an option's value: an attachment of the site when it is given, and kept as it is once stored
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;
use WP_REST_Response;

/**
 * A value given an image names an attachment of the site's media library, or the save is refused before anything is written. An image a value already has is not judged again, so a product read and sent back saves after its attachment was deleted.
 *
 * Planted violation, shown failing and put back: in SaveProduct::refuseVariantSet(), leave out the
 * call of refuseImages(). A page's id is then saved as a value's image.
 *
 * @since 0.2.0
 */
final class ProductOptionImagesTest extends ProductRestTestCase {

	use SavesVariantSets;

	/**
	 * Tests that a value given the id of a post that is not an attachment is refused, and nothing is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_given_a_post_that_is_not_an_attachment_is_refused_before_anything_is_written(): void {
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'NOTIMG' ) );
		$page     = $this->post( 'publish', 'page' );
		$response = $this->putOptions( $six->postId, self::withImage( $page ) );

		$this->assertSame( array( 400, CatalogError::OptionInvalid->value ), array( $response->get_status(), $response->get_data()['code'] ?? null ), (string) wp_json_encode( $response->get_data() ) );
		$this->assertNull( $this->firstImage( $six->postId ), 'The refused image was written.' );
	}

	/**
	 * Tests that a value given an attachment keeps it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_given_an_attachment_keeps_it(): void {
		$six      = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'IMG' ) );
		$image    = $this->post( 'inherit', 'attachment' );
		$response = $this->putOptions( $six->postId, self::withImage( $image ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $image, $this->firstImage( $six->postId ) );
	}

	/**
	 * Tests that an image a value already has is not judged again: the options read and sent back save after the attachment was deleted.
	 *
	 * @since 0.2.0
	 */
	public function test_an_image_a_value_has_is_kept_by_a_read_sent_back_after_its_attachment_is_deleted(): void {
		$six   = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'GONE' ) );
		$image = $this->post( 'inherit', 'attachment' );

		$this->assertSame( 200, $this->putOptions( $six->postId, self::withImage( $image ) )->get_status() );

		wp_delete_attachment( $image, true );

		$read     = $this->request( 'GET', '/' . $six->postId, array(), array( 'context' => 'edit' ) )->get_data()[ ProductCommerceSchema::PROPERTY ]['options'];
		$response = $this->putOptions( $six->postId, $read );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $image, $this->firstImage( $six->postId ), 'The stored image was dropped or judged again.' );
	}

	/**
	 * Returns the test's options with an image on the first value of the first axis.
	 *
	 * @since 0.2.0
	 *
	 * @param int $image The image's attachment id.
	 * @return list<array<string, mixed>> The options.
	 */
	private static function withImage( int $image ): array {
		$options = VariantSets::options();

		$options[0]['values'][0]['image_id'] = $image;

		return $options;
	}

	/**
	 * Sends a product's options alone through the REST server.
	 *
	 * @since 0.2.0
	 *
	 * @param int                        $postId  The post.
	 * @param list<array<string, mixed>> $options The options.
	 * @return WP_REST_Response The answer.
	 */
	private function putOptions( int $postId, array $options ): WP_REST_Response {
		return $this->request( 'PUT', '/' . $postId, array( ProductCommerceSchema::PROPERTY => array( 'options' => $options ) ) );
	}

	/**
	 * Returns the image of the first value of the first axis, as the product is stored.
	 *
	 * @since 0.2.0
	 *
	 * @param int $postId The post.
	 * @return int|null The attachment id, or null for none.
	 */
	private function firstImage( int $postId ): ?int {
		return $this->products->findByPost( $postId )?->options()->axes[0]->values[0]->imageAttachmentId;
	}
}
