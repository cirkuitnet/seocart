<?php
/**
 * Tests that SEOCart's jobs coexist with another plugin's copy of Action Scheduler, older or newer, in either load order
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\Jobs\JobsTestCase;

/**
 * The highest version wins whatever the load order, SEOCart's jobs run under it, the report names it, and another group is never touched.
 *
 * A plugin that bundles Action Scheduler registers its copy for the version negotiation; the
 * newest is initialised. Each case boots WordPress in a child process with SEOCart and one
 * competing copy, loaded just before or just after SEOCart (tests/Support/jobs-coexistence-probe.php).
 * The competing copies are SEOCart's bundled copy, relabelled: one as an older version, one as
 * a newer one. Same code, different registration: what is tested is the negotiation, which copy
 * SEOCart's queue reports as in control, and that SEOCart's jobs run under whichever copy that
 * is, not the behaviour of a particular old release (the coexistence runs on disposable sites
 * cover real releases and WooCommerce).
 *
 * Planted violation: move the `require_once` of the bundled library from seocart.php into
 * Kernel::boot() (the kernel's `plugins_loaded` callback, priority 10); in the "older copy
 * loaded first" cases the older copy is then in control, and the report says so.
 *
 * @since 0.1.0
 */
final class CoexistenceTest extends JobsTestCase {

	/**
	 * The version SEOCart bundles.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const BUNDLED = '4.2.0';

	/**
	 * The directory holding the relabelled copies, created on first use.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private static ?string $copies = null;

	/**
	 * Removes the relabelled copies.
	 *
	 * @since 0.1.0
	 */
	public static function tear_down_after_class(): void {
		if ( null !== self::$copies ) {
			self::removeDirectory( self::$copies );

			self::$copies = null;
		}

		parent::tear_down_after_class();
	}

	/**
	 * Tests each competing copy in each load order.
	 *
	 * @dataProvider competingCopies
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The competing copy's version.
	 * @param string $order   `before` or `after` SEOCart.
	 */
	public function test_the_highest_version_runs_seocarts_jobs_whatever_the_load_order( string $version, string $order ): void {
		$copy     = self::copy( $version );
		$result   = ChildProcessProbe::run( dirname( __DIR__, 2 ) . '/Support/jobs-coexistence-probe.php', array( $copy, $order ) );
		$winner   = version_compare( $version, self::BUNDLED, '>' ) ? $version : self::BUNDLED;
		$in_ours  = version_compare( $version, self::BUNDLED, '<' );
		$expected = $in_ours ? 'SEOCart' : wp_normalize_path( $copy );

		$this->assertSame( $winner, $result['negotiated'], 'The negotiation must pick the highest version.' );
		$this->assertSame( $winner, $result['report']['version'], 'The queue must report the version in control.' );
		$this->assertSame( $expected, $result['report']['source'], 'The queue must report where the copy in control lives.' );
		$this->assertSame( $in_ours ? array( self::BUNDLED, $version ) : array( $version, self::BUNDLED ), $result['report']['registered'], 'Every registered version is reported, highest first.' );
		$this->assertTrue( $result['report']['supported'] );
		$this->assertSame( array( true, false ), $result['queued'], 'The keyed job is queued once; the redelivery adds nothing.' );
		$this->assertCount( 1, $result['runs'], 'The job ran once, on the library\'s own runner.' );
		$this->assertStringStartsWith( $in_ours ? wp_normalize_path( dirname( __DIR__, 3 ) ) . '/vendor-scoped/' : wp_normalize_path( $copy ) . '/', wp_normalize_path( $result['runs'][0]['library'] ), 'The job must run under the copy in control.' );
		$this->assertSame( array( 'complete' ), array_column( $result['ours_after_run'], 'status' ) );
		$this->assertSame( 1, $result['cancelled'] );
		$this->assertGreaterThanOrEqual( 1, $result['cleaned'] );
		$this->assertSame( array( 'pending', 'complete' ), array_column( $result['other_after'], 'status' ), 'Another plugin\'s actions survive SEOCart\'s cancel and cleanup.' );
		$this->assertFalse( $result['retention_filter'] );
		$this->assertSame( array(), $result['reports'] );
	}

	/**
	 * Supplies the competing copies and load orders.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string}> Case => version, order.
	 */
	public static function competingCopies(): array {
		return array(
			'an older copy, loaded first' => array( '3.6.0', 'before' ),
			'an older copy, loaded last'  => array( '3.6.0', 'after' ),
			'a newer copy, loaded first'  => array( '4.99.0', 'before' ),
			'a newer copy, loaded last'   => array( '4.99.0', 'after' ),
		);
	}

	/**
	 * Returns the directory of the bundled copy relabelled as a version, creating it on first use.
	 *
	 * The copy's main file registers its version through functions named after it, so both the
	 * version string and those names are rewritten; nothing else changes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The version to register as.
	 * @return string The directory.
	 */
	private static function copy( string $version ): string {
		self::$copies ??= (string) tempnam( sys_get_temp_dir(), 'seocart-as-' );

		if ( is_file( self::$copies ) ) {
			unlink( self::$copies );
			mkdir( self::$copies );
		}

		$directory = self::$copies . '/' . str_replace( '.', '-', $version ) . '/action-scheduler';

		if ( is_dir( $directory ) ) {
			return $directory;
		}

		self::copyDirectory( dirname( __DIR__, 3 ) . '/vendor-scoped/woocommerce/action-scheduler', $directory );

		$main = $directory . '/action-scheduler.php';

		file_put_contents(
			$main,
			str_replace(
				array( self::BUNDLED, str_replace( '.', '_dot_', self::BUNDLED ) ),
				array( $version, str_replace( '.', '_dot_', $version ) ),
				(string) file_get_contents( $main )
			)
		);

		return $directory;
	}

	/**
	 * Copies a directory tree.
	 *
	 * @since 0.1.0
	 *
	 * @param string $from The source.
	 * @param string $to   The destination, created.
	 */
	private static function copyDirectory( string $from, string $to ): void {
		mkdir( $to, 0700, true );

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
			$target = $to . '/' . substr( $item->getPathname(), strlen( $from ) + 1 );

			if ( $item->isDir() ) {
				mkdir( $target, 0700, true );
			} else {
				copy( $item->getPathname(), $target );
			}
		}
	}

	/**
	 * Removes a directory tree.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory The directory.
	 */
	private static function removeDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $directory );
	}
}
