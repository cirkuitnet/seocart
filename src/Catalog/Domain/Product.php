<?php
/**
 * Product: the commerce identity of what a store sells, bound to the posts that present it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Catalog\Domain\Event\ProductBindingPromoted;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\Event\ProductSaved;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Events\RecordsEvents;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A LogicException names a programming error for the developer; it is never rendered.

/**
 * A product: the aggregate root of the catalog.
 *
 * Owns one fact: what makes a product whole. A product has its own identity, independent of any
 * post, and is bound to the posts that present it, one per locale; the source binding, recorded
 * by its post id, is the one that controls the product's existence. It has option axes, each with
 * its values, and the variants of the generation it publishes, one per combination of those
 * values; a product without options has one variant, the default, whose combination is the empty
 * one. settle() is the one rule that decides whether the marker may say `complete`: the product is
 * bound through its source post, has at least one enabled variant in the generation it publishes,
 * every enabled variant has a price in the store's base currency, and the stock items exist.
 * Every writer asks it, and none restates it.
 *
 * Two ways a product begins. firstBinding() is the store binding a post for the first time: the
 * product starts `updating`, with its default variant at the first generation, which is also the
 * generation it publishes, or without a variant when the first save names no SKU. reconciled() is
 * the store finding a product post it did not write: the product starts `incomplete`, without a
 * variant, and publishes no generation. A product without a variant is given one by
 * giveDefaultVariant() or applyVariants() on a later save.
 *
 * A save gives the product its whole set of variants with applyVariants(), after its options with
 * applyOptions() when it changes them. The variants are written in the generation the product
 * publishes as long as the axis keys stay the same: a combination it keeps keeps its variant, its
 * id and its stock; one the set leaves out is disabled, never deleted. When the axis keys change,
 * every combination changes with them, and the set is written as the next generation, which the
 * product publishes instead of the one before; that one's variants stay, unpublished, for the
 * carts, orders and ledgers that name them.
 *
 * The variants and bindings are written only through this root, and the events it records are
 * released by the service that saved it.
 *
 * @since 0.1.0
 */
final class Product {

	use RecordsEvents;

	/**
	 * The generation a product publishes before it has one: none.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const NO_GENERATION = 0;

	/**
	 * The most variants one save writes: the set is swapped in the save's own transaction.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_SYNC_VARIANTS = 250;

	/**
	 * The most option axes a product may have.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_AXES = 10;

	/**
	 * The most option values a product may have, its axes' together.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_VALUES = 100;

	/**
	 * The name ProductSaved gives the options among the changed fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTIONS = 'options';

	/**
	 * The name ProductSaved gives the variant set among the changed fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANTS = 'variants';

	/**
	 * The name ProductSaved gives the variants' prices among the changed fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRICES = 'prices';

	/**
	 * The id storage gave the product, or null before it is stored.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $uuid;

	/**
	 * The id of the source post, or null when it has none.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $sourcePostId;

	/**
	 * The posts that present the product, one per locale.
	 *
	 * @since 0.1.0
	 *
	 * @var list<ProductPostBinding>
	 */
	private array $bindings;

	/**
	 * The generation marker.
	 *
	 * @since 0.1.0
	 *
	 * @var GenerationState
	 */
	private GenerationState $generation;

	/**
	 * The generation of variants the product publishes, NO_GENERATION before it has one.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $activeGeneration;

	/**
	 * The generation the product published when it was loaded: the one a write must still find to publish another.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $loadedGeneration;

	/**
	 * The variants of the generation the product publishes, in position order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Variant>
	 */
	private array $variants;

	/**
	 * The variants as loaded, before any change of this write.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Variant>
	 */
	private array $loadedVariants;

	/**
	 * The option axes and their values.
	 *
	 * @since 0.2.0
	 *
	 * @var OptionMatrix
	 */
	private OptionMatrix $options;

	/**
	 * The option axes as loaded.
	 *
	 * @since 0.2.0
	 *
	 * @var OptionMatrix
	 */
	private OptionMatrix $loadedOptions;

	/**
	 * The variants of the loaded generation that the set applyVariants() was given leaves out, disabled; none when the set starts a new generation.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Variant>
	 */
	private array $leftOut = array();

	/**
	 * The variants of earlier generations a save found for the combinations it names, as stored, by combination hash.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, Variant>
	 */
	private array $superseded = array();

