<?php
/**
 * SaveProduct: the one way a product is saved, its post and its commerce rows together
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\ProductWrite;

use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Saves a product: its post through WordPress and every commerce row, in one transaction window, and never leaves a half-built product sellable.
 *
 * Owns one fact: the order of a product write and how it recovers. Saving is not atomic across
 * WordPress, because core runs other plugins' listeners inside the post write, and they may
 * make network calls, end the transaction or crash the request. So the guarantee rests on the
 * generation marker, in this order:
 *
 * - Before anything is written, what needs no database is refused: a post that is not a
 *   product post, a SKU that is not valid, a price in another currency than the base one.
 * - Saves of one stored product take turns on a named lock, `catalog.product.{id}`, held from
 *   before the mark until after the restore. So a mark never replaces the mark of a save that
 *   is still running, and a mark finds `updating` only after a save that was cut short, which
 *   is then the right marker to put back. A save that waits longer than LOCK_WAIT_SECONDS for
 *   the lock is refused as `catalog.write_conflict`, having written nothing.
 * - A stored product is marked `updating` in a transaction of its own, which commits before
 *   the window opens; the mark returns the marker it replaced and the instant it wrote.
 * - The window (no retry: it runs other plugins' listeners, which a retry would run again)
 *   begins by marking the product `updating` again, which takes its row lock, so a window that
 *   anything commits early leaves `updating`, and two windows still take turns should the
 *   named lock be lost.
 * - Inside the window: the post write, the binding, the default variant, its base-currency
 *   price, its stock item, and then the one conditional statement that takes the product out
 *   of `updating`, to `complete` when it is whole and `incomplete` otherwise. A product saved
 *   without a price is `incomplete`: a state, not an error. The ProductSaved event is stored
 *   in the outbox in the same window, so it exists exactly when the save committed.
 * - A first save of a post binds it: there is no stored product to mark or to lock, and a
 *   rollback leaves nothing, no post and no row. Two first saves of one post meet on the
 *   unique source post of a product: the second waits for the first to commit, then fails
 *   with `catalog.write_conflict`, and its window rolls back.
 *
 * When the window fails, a refused save must not take a live product off sale, so the marker
 * the mark replaced is put back, with ProductRepository::restoreMark(), and only when the
 * rollback can be trusted:
 *
 * - The failure is not a TransactionIntegrityLost: the connection did not change, and nothing
 *   ended the transaction before the wrapper's own ROLLBACK.
 * - The restore statement itself changes one row. It changes the marker only while it is still
 *   this save's mark: `updating`, at the instant the mark wrote. The window's first statement
 *   writes a new instant, so if anything committed the window, a listener's COMMIT or a
 *   statement WordPress ran again on a new connection, the instant differs and the restore
 *   changes nothing. The transaction manager reports a failed ROLLBACK rather than throwing,
 *   and gives no other sign that it completed, so this statement is the proof: while a lost
 *   connection still holds the window, the restore waits on its row lock until the server
 *   rolls the window back. Every instant the repository writes into the row is later than
 *   the row's last one, so a mark's instant is never repeated, even within one microsecond or
 *   after the database clock steps back.
 *
 * Otherwise the product stays `updating`, unsellable, until a later save or `doctor` settles
 * it. What the restore cannot see is a statement core or another plugin sent outside the
 * wrapper, on a connection WordPress reopened, followed by a listener that throws: such a
 * statement can only be an editorial write, because every commerce statement goes through the
 * database wrapper, whose identity check turns a reconnect into a TransactionIntegrityLost. A
 * restored product therefore always has whole commerce rows. The one known way an event can
 * outlive its save is the database layer's reconnect limit: should the connection die on the
 * outbox INSERT itself, WordPress runs that one statement again on a new connection, where it
 * commits alone while the window rolls back; ProductSaved is delivered at least once, and a
 * listener reads the product again rather than trusting the event.
 *
 * The service opens its own transactions and refuses to run inside one. It fires no
 * `wp_after_insert_post`: its caller does, after the commit, with SaveResult::$postCreated.
 *
 * @since 0.1.0
 */
final class SaveProduct {

	/**
	 * The name ProductSaved gives the post's own fields among the changed ones.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const POST_FIELDS = 'post';

	/**
	 * The prefix of the named lock saves of one product take turns on; the product's id follows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK_PREFIX = 'catalog.product.';

	/**
	 * How long a save waits for another save of the same product, in seconds.
	 *
	 * A save runs other plugins' listeners in its window, so it may take a few seconds; ten covers
	 * a slow one and still answers a client long before a browser or a proxy gives up.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LOCK_WAIT_SECONDS = 10;

	/**
	 * How long the lock is held without renewal where the lock service keeps it in a table, in seconds.
	 *
	 * Longer than any save should take, so the lease never lapses under a running save; a process
	 * that dies holding it frees it after this time. A server lock is freed with its connection.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LOCK_TTL_SECONDS = 60;

	/**
	 * Stores and loads products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Writes the post.
	 *
	 * @since 0.1.0
	 *
	 * @var PostGateway
	 */
	private PostGateway $posts;

