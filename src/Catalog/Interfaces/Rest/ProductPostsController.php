<?php
/**
 * ProductPostsController: the plugin's own `wp/v2` controller for the product post type
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Rest;

use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Application\ProductWrite\CommerceInput;
use SEOCart\Catalog\Application\ProductWrite\ProductSave;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Application\ProductWrite\VariantFields;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionMatrix;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Domain\VariantSelection;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\SetStockLevels;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\StockOutcome;
use SEOCart\Inventory\Domain\StockTarget;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Serves `wp/v2/seocart-products` as WordPress's posts controller does, and saves a product's post and commerce data in one call.
 *
 * Owns one fact: how a request to the product's `wp/v2` endpoint becomes one product save. A
 * create or an update is prepared by core's own prepare_item_for_database(), so every core
 * field rule and the `rest_pre_insert_seocart_product` filter apply, and the `seocart` object,
 * validated against ProductCommerceSchema by WordPress, becomes the save's commerce input, and its
 * `locale` and `translation_of` the post's language and the post it translates. Then
 * SaveProduct writes the post and every commerce row in one transaction. Only after it has
 * committed does the rest of core's sequence run, in core's order and with core's hooks:
 * `rest_insert_seocart_product` on the reloaded post, featured media, template, terms and
 * registered meta, every other plugin's REST field update callback, then
 * `rest_after_insert_seocart_product` and the deferred `wp_after_insert_post`. So REST field
 * callbacks, `rest_insert_`, `rest_after_insert_` and `wp_after_insert_post` run after the
 * product's transaction has committed; the listeners core fires inside its post write,
 * `save_post` and the status transitions among them, run inside the save's window, as the
 * product write's recovery allows for. One request is one save. A failure of one of the later
 * steps is returned as core returns it, after the product is saved, as in core. A refused save
 * is the plugin's error, in its shape.
 *
 * The terms step is core's handle_terms(), which writes the product's categories, brands and
 * tags, the taxonomies attached to the post type, after the save has committed and outside its
 * transaction. So the terms are not part of the product's save: no `ProductSaved` names them
 * among its changed fields (an update that changes only terms is saved, and named, as a change
 * of the post), and writing them changes neither the product's generation marker nor its
 * `updated_at`. WordPress keeps the term counts.
 *
 * A response is core's, with the `seocart` object added among the REST fields, before core's
 * context filter and `rest_prepare_seocart_product`: the single variant's commerce data for a
 * product without options, the post's locale among the product's posts and the product's source
 * post when it is another, the verdict on the product for the requesting user in the post's
 * locale, and in the `edit` context the product's marker, its option axes and its variants, each
 * with its prices, its verdict and a stock cell per active location: one read of the levels for
 * every variant.
 *
 * Stock cells ride the same request: each sets a variant's units on hand at a location, from the
 * count the merchant read there. Their locations are resolved before anything is written, and a
 * request that gives cells needs `seocart_manage_inventory` besides the right to edit the post,
 * checked with the others before anything is written. They are applied after core's sequence,
 * by the ids the save gave each combination, in one unit of work of their own (SetStockLevels),
 * and each comes back in the response with what became of it. A refusal of the unit, the product
 * saved, is returned as a failure of a later step is.
 *
 * Reading and writing are decided by the product post type's mapped capabilities, exactly as for
 * core's controller. A create or an update that names, in `translation_of`, the post it
 * translates also needs `edit_post` on the source post of that post's product, since the save
 * joins that product and may change its commerce data; so does an update that gives commerce
 * fields through a post that is not its product's source post, since the product's SKU, price
 * and stock are one for all its posts. The check is core's meta capability, so `edit_others_*`
 * applies, and it is made before anything is written. The save asks again under the product's
 * lock. Stock cells need `seocart_manage_inventory` too. Deleting, listing and reading are
 * core's, unchanged. Core's autosave and revision controllers are built from this one: they
 * prepare posts with its prepare_item_for_database(), which knows no commerce field, and never
 * save a product, so an autosave or a revision restore cannot touch a commerce row.
 *
 * WordPress builds a post type's controller itself, with the post type's name only. The kernel
 * puts its own instance, built with the services it needs, into the post type's controller slot
 * with install() whenever a REST server is built, before core registers the routes. The write
 * service, which most REST requests never need, is resolved on the first create or update. An
 * instance WordPress built earlier, without the services, refuses to save and adds no commerce
 * data.
 *
 * @since 0.1.0
 */
final class ProductPostsController extends WP_REST_Posts_Controller {

	/**
	 * Returns the service that saves a product; called on the first create or update.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(): SaveProduct)|null
	 */
	private ?\Closure $save;

	/**
	 * Loads the product a post is bound to.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository|null
	 */
	private ?ProductRepository $products;

	/**
	 * Gives the verdict a response carries.
	 *
	 * @since 0.1.0
	 *
	 * @var Sellability|null
	 */
	private ?Sellability $sellability;

