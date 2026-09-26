<?php
/**
 * AddLine: a line a promotion adds to the cart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Intent;

use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a line, at the price the promotion names: a free gift, or a product bought with another.
 *
 * Owns one fact: the add-a-line intent. The calculation honours it once: promotions are
 * evaluated again with the added line, and a line added then is dropped, so an added line can
 * never add another.
 *
 * @since 0.1.0
 */
final readonly class AddLine implements PromotionIntent {

	/**
	 * Holds the intent.
	 *
	 * @since 0.1.0
	 *
	 * @param Source         $source    What the intent comes from.
	 * @param int            $variantId The variant to add.
	 * @param int            $quantity  How many.
	 * @param AuthoredAmount $unitPrice The price of one, as the promotion names it.
	 * @param string         $taxClass  The tax class of the line.
	 */
	public function __construct(
		private Source $source,
		public int $variantId,
		public int $quantity,
		public AuthoredAmount $unitPrice,
		public string $taxClass
	) {
	}

	/**
	 * Returns the kind of intent.
	 *
	 * @since 0.1.0
	 *
	 * @return string `add_line`.
	 */
	public function type(): string {
		return 'add_line';
	}

	/**
	 * Returns what the intent comes from.
	 *
	 * @since 0.1.0
	 *
	 * @return Source The source.
	 */
	public function source(): Source {
		return $this->source;
	}
}
