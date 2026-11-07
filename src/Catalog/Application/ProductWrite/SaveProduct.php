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

use SEOCart\Catalog\Application\Lifecycle\TranslationGroups;
use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionMatrix;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantInput;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Domain\VariantSelection;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\ChangedMeanwhile;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Locale;

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
 *   product post, a SKU that is not valid, a single price in another currency than the base one,
 *   the single variant's fields given with a variant set or for a product with options, and a
 *   variant set its options do not admit, which Product decides: too many axes, values or
 *   variants, a combination that is not one of the options' or is given twice, a SKU given twice,
 *   a price in a currency the store does not offer.
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
 * - Inside the window: the post write, the binding, the options and the variants with their
 *   prices, written and published as one generation (see ProductRepository::save()), every
 *   variant's stock item, and then the one conditional statement that takes the product out
 *   of `updating`, to `complete` when it is whole and `incomplete` otherwise. A product saved
 *   without a price is `incomplete`: a state, not an error. The ProductSaved event is stored
 *   in the outbox in the same window, so it exists exactly when the save committed. Stock
 *   counts are not part of the save: its caller sets them after the commit, by the ids the
 *   result gives each combination.
 * - A first save of a post binds it: there is no stored product to mark or to lock, and a
 *   rollback leaves nothing, no post and no row. Two first saves of one post meet on the
 *   unique source post of a product: the second waits for the first to commit, then fails
 *   with `catalog.write_conflict`, and its window rolls back.
 * - A first save of a post in another language, one that names the post it translates or whose
 *   translation group presents a product already, does not create a product: the post joins
 *   that product, in its locale, as the product's save does any update, and the multilingual
 *   setup is told its language and group in the same window. The product's SKU, price and stock
 *   stay the product's, whichever of its posts a save comes through. A save that names the post
 *   it translates joins that post's product only when, under the product's lock, the post still
 *   presents it and the actor may edit its source post; and a save that gives commerce fields
 *   through a post that is not the product's source post, joining or not, needs the same.
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
 *
 * @phpstan-type GivenSet array{options: OptionMatrix|null, variants: list<VariantInput>|null, prices: bool}
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
	 * The post type of a file in WordPress's media library, which an option value's image is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const IMAGE_POST_TYPE = 'attachment';

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
	 * Returns the currencies the store offers, its base currency among them.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(): list<Currency>
	 */
	private $offeredCurrencies;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Decides whether the actor may edit the product a save joins.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

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
	 * @param callable           $baseCurrency      Returns the store's base currency (a Currency).
	 * @param callable           $report            Receives a machine code (string) and its context (array).
	 * @param Authorizer         $authorizer        Decides whether the actor may edit the product a save joins.
	 * @param callable           $offeredCurrencies Returns the currencies the store offers (a list of Currency), the base among them.
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed   $withLock
	 * @phpstan-param callable(): Currency                          $baseCurrency
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param callable(): list<Currency>                    $offeredCurrencies
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
		callable $report,
		Authorizer $authorizer,
		callable $offeredCurrencies
	) {
		$this->products          = $products;
		$this->posts             = $posts;
		$this->stock             = $stock;
		$this->transactions      = $transactions;
		$this->withLock          = $withLock;
		$this->events            = $events;
		$this->sellability       = $sellability;
		$this->locales           = $locales;
		$this->clock             = $clock;
		$this->ids               = $ids;
		$this->baseCurrency      = $baseCurrency;
		$this->report            = $report;
		$this->authorizer        = $authorizer;
		$this->offeredCurrencies = $offeredCurrencies;
	}

	/**
	 * Saves a product: binds its post the first time, and writes its post and commerce fields together.
	 *
	 * Coded failures: CatalogError::PostNotProduct, SkuInvalid, CurrencyNotBase, VariantsAmbiguous,
	 * OptionInvalid, TooManyOptions, TooManyVariants, CombinationInvalid, CombinationDuplicate,
	 * SkuDuplicate, CurrencyNotOffered and PriceDuplicate before anything is written, and for a save
	 * in another language LocaleUnsupported, LocaleFixed and PostBoundElsewhere; WriteConflict when
	 * another save of the product holds its lock for longer than the wait; SkuTaken, SkuDuplicate,
	 * SkuInvalid (a variant left without a SKU by a save that came first), VariantsAmbiguous (the
	 * single variant's fields, after a save that came first gave the product options), PostRejected,
	 * ProductNotFound and WriteConflict from the window, and PostBoundElsewhere or
	 * LocaleTaken when a post cannot join the product it translates, after which the product's
	 * marker is put back as the class description says; the kernel's `store.unavailable` while the
	 * schema is being updated.
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

		$set    = null === $commerce ? null : $this->variantSet( $commerce );
		$stored = null === $postId ? null : $this->products->findByPost( $postId );

		if ( null === $stored ) {
			$this->refuseLocale( $command );
		} else {
			$this->refuseTranslation( $stored, $command );
		}

		$joined  = null === $stored ? $this->translatedProduct( $command ) : null;
		$product = $stored ?? $joined;

		if ( null !== $commerce && $commerce->givesFlatFields() ) {
			if ( null !== $product && ! $product->options()->isEmpty() ) {
				// A product with options has no single variant for the flat fields to set.
				CodedException::raise( CatalogError::VariantsAmbiguous );
			}

			if ( null === $product?->defaultVariant() && ! $commerce->has( CommerceFields::SKU ) ) {
				// A price or a weight belongs to the default variant, which cannot exist without a SKU.
				CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => '' ) );
			}
		}

		if ( null !== $set ) {
			$this->refuseVariantSet( $set, $product );
		}

		if ( null !== $joined ) {
			return $this->update( $joined, $command, $base, true, $set );
		}

		return null === $stored ? $this->firstBind( $command, $base, $set ) : $this->update( $stored, $command, $base, false, $set );
	}

	/**
	 * Builds the option axes and the variant set a save gives, refusing an option, a SKU or a currency that is not valid, before anything is written.
	 *
	 * Each variant is given a fresh UUID, which it keeps only if its combination is new. A variant
	 * given no SKU, or a null one, is given none, which only a stored variant without a SKU may be.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid, CombinationInvalid or SkuInvalid, or `support.unknown_currency`.
	 *
	 * @param CommerceInput $commerce The commerce fields the save gives.
	 * @return array<string, mixed>|null The options and the variants, each null when not given, and whether any
	 *                                   variant's prices are given; null when the save gives neither.
	 *
	 * @phpstan-return GivenSet|null
	 */
	private function variantSet( CommerceInput $commerce ): ?array {
		$options  = $commerce->options();
		$variants = $commerce->variants();

		if ( null === $options && null === $variants ) {
			return null;
		}

		$inputs = null;
		$prices = false;

		foreach ( $variants ?? array() as $entry ) {
			$pairs = array();

			foreach ( $entry[ VariantFields::COMBINATION ] ?? array() as $pair ) {
				$pairs[] = array( $pair[ VariantFields::AXIS ], $pair[ VariantFields::VALUE ] );
			}

			$given    = isset( $entry[ VariantFields::PRICES ] );
			$prices   = $prices || $given;
			$inputs[] = new VariantInput(
				$this->ids->generate(),
				VariantSelection::ofList( $pairs ),
				isset( $entry[ CommerceFields::SKU ] ) ? Variant::givenSku( $entry[ CommerceFields::SKU ] ) : null,
				$entry[ VariantFields::ENABLED ] ?? null,
				array_key_exists( CommerceFields::WEIGHT_GRAMS, $entry ),
				$entry[ CommerceFields::WEIGHT_GRAMS ] ?? null,
				$entry[ VariantFields::POSITION ] ?? null,
				$given ? self::prices( $entry[ VariantFields::PRICES ] ) : null
			);
		}

		return array(
			'options'  => null === $options ? null : self::matrix( $options ),
			'variants' => $inputs ?? ( null === $variants ? null : array() ),
			'prices'   => $prices,
		);
	}

	/**
	 * Refuses, before anything is written, a variant set the product's options do not admit, or options no product may have.
	 *
	 * The options the set is judged against are the ones the save gives, or the product's as read,
	 * and the variants it finds stored are the product's as read.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException What Product::refuseOptions(), refuseImages() and Product::refuseVariants() throw.
	 *
	 * @param array<string, mixed> $set     The options and variants the save gives.
	 * @param Product|null         $product The product as read, or null for a first save.
	 *
	 * @phpstan-param GivenSet $set
	 */
	private function refuseVariantSet( array $set, ?Product $product ): void {
		$options = $set['options'] ?? $product?->options() ?? OptionMatrix::none();

		if ( null !== $set['options'] ) {
			Product::refuseOptions( $set['options'] );
			$this->refuseImages( $set['options'], $product?->options() );
		}

		if ( null !== $set['variants'] ) {
			Product::refuseVariants( $options, $set['variants'], ( $this->offeredCurrencies )(), Product::MAX_SYNC_VARIANTS, $product?->storedVariantsUnder( $options ) ?? array() );
		}
	}

	/**
	 * Refuses, before anything is written, an option value given an image that is no attachment of the site.
	 *
	 * Only an image a value is given anew is judged. The one a value already has is kept as it is,
	 * even once its attachment is deleted, so a product read and sent back still saves.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid, its field `image`.
	 *
	 * @param OptionMatrix      $given  The options the save gives.
	 * @param OptionMatrix|null $stored The product's options as read, or null for a first save.
	 */
	private function refuseImages( OptionMatrix $given, ?OptionMatrix $stored ): void {
		foreach ( $given->axes as $axis ) {
			foreach ( $axis->values as $value ) {
				$image = $value->imageAttachmentId;

				if ( null === $image || $stored?->axis( $axis->key )?->value( $value->key )?->imageAttachmentId === $image ) {
					continue;
				}

				if ( self::IMAGE_POST_TYPE !== $this->posts->postTypeOf( $image ) ) {
					CodedException::raise(
						CatalogError::OptionInvalid,
						array(
							'field' => 'image',
							'value' => (string) $image,
						)
					);
				}
			}
		}
	}

	/**
	 * Builds the option axes a save gives.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid.
	 *
	 * @param list<array<string, mixed>> $options The axes as CommerceInput checked them.
	 * @return OptionMatrix The axes.
	 *
	 * @phpstan-param list<array{key: string, label: string, position?: int, values?: list<array{key: string, label: string, position?: int, image_id?: int|null, swatch?: string|null}>}> $options
	 */
	private static function matrix( array $options ): OptionMatrix {
		$axes = array();

		foreach ( $options as $axisIndex => $axis ) {
			$values = array();

			foreach ( $axis[ VariantFields::VALUES ] ?? array() as $valueIndex => $value ) {
				$values[] = OptionValue::of(
					$value[ VariantFields::KEY ],
					$value[ VariantFields::LABEL ],
					$value[ VariantFields::POSITION ] ?? $valueIndex,
					$value[ VariantFields::IMAGE_ID ] ?? null,
					$value[ VariantFields::SWATCH ] ?? null
				);
			}

			$axes[] = OptionAxis::of( $axis[ VariantFields::KEY ], $axis[ VariantFields::LABEL ], $axis[ VariantFields::POSITION ] ?? $axisIndex, $values );
		}

		return OptionMatrix::of( $axes );
	}

	/**
	 * Builds a variant's prices: an entry without a price authors none in its currency.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `support.unknown_currency` for a code that is no currency.
	 *
	 * @param list<array<string, mixed>> $entries The prices as CommerceInput checked them.
	 * @return list<VariantPrice> The prices.
	 *
	 * @phpstan-param list<array{currency?: string, price_minor?: int|null, compare_at_minor?: int|null, amount_basis?: string}> $entries
	 */
	private static function prices( array $entries ): array {
		$prices = array();

		foreach ( $entries as $entry ) {
			$amount = $entry[ CommerceFields::PRICE_MINOR ] ?? null;

			if ( null === $amount ) {
				continue;
			}

			$currency  = Currency::of( $entry[ CommerceFields::CURRENCY ] ?? '' );
			$compareAt = $entry[ CommerceFields::COMPARE_AT_MINOR ] ?? null;
			$prices[]  = VariantPrice::GROSS === ( $entry[ VariantFields::AMOUNT_BASIS ] ?? VariantPrice::NET )
				? VariantPrice::gross( $currency, $amount, $compareAt )
				: VariantPrice::net( $currency, $amount, $compareAt );
		}

		return $prices;
	}

	/**
	 * Refuses, before anything is written, a first save in a locale the site does not publish in.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The save of a post no product is bound to yet.
	 */
	private function refuseLocale( ProductSave $command ): void {
		$locale = $command->locale();

		if ( null !== $locale && ! $this->locales->publishesIn( $locale ) ) {
			CodedException::raise( CatalogError::LocaleUnsupported, array( 'locale' => $locale->toString() ) );
		}
	}

	/**
	 * Refuses, before anything is written, a save of a bound post that names another locale, or another product's post as the one it translates, than the post presents.
	 *
	 * A save that repeats them, as a client that sends back what it read does, is no change.
	 *
	 * @since 0.1.0
	 *
	 * @param Product     $stored  The product the post presents.
	 * @param ProductSave $command The save.
	 */
	private function refuseTranslation( Product $stored, ProductSave $command ): void {
		$postId  = (int) $command->postId();
		$locale  = $command->locale();
		$binding = $stored->bindingOf( $postId );

		if ( null !== $locale && ! (bool) $binding?->locale()->equals( $locale ) ) {
			CodedException::raise(
				CatalogError::LocaleFixed,
				array(
					'post_id' => $postId,
					'locale'  => $locale->toString(),
				)
			);
		}

		$translationOf = $command->translationOf();

		if ( null !== $translationOf && $stored->id() !== $this->products->findByPost( $translationOf )?->id() ) {
			CodedException::raise(
				CatalogError::PostBoundElsewhere,
				array(
					'post_id'    => $postId,
					'product_id' => (int) $stored->id(),
				)
			);
		}
	}

	/**
	 * Returns the product a post in another language presents: the product of the post the save names, or the one the post's translation group presents.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException CatalogError::PostNotProduct when the post the save names presents no product.
	 *
	 * @param ProductSave $command The save.
	 * @return Product|null The product, or null when the post is not a translation of one.
	 */
	private function translatedProduct( ProductSave $command ): ?Product {
		$translationOf = $command->translationOf();

		if ( null !== $translationOf ) {
			$product = $this->products->findByPost( $translationOf );

			if ( null === $product ) {
				CodedException::raise( CatalogError::PostNotProduct, array( 'post_id' => $translationOf ) );
			}

			return $product;
		}

		$postId = $command->postId();

		if ( null === $postId ) {
			return null;
		}

		$productId = TranslationGroups::productOfGroup( $this->products, $this->locales->translationsOf( $postId ) );

		return null === $productId ? null : $this->products->find( $productId );
	}

	/**
	 * Binds a post for the first time: the post, the product and its commerce rows, in one window.
	 *
	 * The window runs once, never again: other plugins' listeners on the post's hooks run inside
	 * it, and nothing may run them twice. Another writer that binds the post meanwhile is answered
	 * CatalogError::WriteConflict on both servers: on MySQL the binding's insert meets the duplicate;
	 * MariaDB refuses the insert of a binding committed since the window's first read (error 1020),
	 * which is answered the same once the server has rolled the window back.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Answers MariaDB's refusal of a row changed meanwhile as a write conflict.
	 *
	 * @param ProductSave               $command The save.
	 * @param Currency                  $base    The store's base currency.
	 * @param array<string, mixed>|null $set     The options and variants the save gives, or null for none.
	 * @return SaveResult The outcome.
	 *
	 * @phpstan-param GivenSet|null $set
	 */
	private function firstBind( ProductSave $command, Currency $base, ?array $set ): SaveResult {
		$created = null === $command->postId();

		$product = ChangedMeanwhile::refusedAs(
			$this->transactions,
			function () use ( $command, $base, $created, $set ): Product {
				$postId = $created || $this->hasPostFields( $command ) ? $this->posts->write( $this->postFields( $command ) ) : (int) $command->postId();
				$locale = $command->locale() ?? $this->locales->localeOf( $postId ) ?? $this->locales->siteLocale();

				$product = Product::firstBinding( $this->ids->generate(), $postId, $locale, null, $this->clock->now() );
				$changed = $this->applyCommerce( $product, $command, $base, $set );

				try {
					$this->products->save( $product );
				} catch ( DuplicateKey $bound ) {
					// Another writer bound the post after it was read; the next save updates that product.
					CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
				}

				$this->assign( $command, $postId, $locale, null );

				return $this->finish( $product, $changed );
			},
			static fn( TransactionRetryable $changed ): CodedException => CodedException::because( CatalogError::WriteConflict, array( 'post_id' => (int) $command->postId() ), $changed )
		);

		return $this->result( $product, (int) $product->sourcePostId(), $created );
	}

	/**
	 * Tells the multilingual setup the locale and translation group of a post the window has bound, when the save gives either.
	 *
	 * It runs after the binding is written, so a binding the catalog refuses changes nothing in the
	 * multilingual setup; a later failure rolls both back, since the setup keeps them in the
	 * same database.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command       The save.
	 * @param int         $postId        The post, bound.
	 * @param Locale      $locale        The locale it is bound in.
	 * @param int|null    $translationOf The post whose translation group it joins, or null.
	 */
	private function assign( ProductSave $command, int $postId, Locale $locale, ?int $translationOf ): void {
		if ( null !== $command->locale() || null !== $translationOf ) {
			$this->locales->assign( $postId, $locale, $translationOf );
		}
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
	 * @param Product                   $stored  The product as it was read before the mark.
	 * @param ProductSave               $command The save.
	 * @param Currency                  $base    The store's base currency.
	 * @param bool                      $joining Whether the save's post joins the product as its post in another locale.
	 * @param array<string, mixed>|null $set     The options and variants the save gives, or null for none.
	 * @return SaveResult The outcome.
	 *
	 * @phpstan-param GivenSet|null $set
	 */
	private function update( Product $stored, ProductSave $command, Currency $base, bool $joining, ?array $set ): SaveResult {
		$productId = (int) $stored->id();

		try {
			return ( $this->withLock )(
				self::LOCK_PREFIX . $productId,
				self::LOCK_TTL_SECONDS,
				self::LOCK_WAIT_SECONDS,
				fn(): SaveResult => $this->updateInTurn( $productId, $command, $base, $joining, $set )
			);
		} catch ( LockNotAcquired $busy ) {
			// Another save of the product held the lock for the whole wait; nothing was written.
			CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => (int) $command->postId() ) );
		}
	}

	/**
	 * Saves a stored product while holding its lock: the mark, then the window; after a failed window, the mark is taken back when that can be trusted.
	 *
	 * The window runs once, never again (RetryPolicy::none()): other plugins' listeners on the
	 * post's hooks run inside it. The servers differ when WordPress or another request changes the
	 * post's own row after the window's first read, such as a comment counted on it: MySQL's update
	 * of the post writes over it and the save goes on; MariaDB refuses that update of a row changed
	 * meanwhile (error 1020), WordPress reports its write failed, and the save is refused with
	 * CatalogError::PostRejected (`db_update_error`). The server has rolled the window back, so
	 * nothing is written; the editor is told the post changed meanwhile, and saves again.
	 *
	 * They differ the same way over any other row the window writes, such as a stock item a hold took
	 * after the window's first read. MySQL's upsert goes on over it, and the save stands beside the
	 * hold. MariaDB refuses the statement (error 1020), which is answered as firstBind() answers it:
	 * CatalogError::WriteConflict, once the server has rolled the window back. The mark is then taken
	 * back as after any other failure, the hold stands, and the editor saves again.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Answers MariaDB's refusal of a row changed meanwhile as a write conflict.
	 *
	 * @throws \Throwable Whatever ended the window, after the mark is taken back when that can be trusted.
	 *
	 * @param int                       $productId The product's id.
	 * @param ProductSave               $command   The save.
	 * @param Currency                  $base      The store's base currency.
	 * @param bool                      $joining   Whether the save's post joins the product in another locale.
	 * @param array<string, mixed>|null $set       The options and variants the save gives, or null for none.
	 * @return SaveResult The outcome.
	 *
	 * @phpstan-param GivenSet|null $set
	 */
	private function updateInTurn( int $productId, ProductSave $command, Currency $base, bool $joining, ?array $set ): SaveResult {
		$postId = (int) $command->postId();

		$mark = $this->transactions->transaction(
			fn(): ?UpdatingMark => $this->products->markUpdating( $productId ),
			RetryPolicy::deadlocks()
		);

		if ( ! $mark instanceof UpdatingMark ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		try {
			$product = ChangedMeanwhile::refusedAs(
				$this->transactions,
				function () use ( $command, $base, $productId, &$postId, $joining, $set ): Product {
					if ( ! $this->products->relock( $productId ) ) {
						CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
					}

					if ( $joining ) {
						$product = $this->joined( $productId, $command, $postId );
					} else {
						// Read again under the lock: the save applies its fields to the product as it is now.
						$product = $this->products->findByPost( $postId );

						if ( $productId !== $product?->id() ) {
							CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
						}

						if ( self::givesCommerce( $command ) && $postId !== $product->sourcePostId() ) {
							$this->mayEdit( $product, $command->actor() );
						}

						if ( $this->hasPostFields( $command ) ) {
							$this->posts->write( $this->postFields( $command ) );
						}
					}

					$changed = $this->applyCommerce( $product, $command, $base, $set );

					$this->products->save( $product );

					return $this->finish( $product, $changed );
				},
				static fn( TransactionRetryable $changed ): CodedException => CodedException::because( CatalogError::WriteConflict, array( 'post_id' => (int) $command->postId() ), $changed )
			);
		} catch ( \Throwable $failure ) {
			$this->takeBackMark( $productId, $mark, $failure );

			throw $failure;
		}

		return $this->result( $product, $postId, $joining && null === $command->postId() );
	}

	/**
	 * Writes the post of a save that joins a product in another locale, tells the multilingual setup its locale and group, and binds it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException CatalogError::PostBoundElsewhere or LocaleTaken when the post cannot join it; what
	 *                        mayJoin() throws.
	 *
	 * @param int         $productId The product, locked.
	 * @param ProductSave $command   The save.
	 * @param int         $postId    Receives the post: the one the save names, or the one it creates.
	 * @return Product The product, with the post's binding added.
	 */
	private function joined( int $productId, ProductSave $command, int &$postId ): Product {
		$this->mayJoin( $productId, $command );

		if ( null === $command->postId() || $this->hasPostFields( $command ) ) {
			$postId = $this->posts->write( $this->postFields( $command ) );
		}

		$locale = $command->locale() ?? $this->locales->localeOf( $postId ) ?? $this->locales->siteLocale();

		$this->products->addBinding( $productId, new ProductPostBinding( $postId, $locale, $this->clock->now() ) );
		$this->assign( $command, $postId, $locale, $command->translationOf() );

		// Read under the lock the window took: the product as it is now, with the post's binding.
		$product = $this->products->find( $productId );

		if ( null === $product ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		return $product;
	}

	/**
	 * Refuses, under the product's lock and before anything is written, a join the actor may not make: one that names a post that no longer presents the product, or that the actor may not edit the product through.
	 *
	 * A save that names the post it translates, or that joins with commerce fields, changes the
	 * product the post joins, so it needs `edit_post` on that product's source post. The endpoint
	 * asked the same question before the save began; asked again here, the answer holds for the
	 * product as the join finds it, even if its source post changed in between. A save that joins
	 * a translation group with no commerce field is left to the multilingual setup's authority.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException CatalogError::WriteConflict when the post the save names presents another product by now,
	 *                        or none; `authorization.denied` when the actor may not edit the product.
	 *
	 * @param int         $productId The product, locked.
	 * @param ProductSave $command   The save.
	 */
	private function mayJoin( int $productId, ProductSave $command ): void {
		$translationOf = $command->translationOf();

		if ( null === $translationOf && ! self::givesCommerce( $command ) ) {
			return;
		}

		$product = $this->products->lock( $productId );

		if ( null === $product || ( null !== $translationOf && null === $product->bindingOf( $translationOf ) ) ) {
			CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => (int) $translationOf ) );
		}

		$this->mayEdit( $product, $command->actor() );
	}

	/**
	 * Refuses a save the actor may not make through a post that is not the product's source post: `edit_post` on the product's source post.
	 *
	 * The product's SKU, price and stock are one for all its posts, so whoever changes them through
	 * a translation must be allowed to edit the product itself.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not edit the product's source post.
	 *
	 * @param Product $product The product, locked.
	 * @param Actor   $actor   On whose authority the save runs.
	 */
	private function mayEdit( Product $product, Actor $actor ): void {
		$this->authorizer->authorize( $actor, ProductCapabilities::map()['edit_post'], (int) $product->sourcePostId() );
	}

	/**
	 * Tells whether a save gives any commerce field.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The save.
	 * @return bool True when it does.
	 */
	private static function givesCommerce( ProductSave $command ): bool {
		return null !== $command->commerce() && array() !== $command->commerce()->fields();
	}

	/**
	 * Finishes a window: the stock items, the settle statement and the event.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every variant of the published generation's stock item.
	 *
	 * @param Product  $product The stored product, with its ids.
	 * @param string[] $changed The fields the save wrote.
	 * @return Product The product, settled.
	 *
	 * @phpstan-param list<string> $changed
	 */
	private function finish( Product $product, array $changed ): Product {
		$variantIds = array_map( static fn( Variant $variant ): int => (int) $variant->id(), $product->variants() );

		if ( array() !== $variantIds ) {
			$this->stock->createItems( $variantIds );
		}

		$settled = $product->settle( array() !== $variantIds );

		if ( ! $this->products->leaveUpdating( (int) $product->id(), $settled ) ) {
			CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => (int) $product->sourcePostId() ) );
		}

		$product->markSaved( $changed, $this->clock->now() );

		$this->events->publish( ...$product->releaseEvents() );

		return $product;
	}

	/**
	 * Applies the commerce fields a save gives: the single variant's fields, or the options and the variant set.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The options and the variant set.
	 *
	 * @param Product                   $product The product.
	 * @param ProductSave               $command The save.
	 * @param Currency                  $base    The store's base currency.
	 * @param array<string, mixed>|null $set     The options and variants the save gives, or null for none.
	 * @return list<string> The fields the save wrote: the commerce fields it gives, `prices` when a variant's prices are
	 *                      among them, and POST_FIELDS when it writes the post.
	 *
	 * @phpstan-param GivenSet|null $set
	 */
	private function applyCommerce( Product $product, ProductSave $command, Currency $base, ?array $set ): array {
		$changed = $this->hasPostFields( $command ) || null === $command->postId() ? array( self::POST_FIELDS ) : array();
		$input   = $command->commerce();

		if ( null === $input || array() === $input->fields() ) {
			return $changed;
		}

		if ( null !== $set ) {
			$this->findSuperseded( $product, array_map( static fn( VariantInput $entry ): VariantSelection => $entry->selection, $set['variants'] ?? array() ) );
			$this->applyVariantSet( $product, $set, $base );

			return array_merge( $changed, $input->fields(), $set['prices'] ? array( Product::PRICES ) : array() );
		}

		$this->findSuperseded( $product, array( VariantSelection::none() ) );
		$this->applyFlatFields( $product, $input, $base );

		return array_merge( $changed, $input->fields() );
	}

	/**
	 * Reads the variants of earlier generations among the combinations a save names, before the save is applied, so that each is applied as a stored variant is.
	 *
	 * One statement, under the product's lock, and only for a product past its first generation
	 * that is given a combination outside the one it publishes. Nothing else tells whether such a
	 * combination has a row, so a save that could bring one back and does not pays the read too, and
	 * finds nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Product            $product    The product, as read under its lock.
	 * @param VariantSelection[] $selections The combinations the save names.
	 *
	 * @phpstan-param list<VariantSelection> $selections
	 */
	private function findSuperseded( Product $product, array $selections ): void {
		$outside = $product->possiblySuperseded( $selections );

		if ( array() !== $outside ) {
			$product->loadSuperseded( $this->products->variantsOfCombinations( (int) $product->id(), $outside ) );
		}
	}

	/**
	 * Gives the product the options and the variant set a save gives.
	 *
	 * Options given without a variant set keep every variant they still admit as it is stored; the
	 * others are disabled when the axis keys stay, and left behind with their generation when they
	 * change. A variant that lost a value is among the others, whatever the options make of the values
	 * it has left: its row stands for the whole combination, so only that combination can name it.
	 *
	 * @since 0.2.0
	 *
	 * @param Product              $product The product.
	 * @param array<string, mixed> $set     The options and variants the save gives.
	 * @param Currency             $base    The store's base currency.
	 *
	 * @phpstan-param GivenSet $set
	 */
	private function applyVariantSet( Product $product, array $set, Currency $base ): void {
		if ( null !== $set['options'] ) {
			$product->applyOptions( $set['options'] );
		}

		$variants = $set['variants'] ?? array_values(
			array_map(
				static fn( Variant $variant ): VariantInput => new VariantInput( $variant->uuid(), $variant->selection(), $variant->hasSku() ? $variant->sku() : null, $variant->isEnabled(), true, $variant->weightGrams(), $variant->position() ),
				array_filter( $product->variants(), static fn( Variant $variant ): bool => $product->options()->admits( $variant->selection() ) && $variant->selection()->hash() === $variant->combinationHash() )
			)
		);

		// The cap is on what a save gives: rows a set left out can take a generation past it, and options alone name them all again.
		$cap = null === $set['variants'] ? max( Product::MAX_SYNC_VARIANTS, count( $variants ) ) : Product::MAX_SYNC_VARIANTS;

		$product->applyVariants( $variants, ( $this->offeredCurrencies )(), $base, $cap );
	}

	/**
	 * Applies the single variant's fields to a product without options, creating its variant when the save names its SKU, or bringing back the one an earlier generation holds.
	 *
	 * A field the save leaves out keeps its value; a price the save gives as null is removed. The
	 * flat fields name no basis: a price keeps the stored price's, and one with none before it is net.
	 * A product with options has no single variant for them to set: the check before the lock saw it
	 * without options when a save that gave it some came first, and the save is refused as it would
	 * have been then.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::VariantsAmbiguous when the product has options.
	 *
	 * @param Product       $product The product.
	 * @param CommerceInput $input   The fields the save gives.
	 * @param Currency      $base    The store's base currency.
	 */
	private function applyFlatFields( Product $product, CommerceInput $input, Currency $base ): void {
		if ( ! $product->options()->isEmpty() ) {
			CodedException::raise( CatalogError::VariantsAmbiguous );
		}

		$variant = $product->defaultVariant();
		$sku     = $input->has( CommerceFields::SKU ) ? Variant::givenSku( (string) $input->sku() ) : $variant?->sku();

		if ( null === $sku ) {
			CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => '' ) );
		}

		if ( null === $variant ) {
			$variant = $product->giveDefaultVariant( Variant::byDefault( $this->ids->generate(), $sku ) );
		}

		$stored     = $variant->basePrice();
		$priceMinor = $input->has( CommerceFields::PRICE_MINOR ) ? $input->priceMinor() : $stored?->priceMinor();
		$compareAt  = $input->has( CommerceFields::COMPARE_AT_MINOR ) ? $input->compareAtMinor() : $stored?->compareAtMinor();
		$currency   = $input->has( CommerceFields::CURRENCY ) ? Currency::of( (string) $input->currency() ) : ( $stored?->currency() ?? $base );
		$weight     = $input->has( CommerceFields::WEIGHT_GRAMS ) ? $input->weightGrams() : $variant->weightGrams();

		$product->applyCommerce( $sku, null === $priceMinor ? null : VariantPrice::net( $currency, $priceMinor, $compareAt )->onBasisOf( $stored ), $weight, $base );
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
	 * Builds the result of a committed save, with the verdict read after the commit, one statement for every variant: in the locale of the post the save came through.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The id of every variant by combination hash, and the verdict on the product.
	 *
	 * @param Product $product The saved product.
	 * @param int     $postId  The post the save wrote or bound.
	 * @param bool    $created Whether the save created the post.
	 * @return SaveResult The result.
	 */
	private function result( Product $product, int $postId, bool $created ): SaveResult {
		$locale     = $postId === $product->sourcePostId() ? null : $product->bindingOf( $postId )?->locale();
		$variantIds = array();

		foreach ( $product->variants() as $variant ) {
			$variantIds[ $variant->combinationHash() ] = (int) $variant->id();
		}

		return new SaveResult(
			(int) $product->id(),
			$postId,
			$product->defaultVariant()?->id(),
			$created,
			$product->generation(),
			array() === $variantIds ? null : $this->sellability->ofProduct( $product, false, $locale ),
			$variantIds
		);
	}

	/**
	 * Refuses, before anything is written, the single variant's fields given with a variant set, a SKU that is not valid and a single price in another currency than the base one.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The single variant's fields with a variant set.
	 *
	 * @param CommerceInput|null $commerce The commerce fields the save gives.
	 * @param Currency           $base     The store's base currency.
	 */
	private static function refuseBeforeWriting( ?CommerceInput $commerce, Currency $base ): void {
		if ( null === $commerce ) {
			return;
		}

		if ( $commerce->givesFlatFields() && ( $commerce->has( VariantFields::VARIANTS ) || $commerce->has( VariantFields::OPTIONS ) ) ) {
			CodedException::raise( CatalogError::VariantsAmbiguous );
		}

		if ( $commerce->has( CommerceFields::SKU ) ) {
			Variant::givenSku( (string) $commerce->sku() );
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