	/**
	 * Creates a product from checked values.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every variant of the generation it publishes, and its options.
	 *
	 * @param int|null             $id               The stored id, or null.
	 * @param string               $uuid             The public identifier.
	 * @param int|null             $sourcePostId     The source post's id, or null.
	 * @param ProductPostBinding[] $bindings         The bindings.
	 * @param GenerationState      $generation       The marker.
	 * @param int                  $activeGeneration The published generation.
	 * @param Variant[]            $variants         The variants of that generation.
	 * @param OptionMatrix         $options          The option axes and values.
	 */
	private function __construct( ?int $id, string $uuid, ?int $sourcePostId, array $bindings, GenerationState $generation, int $activeGeneration, array $variants, OptionMatrix $options ) {
		$this->id               = $id;
		$this->uuid             = $uuid;
		$this->sourcePostId     = $sourcePostId;
		$this->bindings         = array_values( $bindings );
		$this->generation       = $generation;
		$this->activeGeneration = $activeGeneration;
		$this->loadedGeneration = $activeGeneration;
		$this->variants         = self::ordered( $variants );
		$this->loadedVariants   = $this->variants;
		$this->options          = $options;
		$this->loadedOptions    = $options;
	}

	/**
	 * Returns a product being bound to its first post: `updating`, with its default variant published when it has one.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $uuid    The product's public identifier, from the IdGenerator.
	 * @param int                $postId  The post, which becomes the source binding.
	 * @param Locale             $locale  The locale of the post's content.
	 * @param Variant|null       $variant The default variant, not yet stored, or null when the first save names no SKU.
	 * @param \DateTimeImmutable $at      When the post is bound.
	 * @return self The product, not yet stored.
	 */
	public static function firstBinding( string $uuid, int $postId, Locale $locale, ?Variant $variant, \DateTimeImmutable $at ): self {
		return new self( null, $uuid, $postId, array( new ProductPostBinding( $postId, $locale, $at ) ), GenerationState::Updating, $variant?->generation() ?? self::NO_GENERATION, null === $variant ? array() : array( $variant ), OptionMatrix::none() );
	}

	/**
	 * Returns the product for a product post the store did not write: `incomplete`, bound, and without a variant.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $uuid   The product's public identifier, from the IdGenerator.
	 * @param int                $postId The post, which becomes the source binding.
	 * @param Locale             $locale The locale of the post's content.
	 * @param \DateTimeImmutable $at     When the post is bound.
	 * @return self The product, not yet stored.
	 */
	public static function reconciled( string $uuid, int $postId, Locale $locale, \DateTimeImmutable $at ): self {
		return new self( null, $uuid, $postId, array( new ProductPostBinding( $postId, $locale, $at ) ), GenerationState::Incomplete, self::NO_GENERATION, array(), OptionMatrix::none() );
	}

	/**
	 * Returns a product as it was stored.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every variant of the generation it publishes, and its options.
	 *
	 * @param int                  $id               The stored id.
	 * @param string               $uuid             The public identifier.
	 * @param int|null             $sourcePostId     The source post's id, or null.
	 * @param ProductPostBinding[] $bindings         The bindings.
	 * @param GenerationState      $generation       The marker.
	 * @param int                  $activeGeneration The published generation.
	 * @param Variant[]            $variants         The variants of that generation, in any order.
	 * @param OptionMatrix|null    $options          Optional. The option axes and values. Default null, none.
	 * @return self The product.
	 */
	public static function stored( int $id, string $uuid, ?int $sourcePostId, array $bindings, GenerationState $generation, int $activeGeneration, array $variants, ?OptionMatrix $options = null ): self {
		return new self( $id, $uuid, $sourcePostId, $bindings, $generation, $activeGeneration, $variants, $options ?? OptionMatrix::none() );
	}

