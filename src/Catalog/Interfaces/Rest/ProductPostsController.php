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
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
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
 * validated against ProductCommerceSchema by WordPress, becomes the save's commerce input. Then
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
 * A response is core's, with the `seocart` object added among the REST fields, before core's
 * context filter and `rest_prepare_seocart_product`: the commerce data, the verdict on the
 * default variant for the requesting user, and in the `edit` context the product's marker.
 *
 * It overrides no permission method: reading and writing are decided by the product post type's
 * mapped capabilities, exactly as for core's controller. Deleting, listing and reading are
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
	 *
	 * @phpstan-param (\Closure(): SaveProduct)|null             $save
	 * @phpstan-param (callable(\Throwable, string): void)|null $report
	 */
	public function __construct(
		$post_type,
		?\Closure $save = null,
		?ProductRepository $products = null,
		?Sellability $sellability = null,
		?PostGateway $posts = null,
		?ErrorTranslator $errors = null,
		?callable $report = null
	) {
		parent::__construct( $post_type );

		$this->save        = $save;
		$this->products    = $products;
		$this->sellability = $sellability;
		$this->posts       = $posts;
		$this->errors      = $errors;
		$this->report      = $report;
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
			$response_data[ ProductCommerceSchema::PROPERTY ] = $this->commerce( $this->responding );
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
		$saved    = $this->save( $request, $prepared, $before );

		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$post = get_post( $saved );

		if ( ! $post instanceof WP_Post ) {
			return $this->unexpected( new \LogicException( sprintf( 'The saved post %d cannot be read back.', $saved ) ) );
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

		$response = rest_ensure_response( $this->prepare_item_for_response( $post, $request ) );

		if ( $creating ) {
			$response->set_status( 201 );
			$response->header( 'Location', rest_url( rest_get_route_for_post( $post ) ) );
		}

		return $response;
	}

	/**
	 * Saves the product: the post's fields core prepared and the `seocart` object, in one call.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param object          $prepared The post as core prepared it.
	 * @param WP_Post|null    $before   The post before an update, or null for a create.
	 * @return int|WP_Error The post's id, or the error.
	 */
	private function save( WP_REST_Request $request, object $prepared, ?WP_Post $before ): int|WP_Error {
		if ( null === $this->save || null === $this->posts || null === $this->errors ) {
			return $this->unexpected( new \LogicException( 'This instance of the product controller was built by WordPress without its services; the kernel installs the one that saves.' ) );
		}

		$commerce = self::commerceInput( $request );

		if ( $commerce instanceof WP_Error ) {
			return $commerce;
		}

		try {
			$result = ( $this->save )()->save( new ProductSave( $before?->ID, (array) $prepared, $commerce, Actor::user( get_current_user_id() ) ) );
		} catch ( CodedException $refused ) {
			return $this->errors->translate( $refused );
		} catch ( \Throwable $failure ) {
			return $this->unexpected( $failure );
		}

		return $result->postId;
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
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, mixed> The commerce fields of the default variant, the verdict, and the product's marker.
	 */
	private function commerce( WP_Post $post ): array {
		$product = null === $this->products ? null : $this->products->findByPost( $post->ID );
		$values  = self::commerceValues( $product );
		$type    = get_post_type_object( $this->post_type );

		$values[ ProductCommerceSchema::SELLABILITY ] = null === $this->sellability ? null : $this->sellability->ofProduct( $product, $type instanceof WP_Post_Type && current_user_can( $type->cap->read_private_posts ) )->value;

		if ( null !== $product ) {
			$values[ ProductCommerceSchema::GENERATION_STATE ] = $product->generation()->value;
		}

		return $values;
	}

	/**
	 * Returns a product's commerce fields as the wire carries them, or none for a post without a product or a variant.
	 *
	 * @since 0.1.0
	 *
	 * @param Product|null $product The product.
	 * @return array<string, int|string|null> The fields, keyed as CommerceFields names them; a price's currency only with a price.
	 */
	private static function commerceValues( ?Product $product ): array {
		$variant = $product?->defaultVariant();

		if ( null === $variant ) {
			return array();
		}

		$price  = $variant->basePrice();
		$values = array(
			CommerceFields::SKU         => $variant->sku()->toString(),
			CommerceFields::PRICE_MINOR => $price?->priceMinor(),
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
			return CommerceInput::fromArray( self::writableValues( $request[ ProductCommerceSchema::PROPERTY ] ) );
		} catch ( \InvalidArgumentException $invalid ) {
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
