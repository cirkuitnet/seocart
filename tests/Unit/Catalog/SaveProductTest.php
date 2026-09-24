<?php
/**
 * Tests SaveProduct's order of steps, its refusals before any write, and when it takes its mark back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\ProductWrite\CommerceInput;
use SEOCart\Catalog\Application\ProductWrite\ProductSave;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\LoggedEvents;
use SEOCart\Tests\Support\Catalog\LoggedTransactions;
use SEOCart\Tests\Support\Catalog\ScriptedPostGateway;
use SEOCart\Tests\Support\Catalog\ScriptedProductRepository;
use SEOCart\Tests\Support\Catalog\StepLog;
use SEOCart\Tests\Support\Doubles\FakeStockRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * The service's steps, in the order the design gives them, with every collaborator a double.
 *
 * The stock seam is the real StockService over FakeStockRepository: StockService is a final
 * class, and its repository is the port Inventory already fakes, so no seam of the catalog's own
 * stands in for it. The integration tests prove the same steps against MySQL; these prove their
 * order and the decisions between them.
 *
 * The product's lock is a closure that records `lock: <name>` and `unlock` around the work, as
 * LockService::withLock() takes and lets go of a lock; it refuses a transaction around it, as the
 * lock service does.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In SaveProduct::update(), call updateInTurn() without the lock: the order test fails.
 * - In SaveProduct::update(), run the mark inside the window: the order test fails.
 * - In SaveProduct::takeBackMark(), drop the TransactionIntegrityLost return: the lost-transaction test fails.
 * - In SaveProduct::finish(), drop the publish() call: the first-save and update tests fail.
 *
 * @since 0.1.0
 */
final class SaveProductTest extends TestCase {

	/**
	 * A product post.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const POST = 42;

	/**
	 * A page, which is not a product post.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PAGE = 43;

	/**
	 * The instant the scripted mark wrote.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MARKED_AT = '2026-09-24 12:00:00.000001';

	/**
	 * The timeline.
	 *
	 * @since 0.1.0
	 *
	 * @var StepLog
	 */
	private StepLog $log;

	/**
	 * The transactions.
	 *
	 * @since 0.1.0
	 *
	 * @var LoggedTransactions
	 */
	private LoggedTransactions $tx;

	/**
	 * The post gateway.
	 *
	 * @since 0.1.0
	 *
	 * @var ScriptedPostGateway
	 */
	private ScriptedPostGateway $posts;

	/**
	 * The product repository.
	 *
	 * @since 0.1.0
	 *
	 * @var ScriptedProductRepository
	 */
	private ScriptedProductRepository $products;

	/**
	 * Inventory's storage, behind the real stock service.
	 *
	 * @since 0.1.0
	 *
	 * @var FakeStockRepository
	 */
	private FakeStockRepository $stock;

	/**
	 * The events committed.
	 *
	 * @since 0.1.0
	 *
	 * @var RecordingEventPublisher
	 */
	private RecordingEventPublisher $events;

