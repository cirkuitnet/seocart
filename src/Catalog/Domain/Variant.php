<?php
/**
 * Variant: one sellable combination of a product, with its SKU and base price
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A LogicException names a value built by code, for the developer; it is never rendered.

/**
 * A variant of a product: what a cart line and a stock item point at.
 *
 * Owns one fact: what a variant holds. Its identity (an id once stored, and a UUID from the
 * start), its SKU, the combination of option values it stands for, the generation it belongs
 * to, whether it is enabled, its weight and its price in the store's base currency. A product
 * without options has one variant, the default, whose combination is the empty one and whose
 * generation is the first. A variant is changed only through its product, so it is a value:
 * every change returns a new instance.
 *
 * @since 0.1.0
 */
final class Variant {

	/**
	 * The generation a product's first variants are written at.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const FIRST_GENERATION = 1;

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
	 * The price in the store's base currency, or null when none is authored.
	 *
	 * @since 0.1.0
	 *
	 * @var VariantPrice|null
	 */
	private ?VariantPrice $basePrice;

	/**
	 * Creates a variant from checked values.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a value cannot belong to a variant.
	 *
	 * @param int|null          $id              The stored id, or null.
	 * @param string            $uuid            A lowercase UUID.
	 * @param Sku               $sku             The SKU.
	 * @param string            $combinationHash SHA-256 of the combination, 64 lowercase hexadecimal digits.
	 * @param int               $generation      The generation, 1 or more.
	 * @param bool              $enabled         Whether it may be sold.
	 * @param int|null          $weightGrams     The weight in grams, 0 or more, or null.
	 * @param VariantPrice|null $basePrice       The base-currency price, or null.
	 */
	private function __construct( ?int $id, string $uuid, Sku $sku, string $combinationHash, int $generation, bool $enabled, ?int $weightGrams, ?VariantPrice $basePrice ) {
		if ( ( null !== $id && $id < 1 ) || 1 !== preg_match( self::UUID_PATTERN, $uuid ) || 1 !== preg_match( '/^[0-9a-f]{64}\z/', $combinationHash ) || $generation < self::FIRST_GENERATION ) {
			throw new \InvalidArgumentException( sprintf( 'Variant %s: an id is 1 or more, a UUID and a combination hash are lowercase hexadecimal, and a generation is 1 or more.', $uuid ) );
		}

		if ( null !== $weightGrams && $weightGrams < 0 ) {
			throw new \InvalidArgumentException( 'A weight is never negative.' );
		}

		$this->id              = $id;
		$this->uuid            = $uuid;
		$this->sku             = $sku;
		$this->combinationHash = $combinationHash;
		$this->generation      = $generation;
		$this->enabled         = $enabled;
		$this->weightGrams     = $weightGrams;
		$this->basePrice       = $basePrice;
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
		return new self( null, $uuid, $sku, self::defaultCombinationHash(), self::FIRST_GENERATION, true, null, null );
	}

	/**
	 * Returns a variant as it was stored.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $id              The stored id.
	 * @param string            $uuid            The UUID.
	 * @param Sku               $sku             The SKU.
	 * @param string            $combinationHash The combination hash.
	 * @param int               $generation      The generation.
	 * @param bool              $enabled         Whether it may be sold.
	 * @param int|null          $weightGrams     The weight in grams, or null.
	 * @param VariantPrice|null $basePrice       The base-currency price, or null.
	 * @return self The variant.
	 */
	public static function stored( int $id, string $uuid, Sku $sku, string $combinationHash, int $generation, bool $enabled, ?int $weightGrams, ?VariantPrice $basePrice ): self {
		return new self( $id, $uuid, $sku, $combinationHash, $generation, $enabled, $weightGrams, $basePrice );
	}

	/**
	 * Returns the combination hash of the default variant: SHA-256 of the empty combination.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 lowercase hexadecimal digits.
	 */
	public static function defaultCombinationHash(): string {
		return hash( 'sha256', '' );
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
		return new self( $id, $this->uuid, $this->sku, $this->combinationHash, $this->generation, $this->enabled, $this->weightGrams, $this->basePrice );
	}

	/**
	 * Returns the variant with another SKU, weight and base-currency price.
	 *
	 * @since 0.1.0
	 *
	 * @param Sku               $sku         The SKU.
	 * @param VariantPrice|null $basePrice   The base-currency price, or null for none.
	 * @param int|null          $weightGrams The weight in grams, or null for none.
	 * @return self The variant.
	 */
	public function withCommerce( Sku $sku, ?VariantPrice $basePrice, ?int $weightGrams ): self {
		return new self( $this->id, $this->uuid, $sku, $this->combinationHash, $this->generation, $this->enabled, $weightGrams, $basePrice );
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
	 * Returns the price in the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return VariantPrice|null The price, or null when none is authored.
	 */
	public function basePrice(): ?VariantPrice {
		return $this->basePrice;
	}
}
