<?php
/**
 * Tests the request, the response and the failure of the outbound HTTP contract
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Http;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;

/**
 * A request is well formed and hides its credentials; an answer decodes or says it cannot; a failure names only its kind and host.
 *
 * @since 0.2.0
 */
final class OutboundMessagesTest extends TestCase {

	/**
	 * Provides requests with one defect each.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, array<mixed>}> A method and headers, keyed by the defect.
	 */
	public static function malformedRequests(): array {
		return array(
			'method in lower case'       => array( 'post', array() ),
			'method not allowed'         => array( 'TRACE', array() ),
			'line break in a value'      => array( 'GET', array( 'X-Note' => "one\r\nX-Injected: two" ) ),
			'null byte in a value'       => array( 'GET', array( 'X-Note' => "one\0two" ) ),
			'space in a name'            => array( 'GET', array( 'X Note' => 'one' ) ),
			'colon in a name'            => array( 'GET', array( 'X-Note:' => 'one' ) ),
			'numeric name'               => array( 'GET', array( 'one' ) ),
			'value that is not a string' => array( 'GET', array( 'X-Count' => 1 ) ),
		);
	}

	/**
	 * Tests that a malformed request cannot be constructed.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider malformedRequests
	 *
	 * @param string       $method  The method.
	 * @param array<mixed> $headers The headers.
	 */
	public function test_a_malformed_request_is_refused( string $method, array $headers ): void {
		$this->expectException( \InvalidArgumentException::class );

		new OutboundRequest( 'example-rates', $method, 'https://api.example.com/v1/rates', $headers );
	}

	/**
	 * Provides the bodies a GET request is refused with: any at all, an empty one included.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> Bodies, keyed by what they are.
	 */
	public static function getBodies(): array {
		return array(
			'json'  => array( '{"base":"EUR"}' ),
			'empty' => array( '' ),
		);
	}

	/**
	 * Tests that a GET request with a body cannot be constructed, while every other method may carry one.
	 *
	 * Planted violation, shown red and removed: in OutboundRequest::__construct(), drop the check
	 * of a GET request's body.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider getBodies
	 *
	 * @param string $body The body.
	 */
	public function test_a_get_request_with_a_body_is_refused( string $body ): void {
		foreach ( array_diff( OutboundRequest::METHODS, array( 'GET' ) ) as $method ) {
			$this->assertSame( $body, ( new OutboundRequest( 'example-rates', $method, 'https://api.example.com/v1/rates', array(), $body ) )->body );
		}

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'An outbound GET request cannot have a body.' );

		new OutboundRequest( 'example-rates', 'GET', 'https://api.example.com/v1/rates', array(), $body );
	}

	/**
	 * Tests that a well-formed request keeps what it was given, and hides its headers and body from stack traces.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_keeps_its_fields_and_hides_its_credentials(): void {
		$request = new OutboundRequest( 'example-rates', 'POST', 'https://api.example.com/v1/rates', array( 'Authorization' => 'Bearer sk_test_secret' ), '{"base":"EUR"}' );

		$this->assertSame( 'example-rates', $request->hostId );
		$this->assertSame( 'POST', $request->method );
		$this->assertSame( 'https://api.example.com/v1/rates', $request->url );
		$this->assertSame( array( 'Authorization' => 'Bearer sk_test_secret' ), $request->headers );
		$this->assertSame( '{"base":"EUR"}', $request->body );
		$this->assertNull( ( new OutboundRequest( 'example-rates', 'GET', 'https://api.example.com/v1/rates' ) )->body );

		$sensitive = array();

		foreach ( ( new \ReflectionMethod( OutboundRequest::class, '__construct' ) )->getParameters() as $parameter ) {
			if ( array() !== $parameter->getAttributes( \SensitiveParameter::class ) ) {
				$sensitive[] = $parameter->getName();
			}
		}

		$this->assertSame( array( 'headers', 'body' ), $sensitive );
	}

	/**
	 * Tests that an answer's body decodes as a JSON object or array, keeping a long integer exact.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_decodes_its_json_body(): void {
		$this->assertSame(
			array(
				'id'     => 'pi_1',
				'amount' => 1250,
			),
			( new OutboundResponse( 200, array(), '{"id":"pi_1","amount":1250}' ) )->json()
		);
		$this->assertSame( array( 1, 2 ), ( new OutboundResponse( 200, array(), '[1,2]' ) )->json() );
		$this->assertSame( array( 'n' => '123456789012345678901234567890' ), ( new OutboundResponse( 200, array(), '{"n":123456789012345678901234567890}' ) )->json() );
	}

	/**
	 * Provides bodies that are not a JSON object or array.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> Bodies, keyed by what they are.
	 */
	public static function bodiesThatAreNotJsonDocuments(): array {
		return array(
			'empty'         => array( '' ),
			'html'          => array( '<html><body>secret-looking page</body></html>' ),
			'a json string' => array( '"secret-looking text"' ),
			'a json number' => array( '42' ),
		);
	}

	/**
	 * Tests that a body that is not a JSON object or array is refused without quoting it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider bodiesThatAreNotJsonDocuments
	 *
	 * @param string $body The body.
	 */
	public function test_an_answer_that_is_not_a_json_document_is_refused_without_quoting_it( string $body ): void {
		try {
			( new OutboundResponse( 200, array(), $body ) )->json();
			$this->fail( 'The body must be refused.' );
		} catch ( \UnexpectedValueException $refused ) {
			$this->assertStringNotContainsString( 'secret-looking', $refused->getMessage() );
		}
	}

	/**
	 * Tests that a failure's message names its host and nothing more of the request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_failure_names_only_its_kind_and_host(): void {
		foreach ( array( TransportFailure::CONNECT, TransportFailure::TIMEOUT, TransportFailure::TLS, TransportFailure::SIZE ) as $kind ) {
			$failure = new TransportFailure( $kind, 'api.example.com' );

			$this->assertInstanceOf( \RuntimeException::class, $failure );
			$this->assertSame( $kind, $failure->kind );
			$this->assertSame( 'api.example.com', $failure->host );
			$this->assertStringContainsString( 'api.example.com', $failure->getMessage() );
			$this->assertStringNotContainsString( '/', $failure->getMessage(), 'No path, and so no query.' );
		}

		$this->assertStringContainsString( 'timed out', ( new TransportFailure( TransportFailure::TIMEOUT, 'api.example.com' ) )->getMessage() );
	}
}
