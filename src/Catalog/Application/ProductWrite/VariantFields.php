<?php
/**
 * VariantFields: the option axes and the variant set of a product save, as the wire carries them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\ProductWrite;

use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Inventory\Domain\StockOutcome;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `options`, a product's option axes with their values, and `variants`, its whole set of variants, each a list of objects.
 *
 * Owns one fact: what the two lists are on the wire. A variant's SKU, weight, price, compare-at
 * price and currency are CommerceFields' own declarations, the very instances the single variant
 * of a product without options is written with, so neither restates a type or a bound; the SKU
 * is CommerceFields::listedSku(), which is null for a variant whose SKU another variant took. The
 * labels of axes and values are translatable text, kept in the language of the product's source
 * post. A variant names its combination as one `{ axis, value }` pair per axis, by key; its
 * `stock` cells set its on-hand count at a location, each with the count it had when it was read,
 * and come back with what became of them. `variant_id`, a variant's `label`, `sellability` and a
 * cell's `outcome` are read only. Declarations are data: building them calls no WordPress
 * function and translates nothing, and each is built once.
 *
 * @since 0.2.0
 */
final class VariantFields {

	/**
	 * The product's option axes, each with its values.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTIONS = 'options';

	/**
	 * The product's whole set of variants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANTS = 'variants';

	/**
	 * An axis's or a value's key, fixed once it exists.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY = 'key';

	/**
	 * An axis's or a value's label; a variant's label, read only.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LABEL = 'label';

	/**
	 * Where an axis, a value or a variant sorts.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const POSITION = 'position';

	/**
	 * An axis's values.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VALUES = 'values';

	/**
	 * A value's image, an attachment ID.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const IMAGE_ID = 'image_id';

	/**
	 * A value's colour swatch.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SWATCH = 'swatch';

	/**
	 * A variant's ID, read only.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANT_ID = 'variant_id';

	/**
	 * A variant's combination: one pair per axis.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COMBINATION = 'combination';

	/**
	 * The axis key of a pair of a combination.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AXIS = 'axis';

	/**
	 * The value key of a pair of a combination.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VALUE = 'value';

	/**
	 * Whether a variant may be sold.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ENABLED = 'enabled';

	/**
	 * A variant's whole set of authored prices.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRICES = 'prices';

	/**
	 * Whether a price includes tax.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AMOUNT_BASIS = 'amount_basis';

	/**
	 * A variant's stock cells.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STOCK = 'stock';

	/**
	 * The location of a stock cell.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_UUID = 'location_uuid';

	/**
	 * The on-hand count a stock cell sets.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ON_HAND = 'on_hand';

	/**
	 * The on-hand count a stock cell was read at.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const EXPECTED_ON_HAND = 'expected_on_hand';

	/**
	 * What became of a stock cell, read only.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OUTCOME = 'outcome';

	/**
	 * Whether a variant may be sold to the requesting user, read only.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SELLABILITY = 'sellability';

	/**
	 * The largest on-hand count a cell takes: the largest value of the stock's signed INT column.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const ON_HAND_MAX = 2147483647;

	/**
	 * The two declarations, built once.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, FieldSpec>|null
	 */
	private static ?array $all = null;

	/**
	 * Returns both lists, keyed by wire name.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, FieldSpec> `options`, then `variants`.
	 */
	public static function all(): array {
		self::$all ??= array(
			self::OPTIONS  => self::options(),
			self::VARIANTS => self::variants(),
		);

		return self::$all;
	}

	/**
	 * Returns the dotted paths of the read-only fields inside the lists, as JsonSchemaCompiler::restObjectProperty() takes them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The paths.
	 */
	public static function readOnly(): array {
		return array(
			self::VARIANTS . '.' . self::VARIANT_ID,
			self::VARIANTS . '.' . self::LABEL,
			self::VARIANTS . '.' . self::STOCK . '.' . self::OUTCOME,
			self::VARIANTS . '.' . self::SELLABILITY,
		);
	}

