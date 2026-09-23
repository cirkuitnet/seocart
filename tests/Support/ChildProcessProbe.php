<?php
/**
 * ChildProcessProbe: runs a probe script in a fresh PHP process and returns its report
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Starts a probe in a PHP process that has served nothing else, and reads the JSON it writes.
 *
 * This class owns one fact: how a test gets an answer from a process of its own. Some answers
 * mean nothing inside the PHPUnit process, because it only ever accumulates: included files,
 * the query log, and functions that earlier tests defined, such as the stand-ins Brain Monkey
 * leaves behind for WordPress functions. PHPUnit's own process isolation is not an option,
 * because it never returns on FreeBSD, which the development server runs.
 *
 * A probe is a PHP script that takes the path of its result file as its first argument, then
 * any further arguments, and writes one JSON document there. A probe that fails, or that ends
 * without writing, fails the calling test with everything the process printed.
 *
 * @since 0.1.0
 */
final class ChildProcessProbe {

	/**
	 * Runs a probe and returns its report.
	 *
	 * The child inherits this process's environment, and with it the test configuration path and
	 * WP_TESTS_SKIP_INSTALL, which the integration bootstrap exports.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $script    Absolute path of the probe script.
	 * @param string[] $arguments Optional. Arguments after the result file. Default none.
	 * @return array<string, mixed> The decoded report.
	 */
	public static function run( string $script, array $arguments = array() ): array {
		$result_file = (string) tempnam( sys_get_temp_dir(), 'seocart-probe-' );
		$command     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $result_file );

		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}

		exec( $command . ' 2>&1', $output, $status );

		$report = is_file( $result_file ) ? (string) file_get_contents( $result_file ) : '';

		if ( is_file( $result_file ) ) {
			unlink( $result_file );
		}

		if ( 0 !== $status || '' === $report ) {
			Assert::fail( 'The probe ' . basename( $script ) . " failed with exit status {$status}. Its output:\n" . implode( "\n", $output ) . "\n" );
		}

		return json_decode( $report, true, 512, JSON_THROW_ON_ERROR );
	}
}