	/**
	 * Fires the deferred after-insert hook.
	 *
	 * @since 0.1.0
	 *
	 * @var PostGateway|null
	 */
	private ?PostGateway $posts;

	/**
	 * Turns a refused save into the plugin's error.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTranslator|null
	 */
	private ?ErrorTranslator $errors;

	/**
	 * Receives a failure no client caused, and where it happened.
	 *
	 * @since 0.1.0
	 *
	 * @var (callable(\Throwable, string): void)|null
	 */
	private $report;

	/**
	 * Returns the unit that applies stock cells; called on the first write that gives cells.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): SetStockLevels)|null
	 */
	private ?\Closure $stockLevels;

	/**
	 * Returns the stock service, whose levels an `edit`-context response reads.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): StockService)|null
	 */
	private ?\Closure $stock;

	/**
	 * Returns the stock locations, which a stock cell names and an `edit`-context response lists.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): LocationDirectory)|null
	 */
	private ?\Closure $locations;

	/**
	 * What became of the stock cells of the write being answered, by variant and location: none outside a write.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, StockOutcome>
	 */
	private array $outcomes = array();

	/**
	 * The post prepare_item_for_response() is preparing, for add_additional_fields_to_object(); null outside it.
	 *
	 * @since 0.1.0
	 *
	 * @var WP_Post|null
	 */
	private ?WP_Post $responding = null;

	/**
	 * Creates the controller. The services are the kernel's; WordPress passes the post type only.
	 *
	 * @since 0.1.0
	 *
	 * @param string                 $post_type   The post type.
	 * @param \Closure|null          $save        Optional. Returns the service that saves a product (a SaveProduct). Default none.
	 * @param ProductRepository|null $products    Optional. Loads the product a post is bound to. Default none.
	 * @param Sellability|null       $sellability Optional. Gives the verdict a response carries. Default none.
	 * @param PostGateway|null       $posts       Optional. Fires the deferred after-insert hook. Default none.
	 * @param ErrorTranslator|null   $errors      Optional. Turns a refused save into the plugin's error. Default none.
	 * @param callable|null          $report      Optional. Receives an unexpected failure (Throwable) and where it happened (string). Default none.
	 * @param \Closure|null          $stockLevels Optional. Returns the unit that applies stock cells (a SetStockLevels). Default none.
	 * @param \Closure|null          $stock       Optional. Returns the stock service (a StockService). Default none.
	 * @param \Closure|null          $locations   Optional. Returns the stock locations (a LocationDirectory). Default none.
	 *
	 * @phpstan-param (\Closure(): SaveProduct)|null             $save
	 * @phpstan-param (callable(\Throwable, string): void)|null $report
	 * @phpstan-param (\Closure(): SetStockLevels)|null          $stockLevels
	 * @phpstan-param (\Closure(): StockService)|null            $stock
	 * @phpstan-param (\Closure(): LocationDirectory)|null       $locations
	 */
	public function __construct(
		$post_type,
		?\Closure $save = null,
		?ProductRepository $products = null,
		?Sellability $sellability = null,
		?PostGateway $posts = null,
		?ErrorTranslator $errors = null,
		?callable $report = null,
		?\Closure $stockLevels = null,
		?\Closure $stock = null,
		?\Closure $locations = null
	) {
		parent::__construct( $post_type );

		$this->save        = $save;
		$this->products    = $products;
		$this->sellability = $sellability;
		$this->posts       = $posts;
		$this->errors      = $errors;
		$this->report      = $report;
		$this->stockLevels = $stockLevels;
		$this->stock       = $stock;
		$this->locations   = $locations;
	}

	/**
	 * Builds a controller and puts it into its post type's controller slot, where WordPress looks for it.
	 *
	 * Nothing is built or installed when the post type is not registered, since core's constructor
	 * reads the registered type, or when another plugin has made another class its controller: that
	 * class's instance is the one WordPress expects there. The autosave and revision controllers
	 * take the post type's controller as their parent when they are built, and WordPress keeps them
	 * on the post type, so a pair built before this call would keep the controller it replaces:
	 * they are dropped, and core builds them again around this instance when it registers the routes.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $postType   The post type.
	 * @param \Closure $controller Builds the post type's controller with its services (a ProductPostsController).
	 *
	 * @phpstan-param \Closure(): self $controller
	 */
	public static function install( string $postType, \Closure $controller ): void {
		$type = get_post_type_object( $postType );

		if ( ! $type instanceof WP_Post_Type || self::class !== $type->rest_controller_class ) {
			return;
		}

		$type->rest_controller = $controller();

		// @phpstan-ignore assign.propertyType (Null is the property's default, from which WordPress builds the controller; its docblock leaves null out.)
		$type->autosave_rest_controller = null;
		// @phpstan-ignore assign.propertyType (As above.)
		$type->revisions_rest_controller = null;
	}

