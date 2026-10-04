<?php
/**
 * Tests the outbound client's rules against a transport double
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Http\ReportCode;
use SEOCart\Platform\Http\Transport;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\Level;

/**
 * Which requests are sent, with what limits, what comes back, and the one line each one logs.
 *
 * The transport double records what it was asked and answers what the test gives it, so
 * nothing here touches WordPress but wp_parse_url(), which stands in as PHP's parse_url().
 *
 * @since 0.2.0
 */
final class OutboundClientTest extends TestCase {

	/**
	 * A credential, which must never reach a log line or a message.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SECRET = 'sk_test_never_logged';

	/**
	 * What the transport double was asked: each request with its timeout.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{request: OutboundRequest, timeout: int}>
	 */
	private array $sent = array();

	/**
	 * What the transport double does next: return this answer, or throw this failure.
	 *
	 * @since 0.2.0
	 *
	 * @var OutboundResponse|TransportFailure
	 */
	private OutboundResponse|TransportFailure $next;

	/**
	 * Every line the client logged.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{level: Level, code: string, message: string, context: array<mixed>}>
	 */
	private array $lines = array();

	/**
	 * Stands wp_parse_url() in with PHP's parse_url(), and answers 200 by default.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->sent  = array();
		$this->lines = array();
		$this->next  = new OutboundResponse( 200, array( 'content-type' => 'application/json' ), '{"id":"rate_1"}' );
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
	 * Tests that the request reaches the transport as written, once, with the host's timeout.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_reaches_the_transport_once_with_the_hosts_timeout(): void {
		$request  = $this->request( 'POST', 'https://api.example.com/v1/rates?expand=all', '{"base":"EUR"}' );
		$response = $this->client()->send( $request );

		$this->assertSame( $this->next, $response );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( $request, $this->sent[0]['request'], 'Method, URL, headers and body go to the transport unchanged.' );
		$this->assertSame( 15, $this->sent[0]['timeout'], 'The declared default.' );
	}

	/**
	 * Tests that a host's timeout is capped at thirty seconds.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hosts_timeout_is_capped(): void {
		$this->client( array( 'timeoutSeconds' => 25 ) )->send( $this->request() );
		$this->client( array( 'timeoutSeconds' => 90 ) )->send( $this->request() );

		$this->assertSame( array( 25, OutboundClient::MAX_TIMEOUT_SECONDS ), array_column( $this->sent, 'timeout' ) );
		$this->assertSame( 30, OutboundClient::MAX_TIMEOUT_SECONDS );
		$this->assertSame( 5, OutboundClient::CONNECT_TIMEOUT_SECONDS );
		$this->assertSame( 1048576, OutboundClient::MAX_RESPONSE_BYTES );
	}

	/**
	 * Provides answers that are returned as they are: a redirect and errors included.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{int}> Status codes, keyed by what they are.
	 */
	public static function statuses(): array {
		return array(
			'a redirect'   => array( 301 ),
			'a refusal'    => array( 402 ),
			'a conflict'   => array( 409 ),
			'a busy host'  => array( 429 ),
			'a host error' => array( 500 ),
		);
	}

