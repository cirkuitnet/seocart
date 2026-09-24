<?php
/**
 * ProductCommerceSchema: the `seocart` property of the product post type's REST resource
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Rest;

use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\JsonSchemaCompiler;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the product's commerce data on `wp/v2/seocart-products`, and compiles it into the one property the controller adds to the post's schema.
 *
 * Owns one fact: the wire shape of the `seocart` object. It is the commerce fields a save writes,
 * CommerceFields unchanged, then two a client only reads: `sellability`, the verdict on the
 * default variant for the requesting user, and `generation_state`, the product's marker, sent
 * only in the `edit` context. Their allowed values are the enums' own cases, so no list is kept
 * here. The property is compiled by JsonSchemaCompiler::restObjectProperty(), called here and
 * nowhere else; WordPress derives the route arguments from it, skipping the two read-only
 * fields, and validates a written object against it, so a key it does not declare is refused.
 * There is no boolean: whether a product may be sold is the verdict's value, `sellable`.
 *
 * @since 0.1.0
 */
final class ProductCommerceSchema {

	/**
	 * The name of the property in the post's schema and in every response and request body.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PROPERTY = 'seocart';

	/**
	 * The field that carries the verdict on the default variant.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SELLABILITY = 'sellability';

	/**
	 * The field that carries the product's generation marker, in the `edit` context only.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GENERATION_STATE = 'generation_state';

	/**
	 * Returns every field of the property, in the order a response sends them.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The commerce fields a save writes, then the two a client only reads.
	 */
	public static function fields(): array {
		return array_merge(
			array_values( CommerceFields::all() ),
			array(
				new FieldSpec(
					name: self::SELLABILITY,
					type: FieldType::String,
					description: 'Whether the default variant may be sold to the requesting user, and if not the first reason it may not.',
					label: static fn(): string => __( 'Sale status', 'seocart' ),
					example: 'sellable',
					allowed: array_map( static fn( SellabilityReason $reason ): string => $reason->value, SellabilityReason::cases() )
				),
				new FieldSpec(
					name: self::GENERATION_STATE,
					type: FieldType::String,
					description: 'Whether the product is complete, incomplete, or being saved; only a complete product may be sold.',
					label: static fn(): string => __( 'Save state', 'seocart' ),
					example: 'complete',
					allowed: array_map( static fn( GenerationState $state ): string => $state->value, GenerationState::cases() )
				),
			)
		);
	}

	/**
	 * Returns the names of the fields a client may write: the commerce fields a save writes.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	public static function writable(): array {
		return array_keys( CommerceFields::all() );
	}

	/**
	 * Compiles the property the controller adds to the post's schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The property's schema.
	 */
	public static function property(): array {
		return JsonSchemaCompiler::restObjectProperty(
			'The product\'s commerce data: the default variant\'s SKU, price, compare-at price and weight, and whether it may be sold.',
			self::fields(),
			array( self::SELLABILITY, self::GENERATION_STATE ),
			array( self::GENERATION_STATE )
		);
	}
}