	/**
	 * Returns core's schema for the post type, with the `seocart` property.
	 *
	 * WordPress derives the create and update arguments from it, so the property is validated and
	 * sanitized on every write, and its two read-only fields are no argument at all.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The schema, other plugins' REST fields included.
	 */
	public function get_item_schema() {
		parent::get_item_schema();

		if ( ! isset( $this->schema['properties'][ ProductCommerceSchema::PROPERTY ] ) ) {
			$this->schema['properties'][ ProductCommerceSchema::PROPERTY ] = ProductCommerceSchema::property();
		}

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Creates a product: its post and its commerce data, in one save.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error The product, 201, or the error.
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			// Core refuses before it prepares anything: its own answer, unchanged.
			return parent::create_item( $request );
		}

		$prepared = $this->prepare_item_for_database( $request );

		if ( $prepared instanceof WP_Error ) {
			return $prepared;
		}

		$prepared->post_type = $this->post_type;

		if ( ! empty( $prepared->post_name ) && ! empty( $prepared->post_status ) && in_array( $prepared->post_status, array( 'draft', 'pending' ), true ) ) {
			// As core does: a draft's or a pending post's slug is made unique as if it were published.
			$prepared->post_name = wp_unique_post_slug( $prepared->post_name, 0, 'publish', $prepared->post_type, $prepared->post_parent ?? 0 );
		}

