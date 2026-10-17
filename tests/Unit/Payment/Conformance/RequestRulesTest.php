<?php
/**
 * Tests for rule 6's reading of an operation's requests
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Exchange;
use SEOCart\Tests\Conformance\Payment\RequestRules;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Rule 6 finds the key of every request that moves money in a header or the body, and fails one that carries it only in its URL.
 *
 * @since 0.2.0
 */
final class RequestRulesTest extends TestCase {

	/**
	 * The key of the operation the requests are for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = '019b76da-a800-7325-8718-1c4f7d1ef27f:capture';

	/**
	 * Sets Brain Monkey up, for wp_parse_url().
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The stand-in of wp_parse_url() itself.
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
	 * Tests that a key in a header, a form body or a JSON body is carried, and that a read is not a request that moves money.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_key_in_a_header_or_the_body_is_carried(): void {
		$exchanges = array(
			self::exchange( 'GET', '/v1/intents/ri_1', array(), null ),
			self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => self::KEY ), 'amount=1000' ),
			self::exchange( 'POST', '/v1/intents/ri_1/capture', array(), 'key=' . rawurlencode( self::KEY ) ),
			self::exchange( 'POST', '/v1/intents/ri_1/capture', array(), '{"reference":"' . self::KEY . '"}' ),
		);

		$this->assertSame( array( 2, 3, 4 ), array_keys( RequestRules::moneyRequests( $exchanges ) ) );
		$this->assertNull( RequestRules::keyFailure( 'the capture', $exchanges, self::KEY ) );
	}

	/**
	 * Tests that a request that moves money and carries its key only in its URL fails rule 6, and so does one that carries it nowhere.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_key_only_in_the_url_or_nowhere_fails_rule_6(): void {
		$inUrl   = RequestRules::keyFailure( 'the capture', array( self::exchange( 'POST', '/v1/intents/ri_1/capture?idempotency_key=' . rawurlencode( self::KEY ), array(), 'amount=1000' ) ), self::KEY );
		$nowhere = RequestRules::keyFailure( 'the capture', array( self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => 'fresh' ), 'amount=1000' ) ), self::KEY );

		$this->assertStringStartsWith( Rule::KeysAsRuled->message() . 'request 1 of the capture (POST /v1/intents/ri_1/capture?idempotency_key=', (string) $inUrl );
		$this->assertStringContainsString( 'only in its URL', (string) $inUrl );
		$this->assertStringContainsString( 'carries ' . self::KEY . ' nowhere', (string) $nowhere );
	}

	/**
	 * Tests that an operation that sent only reads fails rule 6: no request that moves money carries the key.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_operation_of_reads_only_fails_rule_6(): void {
		$this->assertStringContainsString( 'and none that moves money', (string) RequestRules::keyFailure( 'the capture', array( self::exchange( 'GET', '/v1/intents/ri_1', array( 'idempotency-key' => self::KEY ), null ) ), self::KEY ) );
	}

	/**
	 * Tests that rule 12 finds an operation's own request among what the client carried, by its key, wherever it travels.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_operations_own_request_through_the_client_passes_rule_12(): void {
		$read = self::exchange( 'GET', '/v1/intents/ri_1', array(), null );

		$this->assertNull( RequestRules::sentFailure( 'the capture', array( $read, self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => self::KEY ), 'amount=1000' ) ), self::KEY ) );
		$this->assertNull( RequestRules::sentFailure( 'the capture', array( self::exchange( 'POST', '/v1/intents/ri_1/capture?key=' . rawurlencode( self::KEY ), array(), 'amount=1000' ) ), self::KEY ), 'Where the key travels is rule 6\'s question.' );
	}

	/**
	 * Tests that an operation the client carried nothing of its own for fails rule 12, though other requests went through the client.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_operation_sent_another_way_fails_rule_12(): void {
		$cases = array(
			'no request'                   => array(),
			'a read only'                  => array( self::exchange( 'GET', '/v1/intents/ri_1', array( 'idempotency-key' => self::KEY ), null ) ),
			'another operation\'s request' => array( self::exchange( 'POST', '/v1/intents', array( 'idempotency-key' => 'the-authorizations-key' ), 'amount=1000' ) ),
		);

		foreach ( $cases as $case => $exchanges ) {
			$failure = (string) RequestRules::sentFailure( 'the capture', $exchanges, self::KEY );

			$this->assertStringStartsWith( Rule::EveryCallThroughTheClient->message() . 'the capture sent no request of its own through the HTTP client', $failure, $case );
			$this->assertStringContainsString( sprintf( 'of the %d requests the client carried while it ran', count( $exchanges ) ), $failure, $case );
		}
	}

	/**
	 * Returns a request of an exchange.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $method  The method.
	 * @param string                $path    The path, with its query.
	 * @param array<string, string> $headers The headers.
	 * @param string|null           $body    The body.
	 * @return Exchange The exchange, answered with an empty object.
	 */
	private static function exchange( string $method, string $path, array $headers, ?string $body ): Exchange {
		return new Exchange( $method, 'https://api.reference.example' . $path, $headers, $body, 200, array(), '{}' );
	}
}
