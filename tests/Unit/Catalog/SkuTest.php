<?php
/**
 * Tests Sku: what a valid SKU is, and the one form the store keeps it in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Support\Error\CodedException;

/**
 * A SKU is trimmed and its inner whitespace collapsed; it keeps its case; it has 1 to 64
 * characters of valid UTF-8 and no control or formatting character, or it is refused with
 * `catalog.sku_invalid`.
 *
 * @since 0.1.0
 */
final class SkuTest extends TestCase {

	/**
	 * Tests that a SKU is normalized and keeps its case.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_normalized
	 *
	 * @param string $given    The SKU as given.
	 * @param string $expected The SKU as kept.
	 */
	public function test_a_sku_is_trimmed_collapsed_and_keeps_its_case( string $given, string $expected ): void {
		$this->assertSame( $expected, Sku::of( $given )->toString() );
	}

	/**
	 * Provides SKUs with the form they are kept in.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}> Test cases.
	 */
	public static function data_normalized(): array {
		return array(
			'as typed'                    => array( 'TSHIRT-RED-L', 'TSHIRT-RED-L' ),
			'lower case is kept'          => array( 'tshirt-red-l', 'tshirt-red-l' ),
			'surrounding spaces'          => array( "  AB-1 \t", 'AB-1' ),
			'inner runs become one space' => array( "AB \t\n 1", 'AB 1' ),
			'a no-break space is a space' => array( "AB\u{00A0}\u{00A0}1", 'AB 1' ),
			'letters beyond ASCII'        => array( 'ÉTÉ-Ø-1', 'ÉTÉ-Ø-1' ),
			'64 characters'               => array( str_repeat( 'é', 64 ), str_repeat( 'é', 64 ) ),
			'64 after trimming'           => array( ' ' . str_repeat( 'A', 64 ) . ' ', str_repeat( 'A', 64 ) ),
		);
	}

	/**
	 * Tests that an invalid SKU is refused with `catalog.sku_invalid`, showing what can be shown.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_invalid
	 *
	 * @param string $given The SKU as given.
	 * @param string $shown What the error's context shows.
	 */
	public function test_an_invalid_sku_is_refused( string $given, string $shown ): void {
		try {
			Sku::of( $given );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::SkuInvalid, $refused->errorCode() );
			$this->assertSame( array( 'sku' => $shown ), $refused->context() );

			return;
		}

		$this->fail( 'The SKU was accepted.' );
	}

	/**
	 * Provides invalid SKUs with what their error shows.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}> Test cases.
	 */
	public static function data_invalid(): array {
		return array(
			'empty'                            => array( '', '' ),
			'only whitespace'                  => array( " \t\n ", '' ),
			'65 characters'                    => array( str_repeat( 'é', 65 ), str_repeat( 'é', 65 ) ),
			'a long value is cut in the error' => array( str_repeat( 'A', 200 ), str_repeat( 'A', 80 ) ),
			'a control character'              => array( "AB\x07CD", 'ABCD' ),
			'a zero-width space'               => array( "AB\u{200B}CD", 'ABCD' ),
			'invalid UTF-8'                    => array( "AB\xC3\x28", '' ),
		);
	}

	/**
	 * Tests that two SKUs are equal when written the same, case included.
	 *
	 * @since 0.1.0
	 */
	public function test_equality_is_exact(): void {
		$this->assertTrue( Sku::of( ' AB-1' )->equals( Sku::of( 'AB-1 ' ) ) );
		$this->assertFalse( Sku::of( 'AB-1' )->equals( Sku::of( 'ab-1' ) ), 'Case is the storage key\'s to ignore, not the value\'s.' );
	}
}