		return $this->write( $request, $prepared, null );
	}

	/**
	 * Updates a product: its post and its commerce data, in one save.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error The product, or the error.
	 */
	public function update_item( $request ) {
		$valid = $this->get_post( $request['id'] );

		if ( $valid instanceof WP_Error ) {
			return $valid;
		}

		$before   = get_post( $valid->ID );
		$prepared = $this->prepare_item_for_database( $request );

		if ( $prepared instanceof WP_Error ) {
			return $prepared;
		}

		$status = ! empty( $prepared->post_status ) ? $prepared->post_status : $before?->post_status;

		if ( ! empty( $prepared->post_name ) && in_array( $status, array( 'draft', 'pending' ), true ) ) {
			// As core does: a draft's or a pending post's slug is made unique as if it were published.
			$prepared->post_name = wp_unique_post_slug( $prepared->post_name, $valid->ID, 'publish', $this->post_type, ! empty( $prepared->post_parent ) ? $prepared->post_parent : 0 );
		}

		return $this->write( $request, $prepared, $before );
	}

	/**
	 * Returns core's response for a post, with the `seocart` object.
	 *
	 * The object is added where core adds every other plugin's REST fields, add_additional_fields_to_object(),
	 * so core's context filter applies to it, and a `rest_prepare_seocart_product` filter reads and
	 * changes it like any other field of the response.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post         $item    The post.
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$outer            = $this->responding;
		$this->responding = $item;

		try {
			return parent::prepare_item_for_response( $item, $request );
		} finally {
			$this->responding = $outer;
		}
	}

	/**
	 * Adds the fields other plugins registered, and the `seocart` object of the post being prepared.
	 *
	 * Core calls it inside prepare_item_for_response(), before it filters the response by context
	 * and applies `rest_prepare_seocart_product`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $response_data The response's data so far.
	 * @param WP_REST_Request      $request       The request.
	 * @return array<string, mixed> The data, with the fields added.
	 */
	protected function add_additional_fields_to_object( $response_data, $request ) {
		$response_data = parent::add_additional_fields_to_object( $response_data, $request );

		if ( null === $this->responding || null === $this->products || null === $this->sellability || $request->is_method( 'HEAD' ) ) {
			return $response_data;
		}

		if ( rest_is_field_included( ProductCommerceSchema::PROPERTY, $this->get_fields_for_response( $request ) ) ) {
			$response_data[ ProductCommerceSchema::PROPERTY ] = $this->commerce( $this->responding, 'edit' === $request['context'] );
		}

		return $response_data;
	}

	/**
	 * Saves the product, then runs the rest of core's write sequence on the committed post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param object          $prepared The post as core's prepare_item_for_database() prepared it.
	 * @param WP_Post|null    $before   The post before an update, or null for a create.
	 * @return WP_REST_Response|WP_Error The product, or the error.
	 */
	private function write( WP_REST_Request $request, object $prepared, ?WP_Post $before ) {
		$creating = null === $before;
		$cells    = $this->stockCells( $request );

		if ( $cells instanceof WP_Error ) {
			return $cells;
		}

		$saved = $this->save( $request, $prepared, $before );

		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$post = get_post( $saved->postId );

		if ( ! $post instanceof WP_Post ) {
			return $this->unexpected( new \LogicException( sprintf( 'The saved post %d cannot be read back.', $saved->postId ) ) );
		}

		/** This action is documented in wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php */
		do_action( "rest_insert_{$this->post_type}", $post, $request, $creating ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, fired where core fires it.

		$schema  = $this->get_item_schema();
		$applied = $creating ? $this->applyCreateFields( $schema, $request, $post ) : $this->applyUpdateFields( $schema, $request, $post );

		if ( $applied instanceof WP_Error ) {
			return $applied;
		}

		$post          = get_post( $post->ID );
		$fields_update = $this->update_additional_fields_for_object( $post, $request );

		if ( $fields_update instanceof WP_Error ) {
			return $fields_update;
		}

		$request->set_param( 'context', 'edit' );

		/** This action is documented in wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php */
		do_action( "rest_after_insert_{$this->post_type}", $post, $request, $creating ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, fired where core fires it.

		// save() has refused already when the gateway is missing.
		$this->posts?->fireAfterInsert( $post->ID, ! $creating, $before );

		$applied = $this->applyStock( $cells, $saved );

		if ( $applied instanceof WP_Error ) {
			return $applied;
		}

		try {
			$response = rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );
		} finally {
			$this->outcomes = array();
		}

		if ( $creating ) {
			$response->set_status( 201 );
			$response->header( 'Location', rest_url( rest_get_route_for_post( $post ) ) );
		}

		return $response;
	}

	/**
	 * Checks core's permission to create a post, then the permission to join the product of the post the request names as the one it translates.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True when the request may go on, or the error.
	 */
	public function create_item_permissions_check( $request ) {
		$allowed = parent::create_item_permissions_check( $request );
		$allowed = true === $allowed ? $this->mayJoin( $request ) : $allowed;

		return true === $allowed ? self::maySetStock( $request ) : $allowed;
	}

	/**
	 * Checks core's permission to update the post, then the permission to join the product of the post the request names as the one it translates, to change the commerce data of the product the post presents, and to set its stock.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True when the request may go on, or the error.
	 */
	public function update_item_permissions_check( $request ) {
		$allowed = parent::update_item_permissions_check( $request );
		$allowed = true === $allowed ? $this->mayJoin( $request ) : $allowed;
		$allowed = true === $allowed ? $this->mayChangeCommerce( $request ) : $allowed;

		return true === $allowed ? self::maySetStock( $request ) : $allowed;
	}

	/**
	 * Refuses a request that names, as the post it translates, a post whose product the user may not edit: `edit_post` on the product's source post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True when it names none, or the user may edit that product; the error otherwise.
	 */
	private function mayJoin( WP_REST_Request $request ) {
		$translationOf = self::translationOf( $request );

		if ( null === $translationOf ) {
			return true;
		}

		$source = $this->products?->findByPost( $translationOf )?->sourcePostId() ?? $translationOf;

		if ( current_user_can( 'edit_post', $source ) ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_edit',
			__( 'Sorry, you are not allowed to add a translation to that product.', 'seocart' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Refuses a request that changes commerce fields through a post that is not its product's source post, when the user may not edit the product: `edit_post` on the product's source post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request to update a post.
	 * @return true|WP_Error True when it gives no commerce field, the post is its product's source post or presents
	 *                       none, or the user may edit the product; the error otherwise.
	 */
	private function mayChangeCommerce( WP_REST_Request $request ) {
		$given = array_intersect_key( self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] ), array_merge( CommerceFields::all(), VariantFields::all() ) );

		if ( array() === $given ) {
			return true;
		}

		$postId = (int) $request['id'];
		$source = $this->products?->findByPost( $postId )?->sourcePostId();

		if ( null === $source || $source === $postId || current_user_can( 'edit_post', $source ) ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_edit',
			__( 'Sorry, you are not allowed to change the commerce data of that product.', 'seocart' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Refuses a request that gives stock cells, when the user may not manage inventory: `seocart_manage_inventory`.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The request to create or update a post.
	 * @return true|WP_Error True when it gives no cell, or the user may manage inventory; the error otherwise.
	 */
	private static function maySetStock( WP_REST_Request $request ) {
		$given = false;

		foreach ( self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] )[ VariantFields::VARIANTS ] ?? array() as $variant ) {
			$given = $given || ( is_array( $variant ) && array() !== ( $variant[ VariantFields::STOCK ] ?? array() ) );
		}

		if ( ! $given || current_user_can( InventoryOperations::CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_edit',
			__( 'Sorry, you are not allowed to change the stock of that product.', 'seocart' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Reads the stock cells a request gives, each named by the hash of its variant's combination and resolved to its location, before anything is written.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return list<array{hash: string, location: int, on_hand: int, expected: int}>|WP_Error The cells, or the refusal of a
	 *         location that does not exist, a combination that is not valid, a cell without the count it was read at,
	 *         or two cells of one combination at one location.
	 */
	private function stockCells( WP_REST_Request $request ): array|WP_Error {
		$cells = array();

		try {
			foreach ( self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] )[ VariantFields::VARIANTS ] ?? array() as $variant ) {
				if ( ! is_array( $variant ) || array() === ( $variant[ VariantFields::STOCK ] ?? array() ) ) {
					continue;
				}

				$hash = self::selectionOf( $variant )->hash();

				foreach ( (array) $variant[ VariantFields::STOCK ] as $cell ) {
					$read = $this->stockCell( $hash, (array) $cell );
					$at   = $hash . '@' . $read['location'];

					// One count per item: a second cell would set the item the first one sets.
					if ( isset( $cells[ $at ] ) ) {
						return self::invalid( new \InvalidArgumentException( 'A variant gives two stock cells at one location.' ) );
					}

					$cells[ $at ] = $read;
				}
			}
		} catch ( CodedException $refused ) {
			return null === $this->errors ? $this->unexpected( $refused ) : $this->errors->translate( $refused );
		} catch ( \InvalidArgumentException $invalid ) {
			return self::invalid( $invalid );
		}

		return array_values( $cells );
	}

	/**
	 * Reads one stock cell of a variant, its location resolved by its UUID.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the cell gives no count it was read at, or no location directory was given.
	 * @phpstan-throws \InvalidArgumentException|CodedException `stock.location_missing` when no location has the UUID.
	 *
	 * @param string               $hash The combination hash of the cell's variant.
	 * @param array<string, mixed> $cell The cell, as WordPress validated it.
	 * @return array{hash: string, location: int, on_hand: int, expected: int} The cell.
	 */
	private function stockCell( string $hash, array $cell ): array {
		$uuid     = (string) ( $cell[ VariantFields::LOCATION_UUID ] ?? '' );
		$expected = $cell[ VariantFields::EXPECTED_ON_HAND ] ?? null;

		if ( null === $this->locations || ! is_int( $expected ) ) {
			throw new \InvalidArgumentException( 'A stock cell gives the units on hand it was read at, expected_on_hand.' );
		}

		$location = ( $this->locations )()->findByUuid( $uuid ) ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $uuid ) );

		return array(
			'hash'     => $hash,
			'location' => $location->id,
			'on_hand'  => (int) ( $cell[ VariantFields::ON_HAND ] ?? 0 ),
			'expected' => $expected,
		);
	}

	/**
	 * Applies the stock cells of a committed save, by the ids the save gave each combination, and keeps their outcomes for the response.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{hash: string, location: int, on_hand: int, expected: int}> $cells The cells.
	 * @param SaveResult                                                            $saved The save.
	 * @return true|WP_Error True, or the refusal of the unit, the product saved.
	 */
	private function applyStock( array $cells, SaveResult $saved ) {
		if ( array() === $cells ) {
			return true;
		}

		try {
			if ( null === $this->stockLevels ) {
				return $this->unexpected( new \LogicException( 'This instance of the product controller was built without the unit that applies stock cells.' ) );
			}

			$targets = array();

			foreach ( $cells as $cell ) {
				$variantId = $saved->variantIds[ $cell['hash'] ] ?? null;

				if ( null === $variantId ) {
					return $this->unexpected( new \LogicException( 'A stock cell names a combination the save did not write.' ) );
				}

				$targets[] = new StockTarget( $variantId, $cell['location'], $cell['on_hand'], $cell['expected'] );
			}

			foreach ( ( $this->stockLevels )()->apply( $targets, Actor::user( get_current_user_id() ) ) as $outcome ) {
				$this->outcomes[ $outcome->variantId . '@' . $outcome->locationId ] = $outcome;
			}
		} catch ( CodedException $refused ) {
			return null === $this->errors ? $this->unexpected( $refused ) : $this->errors->translate( $refused );
		} catch ( \Throwable $failure ) {
			return $this->unexpected( $failure );
		}

		return true;
	}

	/**
	 * Saves the product: the post's fields core prepared and the `seocart` object, in one call.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Returns the save's result.
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param object          $prepared The post as core prepared it.
	 * @param WP_Post|null    $before   The post before an update, or null for a create.
	 * @return SaveResult|WP_Error The save's result, or the error.
	 */
	private function save( WP_REST_Request $request, object $prepared, ?WP_Post $before ): SaveResult|WP_Error {
		if ( null === $this->save || null === $this->posts || null === $this->errors ) {
			return $this->unexpected( new \LogicException( 'This instance of the product controller was built by WordPress without its services; the kernel installs the one that saves.' ) );
		}

		$commerce = self::commerceInput( $request );
		$locale   = self::localeOf( $request );

		if ( $commerce instanceof WP_Error ) {
			return $commerce;
		}

		if ( $locale instanceof WP_Error ) {
			return $locale;
		}

		try {
			return ( $this->save )()->save( new ProductSave( $before?->ID, (array) $prepared, $commerce, Actor::user( get_current_user_id() ), self::translationOf( $request ), $locale ) );
		} catch ( CodedException $refused ) {
			return $this->errors->translate( $refused );
		} catch ( \Throwable $failure ) {
			return $this->unexpected( $failure );
		}
	}

	/**
	 * Applies the fields core writes after a create, in core's order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $schema  The item schema.
	 * @param WP_REST_Request      $request The request.
	 * @param WP_Post              $post    The created post.
	 * @return true|WP_Error True, or the error of the terms or the meta.
	 */
	private function applyCreateFields( array $schema, WP_REST_Request $request, WP_Post $post ) {
		if ( ! empty( $schema['properties']['sticky'] ) ) {
			if ( ! empty( $request['sticky'] ) ) {
				stick_post( $post->ID );
			} else {
				unstick_post( $post->ID );
			}
		}

		if ( ! empty( $schema['properties']['featured_media'] ) && isset( $request['featured_media'] ) ) {
			$this->handle_featured_media( $request['featured_media'], $post->ID );
		}

		if ( ! empty( $schema['properties']['format'] ) && ! empty( $request['format'] ) ) {
			set_post_format( $post, $request['format'] );
		}

		if ( ! empty( $schema['properties']['template'] ) && isset( $request['template'] ) ) {
			$this->handle_template( $request['template'], $post->ID, true );
		}

		return $this->applyTermsAndMeta( $schema, $request, $post );
	}

	/**
	 * Applies the fields core writes after an update, in core's order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $schema  The item schema.
	 * @param WP_REST_Request      $request The request.
	 * @param WP_Post              $post    The updated post.
	 * @return true|WP_Error True, or the error of the terms or the meta.
	 */
	private function applyUpdateFields( array $schema, WP_REST_Request $request, WP_Post $post ) {
		if ( ! empty( $schema['properties']['format'] ) && ! empty( $request['format'] ) ) {
			set_post_format( $post, $request['format'] );
		}

		if ( ! empty( $schema['properties']['featured_media'] ) && isset( $request['featured_media'] ) ) {
			$this->handle_featured_media( $request['featured_media'], $post->ID );
		}

		if ( ! empty( $schema['properties']['sticky'] ) && isset( $request['sticky'] ) ) {
			if ( ! empty( $request['sticky'] ) ) {
				stick_post( $post->ID );
			} else {
				unstick_post( $post->ID );
			}
		}

		if ( ! empty( $schema['properties']['template'] ) && isset( $request['template'] ) ) {
			$this->handle_template( $request['template'], $post->ID );
		}

		return $this->applyTermsAndMeta( $schema, $request, $post );
	}

	/**
	 * Applies the terms and the registered meta, as core does after either write.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $schema  The item schema.
	 * @param WP_REST_Request      $request The request.
	 * @param WP_Post              $post    The post.
	 * @return true|WP_Error True, or the error.
	 */
	private function applyTermsAndMeta( array $schema, WP_REST_Request $request, WP_Post $post ) {
		$terms_update = $this->handle_terms( $post->ID, $request );

		if ( $terms_update instanceof WP_Error ) {
			return $terms_update;
		}

		if ( ! empty( $schema['properties']['meta'] ) && isset( $request['meta'] ) ) {
			$meta_update = $this->meta->update_value( $request['meta'], $post->ID );

			if ( $meta_update instanceof WP_Error ) {
				return $meta_update;
			}
		}

		return true;
	}

	/**
	 * Builds the `seocart` object of a response.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The options and the variants in the `edit` context.
	 *
	 * @param WP_Post $post The post.
	 * @param bool    $edit Whether the response is in the `edit` context.
	 * @return array<string, mixed> The commerce fields of the single variant of a product without options, the post's
	 *                              locale and source post, the verdict, and in the `edit` context the product's marker,
	 *                              options and variants.
	 */
	private function commerce( WP_Post $post, bool $edit ): array {
		$product  = null === $this->products ? null : $this->products->findByPost( $post->ID );
		$values   = self::commerceValues( $product );
		$type     = get_post_type_object( $this->post_type );
		$source   = $product?->sourcePostId();
		$locale   = $product?->bindingOf( $post->ID )?->locale();
		$private  = $type instanceof WP_Post_Type && current_user_can( $type->cap->read_private_posts );
		$judged   = $source === $post->ID ? null : $locale;
		$verdicts = array();

		if ( $edit && null !== $product && null !== $this->sellability ) {
			$verdicts = $this->sellability->of( array_map( static fn( Variant $variant ): int => (int) $variant->id(), $product->variants() ), $private, $judged );

			$values[ VariantFields::OPTIONS ]  = self::optionsOf( $product->options() );
			$values[ VariantFields::VARIANTS ] = $this->variantsOf( $product, $verdicts );
		}

		$values[ ProductCommerceSchema::LOCALE ]         = $locale?->toString();
		$values[ ProductCommerceSchema::TRANSLATION_OF ] = null === $source || $source === $post->ID ? null : $source;
		$values[ ProductCommerceSchema::SELLABILITY ]    = null === $this->sellability ? null : ( array() !== $verdicts ? Sellability::verdictOnProduct( $verdicts ) : $this->sellability->ofProduct( $product, $private, $judged ) )->value;

		if ( null !== $product ) {
			$values[ ProductCommerceSchema::GENERATION_STATE ] = $product->generation()->value;
		}

		return $values;
	}

	/**
	 * Returns a product's option axes as the wire carries them.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionMatrix $options The axes.
	 * @return list<array<string, mixed>> Each axis with its values.
	 */
	private static function optionsOf( OptionMatrix $options ): array {
		return array_map(
			static fn( OptionAxis $axis ): array => array(
				VariantFields::KEY      => $axis->key,
				VariantFields::LABEL    => $axis->label,
				VariantFields::POSITION => $axis->position,
				VariantFields::VALUES   => array_map(
					static fn( OptionValue $value ): array => array(
						VariantFields::KEY      => $value->key,
						VariantFields::LABEL    => $value->label,
						VariantFields::POSITION => $value->position,
						VariantFields::IMAGE_ID => $value->imageAttachmentId,
						VariantFields::SWATCH   => $value->swatchHex,
					),
					$axis->values
				),
			),
			$options->axes
		);
	}

	/**
	 * Returns a product's variants as the wire carries them, each with a stock cell per active location and the outcome of a cell the request set.
	 *
	 * The levels of every variant are one read; the locations, the cached list. A variant whose SKU
	 * another variant took has none, and its SKU is null: its placeholder is the store's, never a client's.
	 *
	 * @since 0.2.0
	 *
	 * @param Product                       $product  The product.
	 * @param array<int, SellabilityReason> $verdicts The verdict on each variant, by id: one for every variant.
	 * @return list<array<string, mixed>> The variants.
	 */
	private function variantsOf( Product $product, array $verdicts ): array {
		$ids       = array_map( static fn( Variant $variant ): int => (int) $variant->id(), $product->variants() );
		$levels    = null === $this->stock || array() === $ids ? array() : ( $this->stock )()->levelsAt( $ids );
		$directory = null === $this->locations ? null : ( $this->locations )();
		$variants  = array();

		foreach ( $product->variants() as $variant ) {
			$id     = (int) $variant->id();
			$onHand = array();

			foreach ( $levels[ $id ] ?? array() as $level ) {
				$onHand[ $level->locationId ] = $level->onHand;
			}

			$cells = array();

			foreach ( null === $directory ? array() : $directory->all() as $location ) {
				$outcome = $this->outcomes[ $id . '@' . $location->id ] ?? null;

				if ( ! $location->active && null === $outcome ) {
					continue;
				}

				$cell = array(
					VariantFields::LOCATION_UUID    => $location->uuid,
					VariantFields::ON_HAND          => $onHand[ $location->id ] ?? 0,
					VariantFields::EXPECTED_ON_HAND => null,
				);

				if ( null !== $outcome ) {
					$cell[ VariantFields::OUTCOME ] = $outcome->outcome;
				}

				$cells[] = $cell;
			}

			$variants[] = array(
				VariantFields::VARIANT_ID    => $id,
				VariantFields::COMBINATION   => self::combinationOf( $variant->selection() ),
				CommerceFields::SKU          => $variant->hasSku() ? $variant->sku()->toString() : null,
				VariantFields::ENABLED       => $variant->isEnabled(),
				VariantFields::POSITION      => $variant->position(),
				VariantFields::LABEL         => $variant->label(),
				CommerceFields::WEIGHT_GRAMS => $variant->weightGrams(),
				VariantFields::PRICES        => array_map(
					static fn( VariantPrice $price ): array => array(
						CommerceFields::CURRENCY         => $price->currency()->code(),
						CommerceFields::PRICE_MINOR      => $price->priceMinor(),
						CommerceFields::COMPARE_AT_MINOR => $price->compareAtMinor(),
						VariantFields::AMOUNT_BASIS      => $price->amountBasis(),
					),
					$variant->prices()
				),
				VariantFields::STOCK         => $cells,
				VariantFields::SELLABILITY   => $verdicts[ $id ]->value,
			);
		}

		return $variants;
	}

	/**
	 * Returns a selection as the wire carries a combination: one pair per axis.
	 *
	 * @since 0.2.0
	 *
	 * @param VariantSelection $selection The selection.
	 * @return list<array{axis: string, value: string}> The pairs.
	 */
	private static function combinationOf( VariantSelection $selection ): array {
		$pairs = array();

		foreach ( $selection->pairs() as $axis => $value ) {
			$pairs[] = array(
				VariantFields::AXIS  => (string) $axis,
				VariantFields::VALUE => $value,
			);
		}

		return $pairs;
	}

	/**
	 * Returns the selection a written variant names by its combination.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::CombinationInvalid when a key is not one, or an axis is named twice.
	 *
	 * @param array<mixed> $variant The variant, as WordPress validated it.
	 * @return VariantSelection The selection.
	 */
	private static function selectionOf( array $variant ): VariantSelection {
		$pairs = array();

		foreach ( (array) ( $variant[ VariantFields::COMBINATION ] ?? array() ) as $pair ) {
			$pair = (array) $pair;

			$pairs[] = array( (string) ( $pair[ VariantFields::AXIS ] ?? '' ), (string) ( $pair[ VariantFields::VALUE ] ?? '' ) );
		}

		return VariantSelection::ofList( $pairs );
	}

	/**
	 * Returns a product's default variant's id and its commerce fields as the wire carries them, or none for a post without a product or a variant.
	 *
	 * @since 0.1.0
	 *
	 * @param Product|null $product The product.
	 * @return array<string, int|string|null> The variant's id, then the fields, keyed as CommerceFields names them; a price's currency only with a price.
	 */
	private static function commerceValues( ?Product $product ): array {
		$variant = $product?->defaultVariant();

		if ( null === $variant || ! $product->options()->isEmpty() ) {
			return array();
		}

		$price  = $variant->basePrice();
		$values = array(
			ProductCommerceSchema::VARIANT_ID => $variant->id(),
			CommerceFields::SKU               => $variant->sku()->toString(),
			CommerceFields::PRICE_MINOR       => $price?->priceMinor(),
		);

		if ( null !== $price ) {
			$values[ CommerceFields::CURRENCY ] = $price->currency()->code();
		}

		$values[ CommerceFields::COMPARE_AT_MINOR ] = $price?->compareAtMinor();
		$values[ CommerceFields::WEIGHT_GRAMS ]     = $variant->weightGrams();

		return $values;
	}

	/**
	 * Returns the commerce input of a request's `seocart` object, or the refusal of a value that does not fit its declaration.
	 *
	 * WordPress has validated the object against the same declarations, so a refusal here means
	 * the value was changed after that, by another plugin's filter; it is refused as WordPress
	 * refuses an invalid parameter, with 400, never taken for the server's own failure.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return CommerceInput|WP_Error|null The input, the refusal, or null when the request has no `seocart` object.
	 */
	private static function commerceInput( WP_REST_Request $request ): CommerceInput|WP_Error|null {
		if ( ! $request->has_param( ProductCommerceSchema::PROPERTY ) ) {
			return null;
		}

		try {
			return CommerceInput::fromArray( array_diff_key( self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] ), array_flip( ProductCommerceSchema::translation() ) ) );
		} catch ( \InvalidArgumentException $invalid ) {
			return self::invalid( $invalid );
		}
	}

	/**
	 * Returns the locale a request's `seocart` object gives the post, or the refusal of one that is no locale.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return Locale|WP_Error|null The locale, the refusal, or null when the request gives none.
	 */
	private static function localeOf( WP_REST_Request $request ): Locale|WP_Error|null {
		$locale = self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] )[ ProductCommerceSchema::LOCALE ] ?? null;

		if ( null === $locale ) {
			return null;
		}

		try {
			return Locale::of( (string) $locale );
		} catch ( \InvalidArgumentException $invalid ) {
			return self::invalid( $invalid );
		}
	}

	/**
	 * Returns the post a request's `seocart` object says the post translates, or null when it names none.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return int|null The post, or null.
	 */
	private static function translationOf( WP_REST_Request $request ): ?int {
		$translationOf = self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] )[ ProductCommerceSchema::TRANSLATION_OF ] ?? null;

		return null === $translationOf ? null : (int) $translationOf;
	}

	/**
	 * Refuses a value of the `seocart` object as WordPress refuses an invalid parameter.
	 *
	 * @since 0.1.0
	 *
	 * @param \InvalidArgumentException $invalid Why the value does not fit its declaration.
	 * @return WP_Error The refusal, with 400.
	 */
	private static function invalid( \InvalidArgumentException $invalid ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			/* translators: %s: The name of the parameter. */
			sprintf( __( 'Invalid parameter(s): %s', 'seocart' ), ProductCommerceSchema::PROPERTY ),
			array(
				'status' => 400,
				'params' => array( ProductCommerceSchema::PROPERTY => $invalid->getMessage() ),
			)
		);
	}

	/**
	 * Keeps the fields of a written `seocart` object a client may write; the read-only ones are ignored, as core ignores a read-only property.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values The object WordPress validated against ProductCommerceSchema.
	 * @return array<string, mixed> The writable fields given.
	 */
	private static function writableValues( mixed $values ): array {
		return array_intersect_key( is_array( $values ) ? $values : array(), array_flip( ProductCommerceSchema::writable() ) );
	}

	/**
	 * Reports a failure no client caused, and answers with the generic internal error.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure The failure.
	 * @return WP_Error The error, which carries no detail of the failure.
	 */
	private function unexpected( \Throwable $failure ): WP_Error {
		if ( null !== $this->report ) {
			( $this->report )( $failure, $this->namespace . '/' . $this->rest_base );
		}

		return null === $this->errors ? new WP_Error( 'rest_internal_error', __( 'The product could not be saved.', 'seocart' ), array( 'status' => 500 ) ) : $this->errors->unexpected();
	}
}