	/**
	 * Records the ids storage gave the product and its variants. Only the repository calls this.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The variants' ids, by combination hash.
	 *
	 * @throws \LogicException When the product already has another id, a variant without an id has none in the map, or
	 *                         a variant that has one is given another.
	 *
	 * @param int                $id         The product's id.
	 * @param array<string, int> $variantIds The id of each variant written, by combination hash.
	 */
	public function identify( int $id, array $variantIds ): void {
		if ( null !== $this->id && $this->id !== $id ) {
			throw new \LogicException( sprintf( 'Product %s is stored as %d, not %d.', $this->uuid, $this->id, $id ) );
		}

		$identified = array();

		foreach ( $this->variants as $variant ) {
			$given = $variantIds[ $variant->combinationHash() ] ?? null;

			if ( null === $given && null === $variant->id() ) {
				throw new \LogicException( sprintf( 'Product %s: the variant of combination %s was not given its id.', $this->uuid, $variant->combinationHash() ) );
			}

			if ( null !== $given && null !== $variant->id() && $given !== $variant->id() ) {
				throw new \LogicException( sprintf( 'Product %s: the variant stored as %d cannot become %d.', $this->uuid, $variant->id(), $given ) );
			}

			$identified[] = null === $given ? $variant : $variant->withId( $given );
		}

		$this->id       = $id;
		$this->variants = $identified;
	}

	/**
	 * Gives a product without a variant its default variant, which it then publishes: the one of an earlier generation loadSuperseded() found, as it is stored, or else the one given.
	 *
	 * The variant joins the generation the product publishes; a product that publishes none yet
	 * publishes the variant's.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The default variant of an earlier generation comes back, and it is returned.
	 *
	 * @throws \LogicException When the product already has a variant.
	 *
	 * @param Variant $variant The default variant, not yet stored.
	 * @return Variant The default variant the product now publishes.
	 */
	public function giveDefaultVariant( Variant $variant ): Variant {
		if ( array() !== $this->variants ) {
			throw new \LogicException( sprintf( 'Product %s already has its variants.', $this->uuid ) );
		}

		$variant = $this->superseded[ $variant->combinationHash() ] ?? $variant;

		if ( self::NO_GENERATION !== $this->activeGeneration ) {
			$variant = $variant->withState( $variant->sku(), $variant->isEnabled(), $variant->weightGrams(), $variant->position(), $variant->label(), $this->activeGeneration );
		}

		$this->variants         = array( $variant );
		$this->activeGeneration = $variant->generation();

		return $variant;
	}

	/**
	 * Sets the default variant's SKU, base price and weight: the single variant of a product without options.
	 *
	 * The price must be in the store's base currency: a price in any other is refused with
	 * CatalogError::CurrencyNotBase. A product with options has no single variant to take them,
	 * so the fields are refused with CatalogError::VariantsAmbiguous.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product has no default variant.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param Sku               $sku          The SKU.
	 * @param VariantPrice|null $basePrice    The price, or null for none; a product without one cannot be complete.
	 * @param int|null          $weightGrams  The weight in grams, or null for none.
	 * @param Currency          $baseCurrency The store's base currency.
	 */
	public function applyCommerce( Sku $sku, ?VariantPrice $basePrice, ?int $weightGrams, Currency $baseCurrency ): void {
		if ( ! $this->options->isEmpty() ) {
			CodedException::raise( CatalogError::VariantsAmbiguous );
		}

		$variant = $this->defaultVariant();

		if ( null === $variant ) {
			throw new \LogicException( sprintf( 'Product %s has no default variant to take a SKU and a price.', $this->uuid ) );
		}

		if ( null !== $basePrice && ! $basePrice->currency()->equals( $baseCurrency ) ) {
			CodedException::raise(
				CatalogError::CurrencyNotBase,
				array(
					'currency'      => $basePrice->currency()->code(),
					'base_currency' => $baseCurrency->code(),
				)
			);
		}

		$this->variants = array( $variant->withCommerce( $sku, $basePrice, $weightGrams ) );
	}

	/**
	 * Gives the product its whole set of option axes and values.
	 *
	 * The set replaces the stored one: an axis or a value left out is removed, a key given for the
	 * first time is a new axis or value. Keys never change; a label, a position, an image or a swatch
	 * may. The variants follow with applyVariants().
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::TooManyOptions when the set has more than MAX_AXES axes or
	 *                        MAX_VALUES values in all.
	 *
	 * @param OptionMatrix $desired The axes and their values.
	 */
	public function applyOptions( OptionMatrix $desired ): void {
		self::refuseOptions( $desired );

		$this->options = $desired;
	}

