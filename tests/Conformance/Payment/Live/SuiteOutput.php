<?php
/**
 * SuiteOutput: reads what a PHPUnit run printed, and checks the conformance suite's and the live tests' runs against a provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Reads the summaries PHPUnit prints, and holds a live run to the tests and skips the extension's declaration states.
 *
 * `sh ../seocart/bin/ci/extension.sh conformance . payments` runs two PHPUnit processes: the
 * suite's unit group, then the extension's ConformanceTest with `--verbose`, which lists each
 * skipped test and its reason. Run against the provider, the second must hold exactly the tests
 * the declaration states, every one passing but those it skips, each skipped with the reason the
 * extension declares for its scenario. Any other skip reads green and proves nothing, so it fails
 * the check. A part's live tests must all run and pass: as many as the declaration states.
 *
 * @since 0.2.0
 */
final class SuiteOutput {

	/**
	 * Returns each summary a PHPUnit run printed, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param string $output What the run printed.
	 * @return list<array{ok: bool, tests: int, assertions: int, failures: int, errors: int, skipped: int, incomplete: int, risky: int, warnings: int}> The summaries.
	 */
	public static function summaries( string $output ): array {
		$lines     = self::lines( $output );
		$summaries = array();

		foreach ( $lines as $index => $line ) {
			if ( 1 === preg_match( '/^OK \((\d+) tests?, (\d+) assertions?\)$/', $line, $match ) ) {
				$summaries[] = self::summary( true, (int) $match[1], (int) $match[2], '' );
			} elseif ( 1 === preg_match( '/^Tests: (\d+), Assertions: (\d+)(.*)\.$/', $line, $match ) ) {
				// PHPUnit says "OK, but incomplete, skipped, or risky tests!" on the line before a run that passed with skips.
				$summaries[] = self::summary( str_starts_with( $lines[ $index - 1 ] ?? '', 'OK, but' ), (int) $match[1], (int) $match[2], $match[3] );
			}
		}

		return $summaries;
	}

	/**
	 * Returns the names of the tests a `--verbose` run lists as skipped, with each one's reason.
	 *
	 * @since 0.2.0
	 *
	 * @param string $output What the run printed.
	 * @return array<string, string> Each skipped test's method, by name, and the first line of its reason.
	 */
	public static function skipped( string $output ): array {
		$lines   = self::lines( $output );
		$skipped = array();
		$inList  = false;

		foreach ( $lines as $index => $line ) {
			if ( 1 === preg_match( '/^There (?:was|were) \d+ skipped tests?:$/', $line ) ) {
				$inList = true;
			} elseif ( $inList && 1 === preg_match( '/^There (?:was|were) \d+ /', $line ) ) {
				$inList = false;
			} elseif ( $inList && 1 === preg_match( '/^\d+\) [^:]+::(\w+)$/', $line, $match ) ) {
				$skipped[ $match[1] ] = $lines[ $index + 1 ] ?? '';
			}
		}

		return $skipped;
	}

	/**
	 * Checks the conformance suite's run: the unit group passed, and the extension's test ran its tests with exactly the declared skips.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $output      What `extension.sh conformance . payments` printed.
	 * @return list<string> What is wrong; none when the run is as expected.
	 */
	public static function conformanceProblems( LiveDeclaration $declaration, string $output ): array {
		$summaries = self::summaries( $output );

		if ( 2 !== count( $summaries ) ) {
			return array( sprintf( 'The run printed %d PHPUnit summaries, not 2: the unit group and the extension\'s conformance test.', count( $summaries ) ) );
		}

		list( $unit, $suite ) = $summaries;
		$problems             = array();
		$expected             = self::sortedByKey( $declaration->suiteSkips() );

		if ( ! $unit['ok'] || 0 !== $unit['skipped'] + $unit['incomplete'] + $unit['risky'] ) {
			$problems[] = 'The unit group did not pass whole.';
		}

		if ( $declaration->suiteTests() !== $suite['tests'] ) {
			$problems[] = sprintf( 'The extension\'s conformance test ran %1$d tests, not %2$d.', $suite['tests'], $declaration->suiteTests() );
		}

		if ( 0 !== $suite['failures'] + $suite['errors'] + $suite['incomplete'] + $suite['risky'] + $suite['warnings'] ) {
			$problems[] = sprintf( 'The extension\'s conformance test has %1$d failures, %2$d errors, %3$d incomplete, %4$d risky and %5$d warnings.', $suite['failures'], $suite['errors'], $suite['incomplete'], $suite['risky'], $suite['warnings'] );
		}

		$skipped = self::sortedByKey( self::skipped( $output ) );

		if ( count( $expected ) !== $suite['skipped'] || array_keys( $expected ) !== array_keys( $skipped ) ) {
			$problems[] = sprintf( 'The skipped tests are %1$s, not exactly %2$s.', array() === $skipped ? 'none' : implode( ', ', array_keys( $skipped ) ), array() === $expected ? 'none' : implode( ', ', array_keys( $expected ) ) );
		}

		foreach ( $expected as $test => $scenario ) {
			if ( isset( $skipped[ $test ] ) && ! str_contains( $skipped[ $test ], sprintf( 'cannot produce the scenario %s in test mode', $scenario ) ) ) {
				$problems[] = sprintf( 'The test %1$s was skipped for another reason than the scenario %2$s, which the provider\'s test environment cannot produce.', $test, $scenario );
			}
		}

		return $problems;
	}

