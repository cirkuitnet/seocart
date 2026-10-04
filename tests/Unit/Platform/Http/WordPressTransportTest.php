<?php
/**
 * Tests how the WordPress transport shapes a request for wp_safe_remote_request()
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Http;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Http\WordPressTransport;

/**
 * The arguments WordPress is given, the five callbacks that keep its settings around the call, and the answer it builds.
 *
 * WordPress's HTTP functions are doubles here. What the re-asserting callbacks do against real
 * filters, and the errors WordPress returns, are integration tests.
 *
 * @since 0.2.0
 */
final class WordPressTransportTest extends TestCase {

	/**
	 * What wp_safe_remote_request() was called with.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{url: string, args: array<string, mixed>, filters_added: bool}>
	 */
	private array $calls = array();

	/**
	 * Stands WordPress's HTTP functions in: the request is recorded and answered 200.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->calls = array();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): mixed => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( array $response ): mixed => $response['headers'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => $response['body'] );
		Functions\when( 'wp_safe_remote_request' )->alias(
			function ( string $url, array $args ): array {
				$this->calls[] = array(
					'url'           => $url,
					'args'          => $args,
					'filters_added' => has_filter( 'http_request_args' ) && has_filter( 'pre_http_request' ) && has_action( 'requests-requests.before_request' ) && has_action( 'http_api_curl' ) && has_action( 'requests-curl.before_send' ),
				);

				return array(
					'headers'  => array(
						'Content-Type' => 'application/json',
						'Set-Cookie'   => array( 'a=1', 'b=2' ),
					),
					'body'     => '{"ok":true}',
					'response' => array(
						'code'    => 202,
						'message' => 'Accepted',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests the arguments: the request's method, headers and body, its timeout, no redirect, the size limit, certificate checks on, and the call's own token.
	 *
	 * @since 0.2.0
	 */
	public function test_the_request_is_shaped_with_the_clients_limits(): void {
		$request = new OutboundRequest( 'example-rates', 'PATCH', 'https://api.example.com/v1/rates/7?expand=all', array( 'Authorization' => 'Bearer sk_test_x' ), '{"base":"EUR"}' );

		( new WordPressTransport() )->send( $request, 12 );
		( new WordPressTransport() )->send( $request, 12 );

		$this->assertCount( 2, $this->calls );
		$this->assertSame( 'https://api.example.com/v1/rates/7?expand=all', $this->calls[0]['url'] );

		$tokens = array_column( array_column( $this->calls, 'args' ), 'seocart_call_token' );

		$this->assertCount( 2, $tokens, 'Each call marks its arguments with a token.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/D', $tokens[0] );
		$this->assertNotSame( $tokens[0], $tokens[1], 'Each call has a token of its own.' );

		unset( $this->calls[0]['args']['seocart_call_token'] );

		$this->assertSame(
			array(
				'method'              => 'PATCH',
				'headers'             => array( 'Authorization' => 'Bearer sk_test_x' ),
				'body'                => '{"base":"EUR"}',
				'timeout'             => 12,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => OutboundClient::MAX_RESPONSE_BYTES + 1,
				'reject_unsafe_urls'  => true,
				'blocking'            => true,
				'stream'              => false,
				'cookies'             => array(),
			),
			$this->calls[0]['args']
		);
	}

	/**
	 * Tests that the five callbacks are added last for the call and removed after it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_re_asserting_callbacks_live_only_for_the_call(): void {
		Filters\expectAdded( 'http_request_args' )->once()->with( \Mockery::type( \Closure::class ), PHP_INT_MAX, 1 );
		Filters\expectAdded( 'pre_http_request' )->once()->with( \Mockery::type( \Closure::class ), PHP_INT_MAX, 3 );
		Actions\expectAdded( 'requests-requests.before_request' )->once()->with( \Mockery::type( \Closure::class ), PHP_INT_MAX, 5 );
		Actions\expectAdded( 'http_api_curl' )->once()->with( \Mockery::type( \Closure::class ), PHP_INT_MAX, 2 );
		Actions\expectAdded( 'requests-curl.before_send' )->once()->with( \Mockery::type( \Closure::class ), PHP_INT_MAX, 1 );

		( new WordPressTransport() )->send( new OutboundRequest( 'example-rates', 'GET', 'https://api.example.com/v1/rates' ), 15 );

		$this->assertTrue( $this->calls[0]['filters_added'], 'All five callbacks are in place while WordPress sends the request.' );
		$this->assertFalse( has_filter( 'http_request_args' ), 'The arguments callback is removed afterwards.' );
		$this->assertFalse( has_filter( 'pre_http_request' ), 'The refusing callback is removed afterwards.' );
		$this->assertFalse( has_action( 'requests-requests.before_request' ), 'The options callback is removed afterwards.' );
		$this->assertFalse( has_action( 'http_api_curl' ), 'The handle callback is removed afterwards.' );
		$this->assertFalse( has_action( 'requests-curl.before_send' ), 'The last handle callback is removed afterwards.' );
	}

	/**
	 * Tests that the answer keeps its status and body, with lower-case header names and repeated values joined.
	 *
	 * @since 0.2.0
	 */
	public function test_the_answer_is_built_from_what_wordpress_returned(): void {
		$response = ( new WordPressTransport() )->send( new OutboundRequest( 'example-rates', 'GET', 'https://api.example.com/v1/rates' ), 15 );

		$this->assertSame( 202, $response->status );
		$this->assertSame(
			array(
				'content-type' => 'application/json',
				'set-cookie'   => 'a=1, b=2',
			),
			$response->headers
		);
		$this->assertSame( '{"ok":true}', $response->body );
	}
}
