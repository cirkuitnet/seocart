<?php
/**
 * Tests the search for error catalogs the error reference is generated from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Fixtures\Operations\FixtureStoreError;
use SEOCart\Tools\Docs\ErrorCatalogs;

/**
 * The search finds the catalogs of a source tree, and only enums that implement ErrorCode.
 *
 * @since 0.1.0
 */
final class ErrorCatalogsTest extends TestCase {

	/**
	 * Tests that the plugin's own source yields the shared kernel's catalog, and composes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_plugin_source_yields_its_catalogs(): void {
		$root     = dirname( __DIR__, 3 );
		$catalogs = ErrorCatalogs::find( $root . '/src' );

		$this->assertContains( SupportError::class, $catalogs );

		$cases = 0;

		foreach ( $catalogs as $catalog ) {
			$cases += count( $catalog::cases() );
		}

		$this->assertCount( $cases, ErrorCatalogs::table( $root . '/src' )->definitions(), 'The table holds one row per case of every catalog found.' );
	}

	/**
	 * Tests that the search reads another tree under its own namespace, and ignores its other classes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_tree_is_searched_under_its_namespace(): void {
		$this->assertSame(
			array( FixtureStockError::class, FixtureStoreError::class ),
			ErrorCatalogs::find( dirname( __DIR__, 3 ) . '/tests/Fixtures/Operations', 'SEOCart\\Tests\\Fixtures\\Operations\\' ),
			'The fixture directory holds two catalogs among plain classes, found in name order.'
		);
	}

	/**
	 * Tests that a directory that does not exist is an error, not an empty table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_directory_is_an_error(): void {
		$this->expectException( \RuntimeException::class );

		ErrorCatalogs::find( __DIR__ . '/does-not-exist' );
	}
}