	/**
	 * Checks a run of a part's live tests: one PHPUnit run that passed, with exactly the part's tests, none skipped, incomplete or risky, as a live test that was skipped or never found would read green and prove nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $output      What the run printed.
	 * @param string          $part        The part: a live part of the declaration.
	 * @return list<string> What is wrong; none when every live test of the part ran and passed.
	 */
	public static function liveProblems( LiveDeclaration $declaration, string $output, string $part ): array {
		$parts = $declaration->liveParts();

		if ( ! isset( $parts[ $part ] ) ) {
			return array( sprintf( 'The live tests have no part %1$s: %2$s.', $part, implode( ', ', array_keys( $parts ) ) ) );
		}

		$summaries = self::summaries( $output );

		if ( 1 !== count( $summaries ) ) {
			return array( sprintf( 'The live tests printed %d PHPUnit summaries, not 1.', count( $summaries ) ) );
		}

		$run      = $summaries[0];
		$problems = array();

		if ( $parts[ $part ]['tests'] !== $run['tests'] ) {
			$problems[] = sprintf( 'The live tests of %1$s ran %2$d tests, not %3$d.', $part, $run['tests'], $parts[ $part ]['tests'] );
		}

		if ( ! $run['ok'] || 0 !== $run['failures'] + $run['errors'] + $run['skipped'] + $run['incomplete'] + $run['risky'] + $run['warnings'] ) {
			$problems[] = sprintf( 'The live tests ran %1$d tests: %2$d failures, %3$d errors, %4$d skipped, %5$d incomplete, %6$d risky and %7$d warnings; every one must run and pass.', $run['tests'], $run['failures'], $run['errors'], $run['skipped'], $run['incomplete'], $run['risky'], $run['warnings'] );
		}

		return $problems;
	}

	/**
	 * Returns a summary's counts.
	 *
	 * @since 0.2.0
	 *
	 * @param bool   $ok         Whether the run passed.
	 * @param int    $tests      The tests.
	 * @param int    $assertions The assertions.
	 * @param string $rest       The rest of the line, such as `, Skipped: 2`.
	 * @return array{ok: bool, tests: int, assertions: int, failures: int, errors: int, skipped: int, incomplete: int, risky: int, warnings: int} The summary.
	 */
	private static function summary( bool $ok, int $tests, int $assertions, string $rest ): array {
		$count = static fn( string $name ): int => 1 === preg_match( '/' . $name . ': (\d+)/', $rest, $match ) ? (int) $match[1] : 0;

		return array(
			'ok'         => $ok,
			'tests'      => $tests,
			'assertions' => $assertions,
			'failures'   => $count( 'Failures' ),
			'errors'     => $count( 'Errors' ),
			'skipped'    => $count( 'Skipped' ),
			'incomplete' => $count( 'Incomplete' ),
			'risky'      => $count( 'Risky' ),
			'warnings'   => $count( 'Warnings' ),
		);
	}

	/**
	 * Returns a text's lines, each without colour codes or trailing white space.
	 *
	 * @since 0.2.0
	 *
	 * @param string $output The text.
	 * @return list<string> The lines.
	 */
	private static function lines( string $output ): array {
		return array_map( static fn( string $line ): string => rtrim( (string) preg_replace( '/\e\[[0-9;]*m/', '', $line ) ), explode( "\n", $output ) );
	}

	/**
	 * Returns a map sorted by its keys.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $map The map.
	 * @return array<string, string> The map, sorted.
	 */
	private static function sortedByKey( array $map ): array {
		ksort( $map );

		return $map;
	}
}
