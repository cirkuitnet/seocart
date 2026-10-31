<?php
/**
 * PlantsListedProducts: plants products with posts, terms and featured images for the listing's tests
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;

/**
 * Writes listable products the way a save leaves them, without the product lifecycle.
 *
 * Owns one fact: what a listing test plants. A product post is written with WordPress's after
 * hooks off, so no lifecycle binds it a second time; the product is stored through the
 * repository, bound to the post in its locale, priced and taken out of `updating` into the state
 * asked for; its stock summary is written as asked; its terms are set and its featured image is an
 * attachment with a `medium` size and alternative text, written without a file. For a
 * CatalogTestCase, which deletes the posts; the attachments are deleted by deletePlantedImages().
 *
 * @since 0.2.0
 */
trait PlantsListedProducts {

	/**
	 * The attachments planted.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private array $plantedImages = array();

	/**
	 * How many posts were planted, which dates each one a minute after the last.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $plantedPosts = 0;

	/**
	 * Plants a product with its post.
	 *
	 * @since 0.2.0
	 *
	 * @param string $title   The post's title.
	 * @param string $sku     The default variant's SKU.
	 * @param array  $options Optional. `status` (default `publish`), `excerpt` (default empty), `state`
	 *                        (a GenerationState value, default `complete`), `summary` (default
	 *                        `in_stock`), `terms` (term ids by taxonomy, default none) and `image`
	 *                        (bool, default false). Default none.
	 * @return array{post_id: int, product_id: int, variant_id: int, image_id: int|null} What was planted.
	 *
	 * @phpstan-param array{status?: string, excerpt?: string, state?: string, summary?: string, terms?: array<string, list<int>>, image?: bool} $options
	 */
	protected function plantListed( string $title, string $sku, array $options = array() ): array {
		$base    = Currency::of( CatalogTestCase::BASE_CURRENCY );
		$postId  = $this->plantPost( $title, $options['status'] ?? 'publish', $options['excerpt'] ?? '' );
		$product = Product::firstBinding( $this->ids->generate(), $postId, Locale::of( self::LOCALE ), Variant::byDefault( $this->ids->generate(), Sku::of( $sku ) ), new \DateTimeImmutable( '2026-10-01 09:00:00', new \DateTimeZone( 'UTC' ) ) );

		$product->applyCommerce( Sku::of( $sku ), VariantPrice::net( $base, 1999 ), 250, $base );
		$this->products->save( $product );

		$productId = (int) $product->id();
		$state     = GenerationState::from( $options['state'] ?? GenerationState::Complete->value );

		if ( GenerationState::Updating !== $state ) {
			$this->assertTrue( $this->products->leaveUpdating( $productId, $state ), 'The planted product did not leave `updating`.' );
		}

		$this->db->execute( 'UPDATE %i SET stock_summary = %s WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), $options['summary'] ?? 'in_stock', $productId );

		foreach ( $options['terms'] ?? array() as $taxonomy => $termIds ) {
			$this->assertIsArray( wp_set_object_terms( $postId, $termIds, $taxonomy ) );
		}

		$imageId = ( $options['image'] ?? false ) ? $this->plantImage( $postId, $sku ) : null;

		return array(
			'post_id'    => $postId,
			'product_id' => $productId,
			'variant_id' => (int) $product->defaultVariant()?->id(),
			'image_id'   => $imageId,
		);
	}

	/**
	 * Plants a second post of a product, in another locale.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $title     The post's title.
	 * @param string $locale    The post's locale.
	 * @return int The post's id.
	 */
	protected function plantTranslation( int $productId, string $title, string $locale ): int {
		$postId = $this->plantPost( $title, 'publish', '' );

		$this->products->addBinding( $productId, new ProductPostBinding( $postId, Locale::of( $locale ), new \DateTimeImmutable( '2026-10-01 09:00:00', new \DateTimeZone( 'UTC' ) ) ) );

		return $postId;
	}

	/**
	 * Deletes the attachments planted. Call it in tear_down(), before the posts are deleted.
	 *
	 * @since 0.2.0
	 */
	protected function deletePlantedImages(): void {
		foreach ( $this->plantedImages as $imageId ) {
			wp_delete_post( $imageId, true );
		}

		$this->plantedImages = array();
	}

	/**
	 * Writes a product post, each one a minute newer than the last, with the after hooks off.
	 *
	 * @since 0.2.0
	 *
	 * @param string $title   The title.
	 * @param string $status  The status.
	 * @param string $excerpt The excerpt.
	 * @return int The post's id.
	 */
	private function plantPost( string $title, string $status, string $excerpt ): int {
		$date   = gmdate( 'Y-m-d H:i:s', 1790000000 + 60 * ++$this->plantedPosts );
		$postId = wp_insert_post(
			array(
				'post_type'     => ProductCapabilities::POST_TYPE,
				'post_status'   => $status,
				'post_title'    => $title,
				'post_excerpt'  => $excerpt,
				'post_date'     => $date,
				'post_date_gmt' => $date,
			),
			true,
			false
		);

		$this->assertIsInt( $postId, 'The planted post was not written.' );

		$this->trackPost( $postId );

		return $postId;
	}

	/**
	 * Gives a post a featured image: an attachment with a `medium` size and alternative text, and no file.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $postId The post.
	 * @param string $name   The image's name.
	 * @return int The attachment's id.
	 */
	private function plantImage( int $postId, string $name ): int {
		$file    = '2026/10/' . strtolower( $name ) . '.jpg';
		$imageId = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $name,
				'post_status'    => 'inherit',
			),
			$file,
			$postId,
			true,
			false
		);

		$this->assertIsInt( $imageId, 'The planted image was not written.' );

		$this->plantedImages[] = $imageId;

		wp_update_attachment_metadata(
			$imageId,
			array(
				'width'  => 1200,
				'height' => 1200,
				'file'   => $file,
				'sizes'  => array(
					'medium' => array(
						'file'      => strtolower( $name ) . '-300x300.jpg',
						'width'     => 300,
						'height'    => 300,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);
		update_post_meta( $imageId, '_wp_attachment_image_alt', 'The ' . $name );
		set_post_thumbnail( $postId, $imageId );

		return $imageId;
	}
}
