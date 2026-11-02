<?php
/**
 * Variant: one sellable combination of a product, with its SKU and prices
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A LogicException names a value built by code, for the developer; it is never rendered.

/**
 * A variant of a product: what a cart line and a stock item point at.
 *
 * Owns one fact: what a variant holds. Its identity (an id once stored, and a UUID from the
 * start), its SKU, the combination of option values it stands for and the hash of that
 * combination, the generation it belongs to, whether it is enabled, its weight, its place among
 * its product's variants, its label, and the prices a merchant authored for it, one per currency,
 * of which the one in the store's base currency is its base price. A product without options has
 * one variant, the default, whose combination is the empty one. A variant is changed only through
 * its product, so it is a value: every change returns a new instance.
 *
 * A stored variant whose option value was since removed keeps its hash, which identifies it,
 * while its selection names only the values that remain; such a variant is never enabled again
 * until its combination is given again whole.
 *
 * A variant whose SKU another variant of its product took has no SKU: it keeps a placeholder,
 * NO_SKU_PREFIX and its UUID, which holds its place under the store's unique key and which no
 * SKU a merchant gives may look like. Such a variant is disabled, and is enabled again only with
 * a SKU of its own.
 *
 * @since 0.1.0
 */
final class Variant {

	/**
	 * What the placeholder of a variant without a SKU starts with, followed by the variant's UUID; a SKU a merchant gives never starts with it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NO_SKU_PREFIX = '~';

	/**
	 * The generation a product's first variants are written at.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const FIRST_GENERATION = 1;

	/**
	 * The most characters a label may have: the width of its column; a longer one is cut.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LABEL_MAX_LENGTH = 255;

	/**
	 * The shape of a lowercase UUID.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * The id storage gave the variant, or null before it is stored.
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
	 * The SKU.
	 *
	 * @since 0.1.0
	 *
	 * @var Sku
	 */
	private Sku $sku;

	/**
	 * SHA-256, in hexadecimal, of the option values the variant stands for.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $combinationHash;

	/**
	 * The option values the variant stands for.
	 *
	 * @since 0.2.0
	 *
	 * @var VariantSelection
	 */
	private VariantSelection $selection;

	/**
	 * The generation the variant belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $generation;

	/**
	 * Whether the variant may be sold.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * The weight, in grams, or null when none is given.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $weightGrams;

	/**
	 * Where the variant sorts among its product's.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $position;

	/**
	 * The labels of its option values in axis order, or null without options.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private ?string $label;

	/**
	 * The price in the store's base currency, or null when none is authored.
	 *
	 * @since 0.1.0
	 *
	 * @var VariantPrice|null
	 */
	private ?VariantPrice $basePrice;

	/**
	 * The prices authored in the other currencies, sorted by currency code.
	 *
	 * @since 0.2.0
	 *
	 * @var list<VariantPrice>
	 */
	private array $otherPrices;

