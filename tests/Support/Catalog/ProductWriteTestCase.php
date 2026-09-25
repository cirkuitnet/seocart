<?php
/**
 * ProductWriteTestCase: the base of the product write's integration tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\ProductWrite\CommerceInput;
use SEOCart\Catalog\Application\ProductWrite\ProductSave;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\RunningProbe;
use SEOCart\Tests\Support\SecondConnection;

/**
 * A CatalogTestCase with the outbox and stock tables, and SaveProduct and the product lifecycle built over the test's connection.
 *
 * Owns one fact: how a product-write test saves, forces a save to fail, and reads what was
 * committed. What was committed is read through a second connection, which sees nothing a
 * window has not committed. A post the service creates is deleted after the test. The product
 * lifecycle is built but not hooked: a test that wants it calls `$this->services->attach()`.
 *
 * @since 0.1.0
 */
abstract class ProductWriteTestCase extends CatalogTestCase {

	/**
	 * The message of the listener that fails a save.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const LISTENER_FAILED = 'A listener failed.';

	/**
	 * The service under test, over the test's connection, with strict guards.
	 *
	 * @since 0.1.0
	 *
	 * @var SaveProduct
	 */
	protected SaveProduct $service;

	/**
	 * The product write and the product lifecycle over the test's connection; `$service` is its product write.
	 *
	 * @since 0.1.0
	 *
	 * @var CatalogServices
	 */
	protected CatalogServices $services;

	/**
	 * Creates the outbox and stock tables, and builds the services.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $operations );
		( new CreateStockTablesMigration() )->up( $operations );

		$this->services = ProductWrites::services( $this->db, $this->reporter(), false, null, $this->locales() );
		$this->service  = $this->services->save;
	}

	/**
	 * Returns the locale port the services are built over: null for the site's one locale, the production default.
	 *
	 * A test of several languages returns its own, before the services are built.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales|null The port, or null.
	 */
	protected function locales(): ?PostLocales {
		return null;
	}

	/**
	 * Saves through a service, and deletes a post it created after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null                  $postId    The post, or null for a new one.
	 * @param array<string, mixed>      $editorial Optional. The post's fields. Default none.
	 * @param array<string, mixed>|null $commerce  Optional. The commerce fields, or null for none. Default null.
	 * @param SaveProduct|null          $service   Optional. The service. Default the one over the test's connection.
	 * @return SaveResult The result.
	 */
	protected function save( ?int $postId, array $editorial = array(), ?array $commerce = null, ?SaveProduct $service = null ): SaveResult {
		$result = ( $service ?? $this->service )->save( new ProductSave( $postId, $editorial, null === $commerce ? null : CommerceInput::fromArray( $commerce ), Actor::user( 1 ) ) );

		if ( $result->postCreated ) {
			$this->trackPost( $result->postId );
		}

		return $result;
	}

	/**
	 * Saves a new published product, whole: a title, a SKU and a price in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sku   Optional. The SKU. Default `SKU-1`.
	 * @param string $title Optional. The title. Default `Saved product`.
	 * @return SaveResult The result: the product is complete.
	 */
	protected function savedProduct( string $sku = 'SKU-1', string $title = 'Saved product' ): SaveResult {
		return $this->save(
			null,
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			array(
				'sku'         => $sku,
				'price_minor' => 1999,
			)
		);
	}

	/**
	 * Runs a save that must fail, and returns what it threw.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $save The save.
	 * @return \Throwable What it threw.
	 */
	protected function saveFailure( callable $save ): \Throwable {
		try {
			$save();
		} catch ( \Throwable $failure ) {
			$this->assertSame( 0, $this->db->depth(), 'The failed save left a transaction open.' );

			return $failure;
		}

		$this->fail( 'The save succeeded.' );
	}

	/**
	 * Fails every product save from inside WordPress's post write, as another plugin's listener would.
	 *
	 * @since 0.1.0
	 *
	 * @param callable|null $first Optional. Runs in the listener first, with the post's id. Default none.
	 */
	protected function failInsideThePostWrite( ?callable $first = null ): void {
		add_action(
			'save_post_' . ProductCapabilities::POST_TYPE,
			static function ( $postId ) use ( $first ): void {
				if ( null !== $first ) {
					$first( (int) $postId );
				}

				throw new \RuntimeException( self::LISTENER_FAILED );
			}
		);
	}

