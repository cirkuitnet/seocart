<?php
/**
 * CommerceFields: the fields a product save carries beside the post's own
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\ProductWrite;

use SEOCart\Catalog\Domain\Sku;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the commerce fields of a product save: the default variant's SKU, price, compare-at price, currency and weight.
 *
 * Owns one fact: what each commerce field is on the wire. CommerceInput checks a save's values
 * against these declarations, and the REST fields of the product's endpoint are compiled from
 * the same ones, so neither restates a type or a bound. Prices are in minor units of the
 * store's base currency; a price may be null, which leaves the product incomplete, and so may
 * the compare-at price and the weight. Declarations are data: building them calls no WordPress
 * function and translates nothing.
 *
 * @since 0.1.0
 */
final class CommerceFields {

	/**
	 * The SKU of the default variant.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SKU = 'sku';

	/**
	 * The price of the default variant, in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PRICE_MINOR = 'price_minor';

	/**
	 * The currency of the price.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CURRENCY = 'currency';

	/**
	 * The price the default variant is compared with, in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMPARE_AT_MINOR = 'compare_at_minor';

	/**
	 * The weight of the default variant, in grams.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WEIGHT_GRAMS = 'weight_grams';

	/**
	 * The heaviest weight a variant can have, in grams: the largest value its signed INT column holds, so a larger one is refused, never cut down.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const WEIGHT_MAX_GRAMS = 2147483647;

	/**
	 * Returns every commerce field, in wire order.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, FieldSpec> The declarations, keyed by wire name.
	 */
	public static function all(): array {
		$fields = array(
			new FieldSpec(
				name: self::SKU,
				type: FieldType::String,
				description: 'The stock-keeping unit of the product\'s default variant, unique in the store without regard to case.',
				label: static fn(): string => __( 'SKU', 'seocart' ),
				example: 'TSHIRT-RED-L',
				max_length: Sku::MAX_LENGTH
			),
			new FieldSpec(
				name: self::PRICE_MINOR,
				type: FieldType::Integer,
				description: 'The price of the default variant in minor units of the store\'s base currency; null removes it, which leaves the product incomplete.',
				label: static fn(): string => __( 'Price', 'seocart' ),
				example: 1999,
				nullable: true,
				minimum: 0,
				privacy: Privacy::Financial
			),
			new FieldSpec(
				name: self::CURRENCY,
				type: FieldType::String,
				description: 'The ISO 4217 code of the price\'s currency, which must be the store\'s base currency.',
				label: static fn(): string => __( 'Currency', 'seocart' ),
				example: 'USD',
				max_length: 3,
				privacy: Privacy::Financial
			),
			new FieldSpec(
				name: self::COMPARE_AT_MINOR,
				type: FieldType::Integer,
				description: 'The price the default variant is compared with, in minor units of the same currency; null for none.',
				label: static fn(): string => __( 'Compare-at price', 'seocart' ),
				example: 2499,
				nullable: true,
				minimum: 0,
				privacy: Privacy::Financial
			),
			new FieldSpec(
				name: self::WEIGHT_GRAMS,
				type: FieldType::Integer,
				description: 'The weight of the default variant in grams; null for none.',
				label: static fn(): string => __( 'Weight (g)', 'seocart' ),
				example: 250,
				nullable: true,
				minimum: 0,
				maximum: self::WEIGHT_MAX_GRAMS
			),
		);

		$keyed = array();

		foreach ( $fields as $field ) {
			$keyed[ $field->name() ] = $field;
		}

		return $keyed;
	}
}