	/**
	 * Refuses option axes no product may have: more than MAX_AXES axes, or more than MAX_VALUES values in all.
	 *
	 * The one rule applyOptions() applies; a save asks it before it writes anything.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::TooManyOptions.
	 *
	 * @param OptionMatrix $desired The axes and their values.
	 */
	public static function refuseOptions( OptionMatrix $desired ): void {
		if ( count( $desired->axes ) > self::MAX_AXES || $desired->valueCount() > self::MAX_VALUES ) {
			CodedException::raise(
				CatalogError::TooManyOptions,
				array(
					'axes'        => count( $desired->axes ),
					'values'      => $desired->valueCount(),
					'most_axes'   => self::MAX_AXES,
					'most_values' => self::MAX_VALUES,
				)
			);
		}
	}

	/**
	 * Gives the product its whole set of variants, one per combination of its options.
	 *
	 * Everything the set can be refused for is checked before the product changes. When the axis
	 * keys are the ones the product had when it was loaded, the set is written in the generation it
	 * publishes: a combination it already has keeps its variant, and a variant the set leaves out
	 * is disabled, keeping its id, its SKU and its stock. When the axis keys changed, the set becomes
	 * the next generation. A product that publishes no generation yet starts the first. A combination
	 * an earlier generation holds, as loadSuperseded() found it, is applied as a stored variant is: it
	 * keeps its id, and what the set does not give keeps what its row held, but that its SKU must be
	 * given. A variant without a SKU keeps its placeholder while the set leaves it disabled and gives
	 * it none. A variant the set names takes the selection the set names it by: a value removed took
	 * its row of the selection with it, so a variant whose value is back has its whole combination again.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::TooManyVariants, CombinationInvalid, CombinationDuplicate,
	 *                        CurrencyNotOffered, PriceDuplicate, SkuDuplicate or SkuInvalid.
	 *
	 * @param VariantInput[] $desired The set, in the order it is given.
	 * @param Currency[]     $offered The currencies the store offers, its base currency among them.
	 * @param Currency       $base    The store's base currency.
	 * @param int            $cap     The most variants the set may have.
	 *
	 * @phpstan-param list<VariantInput> $desired
	 * @phpstan-param list<Currency>     $offered
	 */
	public function applyVariants( array $desired, array $offered, Currency $base, int $cap ): void {
		$stored = $this->storedVariantsUnder( $this->options );

		self::refuseVariants( $this->options, $desired, $offered, $cap, $stored );

		$next     = self::nextGeneration( $this->loadedGeneration, $this->options->hasAxesOf( $this->loadedOptions ) );
		$variants = array();

		foreach ( $desired as $index => $input ) {
			$hash     = $input->selection->hash();
			$existing = $stored[ $hash ] ?? $this->superseded[ $hash ] ?? null;
			$sku      = self::skuOf( $input, $stored[ $hash ] ?? null );
			$variant  = $existing ?? Variant::ofCombination( $input->uuid, $input->selection, $sku, $next );

			unset( $stored[ $hash ] );

			$variant = $variant->withState(
				$sku,
				$input->enabled ?? $existing?->isEnabled() ?? true,
				$input->weightGiven ? $input->weightGrams : $existing?->weightGrams(),
				$input->position ?? $index,
				$this->options->labelOf( $input->selection ),
				$next
			)->withSelection( $input->selection );

			if ( null !== $input->prices ) {
				$variant = $variant->withPrices( $input->prices, $base );
			}

			$variants[] = $variant;
		}

		// What the set leaves out of the generation it is written in stays there, disabled.
		$this->leftOut = array_values( array_map( static fn( Variant $variant ): Variant => $variant->disabled(), $stored ) );

		$this->variants         = self::ordered( array_merge( $variants, $this->leftOut ) );
		$this->activeGeneration = $next;
	}

	/**
	 * Returns the selections among those a save gives whose combination only an earlier generation can hold: the ones storage must be asked for before the save is applied.
	 *
	 * The product knows the generation it publishes alone. A product not yet stored has no earlier
	 * generation, nor has one that has published its first at most, the generation moving only when
	 * the axis keys change: the answer for those is none, and nothing need be read.
	 *
	 * @since 0.2.0
	 *
	 * @param VariantSelection[] $selections The selections.
	 * @return array<string, VariantSelection> The ones outside the generation the product published when it was loaded, by combination hash.
	 *
	 * @phpstan-param list<VariantSelection> $selections
	 */
	public function possiblySuperseded( array $selections ): array {
		if ( null === $this->id || $this->loadedGeneration <= Variant::FIRST_GENERATION ) {
			return array();
		}

		$outside = array();

		foreach ( $selections as $selection ) {
			$outside[ $selection->hash() ] = $selection;
		}

		foreach ( $this->loadedVariants as $variant ) {
			unset( $outside[ $variant->combinationHash() ] );
		}

		return $outside;
	}

