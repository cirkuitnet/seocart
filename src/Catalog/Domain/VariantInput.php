<?php
/**
 * VariantInput: what a save asks of one variant of the set it gives
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One entry of the variant set a save gives: the combination it names, and what it sets on that variant.
 *
 * Owns one fact: which fields of a variant a save sets and which it leaves as stored. The
 * combination identifies the variant. The SKU is null for a variant that has none, one whose SKU
 * another variant took, which then stays as it is stored, disabled. An `enabled` given as null,
 * and a weight not given, keep the stored value, or for a new variant the default: enabled, no
 * weight. A position given as null is the variant's place in the set, whose order is given with
 * it, stored or new. Prices given are the variant's whole set of authored prices, one per
 * currency; prices not given keep the stored ones. A variant of an earlier generation the set
 * brings back is a stored one, but that its SKU must be given. The UUID is the one
 * a new variant is given, and is not used when the combination has a variant already.
 *
 * @since 0.2.0
 */
final class VariantInput {

	/**
	 * Creates an entry.
	 *
	 * @since 0.2.0
	 *
	 * @param string                  $uuid        A fresh lowercase UUID, for a variant the combination does not have yet.
	 * @param VariantSelection        $selection   The combination.
	 * @param Sku|null                $sku         The SKU, or null for a variant that has none.
	 * @param bool|null               $enabled     Whether it may be sold; null keeps the stored value, true for a new variant.
	 * @param bool                    $weightGiven Whether the weight is given, null included.
	 * @param int|null                $weightGrams The weight in grams, or null for none.
	 * @param int|null                $position    Where it sorts among the product's variants; null for its place in the set, stored or new.
	 * @param list<VariantPrice>|null $prices      Every authored price, one per currency; null keeps the stored ones.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly VariantSelection $selection,
		public readonly ?Sku $sku,
		public readonly ?bool $enabled = null,
		public readonly bool $weightGiven = false,
		public readonly ?int $weightGrams = null,
		public readonly ?int $position = null,
		public readonly ?array $prices = null
	) {
	}
}
