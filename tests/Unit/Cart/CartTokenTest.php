<?php
/**
 * Tests the cart token: how it is generated and what is accepted as one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use SEOCart\Cart\Domain\CartToken;

/**
 * A token is 256 random bits in one written form, and nothing else is a token.
 *
 * @since 0.1.0
 */
final class CartTokenTest extends TestCase {

	/**
	 * Tests that a generated token is 64 lowercase hexadecimal characters, and every one is new.
	 *
	 * @since 0.1.0
	 */
	public function test_a_generated_token_is_256_bits_written_in_hexadecimal(): void {
		$tokens = array();

		for ( $i = 0; $i < 100; $i++ ) {
			$token = CartToken::generate()->value();

			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', $token );

			$tokens[ $token ] = true;
		}

		$this->assertCount( 100, $tokens, 'Two generated tokens were equal.' );
		$this->assertSame( 32, CartToken::BYTES );
	}

	/**
	 * Tests that a token round-trips through its written form, and compares equal only to itself.
	 *
	 * @since 0.1.0
	 */
	public function test_a_token_is_read_back_from_its_written_form(): void {
		$token = CartToken::generate();
		$read  = CartToken::fromString( $token->value() );

		$this->assertNotNull( $read );
		$this->assertTrue( $token->equals( $read ) );
		$this->assertFalse( $token->equals( CartToken::generate() ) );
	}

	/**
	 * Provides texts that are not tokens.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The texts.
	 */
	public static function notTokens(): array {
		$valid = str_repeat( 'a1', 32 );

		return array(
			'empty'               => array( '' ),
			'one character short' => array( substr( $valid, 1 ) ),
			'one character long'  => array( $valid . 'a' ),
			'uppercase'           => array( strtoupper( $valid ) ),
			'not hexadecimal'     => array( str_repeat( 'g', 64 ) ),
			'a newline after'     => array( $valid . "\n" ),
			'white space around'  => array( ' ' . $valid ),
		);
	}

	/**
	 * Tests that a text in any other form is not a token.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider notTokens
	 *
	 * @param string $text The text.
	 */
	public function test_anything_else_is_not_a_token( string $text ): void {
		$this->assertNull( CartToken::fromString( $text ) );
	}

	/**
	 * Tests that a token never appears in debugging output.
	 *
	 * @since 0.1.0
	 */
	public function test_a_token_is_kept_out_of_debugging_output(): void {
		$token = CartToken::generate();

		$this->assertStringNotContainsString( $token->value(), print_r( $token, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- The debugging output is what is tested.
	}
}
