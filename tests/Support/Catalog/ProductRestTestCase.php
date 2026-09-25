<?php
/**
 * ProductRestTestCase: the base of the tests of the product's `wp/v2` endpoint
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Rest\RestErrorTranslator;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\ReloadsRoles;
use SEOCart\Tests\Support\SecondConnection;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A ProductWriteTestCase with the product's REST endpoint served by a controller built over the test's connection, and an administrator.
 *
 * Owns one fact: how a test sends a request to `wp/v2/seocart-products` and reads what it
 * committed. The controller is built from the production classes with the test's service and
 * reporter, and installed into the post type's controller slot on `rest_api_init`, after the
 * kernel's and before core registers the routes, on a REST server built afresh for each test.
 * The product lifecycle is hooked in the kernel's place, sharing the controller's post gateway,
 * so every request meets it as on a site. The plugin's roles are installed, and put back
 * afterwards.
 *
 * @since 0.1.0
 */
abstract class ProductRestTestCase extends ProductWriteTestCase {

	use CreatesUsers;
	use ReloadsRoles;

	/**
	 * The correlation id every error of the test's controller carries.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const CORRELATION_ID = '00000000-0000-4000-8000-00000000c0de';

	/**
	 * The route of the products.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const ROUTE = '/wp/v2/seocart-products';

	/**
	 * The controller the endpoint is served by.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductPostsController
	 */
	protected ProductPostsController $controller;

	/**
	 * The administrator the requests are sent as.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected int $admin;

	/**
	 * The unexpected failures the controller reported.
	 *
	 * @since 0.1.0
	 *
	 * @var list<\Throwable>
	 */
	protected array $failures = array();

	/**
	 * The roles option before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var mixed
	 */
	private $roles;

	/**
	 * Installs the roles, builds the controller and a REST server that serves it, and acts as an administrator.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->roles    = get_option( self::rolesOption() );
		$this->failures = array();

		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		$this->controller = new ProductPostsController(
			ProductCapabilities::POST_TYPE,
			fn(): SaveProduct => $this->service,
			$this->products,
			new Sellability( $this->products ),
			$this->services->posts,
			new RestErrorTranslator(
				ErrorTable::compose( ...Modules::ERROR_CATALOGS ),
				static fn(): string => self::CORRELATION_ID,
				function ( \Throwable $failure ): void {
					$this->failures[] = $failure;
				}
			),
			function ( \Throwable $failure ): void {
				$this->failures[] = $failure;
			}
		);

		add_action(
			'rest_api_init',
			function (): void {
				ProductPostsController::install( ProductCapabilities::POST_TYPE, fn(): ProductPostsController => $this->controller );
			},
			50
		);

		$this->services->attach();

		self::discardRestServer();

		$this->admin = $this->createUser( 'administrator' );

		wp_set_current_user( $this->admin );
	}

	/**
	 * Discards the REST server, deletes the users and puts the roles back.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		self::discardRestServer();

		wp_set_current_user( 0 );

		$this->deleteCreatedUsers();

		update_option( self::rolesOption(), $this->roles );

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Sends a request to the product endpoint through the REST server, as WordPress serves one.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route below `/wp/v2/seocart-products`, such as `/12`, or an empty string.
	 * @param array<string, mixed> $body   Optional. The body parameters. Default none.
	 * @param array<string, mixed> $query  Optional. The query parameters. Default none.
	 * @return WP_REST_Response The response, the server's own when the request is refused.
	 */
	protected function request( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE . $route );

		$request->set_body_params( $body );
		$request->set_query_params( $query );

		$response = rest_do_request( $request );

		$this->trackCreatedPost( $response );

		return $response;
	}

	/**
	 * Sends a request with a JSON body, as the block editor and most clients send one, so numbers reach WordPress as JSON decodes them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method The HTTP method.
	 * @param string $route  The route below `/wp/v2/seocart-products`, such as `/12`, or an empty string.
	 * @param string $json   The body, as JSON text.
	 * @return WP_REST_Response The response.
	 */
	protected function requestJson( string $method, string $route, string $json ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE . $route );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $json );

		$response = rest_do_request( $request );

		$this->trackCreatedPost( $response );

		return $response;
	}

	/**
	 * Counts every committed ProductSaved outbox row.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b The second connection.
	 * @return int The count: one per save.
	 *
	 * @phpstan-impure
	 */
	protected function committedSavesOfAll( SecondConnection $b ): int {
		return $this->committedCount( $b, OutboxTable::NAME, "event_name = 'product_saved'" );
	}

	/**
	 * Deletes a post a create request made, after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Response $response The response.
	 */
	private function trackCreatedPost( WP_REST_Response $response ): void {
		$data = $response->get_data();

		if ( 201 === $response->get_status() && is_array( $data ) && isset( $data['id'] ) ) {
			$this->trackPost( (int) $data['id'] );
		}
	}

	/**
	 * Discards the current REST server, so the next request builds one and fires `rest_api_init` again.
	 *
	 * @since 0.1.0
	 */
	protected static function discardRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next request must build a new server.
		$wp_rest_server = null;
	}

	/**
	 * Returns the name of the option the site's roles are stored in.
	 *
	 * @since 0.1.0
	 *
	 * @return string The option's name.
	 */
	private static function rolesOption(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}
