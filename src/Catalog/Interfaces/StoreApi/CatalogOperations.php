<?php
/**
 * CatalogOperations: the Store API's product listing, declared once
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\StoreApi;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Catalog\Application\Projection\StockSummary;
use SEOCart\Catalog\Application\Search\ProductListing;
use SEOCart\Catalog\Application\Search\SearchQuery;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `catalog.list_products`, the storefront's one read of the product listing.
 *
 * Owns one fact: the listing's contract with a client. The declaration drives the Store API route
 * `GET /products`, its OpenAPI entry and the surface walk. It is public: no capability, every
 * field public, the same page for every reader. So it has no ability, no command and no agent
 * exposure, which the framework refuses on a public operation, since those surfaces check the
 * capability a route checks. Like every operation route it answers `Cache-Control: no-store,
 * private` with `Vary: Cookie`, and it never sets a cookie.
 *
 * A page lists the products whose post in the locale is published and whose product is complete,
 * newest first. The search matches up to three words, each in the title or the excerpt, or at the
 * start of a SKU; the category filter includes its descendants. A page carries no price: prices
 * are hydrated by the page that shows them.
 *
 * @since 0.2.0
 */
final class CatalogOperations {

	/**
	 * The listing's operation id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_PRODUCTS = 'catalog.list_products';

	/**
	 * The listing's route, under the Store API's namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ROUTE = '/products';

	/**
	 * The most products a page may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_PER_PAGE = 96;

	/**
	 * How many products a page holds when the request does not say.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 24;

	/**
	 * The last page a request may ask for: far past any store's catalog, and small enough that the page's offset stays an integer.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_PAGE = 1000000;

	/**
	 * The most characters a search may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MOST_SEARCH_LENGTH = 100;

	/**
	 * The most characters a term slug may hold, as WordPress stores one.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MOST_SLUG_LENGTH = 200;

	/**
	 * A uuid the documentation shows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXAMPLE_UUID = '6b1f0c2e-4d7a-4c9b-8e15-3a2d9f7c4b60';

	/**
	 * Declares the listing.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function listProducts(): OperationDefinition {
		return new OperationDefinition(
			id: self::LIST_PRODUCTS,
			label: static fn(): string => __( 'List products', 'seocart' ),
			summary: 'Lists one page of the store\'s products in a locale, newest first, searched by words in their titles, excerpts or SKUs and filtered by category, brand, tag and availability; it carries no price and never sets a cookie.',
			input: self::input(),
			output: new ResourceSchema(
				'ProductList',
				array(
					FieldSpec::objectList(
						'items',
						'The products of the page, newest first: each one whose post in the locale is published and whose product is complete.',
						static fn(): string => __( 'Products', 'seocart' ),
						self::item(),
						required: true,
						max_items: self::MOST_PER_PAGE
					),
					self::count( 'page', 'The page, from 1.', static fn(): string => __( 'Page', 'seocart' ), 1, true ),
					self::count( 'per_page', 'How many products a page holds.', static fn(): string => __( 'Products per page', 'seocart' ), self::DEFAULT_PER_PAGE, true ),
					new FieldSpec(
						name: 'has_more',
						type: FieldType::Boolean,
						description: 'Whether at least one more product follows this page.',
						label: static fn(): string => __( 'More products', 'seocart' ),
						example: true,
						required: true
					),
				)
			),
			capability: null,
			resource_field: null,
			errors: array(
				CatalogError::LocaleUnsupported,
				CatalogError::TermNotFound,
				CatalogError::SearchInvalid,
			),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( ProductListing::class, 'list' ),
			rest: new RestBinding( self::ROUTE, store: true )
		);
	}

	/**
	 * Declares the listing's input: the locale, the search, the three term filters, availability, the order and the page.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function input(): array {
		return array(
			new FieldSpec(
				name: 'locale',
				type: FieldType::String,
				description: 'The WordPress locale whose product posts are listed, one the site publishes in; when absent, the site\'s locale.',
				label: static fn(): string => __( 'Language', 'seocart' ),
				example: 'en_US',
				max_length: ProductPostBinding::LOCALE_MAX_LENGTH
			),
			new FieldSpec(
				name: 'search',
				type: FieldType::String,
				description: 'Up to ' . SearchQuery::MOST_WORDS . ' words separated by spaces, each of which must appear in the product\'s title or excerpt or begin one of its SKUs; when absent, nothing is searched.',
				label: static fn(): string => __( 'Search', 'seocart' ),
				example: 'linen shirt',
				max_length: self::MOST_SEARCH_LENGTH
			),
			self::slug( 'category', 'The slug of a product category: the products in it or in any of its descendants are listed.', static fn(): string => __( 'Category', 'seocart' ), 'shirts' ),
			self::slug( 'brand', 'The slug of a product brand: only its products are listed.', static fn(): string => __( 'Brand', 'seocart' ), 'acme' ),
			self::slug( 'tag', 'The slug of a product tag: only the products it is given to are listed.', static fn(): string => __( 'Tag', 'seocart' ), 'summer' ),
			new FieldSpec(
				name: 'in_stock',
				type: FieldType::Boolean,
				description: 'Whether to list only the products in stock; when absent, products are listed whatever their stock.',
				label: static fn(): string => __( 'In stock only', 'seocart' ),
				example: true
			),
			new FieldSpec(
				name: 'orderby',
				type: FieldType::String,
				description: 'The order of the products: newest, the most recently published first.',
				label: static fn(): string => __( 'Order', 'seocart' ),
				example: SearchQuery::NEWEST,
				default_value: SearchQuery::NEWEST,
				allowed: array( SearchQuery::NEWEST )
			),
			new FieldSpec(
				name: 'page',
				type: FieldType::Integer,
				description: 'The page to list, from 1 to ' . self::MOST_PAGE . '.',
				label: static fn(): string => __( 'Page', 'seocart' ),
				example: 2,
				default_value: 1,
				minimum: 1,
				maximum: self::MOST_PAGE
			),
			new FieldSpec(
				name: 'per_page',
				type: FieldType::Integer,
				description: 'How many products a page holds, from 1 to ' . self::MOST_PER_PAGE . '.',
				label: static fn(): string => __( 'Products per page', 'seocart' ),
				example: self::DEFAULT_PER_PAGE,
				default_value: self::DEFAULT_PER_PAGE,
				minimum: 1,
				maximum: self::MOST_PER_PAGE
			),
		);
	}

	/**
	 * Declares the fields of one listed product.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function item(): array {
		return array(
			self::count( 'post_id', 'The product\'s post in the locale.', static fn(): string => __( 'Post', 'seocart' ), 40, false ),
			self::count( 'product_id', 'The product.', static fn(): string => __( 'Product', 'seocart' ), 12, false ),
			new FieldSpec(
				name: 'product_uuid',
				type: FieldType::Uuid,
				description: 'The product\'s public identifier.',
				label: static fn(): string => __( 'Product identifier', 'seocart' ),
				example: self::EXAMPLE_UUID,
				required: true
			),
			new FieldSpec(
				name: 'variant_id',
				type: FieldType::Integer,
				description: 'The variant the listing shows: the enabled variant of the lowest position; null for a product whose variants are all disabled, which doctor reports.',
				label: static fn(): string => __( 'Variant', 'seocart' ),
				example: 15,
				required: true,
				nullable: true
			),
			new FieldSpec(
				name: 'sku',
				type: FieldType::String,
				description: 'The SKU of that variant, or null when there is none.',
				label: static fn(): string => __( 'SKU', 'seocart' ),
				example: 'SHIRT-LINEN-M',
				required: true,
				nullable: true
			),
			new FieldSpec(
				name: 'title',
				type: FieldType::String,
				description: 'The title of the product\'s post, as WordPress displays it, so it may hold HTML entities.',
				label: static fn(): string => __( 'Title', 'seocart' ),
				example: 'Linen shirt',
				required: true,
				translatable: true
			),
			new FieldSpec(
				name: 'permalink',
				type: FieldType::String,
				description: 'The address of the product\'s post.',
				label: static fn(): string => __( 'Address', 'seocart' ),
				example: 'https://example.com/products/linen-shirt/',
				required: true
			),
			FieldSpec::object(
				'image',
				'The post\'s featured image at the medium size, or null for a post without one.',
				static fn(): string => __( 'Image', 'seocart' ),
				array(
					self::count( 'id', 'The image\'s attachment.', static fn(): string => __( 'Image', 'seocart' ), 77, false ),
					new FieldSpec(
						name: 'url',
						type: FieldType::String,
						description: 'The address of the image at the medium size, or at its full size when it has no medium size.',
						label: static fn(): string => __( 'Image address', 'seocart' ),
						example: 'https://example.com/wp-content/uploads/linen-shirt-300x300.jpg',
						required: true
					),
					new FieldSpec(
						name: 'alt',
						type: FieldType::String,
						description: 'The image\'s alternative text, empty when it has none.',
						label: static fn(): string => __( 'Alternative text', 'seocart' ),
						example: 'A linen shirt, folded',
						required: true,
						translatable: true
					),
				),
				required: true,
				nullable: true
			),
			new FieldSpec(
				name: 'availability',
				type: FieldType::String,
				description: 'Whether the product is in stock at any location that ships online orders: in_stock, out_of_stock, or unknown while its stock has not been summarized.',
				label: static fn(): string => __( 'Availability', 'seocart' ),
				example: StockSummary::IN_STOCK,
				required: true,
				allowed: StockSummary::values()
			),
		);
	}

	/**
	 * Declares the slug of one term filter.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param string   $example     A slug the documentation shows.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function slug( string $name, string $description, \Closure $label, string $example ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::String,
			description: $description,
			label: $label,
			example: $example,
			max_length: self::MOST_SLUG_LENGTH
		);
	}

	/**
	 * Declares one required whole number of the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param int      $example     A value the field holds.
	 * @param bool     $fromOne     Whether the value is at least 1, as a page is.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function count( string $name, string $description, \Closure $label, int $example, bool $fromOne ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: $example,
			required: true,
			minimum: $fromOne ? 1 : null
		);
	}
}
