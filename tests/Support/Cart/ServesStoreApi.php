<?php
/**
 * ServesStoreApi: serves Store API requests through the production wiring, for a cart test
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Cart;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\ServesRequests;
use Spy_REST_Server;

/**
 * Wires the real Modules::register() and subscribe(), with the production operations, and serves
 * requests the way WordPress serves one from the web, through a spy server.
 *
 * The replacements: the transport's cookie sender, which records into $cookies instead of calling
 * setcookie(); and the unit of work, the plain Database, since a cart test creates the tables it
 * needs itself rather than installing the schema the kernel's write gate waits for.
 *
 * @since 0.1.0
 */
trait ServesStoreApi {

	use ServesRequests;

	/**
	 * The server.
	 *
	 * @since 0.1.0
	 *
	 * @var Spy_REST_Server
	 */
	protected Spy_REST_Server $server;

	/**
	 * Every cookie the transport sent: its name, value and options.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{name: string, value: string, options: array<string, mixed>}>
	 */
	protected array $cookies = array();

	/**
	 * Wires the kernel and boots the spy server. Call it in set_up().
	 *
	 * @since 0.1.0
	 */
	protected function bootStoreApi(): void {
		$this->saveRequestGlobals();

		$_SERVER['REMOTE_ADDR'] = '192.0.2.30';

		$container = new Container(
			array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ),
				CartTokenTransport::class => fn(): CartTokenTransport => new CartTokenTransport(
					FrozenClock::at( '2026-09-25 10:00:00' ),
					function ( string $name, string $value, array $options ): void {
						$this->cookies[] = array(
							'name'    => $name,
							'value'   => $value,
							'options' => $options,
						);
					}
				),
			)
		);

		Modules::register( $container );

		add_filter( 'wp_rest_server_class', static fn(): string => Spy_REST_Server::class );
		KernelHooks::detach( 'rest_api_init', 'wp_abilities_api_categories_init', 'wp_abilities_api_init' );
		OperationSurfaces::discard();
		Modules::subscribe( $container );

		$server = rest_get_server();

		\PHPUnit\Framework\Assert::assertInstanceOf( Spy_REST_Server::class, $server );

		$this->server = $server;
	}

	/**
	 * Restores the request globals and discards the server. Call it in tear_down().
	 *
	 * @since 0.1.0
	 */
	protected function shutStoreApi(): void {
		$this->restoreRequestGlobals();

		OperationSurfaces::discard();
	}

	/**
	 * Serves one Store API request, with the Store API's header on a write.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route, relative to the Store API's namespace.
	 * @param array<string, mixed> $body   Optional. The form body. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	protected function store( string $method, string $route, array $body = array() ): array {
		$headers = 'GET' === $method ? array() : array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE );

		return $this->serve( $this->server, $method, '/' . RestBinding::STORE_NAMESPACE . $route, $headers, $body );
	}

	/**
	 * Builds the body of an add-lines write.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $quantities Units by variant id.
	 * @param int|null        $version    Optional. The cart version. Default none.
	 * @return array<string, mixed> The body.
	 */
	protected static function linesBody( array $quantities, ?int $version = null ): array {
		$lines = array();

		foreach ( $quantities as $variant => $quantity ) {
			$lines[] = array(
				'variant_id' => $variant,
				'quantity'   => $quantity,
			);
		}

		return array( 'lines' => $lines ) + ( null === $version ? array() : array( 'cart_version' => $version ) );
	}

	/**
	 * Presents a cart token as a browser does: in the cookie.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token The token.
	 */
	protected function presentCookie( string $token ): void {
		$_COOKIE[ CartTokenTransport::COOKIE ] = $token;
	}
}