	/**
	 * Returns a product's committed marker and the instant it was written, as a second connection reads them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $productId The product.
	 * @return array{0: string|null, 1: string|null} The marker and `updated_at`.
	 *
	 * @phpstan-impure
	 */
	protected function committedMarker( SecondConnection $b, int $productId ): array {
		$row = $b->fetchRow( sprintf( 'SELECT generation_state, updated_at FROM `%s` WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), $productId ) );

		return array( $row['generation_state'] ?? null, $row['updated_at'] ?? null );
	}

	/**
	 * Returns what a second connection reads of a saved product: its post's title, its SKU and its base price.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param SaveResult       $saved     The save that wrote it.
	 * @return array{0: string|null, 1: string|null, 2: string|null} The title, the SKU and the price in minor units.
	 *
	 * @phpstan-impure
	 */
	protected function committedProduct( SecondConnection $b, SaveResult $saved ): array {
		global $wpdb;

		return array(
			$b->fetchValue( sprintf( 'SELECT post_title FROM `%s` WHERE ID = %d', $wpdb->posts, $saved->postId ) ),
			$b->fetchValue( sprintf( 'SELECT sku FROM `%s` WHERE id = %d', $this->db->table( CatalogTables::VARIANTS ), (int) $saved->variantId ) ),
			$b->fetchValue( sprintf( "SELECT price_minor FROM `%s` WHERE variant_id = %d AND currency = '%s'", $this->db->table( CatalogTables::VARIANT_PRICES ), (int) $saved->variantId, self::BASE_CURRENCY ) ),
		);
	}

	/**
	 * Counts committed rows of a plugin table, as a second connection reads them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     The second connection.
	 * @param string           $table The table's name without its prefix, from CatalogTables, InventoryTables or OutboxTable.
	 * @param string           $where Optional. A condition. Default all rows.
	 * @return int The count.
	 *
	 * @phpstan-impure
	 */
	protected function committedCount( SecondConnection $b, string $table, string $where = '1 = 1' ): int {
		return (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE %s', $this->db->table( $table ), $where ) );
	}

	/**
	 * Reads the checksum of each of the four catalog tables, as a second connection sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b The second connection.
	 * @return array<string, string> The checksum of each table, by name.
	 *
	 * @phpstan-impure
	 */
	protected function catalogChecksums( SecondConnection $b ): array {
		$sums = array();

		foreach ( array( CatalogTables::PRODUCTS, CatalogTables::PRODUCT_POSTS, CatalogTables::VARIANTS, CatalogTables::VARIANT_PRICES ) as $table ) {
			$sums[ $table ] = (string) ( $b->fetchRow( sprintf( 'CHECKSUM TABLE `%s`', $this->db->table( $table ) ) )['Checksum'] ?? '' );
		}

		return $sums;
	}

	/**
	 * Counts the committed ProductSaved outbox rows of a product.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $productId The product.
	 * @return int The count.
	 *
	 * @phpstan-impure
	 */
	protected function committedSaves( SecondConnection $b, int $productId ): int {
		return $this->committedCount( $b, OutboxTable::NAME, sprintf( "event_name = '%s' AND aggregate_id = %d", ProductSaved::eventName(), $productId ) );
	}

	/**
	 * Counts the committed stock items of a variant.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return int The count.
	 *
	 * @phpstan-impure
	 */
	protected function committedStockItems( SecondConnection $b, int $variantId ): int {
		return $this->committedCount( $b, InventoryTables::ITEMS, sprintf( 'variant_id = %d AND on_hand = 0', $variantId ) );
	}

	/**
	 * Returns the verdict a shopper gets for a variant, read now.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant.
	 * @return SellabilityReason The verdict.
	 */
	protected function verdict( int $variantId ): SellabilityReason {
		return ( new Sellability( $this->products ) )->of( array( $variantId ), false )[ $variantId ];
	}

	/**
	 * Starts a save of a post's title in a process of its own; see tests/Support/product-save-probe.php.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId The post.
	 * @param string $title  The new title.
	 * @param string $ending How the save ends: `saves`, `loses` or `crashes`.
	 * @param bool   $pause  Whether it waits at the barrier before its window's first statement.
	 * @return RunningProbe The probe.
	 */
	protected function saveElsewhere( int $postId, string $title, string $ending, bool $pause ): RunningProbe {
		return ChildProcessProbe::start( dirname( __DIR__ ) . '/product-save-probe.php', array( (string) $postId, $title, $ending, $pause ? 'pause' : 'go' ) );
	}
}
