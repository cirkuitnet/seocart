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

use SEOCart\Inventory\Application\Availability;
use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\LocationRepository;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Inventory\Infrastructure\MysqlTransferRepository;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Jobs\JobQueue;
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
		$mode    ??= static fn(): FulfilmentMode => FulfilmentMode::Split;
		$directory = new LocationDirectory( $locations );
		$routing   = new FulfilmentRouting( $directory, $stock, $mode );

		return new StockService( $stock, $tx, $events, $ids, $clock, $correlation, new Authorizer( new CapabilityDeclaration() ), $directory, $routing, new Availability( $directory, $stock, $mode ) );
	}

	/**
	 * Builds the locations service over a connection and the MySQL repositories, as the plugin wires it.
	 *
	 * @since 0.2.0
	 *
	 * @param Database    $db        The connection, which is also the unit of work.
	 * @param IdGenerator $ids       Mints new locations' public identifiers.
	 * @param Transfers   $transfers Moves a deactivated location's stock.
	 * @return Locations The service.
	 */
	public static function locations( Database $db, IdGenerator $ids, Transfers $transfers ): Locations {
		return new Locations( new MysqlLocationRepository( $db ), new MysqlStockRepository( $db ), $db, $ids, new Authorizer( new CapabilityDeclaration() ), $transfers );
	}

	/**
	 * Builds the transfer service over a connection and the MySQL repositories, as the plugin wires it.
	 *
	 * @since 0.2.0
	 *
	 * @param Database       $db          The connection, which is also the unit of work.
	 * @param EventPublisher $events      Publishes the events.
	 * @param IdGenerator    $ids         Mints transfers' public identifiers.
	 * @param Clock          $clock       Says when an event happened.
	 * @param CorrelationId  $correlation The request's correlation id.
	 * @param JobQueue       $queue       Queues a deactivation's move.
	 * @return Transfers The service.
	 */
	public static function transfers( Database $db, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation, JobQueue $queue ): Transfers {
		$locations = new MysqlLocationRepository( $db );

		return new Transfers( new MysqlTransferRepository( $db ), new MysqlStockRepository( $db ), $locations, new LocationDirectory( $locations ), $db, $events, $ids, $clock, $correlation, new Authorizer( new CapabilityDeclaration() ), $queue );
	}

	/**
	 * Builds the stock check of doctor over a connection.
	 *
	 * @since 0.2.0
	 *
	 * @param Database      $db          The connection.
	 * @param StockService  $service     The service its repair writes through.
	 * @param \Closure|null $orderStatus Optional. Returns an order's status from its id, or null. Default null: no order exists.
	 * @param int           $warningDays Optional. The days a transfer may stay requested or in transit. Default 3, the setting's default.
	 * @return StockProjectionCheck The check.
	 *
	 * @phpstan-param (\Closure(int): ?string)|null $orderStatus
	 */
	public static function check( Database $db, StockService $service, ?\Closure $orderStatus = null, int $warningDays = 3 ): StockProjectionCheck {
		$locations = new MysqlLocationRepository( $db );

		return new StockProjectionCheck( new MysqlStockRepository( $db ), $locations, new LocationDirectory( $locations ), $service, $db, new MysqlTransferRepository( $db ), static fn(): int => $warningDays, $orderStatus ?? static fn(): ?string => null );
	}
}
