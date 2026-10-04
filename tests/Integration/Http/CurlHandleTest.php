<?php
/**
 * Tests that the cURL handle a request is sent with keeps the client's settings against other plugins' callbacks
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Http;

use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Http\WordPressTransport;
use WP_UnitTestCase;

/**
 * Real requests over cURL to a TLS server on this machine, while another plugin's callbacks change the handle.
 *
 * The server is `openssl s_server` on 127.0.0.1, with a certificate made for this run that
 * nothing trusts unless a test says so. It answers each path with a file of this run. The
 * declared host is 127.0.0.1 on the https port; each test sends the connection on to the
 * server's port with CURLOPT_CONNECT_TO, which the client leaves alone, so nothing leaves the
 * machine. WordPress refuses a loopback address as unsafe, so these tests allow that one.
 *
 * Each test that guards a setting names its planted violation, in
 * src/Platform/Http/WordPressTransport.php.
 *
 * @since 0.2.0
 */
final class CurlHandleTest extends WP_UnitTestCase {

	/**
	 * The declared host, and the address the server listens on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const HOST = '127.0.0.1';

	/**
	 * What each path the server knows answers: a whole HTTP response, as `s_server -HTTP` sends it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const ANSWERS = array(
		'v1/rates'     => "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nrates",
		'v1/elsewhere' => "HTTP/1.1 200 OK\r\nContent-Length: 9\r\nConnection: close\r\n\r\nelsewhere",
		'v1/moved'     => "HTTP/1.1 301 Moved Permanently\r\nLocation: https://127.0.0.1/v1/rates\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
	);

	/**
	 * The run's directory: the certificate, its key, the answers and the server's output.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private static string $directory = '';

	/**
	 * The running server.
	 *
	 * @since 0.2.0
	 *
	 * @var resource|null
	 */
	private static $server = null;

	/**
	 * The port the server listens on.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private static int $port = 0;

	/**
	 * Why the server could not start, or empty when it runs.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private static string $unavailable = '';

	/**
	 * Starts the server once for the class.
	 *
	 * @since 0.2.0
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::$unavailable = self::startServer();
	}

	/**
	 * Stops the server and removes the run's files.
	 *
	 * @since 0.2.0
	 */
	public static function tear_down_after_class(): void {
		self::stopServer();

		parent::tear_down_after_class();
	}

