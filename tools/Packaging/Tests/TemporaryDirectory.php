<?php
/**
 * TemporaryDirectory: a scratch directory for the packaging self-tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Gives a test case a private directory that is removed after each test.
 *
 * @since 0.1.0
 */
trait TemporaryDirectory {

	/**
	 * Absolute path of the scratch directory, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $directory = '';

	/**
	 * Creates the scratch directory.
	 *
	 * @since 0.1.0
	 *
	 * @before
	 */
	protected function createTemporaryDirectory(): void {
		$this->directory = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/seocart-packaging-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->directory, 0700, true );
	}

	/**
	 * Removes the scratch directory and everything in it, without following links.
	 *
	 * @since 0.1.0
	 *
	 * @after
	 */
	protected function removeTemporaryDirectory(): void {
		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		/**
		 * One entry of the scratch directory.
		 *
		 * @var SplFileInfo $entry
		 */
		foreach ( $entries as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $this->directory );
	}

	/**
	 * Writes a file beneath the scratch directory, creating its directories.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the scratch directory.
	 * @param string $contents File contents.
	 * @return string Absolute path of the file.
	 */
	private function writeFile( string $relative, string $contents ): string {
		$path = $this->directory . '/' . $relative;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}

		file_put_contents( $path, $contents );

		return $path;
	}
}
