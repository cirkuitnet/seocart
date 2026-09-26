<?php
/**
 * Tests the kernel's wiring of the order module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\ConversionContexts;
use SEOCart\Order\Domain\OrderNumberGenerator;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Order\Infrastructure\MysqlConversionContexts;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\SequenceOrderNumberGenerator;
use SEOCart\Order\Infrastructure\WordPressAccessKeys;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * The production container resolves each order port to its production class, and building them sends no query.
 *
 * The service's collaborators from other modules, the event publisher and the transaction
 * manager, are resolved before the count: the job queue behind the publisher reads its lock mode
 * when it is built, which is that module's cost, not the order module's.
 *
 * Planted violation, shown red and removed: in Modules::orderRegister(), leave out the
 * OrderNumberGenerator binding: the service cannot be built.
 *
 * @since 0.1.0
 */
final class OrderWiringTest extends DatabaseTestCase {

	/**
	 * Tests that each port resolves to its production class without a query.
	 *
	 * @since 0.1.0
	 */
	public function test_each_port_resolves_to_its_production_class_without_a_query(): void {
		$container = KernelContainer::build( $this->db, $this->reporter() );
		$resolved  = array();

		$container->get( EventPublisher::class );

		$log = $this->captureQueries(
			static function () use ( $container, &$resolved ): void {
				foreach ( array( OrderRepository::class, OrderNumberGenerator::class, AccessKeys::class, ConversionContexts::class, OrderStatusRegistry::class, Orders::class ) as $port ) {
					$resolved[ $port ] = get_class( $container->get( $port ) );
				}
			}
		);

		$this->assertSame(
			array(
				OrderRepository::class      => MysqlOrderRepository::class,
				OrderNumberGenerator::class => SequenceOrderNumberGenerator::class,
				AccessKeys::class           => WordPressAccessKeys::class,
				ConversionContexts::class   => MysqlConversionContexts::class,
				OrderStatusRegistry::class  => OrderStatusRegistry::class,
				Orders::class               => Orders::class,
			),
			$resolved
		);
		$this->assertQueryCount( 0, $log, 'Building the order module\'s services' );
	}
}
