<?php
/**
 * Tests the one rule contract versions are compared by
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\ContractVersion;

/**
 * Before 1.0 a contract is supported at its own major and minor alone; from 1.0, at its own major and any minor up to its own; a patch never matters, and anything that is no version is never supported.
 *
 * @since 0.2.0
 */
final class ContractVersionTest extends TestCase {

	/**
	 * Provides an implemented version, a version written against, and whether it is supported.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, bool}> The cases.
	 */
	public static function versions(): array {
		return array(
			'before 1.0, the same'                => array( '0.2.0', '0.2.0', true ),
			'before 1.0, without a patch'         => array( '0.2.0', '0.2', true ),
			'before 1.0, another patch'           => array( '0.2.0', '0.2.9', true ),
			'before 1.0, an earlier minor'        => array( '0.2.0', '0.1.0', false ),
			'before 1.0, a later minor'           => array( '0.2.0', '0.3.0', false ),
			'before 1.0, a later major'           => array( '0.2.0', '1.0.0', false ),
			'from 1.0, an earlier minor'          => array( '1.3.0', '1.1.0', true ),
			'from 1.0, the same minor'            => array( '1.3.0', '1.3.4', true ),
			'from 1.0, a later minor'             => array( '1.3.0', '1.4.0', false ),
			'from 1.0, another major'             => array( '1.3.0', '2.0.0', false ),
			'a written-against text that is none' => array( '0.2.0', 'latest', false ),
			'a version with a suffix'             => array( '0.2.0', '0.2.0-beta', false ),
			'an implemented text that is none'    => array( 'dev', '0.2.0', false ),
		);
	}

	/**
	 * Tests one case.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider versions
	 *
	 * @param string $implemented    The version implemented.
	 * @param string $writtenAgainst The version written against.
	 * @param bool   $supported      Whether it is supported.
	 */
	public function test_a_version_is_supported_by_the_rule( string $implemented, string $writtenAgainst, bool $supported ): void {
		$this->assertSame( $supported, ContractVersion::supports( $implemented, $writtenAgainst ) );
	}
}