	/**
	 * Skips the test without a server, and lets requests to the loopback address through.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( '' !== self::$unavailable ) {
			$this->markTestSkipped( self::$unavailable );
		}

		add_filter(
			'http_request_host_is_external',
			static fn( $external, $host ): bool => self::HOST === $host || (bool) $external,
			10,
			2
		);
	}

	/**
	 * Tests that the widely copied snippet cannot turn certificate checks off: a certificate nobody trusts is refused.
	 *
	 * The snippet, on `http_api_curl` at the default priority, turns peer and host-name checks
	 * off and redirects on. Left alone, it lets the request through to the server, which answers
	 * 200.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `http_api_curl` callback.
	 *
	 * @since 0.2.0
	 */
	public function test_a_curl_callback_cannot_turn_certificate_checks_off(): void {
		self::routeTo( self::$port );
		self::plantSnippet( 'http_api_curl' );

		try {
			$response = self::send( 'v1/rates' );
			$this->fail( "A certificate nobody trusts must be refused; the server answered {$response->status}." );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TLS, $failure->kind );
		}
	}

	/**
	 * Tests that the same snippet on `requests-curl.before_send`, which WordPress runs after `http_api_curl`, cannot turn certificate checks off either.
	 *
	 * That action is the last hook before the request is sent.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `requests-curl.before_send` callback.
	 *
	 * @since 0.2.0
	 */
	public function test_a_callback_on_the_last_hook_cannot_turn_certificate_checks_off(): void {
		self::routeTo( self::$port );
		self::plantSnippet( 'requests-curl.before_send' );

		try {
			$response = self::send( 'v1/rates' );
			$this->fail( "A certificate nobody trusts must be refused; the server answered {$response->status}." );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TLS, $failure->kind );
		}
	}

	/**
	 * Tests that a call whose token a filter removed is refused before it is sent, so a cURL callback cannot turn certificate checks off for it.
	 *
	 * The filter rebuilds the arguments without the token, so none of the transport's callbacks
	 * can recognise the request; the snippet on `http_api_curl` would then go unopposed.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `pre_http_request` callback that refuses such a call.
	 *
	 * @since 0.2.0
	 */
	public function test_a_call_whose_token_was_removed_is_refused(): void {
		self::routeTo( self::$port );
		add_filter(
			'http_request_args',
			static fn( array $args ): array => array_diff_key( $args, array( 'seocart_call_token' => true ) )
		);
		self::plantSnippet( 'http_api_curl' );

		try {
			$response = self::send( 'v1/rates' );
			$this->fail( "An untrusted certificate was accepted; the server answered {$response->status}." );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TLS, $failure->kind );
		}
	}

	/**
	 * Tests that a call moved to another host before the Requests library sends it, with certificate checks off, is refused.
	 *
	 * The callback on `requests-requests.before_request` runs before the transport's. It changes
	 * the URL's host, so the transport's options callback does not recognise the call, and it sets
	 * the library's `verify` option false, which the library applies to the handle after the last
	 * hook WordPress offers.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not refuse a call
	 * the options callback did not see.
	 *
	 * @since 0.2.0
	 */
	public function test_a_call_moved_to_another_host_before_it_is_sent_is_refused(): void {
		self::routeTo( self::$port );
		add_action(
			'requests-requests.before_request',
			static function ( &$url, $headers, $data, $type, &$options ): void {
				$url                   = 'https://elsewhere.example.test/v1/rates';
				$options['verify']     = false;
				$options['verifyname'] = false;
			},
			10,
			5
		);

		try {
			$response = self::send( 'v1/rates' );
			$this->fail( "An untrusted certificate was accepted; the server answered {$response->status}." );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TLS, $failure->kind );
		}
	}

	/**
	 * Tests that a same-host request another plugin sends during the call cannot vouch for the call when the call is then moved to another host with certificate checks off.
	 *
	 * The other plugin sends its request to the same host from an argument filter, so the
	 * transport's options callback recognises that request and has its own hooks vouch for its
	 * handle. The call itself is then moved, as in the test above, and nothing vouches for it.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), vouch for every
	 * request's handle, recognised or not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_same_host_request_sent_meanwhile_cannot_vouch_for_a_moved_call(): void {
		self::routeTo( self::$port );
		$sent = false;
		add_filter(
			'http_request_args',
			static function ( array $args, string $url ) use ( &$sent ): array {
				if ( ! $sent && str_ends_with( $url, '/v1/rates' ) ) {
					$sent = true;

					wp_remote_get( 'https://' . self::HOST . '/v1/elsewhere' );
				}

				return $args;
			},
			10,
			2
		);
		add_action(
			'requests-requests.before_request',
			static function ( &$url, $headers, $data, $type, &$options ): void {
				if ( str_ends_with( $url, '/v1/rates' ) ) {
					$url                   = 'https://elsewhere.example.test/v1/rates';
					$options['verify']     = false;
					$options['verifyname'] = false;
				}
			},
			10,
			5
		);

		try {
			$response = self::send( 'v1/rates' );
			$this->fail( "An untrusted certificate was accepted; the server answered {$response->status}." );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TLS, $failure->kind );
		}
	}

	/**
	 * Tests that a call begun inside another request's argument filter, whose token a filter removed, is refused.
	 *
	 * Begun there, the call cannot tell a request of its own from one another plugin starts while
	 * arguments are being filtered, so the exemption for the latter does not apply, and the
	 * unmarked call is refused like any other whose token was removed.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), exempt every request
	 * started inside an argument filter, also when the call itself began inside one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_call_begun_inside_an_argument_filter_whose_token_was_removed_is_refused(): void {
		$trigger = 'https://192.0.2.1/trigger';
		$outcome = null;

		self::routeTo( self::$port );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $trigger ) {
				if ( $trigger !== $url ) {
					return $pre;
				}

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		add_filter(
			'http_request_args',
			static function ( array $args, string $url ) use ( $trigger, &$outcome ): array {
				if ( null === $outcome && $trigger === $url ) {
					try {
						$response = self::send( 'v1/rates' );
						$outcome  = "An untrusted certificate was accepted; the server answered {$response->status}.";
					} catch ( TransportFailure $failure ) {
						$outcome = $failure->kind;
					}
				}

				return $args;
			},
			10,
			2
		);
		add_filter(
			'http_request_args',
			static fn( array $args ): array => array_diff_key( $args, array( 'seocart_call_token' => true ) ),
			20
		);
		self::plantSnippet( 'http_api_curl' );

		wp_remote_get( $trigger );

		$this->assertSame( TransportFailure::TLS, $outcome );
	}

	/**
	 * Tests that a same-host request sent while the call's cURL handle is being prepared cannot vouch for the call when it was moved to another host.
	 *
	 * The other request is sent from `requests-curl.before_request`, after the transport's options
	 * callback skipped the moved call, so it is the last request that callback recognises before
	 * the call's handle reaches `http_api_curl`.
	 *
	 * Planted violations, each shown red and removed, in WordPressTransport::send(): record in one
	 * flag whether the options callback's last invocation recognised its request; vouch for every
	 * request's handle, recognised or not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_same_host_request_sent_while_the_handle_is_prepared_cannot_vouch_for_a_moved_call(): void {
		self::routeTo( self::$port );
		self::moveCallToAnotherHost();
		self::sendOnceFrom( 'requests-curl.before_request', 'https://' . self::HOST . '/v1/elsewhere' );

		$this->assertRefusedAsUntrusted();
	}

	/**
	 * Tests that a same-host request sent from an earlier `http_api_curl` callback cannot vouch for the call when it was moved to another host.
	 *
	 * Planted violations, each shown red and removed, in WordPressTransport::send(): record in one
	 * flag whether the options callback's last invocation recognised its request; vouch for every
	 * request's handle, recognised or not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_same_host_request_sent_from_an_earlier_curl_callback_cannot_vouch_for_a_moved_call(): void {
		self::routeTo( self::$port );
		self::moveCallToAnotherHost();
		self::sendOnceFrom( 'http_api_curl', 'https://' . self::HOST . '/v1/elsewhere' );

		$this->assertRefusedAsUntrusted();
	}

	/**
	 * Tests that a request to another host, sent while the call's cURL handle is being prepared, does not get an honest call refused.
	 *
	 * The other request goes to a closed port on this machine and fails at once; the call itself
	 * is not moved, and the server is trusted, so it is answered.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), record in one flag
	 * whether the options callback's last invocation recognised its request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_to_another_host_sent_meanwhile_does_not_refuse_an_honest_call(): void {
		self::routeTo( self::$port );
		self::trustServer();
		self::sendOnceFrom( 'requests-curl.before_request', 'https://localhost:1/v1/elsewhere' );

		$response = self::send( 'v1/rates' );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'rates', $response->body );
	}

	/**
	 * Tests that a refused call leaves WordPress's list of running hooks as it found it.
	 *
	 * The call is made from inside an action and refused from inside `http_api_curl`. Had that
	 * action stayed on the list, the enclosing action would take it off in its own place, and
	 * would itself seem to run for the rest of the request.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not put the list
	 * of running hooks back after the call.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_call_leaves_the_list_of_running_hooks_as_it_was(): void {
		self::routeTo( self::$port );
		self::moveCallToAnotherHost();
		$kind = null;
		add_action(
			'seocart_test_enclosing_action',
			static function () use ( &$kind ): void {
				try {
					self::send( 'v1/rates' );
				} catch ( TransportFailure $failure ) {
					$kind = $failure->kind;
				}
			}
		);
		$before = $GLOBALS['wp_current_filter'];

		do_action( 'seocart_test_enclosing_action' );

		$this->assertSame( TransportFailure::TLS, $kind, 'The call was refused.' );
		$this->assertSame( $before, $GLOBALS['wp_current_filter'], 'The list of running hooks is as it was.' );
		$this->assertFalse( doing_action( 'seocart_test_enclosing_action' ) );
	}

	/**
	 * Tests that a call refused while another call's arguments are being filtered leaves that other call's token guard working.
	 *
	 * The inner call is refused from inside `http_api_curl`. Had that action stayed on the list
	 * of running hooks, the outer call's argument filtering would end by taking the action off
	 * instead of itself, and the outer call, whose token a filter removed, would then seem to be
	 * another plugin's request started during argument filtering, and be sent.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not put the list
	 * of running hooks back after the call.
	 *
	 * @since 0.2.0
	 */
	public function test_a_call_refused_inside_another_calls_argument_filters_leaves_its_token_guard_working(): void {
		self::routeTo( self::$port );
		self::moveCallToAnotherHost( '/v1/elsewhere' );
		$innerKind = null;
		add_filter(
			'http_request_args',
			static function ( array $args, string $url ) use ( &$innerKind ): array {
				if ( null === $innerKind && str_ends_with( $url, '/v1/rates' ) ) {
					try {
						self::send( 'v1/elsewhere' );
						$innerKind = 'answered';
					} catch ( TransportFailure $failure ) {
						$innerKind = $failure->kind;
					}
				}

				return $args;
			},
			10,
			2
		);
		add_filter(
			'http_request_args',
			static fn( array $args, string $url ): array => str_ends_with( $url, '/v1/rates' ) ? array_diff_key( $args, array( 'seocart_call_token' => true ) ) : $args,
			20,
			2
		);
		self::plantSnippet( 'http_api_curl' );

		$this->assertRefusedAsUntrusted();
		$this->assertSame( TransportFailure::TLS, $innerKind, 'The inner call was refused first.' );
	}

	/**
	 * Tests that a callback turning cURL's redirects on does not make the client follow one.
	 *
	 * The server is trusted here, so the request reaches it; it answers a permanent redirect to
	 * another of its paths, which cURL would follow.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `http_api_curl` callback.
	 *
	 * @since 0.2.0
	 */
	public function test_a_curl_callback_cannot_make_the_client_follow_a_redirect(): void {
		self::routeTo( self::$port );
		self::trustServer();
		self::plantSnippet( 'http_api_curl' );

		$response = self::send( 'v1/moved' );

		$this->assertSame( 301, $response->status, 'The redirect is the answer.' );
		$this->assertSame( 'https://127.0.0.1/v1/rates', $response->headers['location'] );
	}

	/**
	 * Tests that a callback setting another URL on the handle does not change where the request goes.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `http_api_curl` callback.
	 *
	 * @since 0.2.0
	 */
	public function test_a_curl_callback_cannot_change_where_the_request_goes(): void {
		self::routeTo( self::$port );
		self::trustServer();
		add_action(
			'http_api_curl',
			static function ( \CurlHandle $handle ): void {
				curl_setopt( $handle, CURLOPT_URL, 'https://127.0.0.1/v1/elsewhere' );
			}
		);

		$response = self::send( 'v1/rates' );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'rates', $response->body, 'The request went to its own URL.' );
	}

	/**
	 * Tests that a callback lengthening cURL's timeouts does not make the client wait longer than the host's timeout.
	 *
	 * The connection goes to a socket that accepts it and never answers, so the request ends at
	 * whichever timeout the handle has: the host's one second, or the callback's four.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `http_api_curl` callback.
	 *
	 * @since 0.2.0
	 */
	public function test_a_curl_callback_cannot_lengthen_the_timeout(): void {
		$silent = stream_socket_server( 'tcp://' . self::HOST . ':0' );
		$this->assertIsResource( $silent );

		self::routeTo( (int) substr( (string) strrchr( (string) stream_socket_get_name( $silent, false ), ':' ), 1 ) );
		add_action(
			'http_api_curl',
			static function ( \CurlHandle $handle ): void {
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 4 );
				curl_setopt( $handle, CURLOPT_TIMEOUT, 4 );
			}
		);

		$started = hrtime( true );

		try {
			self::send( 'v1/rates', 1 );
			$this->fail( 'A server that never answers must time out.' );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TIMEOUT, $failure->kind );
			$this->assertLessThan( 3.0, ( hrtime( true ) - $started ) / 1e9, 'The host\'s one-second timeout held, not the callback\'s four.' );
		} finally {
			fclose( $silent );
		}
	}

	/**
	 * Moves a request for a path to another host on `requests-requests.before_request`, with the Requests library's certificate checks off.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Optional. The path of the requests to move. Default '/v1/rates'.
	 */
	private static function moveCallToAnotherHost( string $path = '/v1/rates' ): void {
		add_action(
			'requests-requests.before_request',
			static function ( &$url, $headers, $data, $type, &$options ) use ( $path ): void {
				if ( str_ends_with( $url, $path ) ) {
					$url                   = 'https://elsewhere.example.test' . $path;
					$options['verify']     = false;
					$options['verifyname'] = false;
				}
			},
			10,
			5
		);
	}

	/**
	 * Has another plugin send one request of its own from an action, the first time the action runs.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook The action.
	 * @param string $url  The other plugin's request's URL.
	 */
	private static function sendOnceFrom( string $hook, string $url ): void {
		$sent = false;

		add_action(
			$hook,
			static function () use ( &$sent, $url ): void {
				if ( ! $sent ) {
					$sent = true;

					wp_remote_get( $url, array( 'timeout' => 2 ) );
				}
			}
		);
	}

	/**
	 * Asserts that a request to the server, whose certificate nothing trusts, is refused as a TLS failure.
	 *
	 * @since 0.2.0
	 */
	private function assertRefusedAsUntrusted(): void {
		try {
			$response = self::send( 'v1/rates' );
			$this->fail( "An untrusted certificate was accepted; the server answered {$response->status}." );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::TLS, $failure->kind );
		}
	}

	/**
	 * Adds the widely copied snippet to a hook: certificate and host-name checks off, redirects on.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook The hook that hands over the cURL handle.
	 */
	private static function plantSnippet( string $hook ): void {
		add_action(
			$hook,
			static function ( \CurlHandle $handle ): void {
				curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
				curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, true );
			}
		);
	}

	/**
	 * Sends the declared host's connections to a port on this machine.
	 *
	 * @since 0.2.0
	 *
	 * @param int $port The port.
	 */
	private static function routeTo( int $port ): void {
		add_action(
			'http_api_curl',
			static function ( \CurlHandle $handle ) use ( $port ): void {
				curl_setopt( $handle, CURLOPT_CONNECT_TO, array( self::HOST . ':443:' . self::HOST . ':' . $port ) );
			}
		);
	}

	/**
	 * Makes WordPress trust the server's certificate, through the request argument WordPress has for a certificate bundle.
	 *
	 * @since 0.2.0
	 */
	private static function trustServer(): void {
		add_filter(
			'http_request_args',
			static fn( array $args ): array => array( 'sslcertificates' => self::$directory . '/cert.pem' ) + $args
		);
	}

	/**
	 * Sends a GET request to a path of the server through a client that declares it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path           The path, without its leading slash.
	 * @param int    $timeoutSeconds Optional. The host's timeout. Default 5.
	 * @return OutboundResponse The answer.
	 */
	private static function send( string $path, int $timeoutSeconds = 5 ): OutboundResponse {
		$host = new OutboundHost(
			id: 'loopback',
			service: 'Loopback',
			purpose: 'A TLS server on this machine, started by these tests.',
			endpoint: 'https://' . self::HOST . '/v1/*',
			dataSent: 'Nothing.',
			sentWhen: 'When a test sends a request.',
			termsUrl: 'https://example.test/terms',
			privacyUrl: 'https://example.test/privacy',
			timeoutSeconds: $timeoutSeconds
		);

		$client = new OutboundClient( array( $host ), new WordPressTransport(), static function (): void {} );

		return $client->send( new OutboundRequest( 'loopback', 'GET', 'https://' . self::HOST . '/' . $path ) );
	}

	/**
	 * Makes the certificate and the answers, and starts the server.
	 *
	 * @since 0.2.0
	 *
	 * @return string Why the server could not start, or an empty string when it runs.
	 */
	private static function startServer(): string {
		if ( ! function_exists( 'curl_init' ) ) {
			return 'Without the cURL extension WordPress sends with sockets, and there is no cURL handle.';
		}

		if ( ! self::onPath( 'openssl' ) ) {
			return 'The openssl command, which serves these tests, is not installed.';
		}

		self::$directory = sys_get_temp_dir() . '/seocart-curl-' . bin2hex( random_bytes( 6 ) );
		mkdir( self::$directory . '/v1', 0700, true );

		foreach ( self::ANSWERS as $path => $answer ) {
			file_put_contents( self::$directory . '/' . $path, $answer );
		}

		$made = proc_open(
			array( 'openssl', 'req', '-x509', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:prime256v1', '-nodes', '-keyout', 'key.pem', '-out', 'cert.pem', '-days', '1', '-subj', '/CN=' . self::HOST, '-addext', 'subjectAltName=IP:' . self::HOST ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', self::$directory . '/req.log', 'w' ),
				2 => array( 'file', self::$directory . '/req.log', 'a' ),
			),
			$pipes,
			self::$directory
		);

		if ( false === $made || 0 !== proc_close( $made ) || ! is_file( self::$directory . '/cert.pem' ) ) {
			return 'openssl could not make a certificate: ' . (string) file_get_contents( self::$directory . '/req.log' );
		}

		$server = proc_open(
			array( 'openssl', 's_server', '-accept', self::HOST . ':0', '-cert', 'cert.pem', '-key', 'key.pem', '-HTTP' ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', self::$directory . '/server.out', 'w' ),
				2 => array( 'file', self::$directory . '/server.err', 'w' ),
			),
			$pipes,
			self::$directory
		);

		if ( false === $server ) {
			return 'openssl s_server could not be started.';
		}

		self::$server = $server;
		self::$port   = self::portOnceListening();

		return 0 === self::$port ? 'openssl s_server did not start listening: ' . (string) file_get_contents( self::$directory . '/server.err' ) : '';
	}

	/**
	 * Waits up to ten seconds for the server to say which port it listens on.
	 *
	 * @since 0.2.0
	 *
	 * @return int The port, or 0 when the server never said.
	 */
	private static function portOnceListening(): int {
		$deadline = hrtime( true ) + 10 * 1000000000;

		do {
			if ( 1 === preg_match( '/^ACCEPT \S*:(\d+)$/m', (string) file_get_contents( self::$directory . '/server.out' ), $matches ) ) {
				return (int) $matches[1];
			}

			usleep( 20000 );
		} while ( hrtime( true ) < $deadline );

		return 0;
	}

	/**
	 * Stops the server and removes the run's directory.
	 *
	 * @since 0.2.0
	 */
	private static function stopServer(): void {
		if ( null !== self::$server ) {
			proc_terminate( self::$server );
			proc_close( self::$server );
			self::$server = null;
		}

		if ( '' === self::$directory || ! is_dir( self::$directory ) ) {
			return;
		}

		foreach ( array( self::$directory . '/v1/*', self::$directory . '/*' ) as $pattern ) {
			foreach ( (array) glob( $pattern ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					unlink( $file );
				}
			}
		}

		rmdir( self::$directory . '/v1' );
		rmdir( self::$directory );
	}

	/**
	 * Tells whether a command is an executable file in one of the PATH's directories.
	 *
	 * @since 0.2.0
	 *
	 * @param string $command The command.
	 * @return bool True when it can be run.
	 */
	private static function onPath( string $command ): bool {
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $directory ) {
			if ( '' !== $directory && is_executable( $directory . '/' . $command ) ) {
				return true;
			}
		}

		return false;
	}
}