	/**
	 * Gives the default variant its stock item.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * Runs the mark and the window.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Stores ProductSaved in the window.
	 *
	 * @since 0.1.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Gives the verdict the result carries.
	 *
	 * @since 0.1.0
	 *
	 * @var Sellability
	 */
	private Sellability $sellability;

	/**
	 * Tells the locale of a post being bound.
	 *
	 * @since 0.1.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

	/**
	 * Tells when a post is bound and a save happens.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Mints the UUIDs of new products and variants.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Creates the service. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products     Stores and loads products.
	 * @param PostGateway        $posts        Writes the post.
	 * @param StockService       $stock        Gives the default variant its stock item.
	 * @param TransactionManager $transactions Runs the mark and the window.
	 * @param callable           $withLock     Takes a named lock (string), for a TTL and a wait (ints, seconds), runs work
	 *                                         (callable) while holding it and releases it: LockService::withLock().
	 * @param EventPublisher     $events       Stores ProductSaved in the window.
	 * @param Sellability        $sellability  Gives the verdict the result carries.
	 * @param PostLocales        $locales      Tells the locale of a post being bound.
	 * @param Clock              $clock        Tells the time.
	 * @param IdGenerator        $ids          Mints UUIDs.
	 * @param callable           $baseCurrency Returns the store's base currency (a Currency).
	 * @param callable           $report       Receives a machine code (string) and its context (array).
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed   $withLock
	 * @phpstan-param callable(): Currency                          $baseCurrency
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct(
		ProductRepository $products,
		PostGateway $posts,
		StockService $stock,
		TransactionManager $transactions,
		callable $withLock,
		EventPublisher $events,
		Sellability $sellability,
		PostLocales $locales,
		Clock $clock,
		IdGenerator $ids,
		callable $baseCurrency,
		callable $report
	) {
		$this->products     = $products;
		$this->posts        = $posts;
		$this->stock        = $stock;
		$this->transactions = $transactions;
		$this->withLock     = $withLock;
		$this->events       = $events;
		$this->sellability  = $sellability;
		$this->locales      = $locales;
		$this->clock        = $clock;
		$this->ids          = $ids;
		$this->baseCurrency = $baseCurrency;
		$this->report       = $report;
	}

	/**
	 * Saves a product: binds its post the first time, and writes its post and commerce fields together.
	 *
	 * Coded failures: CatalogError::PostNotProduct, SkuInvalid and CurrencyNotBase before anything
	 * is written; WriteConflict when another save of the product holds its lock for longer than
	 * the wait; SkuTaken, PostRejected, ProductNotFound and WriteConflict from the window, after
	 * which the product's marker is put back as the class description says; the kernel's
	 * `store.unavailable` while the schema is being updated.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is already open.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param ProductSave $command The save.
	 * @return SaveResult What was written, and where it left the product.
	 */
	public function save( ProductSave $command ): SaveResult {
		if ( 0 !== $this->transactions->depth() ) {
			throw new \LogicException( 'A product save opens its own transactions: its mark must be committed before its window opens, which no transaction around it allows.' );
		}

		$base     = ( $this->baseCurrency )();
		$postId   = $command->postId();
		$commerce = $command->commerce();

		if ( null !== $postId && ProductCapabilities::POST_TYPE !== $this->posts->postTypeOf( $postId ) ) {
			CodedException::raise( CatalogError::PostNotProduct, array( 'post_id' => $postId ) );
		}

		self::refuseBeforeWriting( $commerce, $base );

		$stored = null === $postId ? null : $this->products->findByPost( $postId );

		if ( null === $stored?->defaultVariant() && null !== $commerce && array() !== $commerce->fields() && ! $commerce->has( CommerceFields::SKU ) ) {
			// A price or a weight belongs to the default variant, which cannot exist without a SKU.
			CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => '' ) );
		}

		return null === $stored ? $this->firstBind( $command, $base ) : $this->update( $stored, $command, $base );
	}

	/**
	 * Binds a post for the first time: the post, the product and its commerce rows, in one window.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The save.
	 * @param Currency    $base    The store's base currency.
	 * @return SaveResult The outcome.
	 */
	private function firstBind( ProductSave $command, Currency $base ): SaveResult {
		$created = null === $command->postId();

		$product = $this->transactions->transaction(
			function () use ( $command, $base, $created ): Product {
				$postId = $created || $this->hasPostFields( $command ) ? $this->posts->write( $this->postFields( $command ) ) : (int) $command->postId();

				$product = Product::firstBinding( $this->ids->generate(), $postId, $this->locales->localeOf( $postId ), null, $this->clock->now() );
				$changed = $this->applyCommerce( $product, $command, $base );

				try {
					$this->products->save( $product );
				} catch ( DuplicateKey $bound ) {
					// Another writer bound the post after it was read; the next save updates that product.
					CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
				}

				return $this->finish( $product, $changed );
			},
			RetryPolicy::none()
		);

		return $this->result( $product, $created );
	}

	/**
	 * Saves a stored product in its turn: takes the product's lock, saves while holding it, and lets it go.
	 *
	 * Fails with CatalogError::WriteConflict, having written nothing, when another save held the
	 * lock for the whole wait; otherwise with what updateInTurn() throws.
	 *
	 * @since 0.1.0
	 *
	 * @phpstan-throws CodedException|\Throwable
	 *
	 * @param Product     $stored  The product as it was read before the mark.
	 * @param ProductSave $command The save.
	 * @param Currency    $base    The store's base currency.
	 * @return SaveResult The outcome.
	 */
	private function update( Product $stored, ProductSave $command, Currency $base ): SaveResult {
		$productId = (int) $stored->id();
		$postId    = (int) $command->postId();

		try {
			return ( $this->withLock )(
				self::LOCK_PREFIX . $productId,
				self::LOCK_TTL_SECONDS,
				self::LOCK_WAIT_SECONDS,
				fn(): SaveResult => $this->updateInTurn( $productId, $postId, $command, $base )
			);
		} catch ( LockNotAcquired $busy ) {
			// Another save of the product held the lock for the whole wait; nothing was written.
			CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
		}
	}

	/**
	 * Saves a stored product while holding its lock: the mark, then the window; after a failed window, the mark is taken back when that can be trusted.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable Whatever ended the window, after the mark is taken back when that can be trusted.
	 *
	 * @param int         $productId The product's id.
	 * @param int         $postId    The post the save names.
	 * @param ProductSave $command   The save.
	 * @param Currency    $base      The store's base currency.
	 * @return SaveResult The outcome.
	 */
	private function updateInTurn( int $productId, int $postId, ProductSave $command, Currency $base ): SaveResult {
		$mark = $this->transactions->transaction(
			fn(): ?UpdatingMark => $this->products->markUpdating( $productId ),
			RetryPolicy::deadlocks()
		);

		if ( ! $mark instanceof UpdatingMark ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		try {
			$product = $this->transactions->transaction(
				function () use ( $command, $base, $productId, $postId ): Product {
					if ( ! $this->products->relock( $productId ) ) {
						CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
					}

					// Read again under the lock: the save applies its fields to the product as it is now.
					$product = $this->products->findByPost( $postId );

					if ( $productId !== $product?->id() ) {
						CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
					}

					if ( $this->hasPostFields( $command ) ) {
						$this->posts->write( $this->postFields( $command ) );
					}

					$changed = $this->applyCommerce( $product, $command, $base );

					$this->products->save( $product );

					return $this->finish( $product, $changed );
				},
				RetryPolicy::none()
			);
		} catch ( \Throwable $failure ) {
			$this->takeBackMark( $productId, $mark, $failure );

			throw $failure;
		}

		return $this->result( $product, false );
	}

	/**
	 * Finishes a window: the stock item, the settle statement and the event.
	 *
	 * @since 0.1.0
	 *
	 * @param Product  $product The stored product, with its ids.
	 * @param string[] $changed The fields the save wrote.
	 * @return Product The product, settled.
	 *
	 * @phpstan-param list<string> $changed
	 */
	private function finish( Product $product, array $changed ): Product {
		$variantId = $product->defaultVariant()?->id();

		if ( null !== $variantId ) {
			$this->stock->createItems( array( $variantId ) );
		}

		$settled = $product->settle( null !== $variantId );

		if ( ! $this->products->leaveUpdating( (int) $product->id(), $settled ) ) {
			CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => (int) $product->sourcePostId() ) );
		}

		$product->markSaved( $changed, $this->clock->now() );

		$this->events->publish( ...$product->releaseEvents() );

		return $product;
	}

	/**
	 * Applies the commerce fields a save gives to the product's default variant, creating the variant when the save names its SKU.
	 *
	 * A field the save leaves out keeps its value; a price the save gives as null is removed.
	 *
	 * @since 0.1.0
	 *
	 * @param Product     $product The product.
	 * @param ProductSave $command The save.
	 * @param Currency    $base    The store's base currency.
	 * @return list<string> The fields the save wrote: the commerce fields it gives, and POST_FIELDS when it writes the post.
	 */
	private function applyCommerce( Product $product, ProductSave $command, Currency $base ): array {
		$changed = $this->hasPostFields( $command ) || null === $command->postId() ? array( self::POST_FIELDS ) : array();
		$input   = $command->commerce();

		if ( null === $input || array() === $input->fields() ) {
			return $changed;
		}

		$variant = $product->defaultVariant();
		$sku     = $input->has( CommerceFields::SKU ) ? Sku::of( (string) $input->sku() ) : $variant?->sku();

		if ( null === $sku ) {
			CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => '' ) );
		}

		if ( null === $variant ) {
			$variant = Variant::byDefault( $this->ids->generate(), $sku );

			$product->giveDefaultVariant( $variant );
		}

		$stored     = $variant->basePrice();
		$priceMinor = $input->has( CommerceFields::PRICE_MINOR ) ? $input->priceMinor() : $stored?->priceMinor();
		$compareAt  = $input->has( CommerceFields::COMPARE_AT_MINOR ) ? $input->compareAtMinor() : $stored?->compareAtMinor();
		$currency   = $input->has( CommerceFields::CURRENCY ) ? Currency::of( (string) $input->currency() ) : ( $stored?->currency() ?? $base );
		$weight     = $input->has( CommerceFields::WEIGHT_GRAMS ) ? $input->weightGrams() : $variant->weightGrams();

		$product->applyCommerce( $sku, null === $priceMinor ? null : VariantPrice::net( $currency, $priceMinor, $compareAt ), $weight, $base );

		return array_merge( $changed, $input->fields() );
	}

	/**
	 * Puts back the marker a failed window's mark replaced, when the rollback can be trusted; see the class description.
	 *
	 * Never throws: the window's own failure is what the caller must see.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $productId The product.
	 * @param UpdatingMark $mark      The mark made before the window.
	 * @param \Throwable   $failure   What ended the window.
	 */
	private function takeBackMark( int $productId, UpdatingMark $mark, \Throwable $failure ): void {
		if ( $failure instanceof TransactionIntegrityLost ) {
			return;
		}

		try {
			$this->products->restoreMark( $productId, $mark->before, $mark->markedAt );
		} catch ( \RuntimeException $restoreFailed ) {
			( $this->report )(
				ReportCode::RestoreFailed->value,
				array(
					'product_id' => $productId,
					'error'      => $restoreFailed instanceof CodedException ? (string) $restoreFailed->errorCode()->value : get_class( $restoreFailed ),
				)
			);
		}
	}

	/**
	 * Builds the result of a committed save, with the verdict read after the commit.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The saved product.
	 * @param bool    $created Whether the save created the post.
	 * @return SaveResult The result.
	 */
	private function result( Product $product, bool $created ): SaveResult {
		$variantId = $product->defaultVariant()?->id();

		return new SaveResult(
			(int) $product->id(),
			(int) $product->sourcePostId(),
			$variantId,
			$created,
			$product->generation(),
			null === $variantId ? null : ( $this->sellability->of( array( $variantId ), false )[ $variantId ] ?? null )
		);
	}

	/**
	 * Refuses, before anything is written, a SKU that is not valid and a price in another currency than the base one.
	 *
	 * @since 0.1.0
	 *
	 * @param CommerceInput|null $commerce The commerce fields the save gives.
	 * @param Currency           $base     The store's base currency.
	 */
	private static function refuseBeforeWriting( ?CommerceInput $commerce, Currency $base ): void {
		if ( null === $commerce ) {
			return;
		}

		if ( $commerce->has( CommerceFields::SKU ) ) {
			Sku::of( (string) $commerce->sku() );
		}

		if ( $commerce->has( CommerceFields::CURRENCY ) ) {
			$currency = Currency::of( (string) $commerce->currency() );

			if ( ! $currency->equals( $base ) ) {
				CodedException::raise(
					CatalogError::CurrencyNotBase,
					array(
						'currency'      => $currency->code(),
						'base_currency' => $base->code(),
					)
				);
			}
		}
	}

	/**
	 * Tells whether the save gives any of the post's own fields.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The save.
	 * @return bool True when it does.
	 */
	private function hasPostFields( ProductSave $command ): bool {
		return array() !== $command->editorial();
	}

	/**
	 * Returns the fields the post write sends: the save's, with the post's id when it names one.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The save.
	 * @return array<string, mixed> The fields.
	 */
	private function postFields( ProductSave $command ): array {
		$fields = $command->editorial();

		if ( null !== $command->postId() ) {
			$fields['ID'] = $command->postId();
		}

		return $fields;
	}
}
