<?php
/**
 * Tests the price projection: the rows a save and a rate change write, through the production wiring
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Lifecycle\DeleteProduct;
use SEOCart\Catalog\Application\Projection\CurrencyTerms;
use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Jobs\RepriceProjections;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\Cli\OutboxCommand;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Domain\Event\ExchangeRateVersionChanged;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Doubles\RecordingJobQueue;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Inventory\StockTables;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plants prices directly, as a merchant's screens will write them, and reads the projection back as the database holds it.

/**
 * `product_price_projections` holds, for each product and each currency the store sells in, the price its listed variant sorts by, written after each save and repriced after each new exchange-rate version, through the kernel's own wiring.
 *
 * The store sells in USD, its base currency, and in EUR, whose base prices are converted with a
 * cash step of 5, so the cash step shows in a converted figure. A converted figure is the unit
 * price a cart in EUR gives the same variant (PriceResolver), since both convert through
 * PresentmentCurrency::convert().
 *
 * Planted violations, each shown red and removed:
 * - in PricingCurrencyTerms::convert(), convert without the cash step
 *   (`$terms->context->convertToQuoteMoney( $base, $terms->roundingRule->roundingMode() )`): the
 *   EUR row is 1824 where a cart prices 1825;
 * - in PriceProjector::project(), rewrite every row whether or not it differs (drop
 *   `self::differs( $old, $row ) &&`): the second projection sends an UPDATE per row, though the
 *   guard in UPDATE_PROJECTION still changes none; with the guard dropped too, it rewrites both;
 *   and the repricing job rewrites the authored EUR row, whose instant then moves;
 * - in MysqlPriceProjections::insert(), also touch the product's row (`UPDATE {products} SET
 *   updated_at = UTC_TIMESTAMP(6) WHERE id = %d`): the product's instant moves;
 * - in DeleteProduct::delete(), leave out the projections' cascade: the deleted product's rows stay.
 *
 * @since 0.2.0
 */
final class PriceProjectionTest extends CatalogTestCase {

	use PricesInCurrencies;

	/**
	 * The rate from USD to EUR of the first version.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EUR_RATE = '0.91230';

	/**
	 * The kernel's container, as a request that publishes builds it.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The queue the kernel queues jobs on.
	 *
	 * @since 0.2.0
	 *
	 * @var RecordingJobQueue
	 */
	private RecordingJobQueue $queue;

