<?php
/**
 * ClaimsOrders: sends a claim of an order to the Store API, as a browser does
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Order;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;

/**
 * A claim of an order, served by the test's spy server: `POST seocart/store/v1/account/orders` with the Store API's header, the order named in the body and its key in the key header.
 *
 * For a test that serves requests (ServesRequests) through a spy server it keeps as `$server`.
 *
 * @since 0.2.0
 */
trait ClaimsOrders {

	/**
	 * Serves one claim, as a browser sends a Store API write: with the store's header.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $uuid    The order the body names.
	 * @param array<string, string> $headers The request headers: the nonce of a logged-in browser, the key header.
	 * @param array<string, string> $body    Optional. More of the body. Default none.
	 * @param array<string, string> $query   Optional. The query. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	protected function claim( string $uuid, array $headers, array $body = array(), array $query = array() ): array {
		return $this->serve( $this->server, 'POST', '/' . RestBinding::STORE_NAMESPACE . OrderStoreOperations::LIST_ROUTE, array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE ) + $headers, array( 'uuid' => $uuid ) + $body, $query );
	}

	/**
	 * Returns the header that carries an order's key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key.
	 * @return array<string, string> The header.
	 */
	protected static function keyHeader( string $key ): array {
		return array( OrderStoreOperations::KEY_HEADER => $key );
	}
}
