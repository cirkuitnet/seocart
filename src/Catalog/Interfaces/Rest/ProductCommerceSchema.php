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
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\JsonSchemaCompiler;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the product's commerce data on `wp/v2/seocart-products`, and compiles it into the one property the controller adds to the post's schema.
 *
 * Owns one fact: the wire shape of the `seocart` object. It is the commerce fields a save writes,
 * CommerceFields unchanged; the two that place the post among the product's posts, one per
 * language: `locale`, the language the post presents the product in, and `translation_of`, the
 * post it translates, which a post's first save may give to join that post's product; then two a
 * client only reads: `sellability`, the verdict on the default variant for the requesting user,
 * in the post's own language, and `generation_state`, the product's marker, sent only in the
 * `edit` context. The two translation fields are the multilingual plugin's REST language and
 * translation parameters, which a free multilingual plugin may not offer, declared here once, inside
 * the plugin's own object so that they never collide with a multilingual plugin's own. Their allowed values are the enums' own cases, so no list is kept
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
	 * The field that carries the locale the post presents its product in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCALE = 'locale';

	/**
	 * The field that carries the post a post translates: written on its first save to join that post's product, read as the product's source post.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TRANSLATION_OF = 'translation_of';

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
	 * @return list<FieldSpec> The commerce fields a save writes, the two translation fields, then the two a client only reads.
	 */
	public static function fields(): array {
		return array_merge(
			array_values( CommerceFields::all() ),
			array(
				new FieldSpec(
					name: self::LOCALE,
					type: FieldType::String,
					description: 'The WordPress locale the post presents the product in, such as de_DE; given on a first save, the post is published in that language, which a later save cannot change.',
					label: static fn(): string => __( 'Language', 'seocart' ),
					example: 'de_DE',
					nullable: true,
					max_length: ProductPostBinding::LOCALE_MAX_LENGTH
				),
				new FieldSpec(
					name: self::TRANSLATION_OF,
					type: FieldType::Integer,
					description: 'The ID of the product post this post translates; given on a first save, the post joins the product of that post, and read, it is the source post of the product, or null for the source post itself.',
					label: static fn(): string => __( 'Translation of', 'seocart' ),
					example: 42,
					nullable: true,
					minimum: 1
				),
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
	 * Returns the names of the fields a client may write: the commerce fields a save writes, and the two translation fields.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	public static function writable(): array {
		return array_merge( array_keys( CommerceFields::all() ), self::translation() );
	}

	/**
	 * Returns the names of the two fields that place the post among its product's posts: its locale, and the post it translates.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	public static function translation(): array {
		return array( self::LOCALE, self::TRANSLATION_OF );
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
			'The product\'s commerce data: the default variant\'s SKU, price, compare-at price and weight, the post\'s place among the product\'s posts in each language, and whether it may be sold.',
			self::fields(),
			array( self::SELLABILITY, self::GENERATION_STATE ),
			array( self::GENERATION_STATE )
		);
	}
}
