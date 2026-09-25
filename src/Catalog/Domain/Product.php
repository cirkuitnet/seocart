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
 * by its post id, is the one that controls the product's existence. It has a default variant,
 * which carries the SKU and the price, and a generation marker. settle() is the one rule that
 * decides whether the marker may say `complete`: the product is bound through its source post,
 * has its default variant, that variant has a price in the store's base currency, and its stock
 * item exists. Every writer asks it, and none restates it.
 *
 * Two ways a product begins. firstBinding() is the store binding a post for the first time: the
 * product starts `updating`, with its default variant at the first generation, which is also the
 * generation it publishes, or without a variant when the first save names no SKU. reconciled() is
 * the store finding a product post it did not write: the product starts `incomplete`, without a
 * variant, and publishes no generation. A product without a variant is given one by
 * giveDefaultVariant() on a later save.
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
	 * The default variant, or null for a product that has none yet.
	 *
	 * @since 0.1.0
	 *
	 * @var Variant|null
	 */
	private ?Variant $defaultVariant;

	/**
	 * Creates a product from checked values.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null             $id               The stored id, or null.
	 * @param string               $uuid             The public identifier.
	 * @param int|null             $sourcePostId     The source post's id, or null.
	 * @param ProductPostBinding[] $bindings         The bindings.
	 * @param GenerationState      $generation       The marker.
	 * @param int                  $activeGeneration The published generation.
	 * @param Variant|null         $defaultVariant   The default variant, or null.
	 */
	private function __construct( ?int $id, string $uuid, ?int $sourcePostId, array $bindings, GenerationState $generation, int $activeGeneration, ?Variant $defaultVariant ) {
		$this->id               = $id;
		$this->uuid             = $uuid;
		$this->sourcePostId     = $sourcePostId;
		$this->bindings         = array_values( $bindings );
		$this->generation       = $generation;
		$this->activeGeneration = $activeGeneration;
		$this->defaultVariant   = $defaultVariant;
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
		return new self( null, $uuid, $postId, array( new ProductPostBinding( $postId, $locale, $at ) ), GenerationState::Updating, $variant?->generation() ?? self::NO_GENERATION, $variant );
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
		return new self( null, $uuid, $postId, array( new ProductPostBinding( $postId, $locale, $at ) ), GenerationState::Incomplete, self::NO_GENERATION, null );
	}

	/**
	 * Returns a product as it was stored.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id               The stored id.
	 * @param string               $uuid             The public identifier.
	 * @param int|null             $sourcePostId     The source post's id, or null.
	 * @param ProductPostBinding[] $bindings         The bindings.
	 * @param GenerationState      $generation       The marker.
	 * @param int                  $activeGeneration The published generation.
	 * @param Variant|null         $defaultVariant   The default variant, or null.
	 * @return self The product.
	 */
	public static function stored( int $id, string $uuid, ?int $sourcePostId, array $bindings, GenerationState $generation, int $activeGeneration, ?Variant $defaultVariant ): self {
		return new self( $id, $uuid, $sourcePostId, $bindings, $generation, $activeGeneration, $defaultVariant );
	}

	/**
	 * Records the ids storage gave the product and its default variant. Only the repository calls this.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product already has another id, or a variant id is given for a
	 *                         product without a variant.
	 *
	 * @param int      $id        The product's id.
	 * @param int|null $variantId The default variant's id, or null when the product has none.
	 */
	public function identify( int $id, ?int $variantId ): void {
		if ( null !== $this->id && $this->id !== $id ) {
			throw new \LogicException( sprintf( 'Product %s is stored as %d, not %d.', $this->uuid, $this->id, $id ) );
		}

		if ( ( null === $this->defaultVariant ) !== ( null === $variantId ) ) {
			throw new \LogicException( sprintf( 'Product %s: a variant id goes with a default variant, and only with one.', $this->uuid ) );
		}

		$this->id = $id;

		if ( null !== $this->defaultVariant && null !== $variantId ) {
			$this->defaultVariant = $this->defaultVariant->withId( $variantId );
		}
	}

	/**
	 * Gives a product without a variant its default variant, which it then publishes.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product already has a default variant.
	 *
	 * @param Variant $variant The default variant, not yet stored.
	 */
	public function giveDefaultVariant( Variant $variant ): void {
		if ( null !== $this->defaultVariant ) {
			throw new \LogicException( sprintf( 'Product %s already has its default variant.', $this->uuid ) );
		}

		$this->defaultVariant   = $variant;
		$this->activeGeneration = $variant->generation();
	}

	/**
	 * Sets the default variant's SKU, base price and weight.
	 *
	 * The price must be in the store's base currency: a price in any other is refused with
	 * CatalogError::CurrencyNotBase, because every other currency is derived from the base one.
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
		if ( null === $this->defaultVariant ) {
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

		$this->defaultVariant = $this->defaultVariant->withCommerce( $sku, $basePrice, $weightGrams );
	}

	/**
	 * Decides and records the marker a finished write leaves: Complete when the product is whole, Incomplete otherwise.
	 *
	 * Whole means: bound through its source post, with a default variant, whose price in the base
	 * currency is set, and whose stock item exists. Missing any of them is a state, not an error.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $stockItemKnown Whether the default variant's stock item exists.
	 * @return GenerationState The marker, now also the product's.
	 */
	public function settle( bool $stockItemKnown ): GenerationState {
		$whole = $this->isBoundToItsSource()
			&& null !== $this->defaultVariant
			&& null !== $this->defaultVariant->basePrice()
			&& $stockItemKnown;

		$this->generation = $whole ? GenerationState::Complete : GenerationState::Incomplete;

		return $this->generation;
	}

	/**
	 * Records that the product was saved.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the product is not stored yet or has no source post.
	 *
	 * @param string[]           $changedFields The names of the fields the save changed.
	 * @param \DateTimeImmutable $at            When it was saved.
	 */
	public function markSaved( array $changedFields, \DateTimeImmutable $at ): void {
		$price = $this->defaultVariant?->basePrice();

		$this->recordThat(
			new ProductSaved(
				$this->storedId(),
				$this->sourcePostIdOrFail(),
				array_values( array_map( 'strval', $changedFields ) ),
				$this->defaultVariant?->sku()->toString(),
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
	 * deleted: the aggregate knows its default variant only, as it was loaded.
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
	 * Returns the default variant.
	 *
	 * @since 0.1.0
	 *
	 * @return Variant|null The variant, or null for a product that has none yet.
	 */
	public function defaultVariant(): ?Variant {
		return $this->defaultVariant;
	}

	/**
	 * Returns how many variants the product has.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 or 1.
	 */
	public function variantCount(): int {
		return null === $this->defaultVariant ? 0 : 1;
	}

	/**
	 * Returns how many of its variants may be sold.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 or 1.
	 */
	public function enabledVariantCount(): int {
		return null !== $this->defaultVariant && $this->defaultVariant->isEnabled() ? 1 : 0;
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
