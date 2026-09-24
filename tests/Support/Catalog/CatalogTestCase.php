<?php
/**
 * CatalogTestCase: the base of the catalog's integration tests, with its tables and product posts
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant facts in the rows directly, and read them back as the database holds them.

/**
 * A DatabaseTestCase with the catalog's tables, the product post type and a repository over them.
 *
 * Owns one fact: how a catalog test gets committed product rows and posts and leaves none behind.
 * The tables are created by the catalog's own migration and dropped by DatabaseTestCase. Posts are
 * written with wp_insert_post(), committed like everything else here, and deleted in tear_down().
 * The repository's base currency is USD. storedProduct() stores a whole product the way a
 * successful save leaves one: bound, priced, and taken out of `updating` into `complete`.
 *
 * @since 0.1.0
 */
abstract class CatalogTestCase extends DatabaseTestCase {

	/**
	 * The base currency of the repository under test, and of every catalog service a test builds.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BASE_CURRENCY = 'USD';

	/**
	 * The locale the fixtures bind posts in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const LOCALE = 'en_US';

	/**
	 * The repository under test.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlProductRepository
	 */
	protected MysqlProductRepository $products;

	/**
	 * Mints the fixtures' UUIDs.
	 *
	 * @since 0.1.0
	 *
	 * @var SequentialIdGenerator
	 */
	protected SequentialIdGenerator $ids;

	/**
	 * The posts this test wrote.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $posts = array();

	/**
	 * Creates the catalog's tables and registers the post type.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateCatalogTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		// Another test may have unregistered the type the kernel registers on `init`.
		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) ) {
			ProductPostType::register();
		}

		$this->posts    = array();
		$this->ids      = new SequentialIdGenerator( 1000 );
		$this->products = new MysqlProductRepository( $this->db, static fn(): Currency => Currency::of( self::BASE_CURRENCY ) );
	}

	/**
	 * Deletes the posts this test wrote; DatabaseTestCase drops the tables.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );

		foreach ( $this->posts as $postId ) {
			wp_delete_post( $postId, true );
		}

		parent::tear_down();
	}

	/**
	 * Writes a post, committed, and deletes it after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Optional. The post status. Default `publish`.
	 * @param string $type   Optional. The post type. Default the product post type.
	 * @return int The post's id.
	 */
	protected function post( string $status = 'publish', string $type = ProductCapabilities::POST_TYPE ): int {
		$postId = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => $status,
				'post_title'  => 'Fixture product',
			),
			true
		);

		$this->assertIsInt( $postId, 'The fixture post was not written.' );

		$this->trackPost( $postId );

		return $postId;
	}

	/**
	 * Deletes a post after the test, one written by the code under test.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 */
	protected function trackPost( int $postId ): void {
		$this->posts[] = $postId;
	}

	/**
	 * Returns a product bound for the first time to a new post, with a default variant, not yet stored.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $sku        Optional. The SKU. Default `SKU-1`.
	 * @param int|null $priceMinor Optional. The base-currency price, or null for none. Default 1999.
	 * @param string   $status     Optional. The post's status. Default `publish`.
	 * @return Product The product.
	 */
	protected function boundProduct( string $sku = 'SKU-1', ?int $priceMinor = 1999, string $status = 'publish' ): Product {
		$base    = Currency::of( self::BASE_CURRENCY );
		$product = Product::firstBinding(
			$this->ids->generate(),
			$this->post( $status ),
			Locale::of( self::LOCALE ),
			Variant::byDefault( $this->ids->generate(), Sku::of( $sku ) ),
			new \DateTimeImmutable( '2026-09-25 10:00:00', new \DateTimeZone( 'UTC' ) )
		);

		$product->applyCommerce( Sku::of( $sku ), null === $priceMinor ? null : VariantPrice::net( $base, $priceMinor ), 250, $base );

		return $product;
	}

	/**
	 * Stores a whole product as a successful save leaves it: bound, priced, `complete`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sku    Optional. The SKU. Default `SKU-1`.
	 * @param string $status Optional. The post's status. Default `publish`.
	 * @return Product The product as stored, with its ids.
	 */
	protected function storedProduct( string $sku = 'SKU-1', string $status = 'publish' ): Product {
		$product = $this->boundProduct( $sku, 1999, $status );

		$this->products->save( $product );
		$this->assertTrue( $this->products->leaveUpdating( (int) $product->id(), GenerationState::Complete ), 'The fixture product did not leave `updating`.' );

		return $product;
	}

	/**
	 * Returns the full name of a catalog table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name One of CatalogTables' names.
	 * @return string The prefixed name.
	 */
	protected function catalogTable( string $name ): string {
		return $this->db->table( $name );
	}

	/**
	 * Returns a product's row as the database holds it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return array<string, mixed>|null The row, or null.
	 */
	protected function productRow( int $productId ): ?array {
		return $this->db->fetchRow( 'SELECT * FROM %i WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), $productId );
	}
}