	/**
	 * Gives the product the variants of earlier generations a save names, as stored, for the save to apply as it applies the ones the product publishes.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant[] $variants The variants, read under the product's lock.
	 *
	 * @phpstan-param list<Variant> $variants
	 */
	public function loadSuperseded( array $variants ): void {
		foreach ( $variants as $variant ) {
			$this->superseded[ $variant->combinationHash() ] = $variant;
		}
	}

	/**
	 * Decides and records the marker a finished write leaves: Complete when the product is whole, Incomplete otherwise.
	 *
	 * Whole means: bound through its source post, with at least one enabled variant in the
	 * generation it publishes, every enabled variant with its price in the base currency, and
	 * the stock items of its variants existing. A disabled variant may lack a price. Missing any of
	 * them is a state, not an error.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every enabled variant of the published generation.
	 *
	 * @param bool $stockItemsKnown Whether the stock items of the product's variants exist.
	 * @return GenerationState The marker, now also the product's.
	 */
	public function settle( bool $stockItemsKnown ): GenerationState {
		$enabled = array_filter( $this->variants, static fn( Variant $variant ): bool => $variant->isEnabled() );
		$priced  = array_filter( $enabled, static fn( Variant $variant ): bool => null !== $variant->basePrice() );

		$whole = $this->isBoundToItsSource()
			&& array() !== $enabled
			&& count( $priced ) === count( $enabled )
			&& $stockItemsKnown;

		$this->generation = $whole ? GenerationState::Complete : GenerationState::Incomplete;

		return $this->generation;
	}

	/**
	 * Records that the product was saved.
	 *
	 * The event carries the default variant's SKU, price and currency, which a product with
	 * options does not have: they are null for it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product is not stored yet or has no source post.
	 *
	 * @param string[]           $changedFields The names of the fields the save changed.
	 * @param \DateTimeImmutable $at            When it was saved.
	 */
	public function markSaved( array $changedFields, \DateTimeImmutable $at ): void {
		$default = $this->defaultVariant();
		$price   = $default?->basePrice();

		$this->recordThat(
			new ProductSaved(
				$this->storedId(),
				$this->sourcePostIdOrFail(),
				array_values( array_map( 'strval', $changedFields ) ),
				$default?->sku()->toString(),
				$price?->priceMinor(),
				$price?->currency()->code(),
				$at
			)
		);
	}

	/**
	 * Records that the product was deleted, with the SKUs its variants had.
	 *
	 * The SKUs are the ones the deletion read under the product's lock, of every variant it
	 * deleted, of every generation: the aggregate knows the variants of the published one only.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product is not stored yet or has no source post.
	 *
	 * @param string[]           $skus The SKUs of the variants deleted with it, in the order of their ids.
	 * @param \DateTimeImmutable $at   When it was deleted.
	 *
	 * @phpstan-param list<string> $skus
	 */
	public function markDeleted( array $skus, \DateTimeImmutable $at ): void {
		$this->recordThat( new ProductDeleted( $this->storedId(), $this->sourcePostIdOrFail(), $skus, $at ) );
	}

	/**
	 * Records that another of the product's posts became its source post; the product must be loaded as it is after the move.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product is not stored, has no source post, or the post that was the source is not among its bindings.
	 *
	 * @param int                $fromPostId The post that was the source.
	 * @param string             $actorType  `user` for a user in person, `system` for a process acting for one.
	 * @param int|null           $actorId    The user, or null when no one was logged in.
	 * @param \DateTimeImmutable $at         When the source moved.
	 */
	public function markPromoted( int $fromPostId, string $actorType, ?int $actorId, \DateTimeImmutable $at ): void {
		$toPostId = $this->sourcePostIdOrFail();
		$from     = $this->bindingOf( $fromPostId );
		$to       = $this->bindingOf( $toPostId );

		if ( null === $from || null === $to ) {
			throw new \LogicException( sprintf( 'Product %s is bound neither to post %d nor to post %d as both source posts of a promotion.', $this->uuid, $fromPostId, $toPostId ) );
		}

		$this->recordThat( new ProductBindingPromoted( $this->storedId(), $fromPostId, $from->locale()->toString(), $toPostId, $to->locale()->toString(), $actorType, $actorId, $at ) );
	}

