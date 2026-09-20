<?php
/**
 * ReadsTestSources: gives a tooling test the source text of every test file in the repository
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Tooling;

use PHPUnit\Framework\Assert;

/**
 * Reads the PHP files of the directories that hold tests.
 *
 * Where tests live is declared once, by the test suites of phpunit.xml.dist, and read from
 * there, so that every guard over the test sources looks where PHPUnit looks, and keeps doing
 * so when a suite is added.
 *
 * @since 0.1.0
 */
trait ReadsTestSources {

	/**
	 * Returns the source text of every PHP file under the test directories.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Source text, keyed by the file's path relative to the repository root, with forward slashes.
	 */
	private function testSources(): array {
		$sources = array();

		foreach ( $this->testRoots() as $directory ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $files as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$sources[ $this->relativePath( $file->getPathname() ) ] = (string) file_get_contents( $file->getPathname() );
				}
			}
		}

		ksort( $sources );

		// This file is a test source itself. A scan that does not come across it found nothing a guard could rely on.
		Assert::assertArrayHasKey( $this->relativePath( __FILE__ ), $sources, 'The scan of the test directories did not find the file that does the scanning.' );

		return $sources;
	}

	/**
	 * Returns the top-level directories that hold test code.
	 *
	 * Each suite directory is widened to the top-level directory it lies in: `tests/Unit`
	 * becomes `tests`. A base class or a trait beside the suites, in `tests/Support` for
	 * instance, can carry an annotation that PHPUnit honours in the test that inherits it.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Directory names relative to the repository root.
	 */
	private function testRoots(): array {
		$configuration = new \DOMDocument();

		Assert::assertTrue( $configuration->load( $this->root() . '/phpunit.xml.dist' ), 'phpunit.xml.dist could not be read.' );

		$entries = ( new \DOMXPath( $configuration ) )->query( '/phpunit/testsuites/testsuite/directory | /phpunit/testsuites/testsuite/file' );
		$roots   = array();

		foreach ( false === $entries ? array() : $entries as $entry ) {
			$path = preg_replace( '#^(?:\./)+#', '', trim( (string) $entry->textContent ) );

			$roots[] = explode( '/', (string) $path, 2 )[0];
		}

		$roots = array_values( array_unique( $roots ) );

		Assert::assertNotSame( array(), $roots, 'No test suite directory was found in phpunit.xml.dist, so there is nothing to scan and every guard over the test sources would pass.' );

		return $roots;
	}

	/**
	 * Shortens an absolute path to one relative to the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Absolute path of a file under the repository root.
	 * @return string The relative path, with forward slashes.
	 */
	private function relativePath( string $file ): string {
		return str_replace( '\\', '/', substr( $file, strlen( $this->root() ) + 1 ) );
	}

	/**
	 * Returns the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}
}
