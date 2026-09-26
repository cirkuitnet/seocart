<?php
/**
 * ServesRequests: serves a request the way WordPress serves one from the web, and restores the request globals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use Spy_REST_Server;

/**
 * For a test that serves requests through the test library's spy server, which records the
 * headers it sends and the body it prints.
 *
 * WordPress builds a served request from the globals: the method and the headers from $_SERVER,
 * the cookies from $_COOKIE, the query and the form body from $_GET and $_POST. serve() sets them
 * for one request; saveRequestGlobals() in set_up() and restoreRequestGlobals() in tear_down()
 * put them back, WordPress's record that a login cookie was valid included.
 *
 * @since 0.1.0
 */
trait ServesRequests {

	/**
	 * The request globals as the test found them.
	 *
	 * @since 0.1.0
	 *
	 * @var array{server: array<string, mixed>, cookie: array<string, mixed>, get: array<string, mixed>, post: array<string, mixed>, request: array<string, mixed>}
	 */
	private array $savedRequestGlobals;

	/**
	 * Saves the request globals. Call it in set_up().
	 *
	 * @since 0.1.0
	 */
	protected function saveRequestGlobals(): void {
		$this->savedRequestGlobals = array(
			'server'  => $_SERVER,
			'cookie'  => $_COOKIE,
			'get'     => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to be restored.
			'post'    => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- saved to be restored.
			'request' => $_REQUEST, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to be restored.
		);
	}

	/**
	 * Restores the request globals and WordPress's record of cookie authentication. Call it in tear_down().
	 *
	 * @since 0.1.0
	 */
	protected function restoreRequestGlobals(): void {
		$_SERVER  = $this->savedRequestGlobals['server'];
		$_COOKIE  = $this->savedRequestGlobals['cookie'];
		$_GET     = $this->savedRequestGlobals['get'];
		$_POST    = $this->savedRequestGlobals['post'];
		$_REQUEST = $this->savedRequestGlobals['request'];

		unset( $GLOBALS['wp_rest_auth_cookie'] );
	}

	/**
	 * Serves one request through a spy server.
	 *
	 * @since 0.1.0
	 *
	 * @param Spy_REST_Server       $server  The server.
	 * @param string                $method  The HTTP method.
	 * @param string                $path    The route, with its namespace.
	 * @param array<string, string> $headers Optional. Request headers, by name. Default none.
	 * @param array<string, mixed>  $body    Optional. The form body. Default none.
	 * @param array<string, mixed>  $query   Optional. The query. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	protected function serve( Spy_REST_Server $server, string $method, string $path, array $headers = array(), array $body = array(), array $query = array() ): array {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = $query;
		$_POST                     = $body;
		$_REQUEST                  = array_merge( $query, $body );

		foreach ( $headers as $name => $value ) {
			$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] = $value;
		}

		$server->sent_headers = array();

		$server->serve_request( $path );

		foreach ( array_keys( $headers ) as $name ) {
			unset( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] );
		}

		$decoded = json_decode( $server->sent_body, true );

		\PHPUnit\Framework\Assert::assertIsArray( $decoded, 'The server printed no JSON: ' . $server->sent_body );

		return array(
			'status'  => $server->status,
			'headers' => $server->sent_headers,
			'body'    => $decoded,
		);
	}

	/**
	 * Asserts that an error body has a code and exactly the three data members, with a status and a correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $body   The decoded body.
	 * @param string               $code   The code it must carry.
	 * @param int                  $status The status its data must carry.
	 */
	protected function assertErrorShape( array $body, string $code, int $status ): void {
		\PHPUnit\Framework\Assert::assertSame( $code, $body['code'] ?? null, 'The error code: ' . (string) wp_json_encode( $body ) );
		\PHPUnit\Framework\Assert::assertIsArray( $body['data'] ?? null );
		\PHPUnit\Framework\Assert::assertSame( array( 'status', 'details', 'correlation_id' ), array_keys( $body['data'] ), 'The data has exactly the three members: none missing, none extra.' );
		\PHPUnit\Framework\Assert::assertSame( $status, $body['data']['status'] );
		\PHPUnit\Framework\Assert::assertIsString( $body['data']['correlation_id'] );
	}
}