	/**
	 * Creates the outbox, the log, the rate and the stock tables beside the catalog's, plants an installation record and wires the kernel, with EUR sold by conversion.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$schema = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $schema );
		( new CreateLogsMigration() )->up( $schema );
		$this->createRateTables();
		StockTables::create( $this->db );
		$this->plantBootRecord();
		$this->enableCurrency( 'EUR', true, RoundingMode::HalfUp, 5 );

		$this->queue  = new RecordingJobQueue( $this->db );
		$this->kernel = $this->kernel();
	}

	/**
	 * Puts back the installation record.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that a save projects the base price as authored, and a converted price equal to the unit price a cart in the currency gives the variant.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When EUR is not offered, which set_up() makes it.
	 */
	public function test_a_save_projects_the_base_price_and_the_converted_price_a_cart_shows(): void {
		$version = $this->saveRates( array( 'EUR' => self::EUR_RATE ) );
		$product = $this->product( 'PRJ-1', 1999 );

		$this->assertSame( array(), $this->rows( $product ), 'A product saved before the projection has no row.' );

		$this->saved( $product );
		$this->drain();

		$cart = ( new PriceResolver( $this->products ) )->resolve(
			array( new LineRequest( 'line', (int) $product->defaultVariant()?->id(), 1 ) ),
			$this->kernel->get( PresentmentCurrencies::class )->find( Currency::of( self::BASE_CURRENCY ), Currency::of( 'EUR' ) ) ?? throw new \LogicException( 'EUR is offered.' )
		);

		$this->assertSame( 1825, $cart->lines[0]->unitPrice->amount->minorUnits(), '1999 at 0.9123 is 1823.69, 1824 once rounded, 1825 at the cash step.' );
		$this->assertSame(
			array(
				'EUR' => array( $cart->lines[0]->unitPrice->amount->minorUnits(), 'net', 0, $version ),
				'USD' => array( 1999, 'net', 1, null ),
			),
			$this->rows( $product ),
			'The base price as authored, and the converted price at the version the cart prices at.'
		);
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a price authored in a currency is its row, and a currency that sells no converted price has none.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authored_price_wins_and_a_currency_without_conversion_has_no_row(): void {
		$this->enableCurrency( 'GBP', false );
		$this->saveRates(
			array(
				'EUR' => self::EUR_RATE,
				'GBP' => '0.79',
			)
		);

		$product = $this->product( 'PRJ-2', 1999 );

		$this->authorPrice( $product, 'EUR', 1750, 'gross' );
		$this->saved( $product );
		$this->drain();

		$this->assertSame(
			array(
				'EUR' => array( 1750, 'gross', 1, null ),
				'USD' => array( 1999, 'net', 1, null ),
			),
			$this->rows( $product ),
			'The authored EUR price, as authored; GBP sells no converted price, so the product is in no GBP price order.'
		);
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that the rows follow a price change, go when the base price is removed and come back with it, and go with the product.
	 *
	 * @since 0.2.0
	 */
	public function test_the_rows_follow_the_price_and_go_with_the_product(): void {
		$version = $this->saveRates( array( 'EUR' => self::EUR_RATE ) );
		$product = $this->product( 'PRJ-3', 1999 );
		$prices  = $this->db->table( CatalogTables::VARIANT_PRICES );
		$variant = (int) $product->defaultVariant()?->id();

		$this->saved( $product );
		$this->drain();
		$this->assertCount( 2, $this->rows( $product ) );

		$this->db->execute( 'UPDATE %i SET price_minor = 1000 WHERE variant_id = %d AND currency = %s', $prices, $variant, self::BASE_CURRENCY );
		$this->saved( $product );
		$this->drain();

		$this->assertSame(
			array(
				'EUR' => array( 910, 'net', 0, $version ),
				'USD' => array( 1000, 'net', 1, null ),
			),
			$this->rows( $product ),
			'A new base price moves both rows: 1000 at 0.9123 is 912.3, 912 once rounded, 910 at the cash step.'
		);

		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $prices, $variant );
		$this->saved( $product );
		$this->drain();

		$this->assertSame( array(), $this->rows( $product ), 'A variant without a base price gives its product no row.' );

		$this->authorPrice( $product, self::BASE_CURRENCY, 1999, 'net' );
		$this->saved( $product );
		$this->drain();
		$this->assertCount( 2, $this->rows( $product ) );

		$this->kernel->get( DeleteProduct::class )->delete( (int) $product->id(), Actor::user( 1 ) );

		$this->assertSame( array(), $this->rows( $product ), 'Deleting the product deletes its rows in the same transaction.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a second projection writes nothing, and that neither leaves a trace on the product's row: its marker's instant stays, byte for byte.
	 *
	 * @since 0.2.0
	 */
	public function test_a_second_projection_writes_nothing_and_never_touches_the_product(): void {
		$this->saveRates( array( 'EUR' => self::EUR_RATE ) );

		$product   = $this->product( 'PRJ-4', 1999 );
		$productId = (int) $product->id();
		$before    = (string) $this->productRow( $productId )['updated_at'];
		$projector = $this->kernel->get( PriceProjector::class );

		$this->assertSame( 2, $projector->project( array( $productId ) ), 'The first projection inserts both rows.' );

		$written = null;
		$log     = $this->captureQueries(
			static function () use ( $projector, $productId, &$written ): void {
				$written = $projector->project( array( $productId ) );
			}
		);

		$this->assertSame( 0, $written, 'The second projection writes no row.' );
		$this->assertQueryCount( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE' ), 'Writes of a second projection' );
		$this->assertSame( 2, $log->count(), 'A projection reads the variants with their prices, then the stored rows: ' . $log->describe() );
		$this->assertSame( $before, (string) $this->productRow( $productId )['updated_at'], 'The product\'s row is never written.' );
	}

	/**
	 * Tests that a rate save stores its event in its own transaction, and that its delivery queues one repricing for the version, whatever the number of deliveries.
	 *
	 * @since 0.2.0
	 */
	public function test_a_rate_save_stores_its_event_and_queues_one_repricing_per_version(): void {
		$first  = $this->saveRates( array( 'EUR' => self::EUR_RATE ) );
		$stored = $this->db->fetchAll( 'SELECT payload_json FROM %i WHERE event_name = %s ORDER BY id', $this->db->table( 'outbox' ), ExchangeRateVersionChanged::eventName() );

		$this->assertCount( 1, $stored, 'The save stored one event.' );
		$this->assertSame( array( 'version' => $first ), Outbox::decode( (string) $stored[0]['payload_json'] )['p'] );
		$this->assertSame( PriceProjector::RATE_VERSION_CHANGED, ExchangeRateVersionChanged::eventName(), 'The projector names the event by its name.' );

		$this->drain();

		$this->assertSame( array( array( RepriceProjections::name(), array( 'rate_version' => $first ), 'rate_version:' . $first ) ), $this->queued() );

		// The outbox delivers at least once: the same version delivered again queues nothing more.
		$this->kernel->get( PriceProjector::class )->on( new ExchangeRateVersionChanged( $first, new \DateTimeImmutable() ), EventEnvelope::inMemory( new ExchangeRateVersionChanged( $first, new \DateTimeImmutable() ), 'again' ) );
		$this->assertCount( 1, $this->queued() );

		$second = $this->saveRates( array( 'EUR' => '0.95' ) );

		$this->drain();

		$this->assertSame( array( 'rate_version:' . $first, 'rate_version:' . $second ), array_column( $this->queued(), 2 ), 'A new version queues a repricing of its own.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that the repricing job rewrites converted prices only, waits for a drain at work, and ends only when a page finds nothing behind the version read again.
	 *
	 * @since 0.2.0
	 */
	public function test_the_job_reprices_converted_rows_only_until_none_is_behind(): void {
		// The authored product sells in GBP by conversion too, so the job's pages hold it with its authored EUR row.
		$this->enableCurrency( 'GBP' );

		$first     = $this->saveRates(
			array(
				'EUR' => self::EUR_RATE,
				'GBP' => '0.79',
			)
		);
		$converted = $this->product( 'PRJ-5', 1999 );
		$authored  = $this->product( 'PRJ-6', 1999 );

		$this->authorPrice( $authored, 'EUR', 1750, 'net' );
		$this->saved( $converted );
		$this->saved( $authored );
		$this->drain();

		$authoredAt = $this->updatedAt( $authored, 'EUR' );
		$second     = $this->saveRates(
			array(
				'EUR' => '0.95',
				'GBP' => '0.80',
			)
		);
		$job        = new RepriceProjections( $this->kernel->get( PriceProjections::class ), $this->kernel->get( PriceProjector::class ), $this->kernel->get( CurrencyTerms::class ), array( $this->kernel->get( LockService::class ), 'withLock' ), 0 );

		$this->assertSame( array( 1825, 'net', 0, $first ), $this->rows( $converted )['EUR'], 'Before the job, the row is at the first version.' );

		// A drain at work in another process holds the drainer's lock: the page waits for none, and asks to run again.
		$other = new SecondDatabase( $this->reporter() );
		$held  = $this->kernel( $other->db() )->get( LockService::class )->acquire( OutboxDrainer::LOCK_NAME, 60, 0 );

		try {
			$this->assertSame( RepriceProjections::AGAIN_AFTER_SECONDS, $job->handle( array( 'rate_version' => $second ) ), 'A locked page runs again a moment later.' );
			$this->assertSame( $first, $this->rows( $converted )['EUR'][3], 'The locked page wrote nothing.' );
		} finally {
			$held->release();
			$other->close();
		}

		$this->assertSame( RepriceProjections::AGAIN_AFTER_SECONDS, $job->handle( array( 'rate_version' => $second ) ), 'With no time left after a page, the run asks to go on.' );
		$this->assertSame( array( 1900, 'net', 0, $second ), $this->rows( $converted )['EUR'], '1999 at 0.95 is 1899.05, 1899 once rounded, 1900 at the cash step.' );

		// A version saved while the job runs: the next page reads it, and finds the row behind it again.
		$third = $this->saveRates(
			array(
				'EUR' => '0.90',
				'GBP' => '0.81',
			)
		);

		$this->assertSame( RepriceProjections::AGAIN_AFTER_SECONDS, $job->handle( array( 'rate_version' => $second ) ), 'The page reads the current version again.' );
		$this->assertSame( array( 1800, 'net', 0, $third ), $this->rows( $converted )['EUR'], '1999 at 0.90 is 1799.1, 1799 once rounded, 1800 at the cash step.' );
		$this->assertNull( $job->handle( array( 'rate_version' => $second ) ), 'A page that finds nothing behind the version ends the run.' );

		$this->assertSame( array( 1750, 'net', 1, null ), $this->rows( $authored )['EUR'], 'An authored price is never repriced.' );
		$this->assertSame( $third, $this->rows( $authored )['GBP'][3], 'The same product\'s converted price is.' );
		$this->assertSame( $authoredAt, $this->updatedAt( $authored, 'EUR' ), 'The authored row was not rewritten.' );
		$this->assertSame( array( 1999, 'net', 1, null ), $this->rows( $converted )['USD'] );
	}

	/**
	 * Tests that a save stored by one process is projected by a drain in another that published nothing, through `wp seocart outbox drain`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_drain_in_a_process_that_published_nothing_projects(): void {
		$product = $this->product( 'PRJ-7', 1999 );

		$this->saved( $product );

		// The publishing request ends here: whatever it put on the plugin's actions ends with it.
		self::forgetListeners();

		$draining = $this->kernel();
		$lines    = array();
		$command  = new OutboxCommand(
			$draining->get( OutboxDrainer::class ),
			$draining->get( Outbox::class ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->assertSame( OutboxCommand::EXIT_OK, $command->run( array( 'drain' ), array() ), implode( "\n", $lines ) );
		$this->assertSame( array( 'USD' => array( 1999, 'net', 1, null ) ), $this->rows( $product ), 'The drain delivered the save to the projector: ' . implode( "\n", $lines ) );
		$this->assertNoListenerFailed();
	}

	/**
	 * Stores a complete product with a base price and its stock item.
	 *
	 * Its post is written without `wp_after_insert_post`, as the test plants an installation record and
	 * the plugin's own post lifecycle would otherwise bind the post to a product of its own first.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sku        The SKU.
	 * @param int    $priceMinor The base price, in cents.
	 * @return Product The product.
	 */
	private function product( string $sku, int $priceMinor ): Product {
		$base    = Currency::of( self::BASE_CURRENCY );
		$product = Product::firstBinding( $this->ids->generate(), $this->unboundPost(), Locale::of( self::LOCALE ), Variant::byDefault( $this->ids->generate(), Sku::of( $sku ) ), new \DateTimeImmutable( '2026-10-31 10:00:00', new \DateTimeZone( 'UTC' ) ) );

		$product->applyCommerce( Sku::of( $sku ), VariantPrice::net( $base, $priceMinor ), 250, $base );

		$this->products->save( $product );
		$this->assertTrue( $this->products->leaveUpdating( (int) $product->id(), GenerationState::Complete ) );

		$stock   = $this->kernel->get( StockService::class );
		$variant = (int) $product->defaultVariant()?->id();

		$this->db->transaction(
			static function () use ( $stock, $variant ): void {
				$stock->createItems( array( $variant ) );
			}
		);

		return $product;
	}

	/**
	 * Authors a variant's price in a currency, as a merchant's screen will.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $product    The product, whose default variant gets the price.
	 * @param string  $currency   The currency.
	 * @param int     $priceMinor The price, in minor units.
	 * @param string  $basis      net or gross.
	 */
	private function authorPrice( Product $product, string $currency, int $priceMinor, string $basis ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, %s, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->db->table( CatalogTables::VARIANT_PRICES ),
			(int) $product->defaultVariant()?->id(),
			$currency,
			$basis,
			$priceMinor
		);
	}

	/**
	 * Saves a rate set as the next version, through the kernel's writer, and returns the version.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $rates The rates from USD, by currency.
	 * @return int The version.
	 */
	private function saveRates( array $rates ): int {
		$set = array();

		foreach ( $rates as $code => $rate ) {
			$set[] = self::rateTo( $code, $rate );
		}

		return $this->kernel->get( ExchangeRates::class )->saveVersion( $set, Actor::user( 1 ) );
	}

	/**
	 * Publishes a product's save, as the product write does in its transaction.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $product The product.
	 */
	private function saved( Product $product ): void {
		$events = $this->kernel->get( EventPublisher::class );

		$this->db->transaction(
			static function () use ( $product, $events ): void {
				$product->markSaved( array( 'post' ), new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
				$events->publish( ...$product->releaseEvents() );
			}
		);
	}

	/**
	 * Delivers what the outbox holds, as the end of the request does.
	 *
	 * @since 0.2.0
	 */
	private function drain(): void {
		$this->kernel->get( OutboxDrainer::class )->drain( DrainOptions::command( 30 ) );
	}

	/**
	 * Returns a product's rows as stored, by currency.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $product The product.
	 * @return array<string, array{0: int, 1: string, 2: int, 3: int|null}> By currency: the sort price, the basis, whether it is authored, the rate version.
	 */
	private function rows( Product $product ): array {
		$rows = array();

		foreach ( $this->db->fetchAll( 'SELECT currency, sort_price_minor, amount_basis, has_explicit_price, rate_version FROM %i WHERE product_id = %d ORDER BY currency', $this->db->table( CatalogTables::PRICE_PROJECTIONS ), (int) $product->id() ) as $row ) {
			$rows[ (string) $row['currency'] ] = array( (int) $row['sort_price_minor'], (string) $row['amount_basis'], (int) $row['has_explicit_price'], null === $row['rate_version'] ? null : (int) $row['rate_version'] );
		}

		return $rows;
	}

	/**
	 * Returns when a product's row in a currency was last computed, as stored.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $product  The product.
	 * @param string  $currency The currency.
	 * @return string The instant, with its microseconds.
	 */
	private function updatedAt( Product $product, string $currency ): string {
		return (string) $this->db->fetchValue( 'SELECT updated_at FROM %i WHERE product_id = %d AND currency = %s', $this->db->table( CatalogTables::PRICE_PROJECTIONS ), (int) $product->id(), $currency );
	}

	/**
	 * Returns the jobs queued: each one's handler, payload and key.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{0: string, 1: array<string, mixed>, 2: string|null}> The jobs, in order.
	 */
	private function queued(): array {
		return array_map( static fn( Job $job ): array => array( $job->handler, $job->payload, $job->uniqueKey ), $this->queue->queued );
	}

	/**
	 * Asserts that no listener failed.
	 *
	 * @since 0.2.0
	 */
	private function assertNoListenerFailed(): void {
		$codes = array_column( $this->db->fetchAll( 'SELECT machine_code FROM %i ORDER BY id', $this->db->table( LogsTable::NAME ) ), 'machine_code' );

		$this->assertSame( array(), array_values( array_filter( $codes, static fn( mixed $code ): bool => str_starts_with( (string) $code, 'events.' ) ) ), 'An event report was logged.' );
	}

	/**
	 * Builds the kernel's container as a request builds it, with the plain connection as its unit of work, a wake that records and the recording queue.
	 *
	 * @since 0.2.0
	 *
	 * @param Database|null $db Optional. The connection, for a container that plays another process. Default the test's.
	 * @return Container The container.
	 */
	private function kernel( ?Database $db = null ): Container {
		$queue = $this->queue;

		return KernelContainer::build(
			$db ?? $this->db,
			$this->reporter(),
			array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ),
				EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher( $c->get( TransactionManager::class ), $c->get( Outbox::class ), $c->get( HookBridge::class ), $c->get( EventCatalog::class ), $c->get( CorrelationId::class ), new RecordingWake() ),
				JobQueue::class           => static fn(): JobQueue => $queue,
			)
		);
	}

	/**
	 * Takes every callback off the plugin's listened actions, as the end of a process does.
	 *
	 * @since 0.2.0
	 */
	private static function forgetListeners(): void {
		foreach ( Modules::EVENT_LISTENERS as $events ) {
			foreach ( $events as $event ) {
				remove_all_actions( EventEnvelope::hookFor( $event::eventName() ) );
			}
		}
	}
}