	/**
	 * Creates a variant from checked values.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The selection, the position, the label and the prices in other currencies.
	 *
	 * @throws \InvalidArgumentException When a value cannot belong to a variant.
	 *
	 * @param int|null          $id              The stored id, or null.
	 * @param string            $uuid            A lowercase UUID.
	 * @param Sku               $sku             The SKU.
	 * @param string            $combinationHash SHA-256 of the combination, 64 lowercase hexadecimal digits.
	 * @param VariantSelection  $selection       The combination.
	 * @param int               $generation      The generation, 1 or more.
	 * @param bool              $enabled         Whether it may be sold.
	 * @param int|null          $weightGrams     The weight in grams, 0 or more, or null.
	 * @param int               $position        Where it sorts, 0 or more.
	 * @param string|null       $label           The label, or null.
	 * @param VariantPrice|null $basePrice       The base-currency price, or null.
	 * @param VariantPrice[]    $otherPrices     The prices in other currencies, none in the base price's currency, none twice.
	 *
	 * @phpstan-param list<VariantPrice> $otherPrices
	 */
	private function __construct( ?int $id, string $uuid, Sku $sku, string $combinationHash, VariantSelection $selection, int $generation, bool $enabled, ?int $weightGrams, int $position, ?string $label, ?VariantPrice $basePrice, array $otherPrices ) {
		if ( ( null !== $id && $id < 1 ) || 1 !== preg_match( self::UUID_PATTERN, $uuid ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $combinationHash ) || $generation < self::FIRST_GENERATION ) {
			throw new \InvalidArgumentException( sprintf( 'Variant %s: an id is 1 or more, a UUID and a combination hash are lowercase hexadecimal, and a generation is 1 or more.', $uuid ) );
		}

		if ( ( null !== $weightGrams && $weightGrams < 0 ) || $position < 0 ) {
			throw new \InvalidArgumentException( 'A weight and a position are never negative.' );
		}

		$codes = array_map( static fn( VariantPrice $price ): string => $price->currency()->code(), $otherPrices );

		if ( count( $codes ) !== count( array_unique( $codes ) ) || ( null !== $basePrice && in_array( $basePrice->currency()->code(), $codes, true ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'Variant %s has one price per currency.', $uuid ) );
		}

		usort( $otherPrices, static fn( VariantPrice $a, VariantPrice $b ): int => strcmp( $a->currency()->code(), $b->currency()->code() ) );

		$this->id              = $id;
		$this->uuid            = $uuid;
		$this->sku             = $sku;
		$this->combinationHash = $combinationHash;
		$this->selection       = $selection;
		$this->generation      = $generation;
		$this->enabled         = $enabled;
		$this->weightGrams     = $weightGrams;
		$this->position        = $position;
		$this->label           = self::fitted( $label );
		$this->basePrice       = $basePrice;
		$this->otherPrices     = $otherPrices;
	}

	/**
	 * Returns a new default variant: the empty combination, the first generation, enabled, with no weight and no price.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid A lowercase UUID, from the IdGenerator.
	 * @param Sku    $sku  The SKU.
	 * @return self The variant, not yet stored.
	 */
	public static function byDefault( string $uuid, Sku $sku ): self {
		return self::ofCombination( $uuid, VariantSelection::none(), $sku, self::FIRST_GENERATION );
	}

	/**
	 * Returns a new variant of a combination: enabled, at a generation, with no weight, no label and no price.
	 *
	 * @since 0.2.0
	 *
	 * @param string           $uuid       A lowercase UUID, from the IdGenerator.
	 * @param VariantSelection $selection  The combination.
	 * @param Sku              $sku        The SKU.
	 * @param int              $generation The generation it is written at.
	 * @return self The variant, not yet stored.
	 */
	public static function ofCombination( string $uuid, VariantSelection $selection, Sku $sku, int $generation ): self {
		return new self( null, $uuid, $sku, $selection->hash(), $selection, $generation, true, null, 0, null, null, array() );
	}

	/**
	 * Returns a variant as it was stored.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The selection, the position, the label and the prices in other currencies.
	 *
	 * @param int                   $id              The stored id.
	 * @param string                $uuid            The UUID.
	 * @param Sku                   $sku             The SKU.
	 * @param string                $combinationHash The combination hash.
	 * @param int                   $generation      The generation.
	 * @param bool                  $enabled         Whether it may be sold.
	 * @param int|null              $weightGrams     The weight in grams, or null.
	 * @param VariantPrice|null     $basePrice       The base-currency price, or null.
	 * @param VariantSelection|null $selection       Optional. The values its option rows name. Default null, the empty selection.
	 * @param int                   $position        Optional. Where it sorts. Default 0.
	 * @param string|null           $label           Optional. The label. Default null.
	 * @param VariantPrice[]        $otherPrices     Optional. The prices in the other currencies. Default none.
	 * @return self The variant.
	 *
	 * @phpstan-param list<VariantPrice> $otherPrices
	 */
	public static function stored( int $id, string $uuid, Sku $sku, string $combinationHash, int $generation, bool $enabled, ?int $weightGrams, ?VariantPrice $basePrice, ?VariantSelection $selection = null, int $position = 0, ?string $label = null, array $otherPrices = array() ): self {
		return new self( $id, $uuid, $sku, $combinationHash, $selection ?? VariantSelection::none(), $generation, $enabled, $weightGrams, $position, $label, $basePrice, $otherPrices );
	}

