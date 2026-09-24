<?php
/**
 * Tests the catalog's wiring in the kernel: what each port resolves to, and that resolving costs nothing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Catalog\Infrastructure\WordPressPostGateway;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Localization\SiteLocale;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * The production container resolves each catalog port to its production class, and building them
 * sends no query: the repository and the product write read the base currency when they first
 * need it. The product write's collaborators from other modules, the stock service and the event
 * publisher, are resolved before the count: the job queue behind the publisher reads its lock
 * mode when it is built, which is that module's cost, not the catalog's.
 *
 * Planted violation: in Modules::baseCurrency(), read the setting when the reader is built
 * (`$base = Currency::of( ... );`, then `return static fn(): Currency => $base;`): resolving the
 * repository or the product write sends the settings query, and the test fails.
 *
 * @since 0.1.0
 */
final class CatalogWiringTest extends DatabaseTestCase {

	/**
	 * Tests that each port resolves to its production class without a query.
	 *
	 * @since 0.1.0
	 */
	public function test_each_port_resolves_to_its_production_class_without_a_query(): void {
		$container = KernelContainer::build( $this->db, $this->reporter() );
		$resolved  = array();

		$container->get( StockService::class );
		$container->get( EventPublisher::class );

		$log = $this->captureQueries(
			static function () use ( $container, &$resolved ): void {
				foreach ( array( ProductRepository::class, Sellability::class, PostGateway::class, PostLocales::class, SaveProduct::class ) as $port ) {
					$resolved[ $port ] = get_class( $container->get( $port ) );
				}
			}
		);

		$this->assertSame(
			array(
				ProductRepository::class => MysqlProductRepository::class,
				Sellability::class       => Sellability::class,
				PostGateway::class       => WordPressPostGateway::class,
				PostLocales::class       => SiteLocale::class,
				SaveProduct::class       => SaveProduct::class,
			),
			$resolved
		);
		$this->assertQueryCount( 0, $log, 'Building the catalog\'s services' );
	}
}
