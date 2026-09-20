<?php
/**
 * Tests the evaluation of SPDX licence expressions against the allow-list
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\LicenseAllowList;

/**
 * Covers single identifiers, OR, AND, grouping, exceptions and malformed input.
 *
 * @since 0.1.0
 */
final class LicenseAllowListTest extends TestCase {

	/**
	 * Provides licence expressions and whether each may ship.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> Expression and expected verdict, keyed by expression.
	 */
	public static function expressions(): array {
		$cases = array(
			'GPL-3.0-or-later'                          => true,
			'GPL-2.0-or-later'                          => true,
			'MIT'                                       => true,
			'mit'                                       => true,
			'GPL-2.0+'                                  => true,
			'GPL-2.0-only'                              => false,
			'GPL-2.0'                                   => false,
			'proprietary'                               => false,
			'SSPL-1.0'                                  => false,
			'CC-BY-NC-4.0'                              => false,
			'(MIT OR GPL-2.0-only)'                     => true,
			'GPL-2.0-only OR MIT'                       => true,
			'GPL-2.0-only or MIT'                       => true,
			'GPL-2.0-only OR proprietary'               => false,
			'MIT AND BSD-3-Clause'                      => true,
			'MIT AND GPL-2.0-only'                      => false,
			'GPL-2.0-only AND MIT'                      => false,
			// AND binds tighter than OR.
			'GPL-2.0-only AND MIT OR Apache-2.0'        => true,
			'GPL-2.0-only AND (MIT OR Apache-2.0)'      => false,
			'(GPL-2.0-only OR MIT) AND Apache-2.0'      => true,
			'((MIT))'                                   => true,
			// An exception changes the terms, so the combination must be listed itself.
			'Apache-2.0 WITH LLVM-exception'            => false,
			'Apache-2.0 WITH LLVM-exception OR MIT'     => true,
			'GPL-2.0-only WITH Classpath-exception-2.0' => false,
		);

		$provided = array();

		foreach ( $cases as $expression => $expected ) {
			$provided[ $expression ] = array( $expression, $expected );
		}

		return $provided;
	}

	/**
	 * Tests that an expression passes only when allowed licences alone satisfy it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider expressions
	 *
	 * @param string $expression An SPDX licence expression.
	 * @param bool   $expected   Whether a package under it may ship.
	 */
	public function test_permits( string $expression, bool $expected ): void {
		$this->assertSame( $expected, LicenseAllowList::permits( $expression ) );
	}

	/**
	 * Provides expressions that are not valid SPDX.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Malformed expressions, keyed by themselves.
	 */
	public static function malformedExpressions(): array {
		$provided = array();

		foreach ( array( '', '   ', 'MIT OR', 'OR MIT', '(MIT', 'MIT)', 'MIT BSD-3-Clause', 'MIT WITH', 'MIT AND AND ISC', '()' ) as $expression ) {
			$provided[ '"' . $expression . '"' ] = array( $expression );
		}

		return $provided;
	}

	/**
	 * Tests that a malformed expression is rejected rather than guessed at.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider malformedExpressions
	 *
	 * @param string $expression A malformed expression.
	 */
	public function test_rejects_malformed_expressions( string $expression ): void {
		$this->expectException( \InvalidArgumentException::class );

		LicenseAllowList::permits( $expression );
	}

	/**
	 * Tests that the one licence known to be incompatible never reaches the list.
	 *
	 * @since 0.1.0
	 */
	public function test_gpl_2_only_is_not_allowed(): void {
		$this->assertNotContains( 'GPL-2.0-only', LicenseAllowList::ALLOWED );
		$this->assertNotContains( 'GPL-2.0', LicenseAllowList::ALLOWED );
	}
}
