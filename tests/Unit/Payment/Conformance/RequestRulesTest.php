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
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\CredentialRequests;
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
	 * The provider the requests go to, by its scheme and host.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const HOST = 'https://api.reference.example';

	/**
	 * The path of the provider's token request.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOKEN_PATH = '/v1/oauth2/token';

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

		$this->assertSame( array( 2, 3, 4 ), array_keys( RequestRules::moneyRequests( $exchanges, new CredentialRequests() ) ) );
		$this->assertNull( RequestRules::keyFailure( 'the capture', $exchanges, self::KEY, new CredentialRequests() ) );
	}

	/**
	 * Tests that a request that moves money and carries its key only in its URL fails rule 6, and so does one that carries it nowhere.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_key_only_in_the_url_or_nowhere_fails_rule_6(): void {
		$inUrl   = RequestRules::keyFailure( 'the capture', array( self::exchange( 'POST', '/v1/intents/ri_1/capture?idempotency_key=' . rawurlencode( self::KEY ), array(), 'amount=1000' ) ), self::KEY, new CredentialRequests() );
		$nowhere = RequestRules::keyFailure( 'the capture', array( self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => 'fresh' ), 'amount=1000' ) ), self::KEY, new CredentialRequests() );

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
		$this->assertStringContainsString( 'and none that moves money', (string) RequestRules::keyFailure( 'the capture', array( self::exchange( 'GET', '/v1/intents/ri_1', array( 'idempotency-key' => self::KEY ), null ) ), self::KEY, new CredentialRequests() ) );
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

		$this->assertNull( RequestRules::sentFailure( 'the capture', array( $read, self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => self::KEY ), 'amount=1000' ) ), self::KEY, new CredentialRequests() ) );
		$this->assertNull( RequestRules::sentFailure( 'the capture', array( self::exchange( 'POST', '/v1/intents/ri_1/capture?key=' . rawurlencode( self::KEY ), array(), 'amount=1000' ) ), self::KEY, new CredentialRequests() ), 'Where the key travels is rule 6\'s question.' );
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
			$failure = (string) RequestRules::sentFailure( 'the capture', $exchanges, self::KEY, new CredentialRequests() );

			$this->assertStringStartsWith( Rule::EveryCallThroughTheClient->message() . 'the capture sent no request of its own through the HTTP client', $failure, $case );
			$this->assertStringContainsString( sprintf( 'of the %d requests the client carried while it ran', count( $exchanges ) ), $failure, $case );
		}
	}

	/**
	 * Tests that a declared credential request moves no money: rules 6 and 12 ask it for no key and take the operation's own request beside it; undeclared, it is a money request without the key.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declared_credential_request_moves_no_money(): void {
		$exchanges = array(
			self::exchange( 'POST', self::TOKEN_PATH, array(), 'grant_type=client_credentials' ),
			self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => self::KEY ), 'amount=1000' ),
		);
		$declared  = self::declaring( array( 'POST', self::TOKEN_PATH ) );

		$this->assertSame( array( 2 ), array_keys( RequestRules::moneyRequests( $exchanges, $declared ) ) );
		$this->assertNull( RequestRules::keyFailure( 'the capture', $exchanges, self::KEY, $declared ) );
		$this->assertNull( RequestRules::sentFailure( 'the capture', $exchanges, self::KEY, $declared ) );
		$this->assertFalse( RequestRules::movesMoney( 'POST', self::HOST . self::TOKEN_PATH, $declared ) );

		$undeclared = (string) RequestRules::keyFailure( 'the capture', $exchanges, self::KEY, new CredentialRequests() );

		$this->assertStringStartsWith( Rule::KeysAsRuled->message() . 'request 1 of the capture (POST ' . self::TOKEN_PATH . ') carries ' . self::KEY . ' nowhere', $undeclared );
	}

	/**
	 * Tests that a request is a declared credential request only by its method, its path with its query and a host the gateway declares, and that a read moves no money either way.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_only_the_declared_method_path_and_host_move_no_money(): void {
		$declared = self::declaring( array( 'POST', self::TOKEN_PATH ) );

		$this->assertTrue( RequestRules::movesMoney( 'PUT', self::HOST . self::TOKEN_PATH, $declared ), 'Another method.' );
		$this->assertTrue( RequestRules::movesMoney( 'POST', self::HOST . self::TOKEN_PATH . '?grant=1', $declared ), 'Another query.' );
		$this->assertTrue( RequestRules::movesMoney( 'POST', 'https://api.elsewhere.example' . self::TOKEN_PATH, $declared ), 'A host the gateway does not declare.' );
		$this->assertFalse( RequestRules::movesMoney( 'post', self::HOST . self::TOKEN_PATH, $declared ), 'The method in lower case.' );

		foreach ( array( 'GET', 'HEAD', 'OPTIONS' ) as $read ) {
			$this->assertFalse( RequestRules::movesMoney( $read, self::HOST . '/v1/intents/ri_1', new CredentialRequests() ), $read );
		}
	}

	/**
	 * Tests that a declaration on a path an operation sends money on is refused by rules 6 and 12: a request there carries the operation's key.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declared_path_that_carries_money_is_refused(): void {
		$declared  = self::declaring( array( 'POST', self::TOKEN_PATH ), array( 'POST', '/v1/refunds' ) );
		$refunded  = array(
			self::exchange( 'POST', self::TOKEN_PATH, array(), 'grant_type=client_credentials' ),
			self::exchange( 'POST', '/v1/refunds', array( 'idempotency-key' => self::KEY ), 'amount=1000' ),
		);
		$refusal   = 'the test declares POST /v1/refunds a credential request in credentialRequests(), and the refund sent a request on its path that names the payment, the operation\'s key ' . self::KEY . ': a credential request carries no money, so the declaration is refused.';
		$inTheUrl  = array( self::exchange( 'PUT', '/v1/refunds', array(), 'key=' . rawurlencode( self::KEY ) ) );
		$otherWays = (string) RequestRules::keyFailure( 'the refund', $inTheUrl, self::KEY, $declared );

		$this->assertSame( Rule::KeysAsRuled->message() . $refusal, RequestRules::keyFailure( 'the refund', $refunded, self::KEY, $declared ) );
		$this->assertSame( Rule::EveryCallThroughTheClient->message() . $refusal, RequestRules::sentFailure( 'the refund', $refunded, self::KEY, $declared ) );
		$this->assertStringContainsString( 'the test declares POST /v1/refunds a credential request', $otherWays, 'A request of another method on the declared path, carrying the key, is money on it too.' );
	}

	/**
	 * Tests that a request on a declared credential path that names the payment refuses the declaration in rules 6 and 12, though it carries no key and the operation's keyed request goes elsewhere: a token request names nothing of the payment.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declared_credential_request_that_names_the_payment_is_refused(): void {
		$declared = self::declaring( array( 'POST', self::TOKEN_PATH ) );
		$uuid     = strtok( self::KEY, ':' );
		$names    = array(
			$uuid  => 'the intent\'s uuid',
			'ri_1' => 'the provider\'s id of the intent',
		);
		$keyed    = self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => self::KEY ), 'amount=1000' );

		foreach ( array(
			'the provider\'s id in the body' => array( self::exchange( 'POST', self::TOKEN_PATH, array(), 'grant_type=client_credentials&intent=ri_1' ), 'the provider\'s id of the intent ri_1' ),
			'the intent\'s uuid in a header' => array( self::exchange( 'POST', self::TOKEN_PATH, array( 'x-reference' => (string) $uuid ), 'grant_type=client_credentials' ), 'the intent\'s uuid ' . $uuid ),
		) as $case => list( $token, $named ) ) {
			$refusal = 'the test declares POST ' . self::TOKEN_PATH . ' a credential request in credentialRequests(), and the capture sent a request on its path that names the payment, ' . $named . ': a credential request carries no money, so the declaration is refused.';

			$this->assertSame( Rule::KeysAsRuled->message() . $refusal, RequestRules::keyFailure( 'the capture', array( $token, $keyed ), self::KEY, $declared, $names ), $case );
			$this->assertSame( Rule::EveryCallThroughTheClient->message() . $refusal, RequestRules::sentFailure( 'the capture', array( $token, $keyed ), self::KEY, $declared, $names ), $case );
		}

		foreach ( array( 'grant_type=client_credentials', 'grant_type=client_credentials&scope=ri_12' ) as $body ) {
			$this->assertNull( RequestRules::keyFailure( 'the capture', array( self::exchange( 'POST', self::TOKEN_PATH, array(), $body ), $keyed ), self::KEY, $declared, $names ), $body );
		}
	}

	/**
	 * Tests that a request on a declared credential path that names the operation's amount with its currency refuses the declaration, in minor units or as a decimal: the amount alone, the currency alone or another amount does not.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declared_credential_request_that_names_the_amount_is_refused(): void {
		$declared = self::declaring( array( 'POST', self::TOKEN_PATH ) );
		$keyed    = self::exchange( 'POST', '/v1/intents/ri_1/capture', array( 'idempotency-key' => self::KEY ), 'amount=1000' );
		$amount   = Money::of( 1000, Currency::of( 'USD' ) );
		$refusal  = 'the test declares POST ' . self::TOKEN_PATH . ' a credential request in credentialRequests(), and the capture sent a request on its path that names the payment, the operation\'s amount with its currency, 1000 USD in minor units: a credential request carries no money, so the declaration is refused.';

		foreach ( array(
			'minor units and the currency' => array( 'amount=1000&currency=usd', true ),
			'a decimal and the currency'   => array( '{"amount":{"currency_code":"USD","value":"10.00"}}', true ),
			'the amount alone'             => array( 'amount=1000', false ),
			'the currency alone'           => array( 'currency=usd', false ),
			'another amount'               => array( 'amount=10000&currency=usd', false ),
			'a decimal of another amount'  => array( '{"currency_code":"USD","value":"1000.00"}', false ),
		) as $case => list( $body, $refused ) ) {
			$token = self::exchange( 'POST', self::TOKEN_PATH, array(), $body );

			$this->assertSame( $refused ? Rule::KeysAsRuled->message() . $refusal : null, RequestRules::keyFailure( 'the capture', array( $token, $keyed ), self::KEY, $declared, array(), $amount ), $case );
			$this->assertSame( $refused ? Rule::EveryCallThroughTheClient->message() . $refusal : null, RequestRules::sentFailure( 'the capture', array( $token, $keyed ), self::KEY, $declared, array(), $amount ), $case );
		}
	}

	/**
	 * Tests that a declaration is refused, in one sentence, when it names more than CredentialRequests::MOST requests or an entry that is not a method in capitals and a path from `/`.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declaration_too_long_or_malformed_is_refused(): void {
		$token = array(
			'method' => 'POST',
			'path'   => self::TOKEN_PATH,
		);

		$this->assertNull( CredentialRequests::refusal( array() ) );
		$this->assertNull( CredentialRequests::refusal( array_fill( 0, CredentialRequests::MOST, $token ) ) );
		$this->assertSame( 'The test declares 5 credential requests in credentialRequests(), and a gateway exchanges its credentials in 4 at most: a longer list could hide a request that moves money.', CredentialRequests::refusal( array_fill( 0, CredentialRequests::MOST + 1, $token ) ) );

		foreach ( array(
			'a method in lower case' => array( 'method' => 'post' ) + $token,
			'a path from no `/`'     => array( 'path' => 'v1/oauth2/token' ) + $token,
			'a path with a space'    => array( 'path' => '/v1/oauth2/token now' ) + $token,
			'a path with a fragment' => array( 'path' => '/v1/oauth2/token#x' ) + $token,
			'no path'                => array( 'method' => 'POST' ),
			'a string'               => 'POST /v1/oauth2/token',
		) as $case => $entry ) {
			$this->assertSame( 'Credential request 2 of credentialRequests() is not a method in capitals and a path from `/`, such as POST /v1/oauth2/token.', CredentialRequests::refusal( array( $token, $entry ) ), $case );
		}
	}

	/**
	 * Returns the credential requests a test declares, at the host of the exchanges.
	 *
	 * @since 0.2.0
	 *
	 * @param array{string, string} ...$requests Each request's method and path.
	 * @return CredentialRequests The declaration.
	 */
	private static function declaring( array ...$requests ): CredentialRequests {
		return new CredentialRequests(
			array_map(
				static fn( array $request ): array => array(
					'method' => $request[0],
					'path'   => $request[1],
				),
				array_values( $requests )
			),
			array( 'api.reference.example' )
		);
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
		return new Exchange( $method, self::HOST . $path, $headers, $body, 200, array(), '{}' );
	}
}
