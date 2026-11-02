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
 * Declares the commerce fields of a variant: its SKU, price, compare-at price, currency and weight; given flat, of the single variant of a product without options.
 *
 * Owns one fact: what each commerce field is on the wire. CommerceInput checks a save's values
 * against these declarations, and the REST fields of the product's endpoint are compiled from
 * the same ones, so neither restates a type or a bound. VariantFields composes these very
 * instances into each entry of a variant set, so the single variant and every variant of a
 * product with options are one declaration; all() builds them once. The SKU alone differs in a
 * variant set, where a variant whose SKU another variant took has none: listedSku() is the same
 * declaration, null allowed. Prices are in minor units of
 * their currency, the store's base currency for the single variant; a price may be null, which
 * leaves that variant without it, and so may the compare-at price and the weight. Declarations are
 * data: building them calls no WordPress function and translates nothing.
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
	 * The largest amount a price field takes, in minor units: 2^53 - 1, the largest integer every JSON reader, the block editor's among them, holds exactly.
	 *
	 * A larger number would reach the server already rounded, or be rounded again on its way back
	 * to a client, so it is refused rather than stored.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const AMOUNT_MAX_MINOR = 9007199254740991;

	/**
	 * The declarations, built once, so a variant set composes the very instances.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, FieldSpec>|null
	 */
	private static ?array $all = null;

	/**
	 * The SKU as an entry of a variant set carries it, built once.
	 *
	 * @since 0.2.0
	 *
	 * @var FieldSpec|null
	 */
	private static ?FieldSpec $listedSku = null;

	/**
	 * Returns every commerce field, in wire order.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The same instances on every call.
	 *
	 * @return array<string, FieldSpec> The declarations, keyed by wire name.
	 */
	public static function all(): array {
		self::$all ??= self::build();

		return self::$all;
	}

	/**
	 * Returns the SKU as an entry of a variant set carries it: the single variant's declaration, null allowed.
	 *
	 * A variant whose SKU another of its product's variants took has none: it is read as null, and
	 * given null it keeps none and stays disabled. The single variant always has one.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The declaration, the same instance on every call.
	 */
	public static function listedSku(): FieldSpec {
		self::$listedSku ??= self::sku( true );

		return self::$listedSku;
	}

	/**
	 * Builds the declarations.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, FieldSpec> The declarations, keyed by wire name.
	 */
	private static function build(): array {
		$fields = array(
			self::sku( false ),
			new FieldSpec(
				name: self::PRICE_MINOR,
				type: FieldType::Integer,
				description: 'The variant\'s price in minor units of its currency; null for none, which given flat leaves the product incomplete.',
				label: static fn(): string => __( 'Price', 'seocart' ),
				example: 1999,
				nullable: true,
				minimum: 0,
				maximum: self::AMOUNT_MAX_MINOR,
				privacy: Privacy::Financial
			),
			new FieldSpec(
				name: self::CURRENCY,
				type: FieldType::String,
				description: 'The ISO 4217 code of the price\'s currency: the store\'s base currency given flat, any currency the store offers in a variant\'s prices.',
				label: static fn(): string => __( 'Currency', 'seocart' ),
				example: 'USD',
				max_length: 3,
				privacy: Privacy::Financial
			),
			new FieldSpec(
				name: self::COMPARE_AT_MINOR,
				type: FieldType::Integer,
				description: 'The price the variant is compared with, in minor units of the same currency; null for none.',
				label: static fn(): string => __( 'Compare-at price', 'seocart' ),
				example: 2499,
				nullable: true,
				minimum: 0,
				maximum: self::AMOUNT_MAX_MINOR,
				privacy: Privacy::Financial
			),
			new FieldSpec(
				name: self::WEIGHT_GRAMS,
				type: FieldType::Integer,
				description: 'The variant\'s weight in grams; null for none.',
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

	/**
	 * Builds the SKU's declaration: the single variant's, or an entry's of a variant set, which may be null.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $listed Whether it is an entry's of a variant set.
	 * @return FieldSpec The declaration.
	 */
	private static function sku( bool $listed ): FieldSpec {
		return new FieldSpec(
			name: self::SKU,
			type: FieldType::String,
			description: $listed
				? 'The variant\'s stock-keeping unit, unique in the store without regard to case; null for a variant that has none since another variant took its SKU, which stays disabled until it is given one.'
				: 'The variant\'s stock-keeping unit, unique in the store without regard to case; given flat, of the single variant of a product without options.',
			label: static fn(): string => __( 'SKU', 'seocart' ),
			example: 'TSHIRT-RED-L',
			nullable: $listed,
			max_length: Sku::MAX_LENGTH
		);
	}
}
