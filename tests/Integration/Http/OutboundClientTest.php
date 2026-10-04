<?php
/**
 * Tests the outbound client through WordPress's HTTP API, the transaction guard and the logger
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
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Http\ReportCode;
use SEOCart\Platform\Http\WordPressTransport;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Tests\Support\Logging\LogsTestCase;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Transport;

/**
 * Every request passes `pre_http_request`, keeps its limits against other plugins' filters,
 * never runs inside a transaction on a development site, and writes one line to the real log.
 *
 * Nothing here reaches the network: the tests answer at `pre_http_request`, as a recording
 * double does, or hand the Requests library a transport that records what it was given. Each
 * test that guards a rule names its planted violation, in src/Platform/Http/WordPressTransport.php.
 *
 * @since 0.2.0
 */
final class OutboundClientTest extends LogsTestCase {

	/**
	 * A credential, which must never reach a log line or a message.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SECRET = 'sk_test_never_logged';

	/**
	 * What the `pre_http_request` answer saw: each request's URL and arguments.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $asked = array();

	/**
	 * Tests that a request goes through `pre_http_request` once, and writes one debug line without its query, headers or body.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_goes_through_wordpress_and_logs_one_line(): void {
		$this->answerWith( self::answer( 200, '{"id":"rate_1","echo":"' . self::SECRET . '"}', array( 'Content-Type' => 'application/json' ) ) );

		$response = $this->client()->send( $this->request( 'POST', 'https://api.example.test/v1/rates?key=' . self::SECRET, '{"key":"' . self::SECRET . '"}' ) );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'rate_1', $response->json()['id'] );
		$this->assertSame( 'application/json', $response->headers['content-type'] );
		$this->assertCount( 1, $this->asked, 'One request, through pre_http_request.' );
		$this->assertSame( 'https://api.example.test/v1/rates?key=' . self::SECRET, $this->asked[0]['url'] );
		$this->assertSame( 'Bearer ' . self::SECRET, $this->asked[0]['args']['headers']['Authorization'], 'The credential is sent.' );

		$lines = $this->linesOf( ReportCode::Answered->value );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'debug', $lines[0]['level'] );
		$this->assertSame( 'http', $lines[0]['channel'] );

		$context = self::context( $lines[0] );

		$this->assertSame( 'https://api.example.test/v1/rates', $context['url'] );
		$this->assertSame( 'POST', $context['method'] );
		$this->assertSame( 200, $context['status'] );
		$this->assertSame( implode( ':', str_split( hash( 'sha256', $response->body ), 8 ) ), $context['body_sha256'] );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $this->lines() ), 'No query, header or body reached the log.' );
	}

	/**
	 * Tests that an email address in a URL's path reaches the log line redacted.
	 *
	 * The path is logged, so a caller keeps personal data out of it. One that gets there anyway
	 * meets the logger's redactor, which removes an email address from every string it writes.
	 *
	 * Planted violation, shown red and removed: in Redactor::freeText(), skip the email
	 * addresses.
	 *
	 * @since 0.2.0
	 */
	public function test_an_email_address_in_the_path_is_redacted_in_the_log_line(): void {
		$this->answerWith( self::answer( 200, '{}' ) );

		$this->client()->send( $this->request( 'GET', 'https://api.example.test/v1/customers/alice@example.com/charges' ) );

		$lines = $this->linesOf( ReportCode::Answered->value );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'https://api.example.test/v1/customers/' . Redactor::REDACTED . '/charges', self::context( $lines[0] )['url'] );
		$this->assertStringNotContainsString( 'alice@example.com', (string) wp_json_encode( $this->lines() ) );
	}

	/**
	 * Tests that a filter loosening a request's arguments is undone for this request, and only for it.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `http_request_args` callback; `pre_http_request` then sees certificate checks off.
	 *
	 * @since 0.2.0
	 */
	public function test_a_filter_cannot_loosen_the_arguments_of_a_request(): void {
		$loosen = static fn( array $args ): array => array_merge(
			$args,
			array(
				'sslverify'           => false,
				'redirection'         => 5,
				'limit_response_size' => null,
				'timeout'             => 300,
				'reject_unsafe_urls'  => false,
				'method'              => 'DELETE',
				'headers'             => array( 'X-Added' => 'by another plugin' ),
				'body'                => 'changed',
				'cookies'             => array( 'session' => 'theirs' ),
			)
		);

		add_filter( 'http_request_args', $loosen );
		add_filter( 'http_request_args', $loosen, PHP_INT_MAX );
		$this->answerWith( self::answer( 200, '{}' ) );

		$this->client()->send( $this->request( 'POST', 'https://api.example.test/v1/rates', '{"base":"EUR"}' ) );

		$args = $this->asked[0]['args'];

		$this->assertTrue( $args['sslverify'], 'Certificate checks stay on.' );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( OutboundClient::MAX_RESPONSE_BYTES + 1, $args['limit_response_size'] );
		$this->assertSame( 15, $args['timeout'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( array( 'Authorization' => 'Bearer ' . self::SECRET ), $args['headers'] );
		$this->assertSame( '{"base":"EUR"}', $args['body'] );
		$this->assertSame( array(), $args['cookies'] );

		wp_remote_get( 'https://other.example.test/' );

		$this->assertFalse( $this->asked[1]['args']['sslverify'], 'Another request still gets the site\'s filters: the callback lived only for the call.' );
	}

	/**
	 * Tests that a request another plugin sends to the same URL during the call keeps its own method, headers and body, and is answered.
	 *
	 * The other plugin sends it from its own `http_request_args` callback, which runs before the
	 * transport's for the outer request, so the nested request is filtered while the transport's
	 * callbacks are in place. It carries no token, like a request whose token a filter removed,
	 * and must still not be refused.
	 *
	 * Planted violations, each shown red and removed, in WordPressTransport::send():
	 * - recognise the request by its URL instead of the call's token; the nested request then
	 *   leaves with the outer one's method, credential and body;
	 * - refuse every request to the URL that lacks the token; the nested request then ends in an
	 *   error instead of its answer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_nested_request_to_the_same_url_keeps_its_own_arguments(): void {
		$this->answerWith( self::answer( 200, '{}' ) );

		$sent   = false;
		$theirs = null;

		add_filter(
			'http_request_args',
			static function ( array $args, string $url ) use ( &$sent, &$theirs ): array {
				if ( ! $sent ) {
					$sent = true;

					$theirs = wp_remote_request(
						$url,
						array(
							'method'  => 'PUT',
							'headers' => array( 'X-Other' => 'theirs' ),
							'body'    => 'their body',
						)
					);
				}

				return $args;
			},
			10,
			2
		);

		$this->client()->send( $this->request( 'POST', 'https://api.example.test/v1/rates', '{"base":"EUR"}' ) );

		$this->assertCount( 2, $this->asked, 'The nested request, then the outer one.' );
		$this->assertSame( 'https://api.example.test/v1/rates', $this->asked[0]['url'] );
		$this->assertIsArray( $theirs, 'The nested request is answered, not refused.' );

		$nested = $this->asked[0]['args'];

		$this->assertSame( 'PUT', $nested['method'] );
		$this->assertSame( array( 'X-Other' => 'theirs' ), $nested['headers'], 'The credential stays with its own request.' );
		$this->assertSame( 'their body', $nested['body'] );

		$ours = $this->asked[1]['args'];

		$this->assertSame( 'POST', $ours['method'] );
		$this->assertSame( array( 'Authorization' => 'Bearer ' . self::SECRET ), $ours['headers'] );
		$this->assertSame( '{"base":"EUR"}', $ours['body'] );
	}

	/**
	 * Tests that the Requests library sends with certificate checks on, the connect timeout and no redirect, whatever the filters say, and returns a redirect as an answer.
	 *
	 * The request is not answered at `pre_http_request`: it goes on to the Requests library,
	 * whose transport is replaced by one that records its options and answers a redirect. The
	 * host is a documentation address, so WordPress looks nothing up; WordPress refuses such an
	 * address as unsafe, so this test allows that one host, and nothing is ever sent to it.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), do not add the
	 * `requests-requests.before_request` callback; the transport then sees verification off.
	 *
	 * @since 0.2.0
	 */
	public function test_requests_sends_with_verification_on_whatever_the_filters_say(): void {
		$transport = new class() implements Transport {

			/**
			 * What each request was sent with.
			 *
			 * @since 0.2.0
			 *
			 * @var list<array{url: string, options: array<string, mixed>}>
			 */
			public array $requests = array();

			/**
			 * Records the request and answers a permanent redirect.
			 *
			 * @since 0.2.0
			 *
			 * @param string               $url     The URL.
			 * @param array<string, mixed> $headers The headers.
			 * @param mixed                $data    The body.
			 * @param array<string, mixed> $options The options.
			 * @return string The raw answer.
			 */
			public function request( $url, $headers = array(), $data = array(), $options = array() ) {
				$this->requests[] = array(
					'url'     => $url,
					'options' => $options,
				);

				return "HTTP/1.1 301 Moved Permanently\r\nLocation: https://192.0.2.11/elsewhere\r\nContent-Length: 0\r\n\r\n";
			}

			/**
			 * Sends nothing: these tests send one request at a time.
			 *
			 * @since 0.2.0
			 *
			 * @param array<mixed> $requests The requests.
			 * @param array<mixed> $options  The options.
			 * @return array<mixed> No answers.
			 */
			public function request_multiple( $requests, $options ) {
				return array();
			}

			/**
			 * Says this transport can do anything asked of it.
			 *
			 * @since 0.2.0
			 *
			 * @param array<string, bool> $capabilities The capabilities.
			 * @return bool Always true.
			 */
			public static function test( $capabilities = array() ) {
				return true;
			}
		};

		add_filter(
			'http_request_host_is_external',
			static fn( $external, $host ): bool => '192.0.2.10' === $host || (bool) $external,
			10,
			2
		);
		add_filter( 'https_ssl_verify', '__return_false' );
		add_filter(
			'http_request_args',
			static fn( array $args ): array => array( 'sslverify' => false ) + $args
		);
		add_action(
			'requests-requests.before_request',
			static function ( $url, $headers, $data, $type, &$options ) use ( $transport ): void {
				$options['verify']           = false;
				$options['verifyname']       = false;
				$options['connect_timeout']  = 60;
				$options['follow_redirects'] = true;
				$options['max_bytes']        = false;
				$options['transport']        = $transport;
			},
			10,
			5
		);

		// What the first request was sent with is checked first: a followed redirect fails later.
		try {
			$response = $this->client( 'https://192.0.2.10/v1/rates' )->send( $this->request( 'GET', 'https://192.0.2.10/v1/rates' ) );
		} catch ( TransportFailure $failure ) {
			$response = $failure;
		}

		$options = $transport->requests[0]['options'];

		$this->assertIsString( $options['verify'], 'Verification is on, with a certificate bundle.' );
		$this->assertSame( Requests::get_certificate_path(), $options['verify'] );
		$this->assertFileExists( $options['verify'] );
		$this->assertTrue( $options['verifyname'] );
		$this->assertSame( OutboundClient::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout'] );
		$this->assertSame( 15, $options['timeout'] );
		$this->assertFalse( $options['follow_redirects'] );
		$this->assertSame( OutboundClient::MAX_RESPONSE_BYTES + 1, $options['max_bytes'] );
		$this->assertCount( 1, $transport->requests, 'One request: the redirect was not followed.' );
		$this->assertInstanceOf( OutboundResponse::class, $response, 'The redirect is the answer.' );
		$this->assertSame( 301, $response->status );
		$this->assertSame( 'https://192.0.2.11/elsewhere', $response->headers['location'] );
	}

	/**
	 * Tests that a redirect answered at `pre_http_request` comes back as the answer, after one request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_redirect_is_returned_as_an_answer(): void {
		$this->answerWith( self::answer( 301, '', array( 'Location' => 'https://elsewhere.example.test/' ) ) );

		$response = $this->client()->send( $this->request() );

		$this->assertSame( 301, $response->status );
		$this->assertSame( 'https://elsewhere.example.test/', $response->headers['location'] );
		$this->assertCount( 1, $this->asked );
	}

	/**
	 * Tests that an answer over one megabyte is a size failure, logged without a hash.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_over_one_megabyte_is_a_size_failure(): void {
		$this->answerWith( self::answer( 200, str_repeat( 'a', OutboundClient::MAX_RESPONSE_BYTES + 1 ) ) );

		try {
			$this->client()->send( $this->request() );
			$this->fail( 'A larger answer must be refused.' );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::SIZE, $failure->kind );
		}

		$lines = $this->linesOf( ReportCode::Failed->value );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'info', $lines[0]['level'] );
		$this->assertSame( TransportFailure::SIZE, self::context( $lines[0] )['failure'] );
		$this->assertArrayNotHasKey( 'body_sha256', self::context( $lines[0] ) );
		$this->assertSame( array(), $this->linesOf( ReportCode::Answered->value ) );
	}

	/**
	 * Provides the errors WordPress returns, with the kind of failure each one is.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> A WP_Error message and the kind, keyed by what happened.
	 */
	public static function wordpressErrors(): array {
		return array(
			'cURL timeout'               => array( 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received', TransportFailure::TIMEOUT ),
			'cURL unknown issuer'        => array( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', TransportFailure::TLS ),
			'cURL handshake'             => array( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to api.example.test:443', TransportFailure::TLS ),
			'cURL refused'               => array( 'cURL error 7: Failed to connect to api.example.test port 443 after 3 ms: Couldn\'t connect to server', TransportFailure::CONNECT ),
			'cURL unresolved'            => array( 'cURL error 6: Could not resolve host: api.example.test', TransportFailure::CONNECT ),
			'cURL reset'                 => array( 'cURL error 56: Recv failure: Connection reset by peer', TransportFailure::CONNECT ),
			'socket timeout'             => array( 'fsocket timed out', TransportFailure::TIMEOUT ),
			'socket certificate'         => array( 'stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed', TransportFailure::TLS ),
			'socket refused'             => array( 'stream_socket_client(): Unable to connect to ssl://api.example.test:443 (Connection refused)', TransportFailure::CONNECT ),
			'unsafe or unresolvable URL' => array( 'A valid URL was not provided.', TransportFailure::CONNECT ),
		);
	}

	/**
	 * Tests that an error WordPress returns becomes a transport failure of the right kind, whose message and line carry no query.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider wordpressErrors
	 *
	 * @param string $message The WP_Error message.
	 * @param string $kind    The kind of failure it is.
	 */
	public function test_a_wordpress_error_is_a_transport_failure( string $message, string $kind ): void {
		$this->answerWith( new \WP_Error( 'http_request_failed', $message ) );

		try {
			$this->client()->send( $this->request( 'GET', 'https://api.example.test/v1/rates?key=' . self::SECRET ) );
			$this->fail( 'The error must be a transport failure.' );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( $kind, $failure->kind );
			$this->assertSame( 'api.example.test', $failure->host );
			$this->assertStringNotContainsString( self::SECRET, $failure->getMessage() );
		}

		$lines = $this->linesOf( ReportCode::Failed->value );

		$this->assertCount( 1, $lines );
		$this->assertSame(
			array(
				'url'     => 'https://api.example.test/v1/rates',
				'method'  => 'GET',
				'failure' => $kind,
			),
			array_diff_key( self::context( $lines[0] ), array( 'duration_ms' => true ) )
		);
	}

	/**
	 * Tests that a request inside a transaction throws on a development site before anything answers, and is reported and sent otherwise.
	 *
	 * The answer comes from `pre_http_request`, exactly as a recording double's would, so this is
	 * also the proof that such a double keeps the guard. `$this->db` has strict guards, which the
	 * kernel gives the database on a site whose environment type is `local` or `development`.
	 *
	 * Planted violation, shown red and removed: in WordPressTransport::send(), send the request
	 * with WpOrg\Requests\Requests::request() instead of wp_safe_remote_request(); the guard never
	 * sees it and nothing throws ForbiddenInsideTransaction.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_inside_a_transaction_is_refused_in_development_and_reported_elsewhere(): void {
		$this->answerWith( self::answer( 200, '{}' ) );

		$client  = $this->client();
		$refused = null;

		try {
			$this->db->transaction( fn() => $client->send( $this->request() ) );
		} catch ( ForbiddenInsideTransaction $forbidden ) {
			$refused = $forbidden;
		}

		$this->assertNotNull( $refused, 'A development site refuses the request.' );
		$this->assertSame( ForbiddenInsideTransaction::KIND_HTTP, $refused->kind() );
		$this->assertSame( 'api.example.test', $refused->detail() );
		$this->assertSame( array(), $this->asked, 'The guard runs before anything answers.' );
		$this->assertSame( array(), $this->linesOf( ReportCode::Answered->value ) );

		$response = $this->makeDatabase( false )->transaction( fn() => $client->send( $this->request() ) );

		$this->assertSame( 200, $response->status, 'Elsewhere the request is sent.' );
		$this->assertCount( 1, $this->asked );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( ForbiddenInsideTransaction::CODE->value, $this->reports[0]['code'] );
		$this->assertSame( ForbiddenInsideTransaction::KIND_HTTP, $this->reports[0]['context']['kind'] );
	}

	/**
	 * Returns a client of the one declared test host, over WordPress, logging to the real log table.
	 *
	 * @since 0.2.0
	 *
	 * @param string $endpoint Optional. The host's endpoint. Default the rates endpoint on api.example.test.
	 * @return OutboundClient The client.
	 */
	private function client( string $endpoint = 'https://api.example.test/v1/rates' ): OutboundClient {
		$host = new OutboundHost(
			id: 'example-rates',
			service: 'Example Rates',
			purpose: 'Example Rates is a currency exchange-rate service used by these tests.',
			endpoint: $endpoint,
			dataSent: 'The store currency code.',
			sentWhen: 'When a test sends a request.',
			termsUrl: 'https://example.test/terms',
			privacyUrl: 'https://example.test/privacy'
		);

		return new OutboundClient( array( $host ), new WordPressTransport(), array( $this->logger(), 'log' ) );
	}

	/**
	 * Returns a request to the declared host, with a credential in its Authorization header.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $method Optional. The method. Default 'GET'.
	 * @param string      $url    Optional. The URL. Default the rates endpoint.
	 * @param string|null $body   Optional. The body. Default none.
	 * @return OutboundRequest The request.
	 */
	private function request( string $method = 'GET', string $url = 'https://api.example.test/v1/rates', ?string $body = null ): OutboundRequest {
		return new OutboundRequest( 'example-rates', $method, $url, array( 'Authorization' => 'Bearer ' . self::SECRET ), $body );
	}

	/**
	 * Answers every request at `pre_http_request` with the given response, recording what was asked.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>|\WP_Error $response What WordPress returns instead of sending.
	 */
	private function answerWith( array|\WP_Error $response ): void {
		$this->asked = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->asked[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Builds an answer in the shape `pre_http_request` returns.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $status  The status code.
	 * @param string                $body    The body.
	 * @param array<string, string> $headers Optional. The headers. Default none.
	 * @return array<string, mixed> The answer.
	 */
	private static function answer( int $status, string $body, array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