	/**
	 * Tests that every status is an answer, returned after one request: nothing is followed or retried.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider statuses
	 *
	 * @param int $status The status.
	 */
	public function test_every_status_is_returned_after_one_request( int $status ): void {
		$this->next = new OutboundResponse( $status, array( 'location' => 'https://elsewhere.example.org/' ), '' );

		$this->assertSame( $status, $this->client()->send( $this->request() )->status );
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * Tests that a failure is thrown after one request, never retried.
	 *
	 * @since 0.2.0
	 */
	public function test_a_failure_is_thrown_after_one_request(): void {
		$this->next = new TransportFailure( TransportFailure::TIMEOUT, 'api.example.com' );

		try {
			$this->client()->send( $this->request() );
			$this->fail( 'The failure must reach the caller.' );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( $this->next, $failure );
		}

		$this->assertCount( 1, $this->sent );
	}

	/**
	 * Provides requests the client must refuse before anything is sent.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> A host id and a URL, keyed by the defect.
	 */
	public static function undeclaredRequests(): array {
		return array(
			'undeclared host id'       => array( 'other-service', 'https://api.example.com/v1/rates' ),
			'another host'             => array( 'example-rates', 'https://api.example.org/v1/rates' ),
			'a sub-domain of the host' => array( 'example-rates', 'https://evil.api.example.com/v1/rates' ),
			'the host as a user name'  => array( 'example-rates', 'https://api.example.com@evil.example.org/v1/rates' ),
			'credentials'              => array( 'example-rates', 'https://user:pass@api.example.com/v1/rates' ),
			'a port'                   => array( 'example-rates', 'https://api.example.com:8443/v1/rates' ),
			'plain http'               => array( 'example-rates', 'http://api.example.com/v1/rates' ),
			'another scheme'           => array( 'example-rates', 'ftp://api.example.com/v1/rates' ),
			'a scheme in capitals'     => array( 'example-rates', 'HTTPS://api.example.com/v1/rates' ),
			'the host in capitals'     => array( 'example-rates', 'https://API.EXAMPLE.COM/v1/rates' ),
			'no scheme'                => array( 'example-rates', '//api.example.com/v1/rates' ),
			'not a URL'                => array( 'example-rates', 'https:///v1/rates' ),
		);
	}

	/**
	 * Tests that a request to an undeclared or mismatched host, or not over https, is a programming error, refused before anything is sent.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider undeclaredRequests
	 *
	 * @param string $hostId The request's host id.
	 * @param string $url    The request's URL.
	 */
	public function test_a_request_off_its_declared_host_is_a_logic_error( string $hostId, string $url ): void {
		try {
			$this->client()->send( new OutboundRequest( $hostId, 'GET', $url ) );
			$this->fail( 'The request must be refused.' );
		} catch ( TransportFailure $failure ) {
			$this->fail( 'A request off its declared host is a programming error, not a transport failure.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringNotContainsString( '/v1/rates', $refused->getMessage(), 'The message names hosts, not the URL.' );
		}

		$this->assertSame( array(), $this->sent, 'Nothing was sent.' );
		$this->assertSame( array(), $this->lines, 'Nothing was logged.' );
	}

	/**
	 * Tests that two hosts with one id are refused.
	 *
	 * @since 0.2.0
	 */
	public function test_two_hosts_with_one_id_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new OutboundClient( array( self::host(), self::host() ), $this->transport(), $this->logger() );
	}

	/**
	 * Tests the line an answered request writes: the URL without its query, the method, the status, the duration and the body's hash; nothing else.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_logs_one_debug_line_without_query_headers_or_body(): void {
		$this->next = new OutboundResponse( 201, array( 'set-cookie' => 'session=' . self::SECRET ), '{"id":"rate_1","echo":"' . self::SECRET . '"}' );

		$this->client()->send( $this->request( 'POST', 'https://api.example.com/v1/rates?key=' . self::SECRET . '#frag', '{"key":"' . self::SECRET . '"}' ) );

		$this->assertCount( 1, $this->lines );

		$line = $this->lines[0];

		$this->assertSame( Level::Debug, $line['level'] );
		$this->assertSame( ReportCode::Answered->value, $line['code'] );
		$this->assertSame( array( 'url', 'method', 'status', 'duration_ms', 'body_sha256' ), array_keys( $line['context'] ) );
		$this->assertSame( 'https://api.example.com/v1/rates', $line['context']['url'] );
		$this->assertSame( 'POST', $line['context']['method'] );
		$this->assertSame( 201, $line['context']['status'] );
		$this->assertIsInt( $line['context']['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0, $line['context']['duration_ms'] );
		$this->assertSame( implode( ':', str_split( hash( 'sha256', $this->next->body ), 8 ) ), $line['context']['body_sha256'] );
		$this->assertStringNotContainsString( self::SECRET, (string) json_encode( $line ), 'No query, header or body reaches the line.' );
	}

	/**
	 * Tests the line a failed request writes: the URL without its query, the method, the duration and the kind; no status and no hash.
	 *
	 * @since 0.2.0
	 */
	public function test_a_failure_logs_one_info_line_without_a_hash(): void {
		$this->next = new TransportFailure( TransportFailure::TLS, 'api.example.com' );

		try {
			$this->client()->send( $this->request( 'GET', 'https://api.example.com/v1/rates?key=' . self::SECRET ) );
		} catch ( TransportFailure $expected ) {
			$this->assertStringNotContainsString( self::SECRET, $expected->getMessage() );
		}

		$this->assertCount( 1, $this->lines );
		$this->assertSame( Level::Info, $this->lines[0]['level'] );
		$this->assertSame( ReportCode::Failed->value, $this->lines[0]['code'] );
		$this->assertSame( array( 'url', 'method', 'duration_ms', 'failure' ), array_keys( $this->lines[0]['context'] ) );
		$this->assertSame( 'https://api.example.com/v1/rates', $this->lines[0]['context']['url'] );
		$this->assertSame( TransportFailure::TLS, $this->lines[0]['context']['failure'] );
		$this->assertStringNotContainsString( self::SECRET, (string) json_encode( $this->lines[0] ) );
	}

	/**
	 * Tests that an answer of exactly one megabyte is accepted, and one byte more is a size failure without the body or the query in it.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_over_one_megabyte_is_a_size_failure(): void {
		$this->next = new OutboundResponse( 200, array(), str_repeat( 'a', OutboundClient::MAX_RESPONSE_BYTES ) );

		$this->assertSame( OutboundClient::MAX_RESPONSE_BYTES, strlen( $this->client()->send( $this->request() )->body ) );

		$this->next  = new OutboundResponse( 200, array(), str_repeat( 'a', OutboundClient::MAX_RESPONSE_BYTES + 1 ) );
		$this->lines = array();

		try {
			$this->client()->send( $this->request( 'GET', 'https://api.example.com/v1/rates?key=' . self::SECRET ) );
			$this->fail( 'A larger answer must be refused.' );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::SIZE, $failure->kind );
			$this->assertSame( 'api.example.com', $failure->host );
			$this->assertStringNotContainsString( self::SECRET, $failure->getMessage() );
			$this->assertStringNotContainsString( 'aaaa', $failure->getMessage() );
		}

		$this->assertSame( array( ReportCode::Failed->value ), array_column( $this->lines, 'code' ) );
		$this->assertSame( TransportFailure::SIZE, $this->lastLine()['context']['failure'] );
	}

	/**
	 * Tests that a logged body hash never looks like a card number to the log's card filter.
	 *
	 * Planted violation, shown red and removed: in OutboundClient::hash(), return the hexadecimal
	 * digest as one string; the filter then finds a card-shaped run in some of these hashes.
	 *
	 * @since 0.2.0
	 */
	public function test_a_body_hash_is_never_card_shaped(): void {
		$caught = array();

		for ( $body = 0; $body < 3000; $body++ ) {
			$this->next  = new OutboundResponse( 200, array(), (string) $body );
			$this->lines = array();
			$this->client()->send( $this->request() );

			if ( CardNumbers::contains( $this->lastLine()['context']['body_sha256'] ) ) {
				$caught[] = $body;
			}
		}

		$this->assertSame( array(), $caught, 'The card filter would remove part of these bodies\' hashes.' );
	}

	/**
	 * Returns the declaration the tests call.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $overrides Optional. Fields to replace. Default none.
	 * @return OutboundHost The host.
	 */
	private static function host( array $overrides = array() ): OutboundHost {
		return new OutboundHost( ...OutboundHostTest::fields( $overrides ) );
	}

	/**
	 * Returns a request to the declared host, with a credential in a header.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $method Optional. The method. Default 'GET'.
	 * @param string      $url    Optional. The URL. Default the rates endpoint.
	 * @param string|null $body   Optional. The body. Default none.
	 * @return OutboundRequest The request.
	 */
	private function request( string $method = 'GET', string $url = 'https://api.example.com/v1/rates', ?string $body = null ): OutboundRequest {
		return new OutboundRequest( 'example-rates', $method, $url, array( 'Authorization' => 'Bearer ' . self::SECRET ), $body );
	}

	/**
	 * Returns a client of the declared host over the transport double.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $overrides Optional. Fields of the declaration to replace. Default none.
	 * @return OutboundClient The client.
	 */
	private function client( array $overrides = array() ): OutboundClient {
		return new OutboundClient( array( self::host( $overrides ) ), $this->transport(), $this->logger() );
	}

	/**
	 * Returns the transport double: it records each request and answers or throws $next.
	 *
	 * @since 0.2.0
	 *
	 * @return Transport The double.
	 */
	private function transport(): Transport {
		$test = $this;

		return new class( $test ) implements Transport {

			/**
			 * Records the test it serves.
			 *
			 * @since 0.2.0
			 *
			 * @param OutboundClientTest $test The test.
			 */
			public function __construct( private OutboundClientTest $test ) {
			}

			/**
			 * Records the request, then answers or throws what the test set.
			 *
			 * @since 0.2.0
			 *
			 * @throws TransportFailure When the test set one.
			 *
			 * @param OutboundRequest $request        The request.
			 * @param int             $timeoutSeconds The timeout.
			 * @return OutboundResponse The answer.
			 */
			public function send( OutboundRequest $request, int $timeoutSeconds ): OutboundResponse {
				return $this->test->answer( $request, $timeoutSeconds );
			}
		};
	}

	/**
	 * Records a request the transport double was asked, and answers or throws $next.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When $next is one.
	 *
	 * @param OutboundRequest $request        The request.
	 * @param int             $timeoutSeconds The timeout.
	 * @return OutboundResponse The answer.
	 */
	public function answer( OutboundRequest $request, int $timeoutSeconds ): OutboundResponse {
		$this->sent[] = array(
			'request' => $request,
			'timeout' => $timeoutSeconds,
		);

		if ( $this->next instanceof TransportFailure ) {
			throw $this->next;
		}

		return $this->next;
	}

	/**
	 * Returns the last line the client logged, failing the test when there is none.
	 *
	 * @since 0.2.0
	 *
	 * @return array{level: Level, code: string, message: string, context: array<mixed>} The line.
	 */
	private function lastLine(): array {
		$this->assertNotSame( array(), $this->lines, 'A line was expected.' );

		return $this->lines[ count( $this->lines ) - 1 ];
	}

	/**
	 * Returns a logger in Logger::log()'s shape that records into $lines.
	 *
	 * @since 0.2.0
	 *
	 * @return \Closure(Level, string, string, array<mixed>): void The logger.
	 */
	private function logger(): \Closure {
		return function ( Level $level, string $code, string $message, array $context ): void {
			$this->lines[] = array(
				'level'   => $level,
				'code'    => $code,
				'message' => $message,
				'context' => $context,
			);
		};
	}
}
