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

/**
 * Starts a probe in a PHP process that has served nothing else, and reads the JSON it writes.
 *
 * This class owns one fact: how a test gets an answer from a process of its own. Some answers
 * mean nothing inside the PHPUnit process, because it only ever accumulates: included files,
 * the query log, and functions that earlier tests defined, such as the stand-ins Brain Monkey
 * leaves behind for WordPress functions. Others need a second process running at the same time
 * as the test, such as a save that waits for a lock the test holds. PHPUnit's own process
 * isolation is not an option, because it never returns on FreeBSD, which the development
 * server runs.
 *
 * A probe is a PHP script that takes the path of its result file as its first argument, then
 * any further arguments, and writes one JSON document there. A probe that fails, or that ends
 * without writing, fails the calling test with everything the process printed.
 *
 * @since 0.1.0
 */
final class ChildProcessProbe {

	/**
	 * Runs a probe to its end and returns its report.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $script    Absolute path of the probe script.
	 * @param string[] $arguments Optional. Arguments after the result file. Default none.
	 * @return array<string, mixed> The decoded report.
	 */
	public static function run( string $script, array $arguments = array() ): array {
		return self::start( $script, $arguments )->finish();
	}

	/**
	 * Starts a probe and returns at once, while it runs.
	 *
	 * The child inherits this process's environment, and with it the test configuration path and
	 * WP_TESTS_SKIP_INSTALL, which the integration bootstrap exports.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $script    Absolute path of the probe script.
	 * @param string[] $arguments Optional. Arguments after the result file. Default none.
	 * @return RunningProbe The running probe; RunningProbe::finish() returns its report.
	 */
	public static function start( string $script, array $arguments = array() ): RunningProbe {
		$result_file = (string) tempnam( sys_get_temp_dir(), 'seocart-probe-' );
		$command     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $result_file );

		foreach ( $arguments as $argument ) {
			$command .= ' ' . escapeshellarg( $argument );
		}

		return new RunningProbe( basename( $script ), $command . ' 2>&1', $result_file );
	}
}
