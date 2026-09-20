<?php
/**
 * TemporaryPluginDirectory: a throw-away plugin checkout for testing the probes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

/**
 * Builds a small directory tree that stands in for a plugin checkout, and removes it again.
 *
 * The ownership rules and the file probe are about real paths, real file sizes and the file a
 * closure was written in, so their tests need real files. Writing them at run time keeps the
 * sizes exact on every platform and keeps deliberately odd PHP out of the linted source tree.
 *
 * @since 0.1.0
 */
trait TemporaryPluginDirectory {

	/**
	 * Absolute path of the directory, without a trailing slash. Empty until it is created.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $pluginDirectory = '';

	/**
	 * Creates the directory with the given files in it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $files File contents, keyed by path relative to the directory.
	 * @return string Absolute, resolved path of the directory, with forward slashes and no trailing slash.
	 */
	private function createPluginDirectory( array $files ): string {
		$base = str_replace( '\\', '/', (string) realpath( sys_get_temp_dir() ) );

		$this->pluginDirectory = $base . '/seocart-probe-' . bin2hex( random_bytes( 6 ) );

		foreach ( $files as $path => $contents ) {
			$file = $this->pluginDirectory . '/' . $path;

			if ( ! is_dir( dirname( $file ) ) ) {
				mkdir( dirname( $file ), 0777, true );
			}

			file_put_contents( $file, $contents );
		}

		return $this->pluginDirectory;
	}

	/**
	 * Removes the directory and everything in it.
	 *
	 * @since 0.1.0
	 */
	private function removePluginDirectory(): void {
		if ( '' === $this->pluginDirectory || ! is_dir( $this->pluginDirectory ) ) {
			return;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->pluginDirectory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( $entry instanceof \SplFileInfo && $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			} elseif ( $entry instanceof \SplFileInfo ) {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $this->pluginDirectory );

		$this->pluginDirectory = '';
	}
}
