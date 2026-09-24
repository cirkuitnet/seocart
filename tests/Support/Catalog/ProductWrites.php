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
 * Builds SaveProduct over one connection from the production classes, the way the kernel does, with a test's reporter.
 *
 * Owns one fact: what a product-write test replaces in the production wiring. The reporter is
 * the test's, so a report is recorded instead of logged; the base currency is the catalog
 * tests' one, CatalogTestCase::BASE_CURRENCY, instead of the settings'; the gateway looks for
 * other plugins' listeners only when asked to; and the outbox's wake is recorded instead of
 * queueing a drain. The stock service and everything else come from the kernel's own
 * bindings, over the given connection.
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
		$base      = static fn(): Currency => Currency::of( CatalogTestCase::BASE_CURRENCY );
		$products  = new MysqlProductRepository( $db, $base );
		$container = KernelContainer::build(
			$db,
			$report,
			array(
				EventPublisher::class => static fn( Container $c ): EventPublisher => new Publisher(
					$c->get( TransactionManager::class ),
					$c->get( Outbox::class ),
					$c->get( HookBridge::class ),
					$c->get( EventCatalog::class ),
					$c->get( CorrelationId::class ),
					new RecordingWake()
				),
			)
		);

		return new SaveProduct(
			$products,
			new WordPressPostGateway( $db, $report, $debug ),
			$container->get( StockService::class ),
			$db,
			array( $container->get( LockService::class ), 'withLock' ),
			$container->get( EventPublisher::class ),
			new Sellability( $products ),
			new SiteLocale(),
			$container->get( Clock::class ),
			$container->get( IdGenerator::class ),
			$base,
			$report
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