	/**
	 * Returns the combination hash of the default variant: SHA-256 of the empty combination.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 lowercase hexadecimal digits.
	 */
	public static function defaultCombinationHash(): string {
		return VariantSelection::none()->hash();
	}

	/**
	 * Returns the SKU a merchant gives a variant, normalized, refusing one that starts as the placeholder of a variant without a SKU does.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::SkuInvalid when Sku::of() refuses it, or it starts with NO_SKU_PREFIX.
	 *
	 * @param string $sku The SKU as given.
	 * @return Sku The SKU.
	 */
	public static function givenSku( string $sku ): Sku {
		$given = Sku::of( $sku );

		if ( str_starts_with( $given->toString(), self::NO_SKU_PREFIX ) ) {
			CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => $given->toString() ) );
		}

		return $given;
	}

	/**
	 * Returns the same variant with its stored id.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The id storage gave it.
	 * @return self The variant.
	 */
	public function withId( int $id ): self {
		return new self( $id, $this->uuid, $this->sku, $this->combinationHash, $this->selection, $this->generation, $this->enabled, $this->weightGrams, $this->position, $this->label, $this->basePrice, $this->otherPrices );
	}

	/**
	 * Returns the variant with another SKU, weight and base-currency price; its prices in other currencies stay.
	 *
	 * @since 0.1.0
	 *
	 * @param Sku               $sku         The SKU.
	 * @param VariantPrice|null $basePrice   The base-currency price, or null for none.
	 * @param int|null          $weightGrams The weight in grams, or null for none.
	 * @return self The variant.
	 */
	public function withCommerce( Sku $sku, ?VariantPrice $basePrice, ?int $weightGrams ): self {
		return new self( $this->id, $this->uuid, $sku, $this->combinationHash, $this->selection, $this->generation, $this->enabled, $weightGrams, $this->position, $this->label, $basePrice, $this->otherPrices );
	}

	/**
	 * Returns the variant as a save sets it: its SKU, whether it is enabled, its weight, place, label and generation.
	 *
	 * @since 0.2.0
	 *
	 * @param Sku         $sku         The SKU.
	 * @param bool        $enabled     Whether it may be sold.
	 * @param int|null    $weightGrams The weight in grams, or null.
	 * @param int         $position    Where it sorts.
	 * @param string|null $label       The label, cut to LABEL_MAX_LENGTH characters, or null.
	 * @param int         $generation  The generation it is written at.
	 * @return self The variant.
	 */
	public function withState( Sku $sku, bool $enabled, ?int $weightGrams, int $position, ?string $label, int $generation ): self {
		return new self( $this->id, $this->uuid, $sku, $this->combinationHash, $this->selection, $generation, $enabled, $weightGrams, $position, $label, $this->basePrice, $this->otherPrices );
	}

	/**
	 * Returns the variant selecting the option values a save names it by.
	 *
	 * A value removed takes its row of the selection with it, so a stored variant under it reads
	 * fewer values than its combination hash stands for; the save that names it again gives them back.
	 *
	 * @since 0.2.0
	 *
	 * @param VariantSelection $selection The selection its combination hash was made from.
	 * @return self The variant.
	 */
	public function withSelection( VariantSelection $selection ): self {
		return new self( $this->id, $this->uuid, $this->sku, $this->combinationHash, $selection, $this->generation, $this->enabled, $this->weightGrams, $this->position, $this->label, $this->basePrice, $this->otherPrices );
	}

	/**
	 * Returns the variant with a whole new set of authored prices, the one in the base currency as its base price.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When two prices are in one currency.
	 *
	 * @param VariantPrice[] $prices The prices, one per currency; a currency left out has no authored price.
	 * @param Currency       $base   The store's base currency.
	 * @return self The variant.
	 *
	 * @phpstan-param list<VariantPrice> $prices
	 */
	public function withPrices( array $prices, Currency $base ): self {
		$basePrice = null;
		$others    = array();

		foreach ( $prices as $price ) {
			if ( null === $basePrice && $price->currency()->equals( $base ) ) {
				$basePrice = $price;
			} else {
				$others[] = $price;
			}
		}

		return new self( $this->id, $this->uuid, $this->sku, $this->combinationHash, $this->selection, $this->generation, $this->enabled, $this->weightGrams, $this->position, $this->label, $basePrice, $others );
	}


	/**
	 * Returns the variant disabled, every other field kept.
	 *
	 * @since 0.2.0
	 *
	 * @return self The variant.
	 */
	public function disabled(): self {
		return new self( $this->id, $this->uuid, $this->sku, $this->combinationHash, $this->selection, $this->generation, false, $this->weightGrams, $this->position, $this->label, $this->basePrice, $this->otherPrices );
	}

	/**
	 * Returns the stored id.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The id, or null before the variant is stored.
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
	 * Returns the SKU.
	 *
	 * @since 0.1.0
	 *
	 * @return Sku The SKU.
	 */
	public function sku(): Sku {
		return $this->sku;
	}

	/**
	 * Tells whether the variant has a SKU of its own, rather than the placeholder of one whose SKU another variant took.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False when its SKU starts with NO_SKU_PREFIX.
	 */
	public function hasSku(): bool {
		return ! str_starts_with( $this->sku->toString(), self::NO_SKU_PREFIX );
	}

	/**
	 * Returns the combination hash.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 lowercase hexadecimal digits.
	 */
	public function combinationHash(): string {
		return $this->combinationHash;
	}

	/**
	 * Returns the option values the variant stands for.
	 *
	 * @since 0.2.0
	 *
	 * @return VariantSelection The selection; empty for a product without options.
	 */
	public function selection(): VariantSelection {
		return $this->selection;
	}

	/**
	 * Returns the generation.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1 or more.
	 */
	public function generation(): int {
		return $this->generation;
	}

	/**
	 * Tells whether the variant may be sold.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it is enabled.
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * Returns the weight.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The weight in grams, or null when none is given.
	 */
	public function weightGrams(): ?int {
		return $this->weightGrams;
	}

	/**
	 * Returns where the variant sorts among its product's.
	 *
	 * @since 0.2.0
	 *
	 * @return int 0 or more.
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Returns the label: the labels of its option values in the order of their axes.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The label, such as "250 g / Whole bean"; null without options.
	 */
	public function label(): ?string {
		return $this->label;
	}

	/**
	 * Returns the price in the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return VariantPrice|null The price, or null when none is authored.
	 */
	public function basePrice(): ?VariantPrice {
		return $this->basePrice;
	}

	/**
	 * Returns every authored price.
	 *
	 * @since 0.2.0
	 *
	 * @return list<VariantPrice> The base price first, then the others by currency code.
	 */
	public function prices(): array {
		return null === $this->basePrice ? $this->otherPrices : array_merge( array( $this->basePrice ), $this->otherPrices );
	}

	/**
	 * Cuts a label to the width of its column.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $label The label.
	 * @return string|null The label, at most LABEL_MAX_LENGTH characters, or null.
	 */
	private static function fitted( ?string $label ): ?string {
		if ( null === $label || 1 !== preg_match( '/\A.{0,' . self::LABEL_MAX_LENGTH . '}/su', $label, $start ) ) {
			return $label;
		}

		return $start[0];
	}
}
