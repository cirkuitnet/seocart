<?php
/**
 * NewOrderLine: one line of an order being placed, as the calculation priced it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * One `order_lines` row with its options and tax components, before any of them has an id.
 *
 * Owns one fact: what an order records about one line it sells. The words are the snapshot the
 * order keeps; the amounts are copied from the calculation, never computed here. The key names
 * the line within the document only, so an adjustment can say which line it changes; the order
 * gives the line its own identifier when it is written.
 *
 * @since 0.1.0
 */
final readonly class NewOrderLine {

	/**
	 * Records the line, refusing one that could not be a line of an order.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the key is empty, a quantity or id is below 1, or two
	 *                                   options share an axis.
	 *
	 * @param string            $key             The line's name within the document, unique in it.
	 * @param int               $variantId       The variant sold.
	 * @param int               $productId       The product sold.
	 * @param string            $sku             The SKU.
	 * @param string            $title           The product title, in the order's locale.
	 * @param string            $variantLabel    The variant's label, in the order's locale; empty for a product with one variant.
	 * @param int               $quantity        Units sold, 1 or more.
	 * @param AmountBasis       $unitAmountBasis Whether the unit price was authored net or gross.
	 * @param Money             $unitPrice       The unit price as authored.
	 * @param Money             $unitPriceGross  The unit price with tax, for display.
	 * @param Money|null        $unitCompareAt   The compare-at unit price, or null for none.
	 * @param Money             $lineSubtotal    The line before discounts, as authored.
	 * @param Money             $lineDiscount    The discounts applied to the line.
	 * @param TaxedMoney        $amount          The line after discounts: net, tax and gross.
	 * @param Money             $lineTotal       The line total shown on the order.
	 * @param Money             $baseLineDiscount The discounts, in the base currency.
	 * @param TaxedMoney        $baseAmount      The line after discounts, in the base currency.
	 * @param string|null       $taxClass        The tax class it was taxed under, or null for the standard class.
	 * @param bool              $isTaxable       Whether it was taxed.
	 * @param string            $priceSource     Where the unit price came from, for example `explicit`.
	 * @param LineOption[]      $options         The options it was sold with.
	 * @param NewTaxComponent[] $taxComponents   Its tax, per jurisdiction.
	 */
	public function __construct(
		public string $key,
		public int $variantId,
		public int $productId,
		public string $sku,
		public string $title,
		public string $variantLabel,
		public int $quantity,
		public AmountBasis $unitAmountBasis,
		public Money $unitPrice,
		public Money $unitPriceGross,
		public ?Money $unitCompareAt,
		public Money $lineSubtotal,
		public Money $lineDiscount,
		public TaxedMoney $amount,
		public Money $lineTotal,
		public Money $baseLineDiscount,
		public TaxedMoney $baseAmount,
		public ?string $taxClass,
		public bool $isTaxable,
		public string $priceSource,
		public array $options = array(),
		public array $taxComponents = array()
	) {
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'An order line needs a key that names it within the order.' );
		}

		if ( $quantity < 1 || $variantId < 1 || $productId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'Order line %s: the quantity, the variant id and the product id are each 1 or more.', $key ) );
		}

		$axes = array();

		foreach ( $options as $option ) {
			if ( isset( $axes[ $option->axisKey ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Order line %s has two options on the axis %s.', $key, $option->axisKey ) );
			}

			$axes[ $option->axisKey ] = true;
		}
	}

	/**
	 * Returns every amount of the line in the order's currency, its components' included.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Money|TaxedMoney> The amounts.
	 */
	public function amounts(): array {
		$amounts = array( $this->unitPrice, $this->unitPriceGross, $this->lineSubtotal, $this->lineDiscount, $this->amount, $this->lineTotal );

		if ( null !== $this->unitCompareAt ) {
			$amounts[] = $this->unitCompareAt;
		}

		foreach ( $this->taxComponents as $component ) {
			$amounts[] = $component->amount;
		}

		return $amounts;
	}

	/**
	 * Returns every amount of the line in the base currency, its components' included.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Money|TaxedMoney> The amounts.
	 */
	public function baseAmounts(): array {
		$amounts = array( $this->baseLineDiscount, $this->baseAmount );

		foreach ( $this->taxComponents as $component ) {
			$amounts[] = $component->base;
		}

		return $amounts;
	}
}