	/**
	 * Tells whether the product holds nothing but its one post: no generation of variants yet, so no SKU, no price and no stock, and no other binding.
	 *
	 * Such a product is what a reconciled post gets. It can give way to the product the post
	 * turns out to translate, since nothing was ever sold or published for it. A product past that
	 * point never does, even when the generation it publishes has no variant: a save that changes
	 * its axes can leave it so, and the variants of its earlier generations, with their SKUs and
	 * their stock, stay in storage out of the product's sight.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for an incomplete product without a generation, bound only to its source post.
	 */
	public function holdsNothing(): bool {
		return GenerationState::Incomplete === $this->generation
			&& array() === $this->variants
			&& self::NO_GENERATION === $this->activeGeneration
			&& 1 === count( $this->bindings )
			&& $this->isBoundToItsSource();
	}

	/**
	 * Tells whether the product holds commerce data: a variant, with its SKU, or a generation, whose variants an earlier save may have left behind in storage.
	 *
	 * A post that presents such a product is never moved onto another product by a change of its
	 * translation group: the product's SKU, price and stock would lose it without a merchant asking.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the product has a variant, or has started a generation of them.
	 */
	public function hasCommerceData(): bool {
		return array() !== $this->variants || self::NO_GENERATION !== $this->activeGeneration;
	}

	/**
	 * Returns the product's binding to a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return ProductPostBinding|null The binding, or null when the post does not present the product.
	 */
	public function bindingOf( int $postId ): ?ProductPostBinding {
		foreach ( $this->bindings as $binding ) {
			if ( $binding->postId() === $postId ) {
				return $binding;
			}
		}

		return null;
	}

	/**
	 * Returns the stored id.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The id, or null before the product is stored.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Returns the public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string A lowercase UUID.
	 */
	public function uuid(): string {
		return $this->uuid;
	}

	/**
	 * Returns the id of the source post.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The id, or null when the product has none.
	 */
	public function sourcePostId(): ?int {
		return $this->sourcePostId;
	}

	/**
	 * Returns the posts that present the product.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ProductPostBinding> One per locale.
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	/**
	 * Returns the generation marker.
	 *
	 * @since 0.1.0
	 *
	 * @return GenerationState The marker.
	 */
	public function generation(): GenerationState {
		return $this->generation;
	}

	/**
	 * Returns the generation of variants the product publishes.
	 *
	 * @since 0.1.0
	 *
	 * @return int The generation, or NO_GENERATION.
	 */
	public function activeGeneration(): int {
		return $this->activeGeneration;
	}

	/**
	 * Returns the generation the product published when it was loaded, which the write that publishes its generation must still find.
	 *
	 * @since 0.2.0
	 *
	 * @return int The generation, or NO_GENERATION.
	 */
	public function loadedGeneration(): int {
		return $this->loadedGeneration;
	}

	/**
	 * Returns the variants of the generation the product publishes.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Variant> The variants, by position, then by id.
	 */
	public function variants(): array {
		return $this->variants;
	}

	/**
	 * Returns the variants of the published generation that the set applyVariants() was given leaves out: disabled, kept.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Variant> The variants; none when no set was given, or when the set starts a new generation.
	 */
	public function leftOutVariants(): array {
		return $this->leftOut;
	}

	/**
	 * Returns the option axes and their values.
	 *
	 * @since 0.2.0
	 *
	 * @return OptionMatrix The matrix; empty for a product without options.
	 */
	public function options(): OptionMatrix {
		return $this->options;
	}

	/**
	 * Tells whether this write changed the option axes or values.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when they differ from the ones loaded.
	 */
	public function optionsChanged(): bool {
		return ! $this->options->equals( $this->loadedOptions );
	}

	/**
	 * Returns the option axes as the product had them when it was loaded.
	 *
	 * @since 0.2.0
	 *
	 * @return OptionMatrix The matrix.
	 */
	public function loadedOptions(): OptionMatrix {
		return $this->loadedOptions;
	}

