<?php
/**
 * SeedVerifier: checks a seeded store with what checks a real one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Seed;

use SEOCart\Catalog\Application\Doctor\ProductSettler;
use SEOCart\Catalog\Application\Lifecycle\Reconciler;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\Doctor\CatalogChecks;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Localization\SiteLocale;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Currency;
use SEOCart\Support\SystemClock;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * Checks a seeded store: doctor must pass, and the sellability query must sell every seeded variant.
 *
 * Owns one fact: when a seeded store is sound. A seed writes its rows directly, so nothing it
 * writes is trusted: doctor runs every check it runs on a real store, and every seeded product
 * is complete and published, so the sellability query must answer `sellable` for each of its
 * variants. doctor() builds the same checks as the kernel's doctor, over the Database it is
 * given, and a test holds the two lists equal, so a check a module adds to doctor checks a
 * seeded store too. Until doctor checks the catalog, a seeded variant without its
 * base-currency price is found by the sellability query.
 *
 * @since 0.1.0
 */
final class SeedVerifier {

	/**
	 * The most variants one sellability query asks about.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const BATCH = 1000;

	/**
	 * Installs the plugin's schema on the current site, as activation installs it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db     The connection.
	 * @param callable $report Receives what the migrator reports: a code and its context.
	 */
	public static function installSchema( Database $db, callable $report ): void {
		self::migrator( $db, $report )->migrate( new MigrationRunOptions( 0 ) );
	}

	/**
	 * Returns what is wrong with a seeded store: each finding of doctor, and each seeded variant that may not be sold.
	 *
	 * Doctor's runner check needs a runner that checked in recently; the caller records one.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db           The connection; every query it sends is one a real store sends.
	 * @param callable $report       Receives what the parts report: a code and its context.
	 * @param string   $baseCurrency The store's base currency.
	 * @param int      $variants     How many variants the seed wrote: ids 1 to this.
	 * @return list<string> One line per problem; empty for a sound store.
	 */
	public static function problems( Database $db, callable $report, string $baseCurrency, int $variants ): array {
		$problems = array();

		foreach ( self::doctor( $db, $report, $baseCurrency )->run() as $result ) {
			if ( ! $result->passed ) {
				$problems[] = sprintf( 'doctor %s: %s %s', $result->check, $result->summary, implode( ' ', $result->findings ) );
			}
		}

		$sellability = new Sellability( new MysqlProductRepository( $db, static fn(): Currency => Currency::of( $baseCurrency ) ) );

		foreach ( array_chunk( range( 1, $variants ), self::BATCH ) as $ids ) {
			foreach ( $sellability->of( $ids, false ) as $variant => $verdict ) {
				if ( SellabilityReason::Sellable !== $verdict ) {
					$problems[] = sprintf( 'variant %d may not be sold: %s', $variant, $verdict->value );
				}
			}
		}

		return $problems;
	}

	/**
	 * Builds doctor with the checks the kernel gives it, over a Database of the caller's.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db           The connection every check reads through.
	 * @param callable $report       Receives what the parts report: a code and its context.
	 * @param string   $baseCurrency Optional. The store's base currency, for the catalog's checks. Default `USD`.
	 * @return Doctor The doctor.
	 */
	public static function doctor( Database $db, callable $report, string $baseCurrency = 'USD' ): Doctor {
		$products   = new MysqlProductRepository( $db, static fn(): Currency => Currency::of( $baseCurrency ) );
		$stock      = new StockService(
			new MysqlStockRepository( $db ),
			$db,
			new RecordingEventPublisher( $db ),
			new SequentialIdGenerator( 950000 ),
			new SystemClock(),
			new CorrelationId( new SequentialIdGenerator( 960000 ) ),
			new Authorizer( new CapabilityDeclaration() )
		);
		$locales    = new SiteLocale();
		$reconciler = new Reconciler( $products, $db, $locales, new SystemClock(), new SequentialIdGenerator( 970000 ) );
		$settler    = new ProductSettler( $products, $stock, $db, array( new LockService( $db, LockMode::GetLock ), 'withLock' ) );

		return new Doctor(
			$db,
			OwnedData::registry(),
			self::migrator( $db, $report ),
			new Outbox( $db ),
			new ActionSchedulerQueue( $db, new LockService( $db, LockMode::Table ), new JobHandlers( JobHandlers::PRODUCTION, 'strval' ), new CorrelationId( new SequentialIdGenerator() ), $report ),
			new StockProjectionCheck( new MysqlStockRepository( $db ) ),
			...( new CatalogChecks( $products, $stock, $reconciler, $settler, new SystemClock(), $db, static fn(): Currency => Currency::of( $baseCurrency ), array( new LockService( $db, LockMode::GetLock ), 'withLock' ) ) )->checks()
		);
	}

	/**
	 * Builds the migrator of the plugin's schema.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db     The connection.
	 * @param callable $report Receives what the migrator reports.
	 * @return Migrator The migrator.
	 */
	private static function migrator( Database $db, callable $report ): Migrator {
		return new Migrator( $db, new LockService( $db, LockMode::Table ), new MigrationsTableState( $db ), OwnedData::registry()->migrations(), new SystemClock(), $report );
	}
}