	/**
	 * What the product's lock throws instead of running the work, or null to run it.
	 *
	 * @since 0.1.0
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $lockFails = null;

	/**
	 * The reports the service made.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $reports = array();

	/**
	 * Builds the doubles; every post is unbound until a test stores a product.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->log      = new StepLog();
		$this->tx       = new LoggedTransactions( new FakeTransactionManager(), $this->log );
		$this->posts    = new ScriptedPostGateway(
			$this->tx,
			$this->log,
			array(
				self::POST => ProductCapabilities::POST_TYPE,
				self::PAGE => 'page',
			)
		);
		$this->products = new ScriptedProductRepository( $this->log, $this->tx );
		$this->stock    = new FakeStockRepository( $this->tx );
		$this->events   = new RecordingEventPublisher( $this->tx );
		$this->reports  = array();

		$this->lockFails = null;
	}

	/**
	 * Tests that a first save writes the post, the product, its stock item, the settle statement and the event in one window, and nothing before it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_binds_the_post_in_one_window(): void {
		$result = $this->service()->save(
			self::command(
				null,
				array( 'post_title' => 'New' ),
				array(
					'sku'         => 'SKU-1',
					'price_minor' => 1999,
				)
			)
		);

		$this->assertSame(
			array(
				'transaction: 1 attempts',
				'post.insert',
				'repository.save',
				'repository.leaveUpdating: complete',
				'publish: product_saved',
				'commit',
			),
			$this->log->steps()
		);
		$this->assertSame( array( 'createItems:91' ), $this->stock->calls(), 'The default variant gets its stock item in the window.' );
		$this->assertSame( array( 90, 500, 91, true, GenerationState::Complete ), array( $result->productId, $result->postId, $result->variantId, $result->postCreated, $result->generation ) );

		$saved = $this->events->publishedOf( ProductSaved::class );

		$this->assertCount( 1, $saved );
		$this->assertSame( array( SaveProduct::POST_FIELDS, 'sku', 'price_minor' ), $saved[0]->changedFields );
		$this->assertSame( array( 'SKU-1', 1999, 'USD' ), array( $saved[0]->sku, $saved[0]->priceMinor, $saved[0]->currency ) );
	}

	/**
	 * Tests that an update marks in a transaction of its own, which commits first, and that the window begins with the relock.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_marks_first_then_relocks_at_the_start_of_its_window(): void {
		$this->storeProduct();

		$result = $this->service()->save( self::command( self::POST, array( 'post_title' => 'Renamed' ), array( 'price_minor' => 2499 ) ) );

		$this->assertSame(
			array(
				'repository.findByPost',
				'lock: catalog.product.7',
				'transaction: 3 attempts',
				'repository.markUpdating',
				'commit',
				'transaction: 1 attempts',
				'repository.relock',
				'repository.findByPost',
				'post.update',
				'repository.save',
				'repository.leaveUpdating: complete',
				'publish: product_saved',
				'commit',
				'unlock',
			),
			$this->log->steps(),
			'The product\'s lock around it all, the mark in its own transaction with the deadlock policy, then the window without retries, the relock first.'
		);
		$this->assertSame( array( 'createItems:70' ), $this->stock->calls() );
		$this->assertSame(
			array(
				'post_title' => 'Renamed',
				'ID'         => self::POST,
			),
			$this->posts->written[0] ?? null,
			'The update names the post.'
		);
		$this->assertSame( array( 7, self::POST, 70, false, GenerationState::Complete ), array( $result->productId, $result->postId, $result->variantId, $result->postCreated, $result->generation ) );
		$this->assertSame( array( SaveProduct::POST_FIELDS, 'price_minor' ), $this->events->publishedOf( ProductSaved::class )[0]->changedFields ?? null );
	}

	/**
	 * Tests that a failure inside the window rolls it back and then puts back the marker the mark replaced, at the mark's instant.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_window_takes_back_its_own_mark(): void {
		$this->storeProduct();

		$listener              = new \RuntimeException( 'A listener failed.' );
		$this->posts->failWith = $listener;
		$this->products->mark  = new UpdatingMark( GenerationState::Incomplete, self::MARKED_AT );

		$this->assertSame( $listener, $this->failure( self::command( self::POST, array( 'post_title' => 'Renamed' ), null ) ), 'The window\'s own failure is what the caller sees.' );
		$this->assertSame(
			array( 'post.update', 'rollback', 'repository.restoreMark: incomplete at ' . self::MARKED_AT, 'unlock' ),
			array_slice( $this->log->steps(), -4 ),
			'The marker the mark found is put back after the rollback, while the product\'s lock is still held, and only while the mark is this save\'s.'
		);
		$this->assertSame( array(), $this->events->published() );
	}

	/**
	 * Tests that a lost transaction leaves the mark: the rollback cannot be trusted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_lost_transaction_leaves_the_mark(): void {
		$this->storeProduct();

		$lost                  = TransactionIntegrityLost::lost( TransactionIntegrityLost::ENDED_EXTERNALLY, 'RELEASE SAVEPOINT sc_0' );
		$this->posts->failWith = $lost;

		$this->assertSame( $lost, $this->failure( self::command( self::POST, array( 'post_title' => 'Renamed' ), null ) ) );
		$this->assertSame( array( 'rollback', 'unlock' ), array_slice( $this->log->steps(), -2 ), 'Nothing is restored after a lost transaction.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a failed restore is reported and the window's failure is still the one thrown.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_restore_is_reported_and_the_window_failure_thrown(): void {
		$this->storeProduct();

		$listener                         = new \RuntimeException( 'A listener failed.' );
		$this->posts->failWith            = $listener;
		$this->products->restoreFailsWith = QueryFailed::fromErrno( 2013, 'HY000', 'UPDATE products', 'Lost connection', false );

		$this->assertSame( $listener, $this->failure( self::command( self::POST, array( 'post_title' => 'Renamed' ), null ) ) );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( ReportCode::RestoreFailed->value, $this->reports[0][0] );
		$this->assertSame( 7, $this->reports[0][1]['product_id'] ?? null );
	}

	/**
	 * Tests that a save that waits in vain for another save of the product is a write conflict, and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_that_cannot_take_the_products_lock_writes_nothing(): void {
		$this->storeProduct();

		$this->lockFails = LockNotAcquired::because(
			LockNotAcquired::CODE,
			array(
				'name'   => 'catalog.product.7',
				'waited' => SaveProduct::LOCK_WAIT_SECONDS * 1000,
			)
		);

		$failure = $this->failure( self::command( self::POST, array( 'post_title' => 'Renamed' ), array( 'price_minor' => 2499 ) ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::WriteConflict, $failure->errorCode() );
		$this->assertSame( array( 'post_id' => self::POST ), $failure->context() );
		$this->assertSame( array( 'repository.findByPost', 'lock: catalog.product.7' ), $this->log->steps(), 'Nothing is marked or written without the lock.' );
	}

	/**
	 * Tests that a first save takes no lock: there is no product yet to lock.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_save_takes_no_lock(): void {
		$this->service()->save( self::command( null, array( 'post_title' => 'New' ), array( 'sku' => 'SKU-1' ) ) );

		$this->assertSame( array(), array_values( array_filter( $this->log->steps(), static fn( string $step ): bool => str_starts_with( $step, 'lock' ) ) ) );
	}

	/**
	 * Tests that a settle statement that changes no row fails the save as a write conflict, which rolls the window back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_settle_that_changes_no_row_is_a_write_conflict(): void {
		$this->storeProduct();

		$this->products->settles = false;

		$failure = $this->failure( self::command( self::POST, array(), array( 'price_minor' => 2499 ) ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::WriteConflict, $failure->errorCode() );
		$this->assertSame( array( 'post_id' => self::POST ), $failure->context() );
		$this->assertContains( 'rollback', $this->log->steps() );
		$this->assertSame( array(), $this->events->published(), 'No event outlives the rolled-back window.' );
	}

	/**
	 * Tests that a save is refused, with nothing written and no transaction opened, when what it gives needs no database to refuse.
	 *
	 * @dataProvider refusedBeforeWriting
	 *
	 * @since 0.1.0
	 *
	 * @param int|null             $postId   The post.
	 * @param array<string, mixed> $commerce The commerce fields.
	 * @param CatalogError         $expected The code.
	 */
	public function test_a_save_is_refused_before_anything_is_written( ?int $postId, array $commerce, CatalogError $expected ): void {
		$failure = $this->failure( self::command( $postId, array( 'post_title' => 'Refused' ), $commerce ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( $expected, $failure->errorCode() );
		$this->assertSame( array(), array_values( array_diff( $this->log->steps(), array( 'repository.findByPost' ) ) ), 'Only a read may happen before the refusal.' );
		$this->assertSame( array(), $this->stock->calls() );
	}

	/**
	 * Returns the saves refused before anything is written.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: int|null, 1: array<string, mixed>, 2: CatalogError}> The cases.
	 */
	public static function refusedBeforeWriting(): array {
		return array(
			'a post that is not a product post' => array( self::PAGE, array( 'sku' => 'SKU-1' ), CatalogError::PostNotProduct ),
			'a SKU that is only blanks'         => array( null, array( 'sku' => '   ' ), CatalogError::SkuInvalid ),
			'a price in another currency'       => array(
				null,
				array(
					'sku'         => 'SKU-1',
					'price_minor' => 1999,
					'currency'    => 'EUR',
				),
				CatalogError::CurrencyNotBase,
			),
			'a price without a SKU'             => array( self::POST, array( 'price_minor' => 1999 ), CatalogError::SkuInvalid ),
		);
	}

	/**
	 * Tests that a save inside an open transaction is refused: its mark could not commit before its window.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_inside_a_transaction_is_refused(): void {
		$service = $this->service();

		$this->expectException( \LogicException::class );

		$this->tx->transaction( static fn(): mixed => $service->save( self::command( null, array( 'post_title' => 'Nested' ), null ) ) );
	}

	/**
	 * Tests that a first save with no commerce field binds the post without a variant, and settles incomplete.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_without_a_sku_binds_without_a_variant(): void {
		$result = $this->service()->save( self::command( self::POST, array(), null ) );

		$this->assertSame( array( null, GenerationState::Incomplete, null ), array( $result->variantId, $result->generation, $result->sellability ) );
		$this->assertContains( 'repository.leaveUpdating: incomplete', $this->log->steps() );
		$this->assertSame( array(), $this->stock->calls(), 'No variant, no stock item.' );
		$this->assertSame( array(), $this->posts->written, 'A save of a bound post that gives no post field does not write the post.' );
	}

	/**
	 * Stores a complete product for self::POST, read afresh each time it is found.
	 *
	 * @since 0.1.0
	 */
	private function storeProduct(): void {
		$usd = Currency::of( 'USD' );

		$this->products = new ScriptedProductRepository(
			$this->log,
			$this->tx,
			static fn( int $postId ): ?Product => self::POST !== $postId ? null : Product::stored(
				7,
				'00000000-0000-4000-8000-000000000007',
				self::POST,
				array( new ProductPostBinding( self::POST, Locale::of( 'en_US' ), new \DateTimeImmutable( '2026-09-01 00:00:00' ) ) ),
				GenerationState::Complete,
				1,
				Variant::stored( 70, '00000000-0000-4000-8000-000000000070', Sku::of( 'SKU-1' ), Variant::defaultCombinationHash(), 1, true, 250, VariantPrice::net( $usd, 1999 ) )
			)
		);
	}

	/**
	 * Builds the service over the doubles.
	 *
	 * @since 0.1.0
	 *
	 * @return SaveProduct The service.
	 */
	private function service(): SaveProduct {
		$clock = FrozenClock::at( '2026-09-24 12:00:00' );
		$ids   = new SequentialIdGenerator( 100 );

		return new SaveProduct(
			$this->products,
			$this->posts,
			new StockService( $this->stock, $this->tx, $this->events, $ids, $clock, new CorrelationId( new SequentialIdGenerator( 9000 ) ), new Authorizer( new CapabilityDeclaration() ) ),
			$this->tx,
			function ( string $name, int $ttlSeconds, int $waitSeconds, callable $work ): mixed {
				if ( 0 !== $this->tx->depth() ) {
					throw new \LogicException( 'A lock is never taken inside a transaction.' );
				}

				$this->log->record( 'lock: ' . $name );

				if ( null !== $this->lockFails ) {
					throw $this->lockFails;
				}

				try {
					return $work();
				} finally {
					$this->log->record( 'unlock' );
				}
			},
			new LoggedEvents( $this->events, $this->log ),
			new Sellability( $this->products ),
			new class() implements PostLocales {

				/**
				 * Answers the store's one locale.
				 *
				 * @since 0.1.0
				 *
				 * @param int $postId Unused.
				 * @return Locale The locale.
				 */
				public function localeOf( int $postId ): Locale {
					return Locale::of( 'en_US' );
				}
			},
			$clock,
			$ids,
			static fn(): Currency => Currency::of( 'USD' ),
			function ( string $code, array $context ): void {
				$this->reports[] = array( $code, $context );
			}
		);
	}

	/**
	 * Saves and returns what the save threw, failing the test when it threw nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The save.
	 * @return \Throwable What it threw.
	 */
	private function failure( ProductSave $command ): \Throwable {
		try {
			$this->service()->save( $command );
		} catch ( \Throwable $failure ) {
			return $failure;
		}

		$this->fail( 'The save succeeded.' );
	}

	/**
	 * Builds a save by user 1.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null                  $postId    The post, or null for a new one.
	 * @param array<string, mixed>      $editorial The post's fields.
	 * @param array<string, mixed>|null $commerce  The commerce fields, or null for none.
	 * @return ProductSave The save.
	 */
	private static function command( ?int $postId, array $editorial, ?array $commerce ): ProductSave {
		return new ProductSave( $postId, $editorial, null === $commerce ? null : CommerceInput::fromArray( $commerce ), Actor::user( 1 ) );
	}
}