	/**
	 * Declares `options`.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The list.
	 */
	private static function options(): FieldSpec {
		return FieldSpec::objectList(
			self::OPTIONS,
			'The product\'s option axes, each with its values, as a whole: an axis or a value left out is removed, and a key is fixed once it exists; the variants follow in `variants`.',
			static fn(): string => __( 'Options', 'seocart' ),
			array(
				self::key( 'axis' ),
				self::label( 'The axis\'s name as a shopper reads it, such as Size, in the language of the product\'s source post.', static fn(): string => __( 'Option name', 'seocart' ), 'Size' ),
				self::position( 'axis' ),
				FieldSpec::objectList(
					self::VALUES,
					'The axis\'s values; a product has at most ' . Product::MAX_VALUES . ' values in all.',
					static fn(): string => __( 'Values', 'seocart' ),
					array(
						self::key( 'value' ),
						self::label( 'The value\'s name as a shopper reads it, such as 250 g, in the language of the product\'s source post.', static fn(): string => __( 'Value', 'seocart' ), '250 g' ),
						self::position( 'value' ),
						new FieldSpec(
							name: self::IMAGE_ID,
							type: FieldType::Integer,
							description: 'The ID of the Media Library attachment that shows the value; null for none.',
							label: static fn(): string => __( 'Image', 'seocart' ),
							example: 314,
							nullable: true,
							minimum: 1
						),
						new FieldSpec(
							name: self::SWATCH,
							type: FieldType::String,
							description: 'The colour that shows the value, written #rrggbb in lower case; null for none.',
							label: static fn(): string => __( 'Swatch', 'seocart' ),
							example: '#7b4a2d',
							nullable: true,
							max_length: 7
						),
					),
					max_items: Product::MAX_VALUES
				),
			),
			max_items: Product::MAX_AXES
		);
	}

	/**
	 * Declares `variants`.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The list.
	 */
	private static function variants(): FieldSpec {
		$commerce = CommerceFields::all();

		return FieldSpec::objectList(
			self::VARIANTS,
			'The product\'s whole set of variants, one per combination of its options: a variant left out is disabled, never deleted; given with the single SKU and price of a product without options, the save is refused.',
			static fn(): string => __( 'Variants', 'seocart' ),
			array(
				new FieldSpec(
					name: self::VARIANT_ID,
					type: FieldType::Integer,
					description: 'The variant\'s ID, which a cart line names; read only.',
					label: static fn(): string => __( 'Variant', 'seocart' ),
					example: 1001,
					minimum: 1
				),
				FieldSpec::objectList(
					self::COMBINATION,
					'The combination the variant stands for: one pair per option axis of the product, naming one of its values; none for a product without options.',
					static fn(): string => __( 'Combination', 'seocart' ),
					array(
						new FieldSpec(
							name: self::AXIS,
							type: FieldType::String,
							description: 'The key of an option axis of the product.',
							label: static fn(): string => __( 'Option', 'seocart' ),
							example: 'size',
							required: true,
							max_length: 32
						),
						new FieldSpec(
							name: self::VALUE,
							type: FieldType::String,
							description: 'The key of one of that axis\'s values.',
							label: static fn(): string => __( 'Value', 'seocart' ),
							example: '250g',
							required: true,
							max_length: 32
						),
					),
					max_items: Product::MAX_AXES
				),
				CommerceFields::listedSku(),
				new FieldSpec(
					name: self::ENABLED,
					type: FieldType::Boolean,
					description: 'Whether the variant may be sold; left out, a variant keeps its state and a new one is enabled.',
					label: static fn(): string => __( 'Enabled', 'seocart' ),
					example: true
				),
				self::position( 'variant' ),
				new FieldSpec(
					name: self::LABEL,
					type: FieldType::String,
					description: 'The labels of the variant\'s option values in the order of their axes, such as 250 g / Whole bean; read only, and null without options.',
					label: static fn(): string => __( 'Variant label', 'seocart' ),
					example: '250 g / Whole bean',
					nullable: true,
					max_length: Variant::LABEL_MAX_LENGTH
				),
				$commerce[ CommerceFields::WEIGHT_GRAMS ],
				FieldSpec::objectList(
					self::PRICES,
					'The variant\'s whole set of authored prices, one per currency the store offers: a currency left out, or given a null price, has no authored price and is converted from the base price; left out, the stored prices stay.',
					static fn(): string => __( 'Prices', 'seocart' ),
					array(
						$commerce[ CommerceFields::CURRENCY ],
						$commerce[ CommerceFields::PRICE_MINOR ],
						$commerce[ CommerceFields::COMPARE_AT_MINOR ],
						new FieldSpec(
							name: self::AMOUNT_BASIS,
							type: FieldType::String,
							description: 'Whether the price includes tax: gross, or net, which it is when left out.',
							label: static fn(): string => __( 'Tax', 'seocart' ),
							example: VariantPrice::GROSS,
							allowed: array( VariantPrice::NET, VariantPrice::GROSS )
						),
					)
				),
				FieldSpec::objectList(
					self::STOCK,
					'The variant\'s stock cells: each sets its on-hand count at a location, applied after the product is saved, and comes back with what became of it; read, one per active location.',
					static fn(): string => __( 'Stock by location', 'seocart' ),
					array(
						new FieldSpec(
							name: self::LOCATION_UUID,
							type: FieldType::Uuid,
							description: 'The location the cell counts stock at.',
							label: static fn(): string => __( 'Location', 'seocart' ),
							example: '0f8b1c7e-5d1a-4c3e-9a7b-2e4f6a8c0d12',
							required: true
						),
						new FieldSpec(
							name: self::ON_HAND,
							type: FieldType::Integer,
							description: 'The units on hand at the location.',
							label: static fn(): string => __( 'On hand', 'seocart' ),
							example: 24,
							required: true,
							minimum: 0,
							maximum: self::ON_HAND_MAX
						),
						new FieldSpec(
							name: self::EXPECTED_ON_HAND,
							type: FieldType::Integer,
							description: 'The units on hand the cell was read at; the count is set only while it is still that, and null when read.',
							label: static fn(): string => __( 'On hand when read', 'seocart' ),
							example: 20,
							nullable: true,
							minimum: 0,
							maximum: self::ON_HAND_MAX
						),
						new FieldSpec(
							name: self::OUTCOME,
							type: FieldType::String,
							description: 'What became of the cell: applied, skipped when the count was already that, or conflict when it had changed since it was read; read only.',
							label: static fn(): string => __( 'Outcome', 'seocart' ),
							example: StockOutcome::APPLIED,
							allowed: StockOutcome::OUTCOMES
						),
					)
				),
				new FieldSpec(
					name: self::SELLABILITY,
					type: FieldType::String,
					description: 'Whether the variant may be sold to the requesting user, and if not the first reason it may not; read only.',
					label: static fn(): string => __( 'Sale status', 'seocart' ),
					example: 'sellable',
					allowed: array_map( static fn( SellabilityReason $reason ): string => $reason->value, SellabilityReason::cases() )
				),
			),
			max_items: Product::MAX_SYNC_VARIANTS
		);
	}

