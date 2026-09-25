<?php
/**
 * ProductWrites: the product write service as the integration tests and their probe build it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\Lifecycle\DeleteProduct;
use SEOCart\Catalog\Application\Lifecycle\DuplicateProduct;
use SEOCart\Catalog\Application\Lifecycle\PostLifecycle;
use SEOCart\Catalog\Application\Lifecycle\Reconciler;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Catalog\Infrastructure\WordPressPostGateway;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Localization\SiteLocale;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\KernelContainer;

/**
 * Builds SaveProduct and the product lifecycle over one connection from the production classes, the way the kernel does, with a test's reporter.
 *
 * Owns one fact: what a product-write test replaces in the production wiring. The reporter is
 * the test's, so a report is recorded instead of logged; the base currency is the catalog
 * tests' one, CatalogTestCase::BASE_CURRENCY, instead of the settings'; the gateway looks for
 * other plugins' listeners only when asked to; the outbox's wake is recorded instead of
 * queueing a drain; and the units of work run on the connection itself, because the test site
 * is not installed and its schema gate would refuse them. The stock service and everything else
 * come from the kernel's own bindings, over the given connection.
 *
 * The probe process a concurrency test starts (tests/Support/product-save-probe.php) builds
 * its service here too, and pauses at the barrier this class names.
 *
 * @since 0.1.0
 */
final class ProductWrites {

	/**
	 * The statement a paused probe waits in: a user lock the test holds until it lets the probe go on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BARRIER_WAIT = "SELECT GET_LOCK( 'seocart_test_save_barrier', 30 )";

	/**
	 * The statement that lets the barrier's lock go.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BARRIER_RELEASE = "SELECT RELEASE_LOCK( 'seocart_test_save_barrier' )";

	/**
	 * The statement that takes the barrier's lock without waiting.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BARRIER_HOLD = "SELECT GET_LOCK( 'seocart_test_save_barrier', 0 )";

	/**
	 * Builds the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db     The connection every part uses.
	 * @param callable $report Receives every report: a code (string) and its context (array).
	 * @param bool     $debug  Optional. Whether the gateway looks for other plugins' listeners, as under WP_DEBUG. Default false.
	 * @return SaveProduct The service.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public static function service( Database $db, callable $report, bool $debug = false ): SaveProduct {
		return self::services( $db, $report, $debug )->save;
	}

	/**
	 * Builds the product write and the product lifecycle over one connection, sharing one post gateway, one stock service and one publisher.
	 *
	 * The lifecycle's units of work run on the given transaction manager, the connection itself
	 * unless a test gives another, such as one that refuses as a closed schema gate does. The
	 * product write always runs on the connection.
	 *
	 * @since 0.1.0
	 *
	 * @param Database                $db           The connection every part uses.
	 * @param callable                $report       Receives every report: a code (string) and its context (array).
	 * @param bool                    $debug        Optional. Whether the gateway and the lifecycle report as under WP_DEBUG. Default false.
	 * @param TransactionManager|null $transactions Optional. The lifecycle's transaction manager. Default the connection.
	 * @return CatalogServices The services.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public static function services( Database $db, callable $report, bool $debug = false, ?TransactionManager $transactions = null ): CatalogServices {
		$base         = static fn(): Currency => Currency::of( CatalogTestCase::BASE_CURRENCY );
		$products     = new MysqlProductRepository( $db, $base );
		$posts        = new WordPressPostGateway( $db, $report, $debug );
		$transactions = $transactions ?? $db;
		$container    = KernelContainer::build(
			$db,
			$report,
			array(
				// The site under test is not installed, so its schema gate is closed: the services work on the connection itself.
				TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ),
				EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher(
					$c->get( TransactionManager::class ),
					$c->get( Outbox::class ),
					$c->get( HookBridge::class ),
					$c->get( EventCatalog::class ),
					$c->get( CorrelationId::class ),
					new RecordingWake()
				),
			)
		);

		$stock  = $container->get( StockService::class );
		$events = $container->get( EventPublisher::class );
		$clock  = $container->get( Clock::class );
		$ids    = $container->get( IdGenerator::class );
		$save   = new SaveProduct(
			$products,
			$posts,
			$stock,
			$db,
			array( $container->get( LockService::class ), 'withLock' ),
			$events,
			new Sellability( $products ),
			new SiteLocale(),
			$clock,
			$ids,
			$base,
			$report
		);

		$reconciler = new Reconciler( $products, $transactions, new SiteLocale(), $clock, $ids );
		$delete     = new DeleteProduct( $products, $stock, $transactions, $events, $clock );

		return new CatalogServices(
			$products,
			$posts,
			$stock,
			$save,
			$reconciler,
			$delete,
			new DuplicateProduct( $products, $save, $posts ),
			new PostLifecycle( $products, $reconciler, $delete, $stock, $transactions, $posts, $report, $debug )
		);
	}

	/**
	 * Returns the statement that marks a product `updating`, as the server receives it: the mark and the window's relock both send it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db        The connection whose table prefix applies.
	 * @param int      $productId The product.
	 * @return string The statement.
	 */
	public static function markStatement( Database $db, int $productId ): string {
		global $wpdb;

		return (string) $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The repository's constant SQL expression for the instant, the same text the repository sends.
			'UPDATE %i SET generation_state = %s, updated_at = ' . MysqlProductRepository::NEXT_INSTANT . ' WHERE id = %d',
			$db->table( CatalogTables::PRODUCTS ),
			GenerationState::Updating->value,
			$productId
		);
	}
}
