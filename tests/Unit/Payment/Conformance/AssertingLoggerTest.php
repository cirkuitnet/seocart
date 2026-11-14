<?php
/**
 * Tests what the conformance suite's logger finds in a gateway's log line
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\Doubles\AssertingLogger;

/**
 * Rule 13's reading of a log line: a provider's answer, the provider's words in it however the line encodes, cases or splits them, a card field or a card number; a code the test declares passes.
 *
 * @since 0.2.0
 */
final class AssertingLoggerTest extends TestCase {

	/**
	 * The provider's answer the gateway was served.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ANSWER = '{"id":"ri_1","status":"Denied","amount":3000,"currency":"usd","decline_code":"card_declined","error":{"message":"The card was declined by its issuer."},"next_action":{"url":"https://pay.example.com/act/ri_1"}}';

	/**
	 * The provider's message, as the answer holds it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MESSAGE = 'The card was declined by its issuer.';

	/**
	 * Sixteen digits that pass the card number's checksum: a published test card's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DIGITS = '4242424242424242';

	/**
	 * A provider's id of 17 letters and digits that holds them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ID = 'A' . self::DIGITS;

	/**
	 * The shape of the provider's ids the test declares: 17 letters and digits, with no prefix.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const SHAPES = array( '' => 17 );

	/**
	 * Sets Brain Monkey up, for wp_json_encode().
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
	 * Returns log lines that hold what no log may, with what the finding must say.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, array<mixed>, string}> The message, the context, the finding.
	 */
	public function offending(): array {
		$words = 'the provider\'s words at error.message';

		return array(
			'the answer as it arrived'          => array( 'Answered.', array( 'body' => self::ANSWER ), 'a response body the gateway was served' ),
			'the answer decoded'                => array( 'Answered.', array( 'body' => json_decode( self::ANSWER, true ) ), 'a response body the gateway was served' ),
			'the message as it is'              => array( 'Declined: ' . self::MESSAGE, array(), $words ),
			'the message URL-encoded'           => array( 'Declined.', array( 'why' => rawurlencode( self::MESSAGE ) ), $words ),
			'the message form-encoded'          => array( 'Declined.', array( 'why' => urlencode( self::MESSAGE ) ), $words ),
			'the message as HTML entities'      => array( 'Declined.', array( 'why' => mb_encode_numericentity( self::MESSAGE, array( 0x0, 0x10FFFF, 0, 0x10FFFF ), 'UTF-8' ) ), $words ),
			'the message with HTML spaces'      => array( 'Declined.', array( 'why' => str_replace( ' ', '&nbsp;', self::MESSAGE ) ), $words ),
			'the message in upper case'         => array( 'Declined.', array( 'why' => strtoupper( self::MESSAGE ) ), $words ),
			'the message split into its words'  => array( 'Declined.', array( 'words' => explode( ' ', self::MESSAGE ) ), $words ),
			'the message split across the line' => array( 'Declined: The card was', array( 'rest' => array( 'declined', array( 'by its issuer.' ) ) ), $words ),
			'a one-word status'                 => array( 'The payment was denied.', array(), 'the provider\'s words at status' ),
			'a card field'                      => array( 'Declined.', array( 'last4' => '4242' ), 'the card field last4' ),
			'a card number'                     => array( 'Declined 4242424242424242.', array(), 'a card number' ),
		);
	}

	/**
	 * Tests that a line holding what no log may is noted, with what it holds.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider offending
	 * @group conformance
	 *
	 * @param string       $message The line's message.
	 * @param array<mixed> $context The line's context.
	 * @param string       $finding What the violation must say.
	 */
	public function test_a_line_holding_what_no_log_may_is_noted( string $message, array $context, string $finding ): void {
		$logger = self::logger();
		$logger->error( 'gateway.declined', $message, $context );

		$this->assertStringContainsString( 'the line gateway.declined holds ' . $finding, implode( '; ', $logger->violations() ) );
	}