	/**
	 * Declares the key of an axis or a value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $of `axis` or `value`.
	 * @return FieldSpec The field.
	 */
	private static function key( string $of ): FieldSpec {
		return new FieldSpec(
			name: self::KEY,
			type: FieldType::String,
			description: 'The ' . $of . '\'s key: 1 to 32 lower-case letters, digits, "-" or "_", fixed once the ' . $of . ' exists; a changed key is a new ' . $of . '.',
			label: static fn(): string => __( 'Key', 'seocart' ),
			example: 'axis' === $of ? 'size' : '250g',
			required: true,
			max_length: 32
		);
	}

	/**
	 * Declares the label of an axis or a value: translatable text.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the form label.
	 * @param string   $example     An example.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function label( string $description, \Closure $label, string $example ): FieldSpec {
		return new FieldSpec(
			name: self::LABEL,
			type: FieldType::String,
			description: $description,
			label: $label,
			example: $example,
			required: true,
			max_length: OptionValue::LABEL_MAX_LENGTH,
			translatable: true
		);
	}

	/**
	 * Declares the position of an axis, a value or a variant.
	 *
	 * @since 0.2.0
	 *
	 * @param string $of What sorts: `axis`, `value` or `variant`.
	 * @return FieldSpec The field.
	 */
	private static function position( string $of ): FieldSpec {
		return new FieldSpec(
			name: self::POSITION,
			type: FieldType::Integer,
			description: 'Where the ' . $of . ' sorts, lowest first; left out, its place in the list.',
			label: static fn(): string => __( 'Position', 'seocart' ),
			example: 0,
			minimum: 0,
			maximum: 2147483647
		);
	}
}
