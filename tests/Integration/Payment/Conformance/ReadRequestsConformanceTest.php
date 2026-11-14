<?php
/**
 * Tests that the conformance suite reads a declared read by POST as one that moves no money, and refuses a declaration that could hide one that does
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
use SEOCart\Tests\Conformance\Payment\ReadRequests;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\DeclaresItsCaptureARead;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\ReadNamesTheAmount;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\ReadsByPost;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rules 1, 2, 6, 8 and 12 against a gateway that reads by POST at a single endpoint: each passes when the test declares its reads, and rule 2 fails when it does not, the read before the refund losing its answer; a declared read that carries the capture's key or names the refund's amount fails rules 6 and 12; a declaration of more reads than ReadRequests::MOST, or of a malformed one, fails the set-up.
 *
 * Planted violation, shown red and removed: in RequestRules::keyFailure() and sentFailure(), drop
 * the reads' guard (moneyInARead()), so a declared read may carry the operation's key: the tests of
 * a declared capture and of a read that names the amount pass rules 6 and 12, and fail here.
 *
 * @group conformance
 * @since 0.2.0
 */
final class ReadRequestsConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup {
		set_up as setUpReference;
	}
	use RuleFailures;

	/**
	 * The test whose declaration names more reads than the set-up takes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOO_MANY = 'test_more_reads_than_the_most_fail_the_set_up';

	/**
	 * The test whose declaration names a read of a method that is a read's already.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MALFORMED = 'test_a_malformed_read_fails_the_set_up';

	/**
	 * The test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNDECLARED = 'test_rule_2_fails_undeclared_reads_by_post';

	/**
	 * What the suite's set-up refused, in the tests that expect it.
	 *
	 * @since 0.2.0
	 *
	 * @var AssertionFailedError|null
	 */
	private ?AssertionFailedError $refused = null;

	/**
	 * Sets the suite up, keeping its refusal for the tests that expect one.
	 *
	 * @since 0.2.0
	 *
	 * @throws AssertionFailedError When the set-up is refused in any other test.
	 */
	public function set_up(): void {
		try {
			$this->setUpReference();
		} catch ( AssertionFailedError $refused ) {
			if ( ! in_array( $this->getName( false ), array( self::TOO_MANY, self::MALFORMED ), true ) ) {
				throw $refused;
			}

			$this->refused = $refused;
		}
	}

	/**
	 * Tests that rule 1 passes declared reads: the refund's object is in its own answer, not only in the read's.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_1_passes_declared_reads_by_post(): void {
		$this->check( Rule::LedgerShape );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 2's refund, its answer lost after a declared read, is found made when asked again.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_passes_declared_reads_by_post(): void {
		$this->check( Rule::MatrixMatchesBehaviour );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 6 asks a declared read for no key.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_6_passes_declared_reads_by_post(): void {
		$this->check( Rule::KeysAsRuled );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 8's queries, by id and by the store's uuid, are declared reads.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_8_passes_declared_reads_by_post(): void {
		$this->check( Rule::NotFoundAuthority );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 12 finds each operation's own request beside a declared read.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_12_passes_declared_reads_by_post(): void {
		$this->check( Rule::EveryCallThroughTheClient );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 2 fails the same gateway when its test declares no read: the read before the refund moves money, loses its answer, and no refund is made.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_fails_undeclared_reads_by_post(): void {
		$this->assertRuleFails( Rule::MatrixMatchesBehaviour, 'and refunding the captured order one unit, then the rest, was refused' );
	}

	/**
	 * Tests that rule 6 refuses a declaration that covers the capture's request: it carries the capture's key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_capture_fails_rule_6(): void {
		$this->assertRuleFails( Rule::KeysAsRuled, 'the test declares POST /v1/requests with the one key captureIntent a read in readRequests(), and the capture sent a request it covers that carries the operation\'s key' );
	}

	/**
	 * Tests that rule 12 refuses a declaration that covers the capture's request.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declared_capture_fails_rule_12(): void {
		$this->assertRuleFails( Rule::EveryCallThroughTheClient, 'the test declares POST /v1/requests with the one key captureIntent a read in readRequests(), and the capture sent a request it covers that carries the operation\'s key' );
	}

	/**
	 * Tests that rule 6 refuses a declared read that names the refund's amount with its currency.
	 *
	 * @since 0.2.0
	 */
	public function test_a_read_that_names_the_amount_fails_rule_6(): void {
		$this->assertRuleFails( Rule::KeysAsRuled, 'the test declares POST /v1/requests with the one key retrieveIntent a read in readRequests(), and the first refund sent a request it covers that names the operation\'s amount with its currency' );
	}

	/**
	 * Tests that rule 12 refuses a declared read that names the refund's amount with its currency.
	 *
	 * @since 0.2.0
	 */
	public function test_a_read_that_names_the_amount_fails_rule_12(): void {
		$this->assertRuleFails( Rule::EveryCallThroughTheClient, 'the test declares POST /v1/requests with the one key retrieveIntent a read in readRequests(), and the first refund sent a request it covers that names the operation\'s amount with its currency' );
	}

	/**
	 * Tests that a declaration of more reads than ReadRequests::MOST fails the suite's set-up with one sentence.
	 *
	 * @since 0.2.0
	 */
	public function test_more_reads_than_the_most_fail_the_set_up(): void {
		$this->assertNotNull( $this->refused, 'The suite set up a declaration of more reads than it takes.' );
		$this->assertSame( (string) ReadRequests::refusal( $this->readRequests() ), $this->refused->getMessage() );
		$this->assertStringStartsWith( sprintf( 'The test declares %d read requests in readRequests()', ReadRequests::MOST + 1 ), $this->refused->getMessage() );
	}

	/**
	 * Tests that a declared read of a method that is a read's already fails the suite's set-up with one sentence.
	 *
	 * @since 0.2.0
	 */
	public function test_a_malformed_read_fails_the_set_up(): void {
		$this->assertNotNull( $this->refused, 'The suite set up a malformed declaration of reads.' );
		$this->assertStringStartsWith( 'Read request 1 of readRequests() is not a method in capitals other than GET, HEAD or OPTIONS', $this->refused->getMessage() );
	}

	/**
	 * Returns the reads each test declares: the gateway's three, unless the test is about another declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{method: string, path: string, body_key: string}> The reads.
	 */
	protected function readRequests(): array {
		$reads = ReadsByPost::reads();

		return match ( $this->getName( false ) ) {
			self::UNDECLARED => array(),
			'test_a_declared_capture_fails_rule_6', 'test_a_declared_capture_fails_rule_12' => array_merge(
				$reads,
				array(
					array(
						'method'   => 'POST',
						'path'     => ReadsByPost::PATH,
						'body_key' => DeclaresItsCaptureARead::CAPTURE,
					),
				)
			),
			self::TOO_MANY => array_fill( 0, ReadRequests::MOST + 1, $reads[0] ),
			self::MALFORMED => array( array( 'method' => 'GET' ) + $reads[0] ),
			default => $reads,
		};
	}

	/**
	 * Builds the gateway that reads by POST; in the tests of a declared capture and of a read that names the amount, the gateway that sends one.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );
		$name    = $this->getName( false );

		if ( str_starts_with( $name, 'test_a_declared_capture' ) ) {
			return new DeclaresItsCaptureARead( $context );
		}

		return str_starts_with( $name, 'test_a_read_that_names_the_amount' ) ? new ReadNamesTheAmount( $context ) : new ReadsByPost( $context );
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