	/**
	 * Returns the variants as storage holds them for this write.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Variant> The variants of the generation the product published when it was loaded, and the ones of
	 *                       earlier generations a save found.
	 */
	public function loadedVariants(): array {
		return array_merge( $this->loadedVariants, array_values( $this->superseded ) );
	}

	/**
	 * Returns the default variant: the variant of the empty combination.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Found among the published generation's variants.
	 *
	 * @return Variant|null The variant, or null for a product that has none, as a product with options has not.
	 */
	public function defaultVariant(): ?Variant {
		foreach ( $this->variants as $variant ) {
			if ( $variant->combinationHash() === Variant::defaultCombinationHash() ) {
				return $variant;
			}
		}

		return null;
	}

	/**
	 * Returns how many variants the generation the product publishes has.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number, the disabled ones included.
	 */
	public function variantCount(): int {
		return count( $this->variants );
	}

	/**
	 * Returns how many of them may be sold.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of enabled variants.
	 */
	public function enabledVariantCount(): int {
		return count( array_filter( $this->variants, static fn( Variant $variant ): bool => $variant->isEnabled() ) );
	}

	/**
	 * Returns the variants a set given under some options finds stored, by combination hash: the ones the product published when it was loaded, while the options keep its axis keys.
	 *
	 * Options with other axis keys find none: every combination of theirs is new.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionMatrix $options The options the set is given under.
	 * @return array<string, Variant> The variants, by combination hash.
	 */
	public function storedVariantsUnder( OptionMatrix $options ): array {
		$stored = array();

		if ( $options->hasAxesOf( $this->loadedOptions ) ) {
			foreach ( $this->loadedVariants as $variant ) {
				$stored[ $variant->combinationHash() ] = $variant;
			}
		}

		return $stored;
	}

	/**
	 * Refuses a variant set no product with these options may have: more variants than the cap, a combination the options do not admit or given twice, a price in a currency the store does not offer or given twice, a SKU given twice, a variant left without a SKU.
	 *
	 * The one rule applyVariants() applies; a save asks it before it writes anything. SKUs are
	 * compared here with their ASCII letters folded; storage compares them in its own collation as
	 * well, and a pair it finds equal is refused the same way. A variant given no SKU must be a
	 * stored one that has none and stays disabled.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::TooManyVariants, CombinationInvalid, CombinationDuplicate,
	 *                        CurrencyNotOffered, PriceDuplicate, SkuDuplicate or SkuInvalid.
	 *
	 * @param OptionMatrix           $options The options the set is a set of.
	 * @param VariantInput[]         $desired The set.
	 * @param Currency[]             $offered The currencies the store offers.
	 * @param int                    $cap     The most variants the set may have.
	 * @param array<string, Variant> $stored  Optional. The variants the set finds stored, by combination hash, as
	 *                                       storedVariantsUnder() returns them. Default none.
	 *
	 * @phpstan-param list<VariantInput> $desired
	 * @phpstan-param list<Currency>     $offered
	 */
	public static function refuseVariants( OptionMatrix $options, array $desired, array $offered, int $cap, array $stored = array() ): void {
		if ( count( $desired ) > $cap ) {
			CodedException::raise(
				CatalogError::TooManyVariants,
				array(
					'count' => count( $desired ),
					'limit' => $cap,
				)
			);
		}

		$offeredCodes = array_map( static fn( Currency $currency ): string => $currency->code(), $offered );
		$hashes       = array();
		$skus         = array();

		foreach ( $desired as $input ) {
			$combination = $input->selection->toString();

			if ( ! $options->admits( $input->selection ) ) {
				CodedException::raise( CatalogError::CombinationInvalid, array( 'combination' => $combination ) );
			}

			if ( isset( $hashes[ $input->selection->hash() ] ) ) {
				CodedException::raise( CatalogError::CombinationDuplicate, array( 'combination' => $combination ) );
			}

			$hashes[ $input->selection->hash() ] = true;

			self::refusePrices( $combination, $input->prices ?? array(), $offeredCodes );
			self::skuOf( $input, $stored[ $input->selection->hash() ] ?? null );

			if ( null !== $input->sku ) {
				$skus[ strtolower( $input->sku->toString() ) ][] = array( $input->sku->toString(), $combination );
			}
		}

		foreach ( $skus as $holders ) {
			if ( count( $holders ) > 1 ) {
				CodedException::raise(
					CatalogError::SkuDuplicate,
					array(
						'sku'          => $holders[0][0],
						'combinations' => implode( '; ', array_column( $holders, 1 ) ),
					)
				);
			}
		}
	}

