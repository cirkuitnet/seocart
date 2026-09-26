<?php
/**
 * InputLine: one priced line of a calculation's input
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A line the calculation prices: which variant, how many, at what unit price and tax class.
 *
 * Owns one fact: what the calculation knows of a line before it runs. The key is the caller's
 * opaque identity for the line, a cart line's identity or an order line's uuid, and every figure
 * the calculation produces for the line is filed under it. A quantity below one or a negative
 * unit price is a caller's mistake and is refused here.
 *
 * @since 0.1.0
 */
final readonly class InputLine {

	/**
	 * Checks and holds the line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the key or the tax class is empty, the variant id is not
	 *                                   positive, the quantity is below one, or the unit price is negative.
	 *
	 * @param string         $key         The caller's identity for the line.
	 * @param int            $variantId   The variant sold.
	 * @param int            $quantity    How many, one or more.
	 * @param AuthoredAmount $unitPrice   The price of one, as authored.
	 * @param PriceSource    $priceSource Where the price came from.
	 * @param string         $taxClass    The tax class whose rates apply to the line.
	 * @param bool           $autoAdded   Optional. Whether a promotion added the line. Default false.
	 */
	public function __construct(
		public string $key,
		public int $variantId,
		public int $quantity,
		public AuthoredAmount $unitPrice,
		public PriceSource $priceSource,
		public string $taxClass,
		public bool $autoAdded = false
	) {
		if ( '' === $key || '' === $taxClass ) {
			throw new \InvalidArgumentException( 'A calculation line has a key and a tax class.' );
		}

		if ( $variantId < 1 || $quantity < 1 ) {
			throw new \InvalidArgumentException( 'A calculation line sells one or more units of a variant.' );
		}

		if ( $unitPrice->amount->isNegative() ) {
			throw new \InvalidArgumentException( 'A unit price is never negative.' );
		}
	}

	/**
	 * Returns the line as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|bool> The line.
	 */
	public function toArray(): array {
		return array(
			'key'          => $this->key,
			'variant_id'   => $this->variantId,
			'quantity'     => $this->quantity,
			'unit_minor'   => $this->unitPrice->amount->minorUnits(),
			'unit_basis'   => $this->unitPrice->basis->value,
			'price_source' => $this->priceSource->value,
			'tax_class'    => $this->taxClass,
			'auto_added'   => $this->autoAdded,
		);
	}
}
