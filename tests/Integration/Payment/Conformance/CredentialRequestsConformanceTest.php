<?php
/**
 * Tests that the conformance suite reads a declared credential request as one that moves no money, and refuses a declaration that could hide one that does
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\CredentialRequests;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\ExchangesCredentialsFirst;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesTheAmountInItsTokenRequest;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesThePaymentInItsTokenRequest;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rules 6 and 12 against a gateway that exchanges its credentials for a token before every request: each passes when the test declares the token request, and rule 6 fails when it does not; a declaration on a path an operation sends money on fails rules 6 and 12, and one of more requests than CredentialRequests::MOST fails the set-up.
 *
 * Planted violation, shown red and removed: in RequestRules::movesMoney(), drop the declared
 * credential requests' exemption, so a token request moves money again: the rule 6 test fails the
 * declared gateway, its token request carrying no key.
 *
 * @group conformance
 * @since 0.2.0
 */
final class CredentialRequestsConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup {
		set_up as setUpReference;
	}
	use RuleFailures;

	/**
	 * The token request the gateway sends before every request.
	 *
	 * @since 0.2.0
	 *
	 * @var array{method: string, path: string}
	 */
	private const TOKEN = array(
		'method' => ExchangesCredentialsFirst::TOKEN_METHOD,
		'path'   => ExchangesCredentialsFirst::TOKEN_PATH,
	);

	/**
	 * The request with which the gateway refunds, a money request.
	 *
	 * @since 0.2.0
	 *
	 * @var array{method: string, path: string}
	 */
	private const REFUND = array(
		'method' => 'POST',
		'path'   => '/v1/refunds',
	);

	/**
	 * The test whose declaration names more requests than the set-up takes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOO_MANY = 'test_more_credential_requests_than_the_most_fail_the_set_up';

	/**
	 * What the suite's set-up refused, in the test that expects it.
	 *
	 * @since 0.2.0
	 *
	 * @var AssertionFailedError|null
	 */
	private ?AssertionFailedError $refused = null;

	/**
	 * Sets the suite up, keeping its refusal for the test that expects one.
	 *
	 * @since 0.2.0
	 *
	 * @throws AssertionFailedError When the set-up is refused in any other test.
	 */
	public function set_up(): void {
		try {
			$this->setUpReference();
		} catch ( AssertionFailedError $refused ) {
			if ( self::TOO_MANY !== $this->getName( false ) ) {
				throw $refused;
			}

			$this->refused = $refused;
		}
	}

	/**
	 * Tests that rule 6 asks a declared token request for no key: each operation's money request carries its own.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_6_passes_a_declared_token_request(): void {
		$this->check( Rule::KeysAsRuled );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 12 finds each operation's own request beside its token request.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_12_passes_a_declared_token_request(): void {
		$this->check( Rule::EveryCallThroughTheClient );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 6 fails the same gateway when its test declares no credential request: its token request moves money and carries no key.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_6_fails_an_undeclared_token_request(): void {
		$this->assertRuleFails( Rule::KeysAsRuled, 'request 1 of the authorization (POST /v1/oauth2/token) carries ' );
	}

	/**
	 * Tests that rule 6 refuses a declaration that names a path the refund sends money on.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_money_path_fails_rule_6(): void {
		$this->assertRuleFails( Rule::KeysAsRuled, 'the test declares POST /v1/refunds a credential request in credentialRequests(), and the first refund sent a request on its path that names the payment, the operation\'s key' );
	}

	/**
	 * Tests that rule 12 refuses a declaration that names a path the refund sends money on.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_money_path_fails_rule_12(): void {
		$this->assertRuleFails( Rule::EveryCallThroughTheClient, 'the test declares POST /v1/refunds a credential request in credentialRequests(), and the first refund sent a request on its path that names the payment, the operation\'s key' );
	}

	/**
	 * Tests that rule 6 refuses the declaration of a gateway that sends, on its declared token path, a request without a key that names the payment.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_token_request_that_names_the_payment_fails_rule_6(): void {
		$this->assertRuleFails( Rule::KeysAsRuled, 'the test declares POST /v1/oauth2/token a credential request in credentialRequests(), and the capture sent a request on its path that names the payment, the provider\'s id of the intent' );
	}

	/**
	 * Tests that rule 12 refuses the declaration of a gateway that sends, on its declared token path, a request without a key that names the payment.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_token_request_that_names_the_payment_fails_rule_12(): void {
		$this->assertRuleFails( Rule::EveryCallThroughTheClient, 'the test declares POST /v1/oauth2/token a credential request in credentialRequests(), and the capture sent a request on its path that names the payment, the provider\'s id of the intent' );
	}

	/**
	 * Tests that rule 6 refuses the declaration of a gateway that sends, on its declared token path, a request without a key that names the refund's amount with its currency.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_token_request_that_names_the_amount_fails_rule_6(): void {
		$this->assertRuleFails( Rule::KeysAsRuled, 'the test declares POST /v1/oauth2/token a credential request in credentialRequests(), and the first refund sent a request on its path that names the payment, the operation\'s amount with its currency' );
	}

	/**
	 * Tests that a declaration of more requests than CredentialRequests::MOST fails the suite's set-up with one sentence.
	 *
	 * @since 0.2.0
	 */
	public function test_more_credential_requests_than_the_most_fail_the_set_up(): void {
		$this->assertNotNull( $this->refused, 'The suite set up a declaration of more credential requests than it takes.' );
		$this->assertSame( (string) CredentialRequests::refusal( $this->credentialRequests() ), $this->refused->getMessage() );
		$this->assertStringStartsWith( sprintf( 'The test declares %d credential requests in credentialRequests()', CredentialRequests::MOST + 1 ), $this->refused->getMessage() );
	}

	/**
	 * Returns the credential requests each test declares: the token request, unless the test is about another declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{method: string, path: string}> The requests.
	 */
	protected function credentialRequests(): array {
		return match ( $this->getName( false ) ) {
			'test_rule_6_fails_an_undeclared_token_request' => array(),
			'test_a_declared_money_path_fails_rule_6', 'test_a_declared_money_path_fails_rule_12' => array( self::TOKEN, self::REFUND ),
			self::TOO_MANY => array_fill( 0, CredentialRequests::MOST + 1, self::TOKEN ),
			default => array( self::TOKEN ),
		};
	}

	/**
	 * Builds the gateway that exchanges its credentials first; in the tests of a request that names the payment on the token path, the gateway that sends one.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );
		$name    = $this->getName( false );

		if ( str_starts_with( $name, 'test_a_declared_token_request_that_names_the_payment' ) ) {
			return new NamesThePaymentInItsTokenRequest( $context );
		}

		return str_starts_with( $name, 'test_a_declared_token_request_that_names_the_amount' ) ? new NamesTheAmountInItsTokenRequest( $context ) : new ExchangesCredentialsFirst( $context );
	}

	/**
	 * Lets the fake provider answer every request: no tape is compared or written.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The transaction manager.
	 * @return RecordingTransport The transport.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		return RecordingTransport::passingThrough( $tx, $this->http );
	}
}
