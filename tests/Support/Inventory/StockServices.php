<?php
/**
 * StockServices: the stock service and its doctor check for a test, wired as the plugin wires them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Inventory;

use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\LocationRepository;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\IdGenerator;

/**
 * Builds the stock service, and the stock check of doctor, the one way every test that needs them does.
 *
 * The service's collaborators are the plugin's own: the location directory over the location
 * repository, and routing over the directory and the stock repository. Only the store's choice
 * for orders no one location ships whole is the caller's, read on each routing, so a test can
 * change it between calls.
 *
 * @since 0.2.0
 */
final class StockServices {

	/**
	 * Builds the stock service over a connection and the MySQL repositories.
	 *
	 * @since 0.2.0
	 *
	 * @param Database       $db          The connection, which is also the unit of work.
	 * @param EventPublisher $events      Publishes the events.
	 * @param IdGenerator    $ids         Mints hold ids and reclaim tokens: a second runner needs its own range.
	 * @param Clock          $clock       Says when an event happened.
	 * @param CorrelationId  $correlation The request's correlation id.
	 * @param \Closure|null  $mode        Optional. The store's choice. Default `split`.
	 * @return StockService The service.
	 *
	 * @phpstan-param (\Closure(): FulfilmentMode)|null $mode
	 */
	public static function over( Database $db, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation, ?\Closure $mode = null ): StockService {
		return self::of( new MysqlStockRepository( $db ), new MysqlLocationRepository( $db ), $db, $events, $ids, $clock, $correlation, $mode );
	}

	/**
	 * Builds the stock service over the repositories given, real or doubles.
	 *
	 * @since 0.2.0
	 *
	 * @param StockRepository    $stock       The stock repository.
	 * @param LocationRepository $locations   The location repository.
	 * @param TransactionManager $tx          The unit of work.
	 * @param EventPublisher     $events      Publishes the events.
	 * @param IdGenerator        $ids         Mints hold ids and reclaim tokens.
	 * @param Clock              $clock       Says when an event happened.
	 * @param CorrelationId      $correlation The request's correlation id.
	 * @param \Closure|null      $mode        Optional. The store's choice. Default `split`.
	 * @return StockService The service.
	 *
	 * @phpstan-param (\Closure(): FulfilmentMode)|null $mode
	 */
	public static function of( StockRepository $stock, LocationRepository $locations, TransactionManager $tx, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation, ?\Closure $mode = null ): StockService {
		$directory = new LocationDirectory( $locations );
		$routing   = new FulfilmentRouting( $directory, $stock, $mode ?? static fn(): FulfilmentMode => FulfilmentMode::Split );

		return new StockService( $stock, $tx, $events, $ids, $clock, $correlation, new Authorizer( new CapabilityDeclaration() ), $directory, $routing );
	}

	/**
	 * Builds the stock check of doctor over a connection.
	 *
	 * @since 0.2.0
	 *
	 * @param Database     $db      The connection.
	 * @param StockService $service The service its repair writes through.
	 * @return StockProjectionCheck The check.
	 */
	public static function check( Database $db, StockService $service ): StockProjectionCheck {
		$locations = new MysqlLocationRepository( $db );

		return new StockProjectionCheck( new MysqlStockRepository( $db ), $locations, new LocationDirectory( $locations ), $service, $db );
	}
}
