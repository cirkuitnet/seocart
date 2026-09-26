<?php
/**
 * Tests Source: the checked form of what caused an adjustment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Source;

/**
 * Proves that a source is a lower-case kind with an optional key, and that anything else is refused.
 *
 * @since 0.1.0
 */
final class SourceTest extends TestCase {

	/**
	 * Tests that the core kinds of source are accepted as written.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_sources
	 *
	 * @param string $source A source.
	 */
	public function test_a_well_formed_source_is_kept_as_written( string $source ): void {
		$this->assertSame( $source, ( new Source( $source ) )->toString() );
	}

	/**
	 * Provides well-formed sources.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public static function data_sources(): array {
		return array(
			'the catalog'            => array( 'catalog' ),
			'a member of staff'      => array( 'staff' ),
			'a promotion'            => array( 'promotion:00000000-0000-7000-8000-000000000001' ),
			'a shipping method'      => array( 'shipping:flat' ),
			'a fee'                  => array( 'fee:handling' ),
			'a plugin rule'          => array( 'plugin:acme-rules/bulk.2' ),
			'a key of 80 characters' => array( 'fee:' . str_repeat( 'k', 80 ) ),
		);
	}

	/**
	 * Tests that a malformed source is refused when it is made.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_malformed
	 *
	 * @param string $source A malformed source.
	 */
	public function test_a_malformed_source_is_refused( string $source ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Source( $source );
	}

	/**
	 * Provides malformed sources.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public static function data_malformed(): array {
		return array(
			'empty'                  => array( '' ),
			'an upper-case kind'     => array( 'Promotion:X' ),
			'an upper-case key'      => array( 'promotion:X' ),
			'a colon and no key'     => array( 'promotion:' ),
			'a space'                => array( 'fee:a b' ),
			'a key of 81 characters' => array( 'fee:' . str_repeat( 'k', 81 ) ),
			'a trailing line feed'   => array( "shipping:flat\n" ),
			'a digit in the kind'    => array( 'fee2:x' ),
		);
	}

	/**
	 * Tests the named sources.
	 *
	 * @since 0.1.0
	 */
	public function test_the_named_sources_have_their_kinds(): void {
		$this->assertSame( 'promotion:00000000-0000-7000-8000-000000000001', Source::promotion( '00000000-0000-7000-8000-000000000001' )->toString() );
		$this->assertSame( 'shipping:flat', Source::shipping( 'flat' )->toString() );
		$this->assertSame( 'fee:handling', Source::fee( 'handling' )->toString() );
	}
}