	/**
	 * Tests that what a log line may carry passes: an id, a code with `_`, a URL, a number, the gateway's own words.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_ids_codes_urls_and_the_gateways_own_words_pass(): void {
		$logger = self::logger();
		$logger->info( 'gateway.declined', 'The provider declined the payment ri_1 of 3000 (card_declined); its page is https://pay.example.com/act/ri_1.', array( 'intent' => 'ri_1' ) );

		$this->assertSame( array(), $logger->violations() );
		$this->assertCount( 1, $logger->lines() );
	}

	/**
	 * Tests that a one-word value of the provider's answer passes only when the test declares it one of the provider's codes.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_declared_code_passes(): void {
		$declared   = self::logger( array( 'Denied', 'usd' ) );
		$undeclared = self::logger();

		foreach ( array( $declared, $undeclared ) as $logger ) {
			$logger->info( 'gateway.declined', 'Status: DENIED, 30.00 USD.' );
		}

		$this->assertSame( array(), $declared->violations() );
		$this->assertStringContainsString( 'the provider\'s words at status', implode( '; ', $undeclared->violations() ) );
	}

	/**
	 * Tests that a context value that is, as a whole, an id of a declared shape with a letter in it is no card number, though its 16 digits pass the card number's checksum: a logger of a test that declares no shape for it notes one.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_id_of_a_declared_shape_with_a_letter_is_no_card_number(): void {
		$context    = array(
			'order'   => self::ID,
			'capture' => array( 'id' => self::ID ),
		);
		$declared   = self::logger( array(), self::SHAPES );
		$undeclared = self::logger();

		foreach ( array( $declared, $undeclared ) as $logger ) {
			$logger->info( 'gateway.authorized', 'The provider authorized the payment.', $context );
		}

		$this->assertSame( array(), $declared->violations() );
		$this->assertSame( array( 'the line gateway.authorized holds a card number' ), $undeclared->violations() );
	}

	/**
	 * Returns log lines that hold a declared id's digits other than as a whole context value, each still a card number.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, array<mixed>}> The message and the context.
	 */
	public function idsAmongOtherText(): array {
		return array(
			'the id in the message'                     => array( 'Authorized ' . self::ID . '.', array() ),
			'the id after other text in a value'        => array( 'Authorized.', array( 'order' => 'order-' . self::ID ) ),
			'a value of the shape that holds no letter' => array( 'Authorized.', array( 'order' => '0' . self::DIGITS ) ),
			'the digits alone'                          => array( 'Authorized.', array( 'order' => self::DIGITS ) ),
		);
	}

	/**
	 * Tests that the id among other text, in the message or in a value, and a value of the shape with no letter, are still card numbers.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider idsAmongOtherText
	 * @group conformance
	 *
	 * @param string       $message The line's message.
	 * @param array<mixed> $context The line's context.
	 */
	public function test_a_declared_id_among_other_text_is_still_a_card_number( string $message, array $context ): void {
		$logger = self::logger( array(), self::SHAPES );
		$logger->info( 'gateway.authorized', $message, $context );

		$this->assertSame( array( 'the line gateway.authorized holds a card number' ), $logger->violations() );
	}

	/**
	 * Returns declared ids the tape scrubber reads as card numbers, with the shapes declared: one with a secret's prefix, and one in a field named like a credential.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<string, int>, array<string, string>}> The shapes and the context.
	 */
	public function idsWhereNoPublicShapePasses(): array {
		return array(
			'an id with a secret\'s prefix'            => array( array( 'sk_' => 16 ), array( 'id' => 'sk_' . self::DIGITS ) ),
			'an id in a field named like a credential' => array( self::SHAPES, array( 'client_token' => self::ID ) ),
			'an id with no letter after its prefix'    => array( array( 'APP-' => 17 ), array( 'app_id' => 'APP-0' . self::DIGITS ) ),
		);
	}

	/**
	 * Tests that the logger reads as a card number every declared id the tape scrubber reads as one: no public shape passes with a secret's prefix or in a field named like a credential.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider idsWhereNoPublicShapePasses
	 * @group conformance
	 *
	 * @param array<string, int>    $shapes  The shapes the test declares.
	 * @param array<string, string> $context The line's context.
	 */
	public function test_a_declared_id_where_no_public_shape_passes_is_still_a_card_number( array $shapes, array $context ): void {
		$logger = self::logger( array(), $shapes );
		$logger->info( 'gateway.authorized', 'Authorized.', $context );

		$this->assertSame( array( 'the line gateway.authorized holds a card number' ), $logger->violations() );
	}

	/**
	 * Builds a logger whose gateway was served the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                          $codes  The codes the test declares.
	 * @param array<int|string, int|array<int>> $shapes Optional. The shapes of the ids the test declares. Default none.
	 * @return AssertingLogger The logger.
	 *
	 * @phpstan-param list<string> $codes
	 * @phpstan-param array<int|string, int|array{int, int}> $shapes
	 */
	private static function logger( array $codes = array(), array $shapes = array() ): AssertingLogger {
		return new AssertingLogger( static fn(): array => array( self::ANSWER ), $codes, $shapes );
	}
}