	/**
	 * Returns the SKU a variant of a set is written with: the one given, or, when none is given, the placeholder of a stored variant that has no SKU and stays disabled.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::SkuInvalid when no SKU is given to a variant that is new, that has a
	 *                        SKU, or that would be enabled.
	 *
	 * @param VariantInput $input    The entry of the set.
	 * @param Variant|null $existing The variant its combination has, or null for a new one.
	 * @return Sku The SKU.
	 */
	private static function skuOf( VariantInput $input, ?Variant $existing ): Sku {
		if ( null !== $input->sku ) {
			return $input->sku;
		}

		// Only a variant whose SKU another variant took has none, and it is not sold until it has one.
		if ( null === $existing || $existing->hasSku() || ( $input->enabled ?? $existing->isEnabled() ) ) {
			CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => '' ) );
		}

		return $existing->sku();
	}

	/**
	 * Refuses a variant's prices in a currency the store does not offer, or two in one currency.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::CurrencyNotOffered or PriceDuplicate.
	 *
	 * @param string         $combination  The variant's combination, for the error.
	 * @param VariantPrice[] $prices       The prices given.
	 * @param string[]       $offeredCodes The codes of the currencies the store offers.
	 *
	 * @phpstan-param list<VariantPrice> $prices
	 * @phpstan-param list<string> $offeredCodes
	 */
	private static function refusePrices( string $combination, array $prices, array $offeredCodes ): void {
		$seen = array();

		foreach ( $prices as $price ) {
			$code = $price->currency()->code();

			if ( ! in_array( $code, $offeredCodes, true ) ) {
				CodedException::raise( CatalogError::CurrencyNotOffered, array( 'currency' => $code ) );
			}

			if ( isset( $seen[ $code ] ) ) {
				CodedException::raise(
					CatalogError::PriceDuplicate,
					array(
						'combination' => $combination,
						'currency'    => $code,
					)
				);
			}

			$seen[ $code ] = true;
		}
	}

	/**
	 * Returns the generation a variant set is written in.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $loaded   The generation the product published when it was loaded.
	 * @param bool $sameAxes Whether the set's axis keys are the ones the product had then.
	 * @return int The first generation for a product that published none; the one it publishes when the axis keys
	 *             stay; the next one when they changed.
	 */
	private static function nextGeneration( int $loaded, bool $sameAxes ): int {
		if ( self::NO_GENERATION === $loaded ) {
			return Variant::FIRST_GENERATION;
		}

		return $sameAxes ? $loaded : $loaded + 1;
	}

	/**
	 * Puts variants in their order: by position, then by id, a new one after the stored ones, then by combination hash.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant[] $variants The variants.
	 * @return list<Variant> The variants, in order.
	 */
	private static function ordered( array $variants ): array {
		$variants = array_values( $variants );

		usort(
			$variants,
			static fn( Variant $a, Variant $b ): int => array( $a->position(), $a->id() ?? PHP_INT_MAX, $a->combinationHash() ) <=> array( $b->position(), $b->id() ?? PHP_INT_MAX, $b->combinationHash() )
		);

		return $variants;
	}

	/**
	 * Tells whether the product has a source post among its bindings.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when one of its bindings is the source post.
	 */
	private function isBoundToItsSource(): bool {
		foreach ( $this->bindings as $binding ) {
			if ( $binding->postId() === $this->sourcePostId ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the stored id, which an event needs.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product is not stored yet.
	 *
	 * @return int The id.
	 */
	private function storedId(): int {
		if ( null === $this->id ) {
			throw new \LogicException( sprintf( 'Product %s is not stored yet, so no event can name it.', $this->uuid ) );
		}

		return $this->id;
	}

	/**
	 * Returns the source post's id, which an event needs.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product has no source post.
	 *
	 * @return int The id.
	 */
	private function sourcePostIdOrFail(): int {
		if ( null === $this->sourcePostId ) {
			throw new \LogicException( sprintf( 'Product %s has no source post.', $this->uuid ) );
		}

		return $this->sourcePostId;
	}
}
