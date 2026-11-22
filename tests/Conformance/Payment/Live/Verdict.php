<?php
/**
 * Verdict: the closing judgement of a live workflow's run, and its table
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Reads the results and outputs of the run's jobs and says, for each thing the run proves against the provider, which job proves it and how it went.
 *
 * Every job the declaration states must have a result, selected or not: GitHub gives the result of
 * each job the verdict needs, `skipped` included, so a job with none is one the verdict's `needs:`
 * lost. A job the run's `parts` input selected must succeed: a skipped one would read green and
 * prove nothing. A job not selected is expected skipped. The suite's job must report its counts, and the
 * skips the replay has, no other. The declaration's optional part may say it ran nothing because
 * a secret is absent: its proofs then read as skipped, with the reason the declaration gives, and
 * the run stays green, as long as its job succeeded.
 *
 * @since 0.2.0
 */
final class Verdict {

	/**
	 * Judges a run.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param array<mixed>    $needs       The jobs' results and outputs, as `toJSON(needs)` gives them.
	 * @param string          $parts       The run's `parts` input; empty for a scheduled run, which runs all.
	 * @param string          $title       The summary's title: the workflow's name.
	 * @return array{green: bool, problems: list<string>, summary: string} Whether the run is green, why not, and the table for the run's summary.
	 */
	public static function judge( LiveDeclaration $declaration, array $needs, string $parts, string $title ): array {
		$parts    = '' === $parts ? 'all' : $parts;
		$problems = array();
		$jobs     = $declaration->jobs();
		$suite    = $declaration->suiteJob();

		if ( 'all' !== $parts && ! in_array( $parts, $jobs, true ) ) {
			$problems[] = sprintf( 'The parts input `%s` names no part: all, %s.', $declaration->guard()->redact( $parts ), implode( ', ', $jobs ) );
		}

		foreach ( $jobs as $job => $part ) {
			$result  = self::result( $needs, $job );
			$enabled = 'all' === $parts || $part === $parts;

			if ( 'missing' === $result ) {
				$problems[] = sprintf( 'The job %s has no result in the jobs the verdict was given: the verdict\'s needs must list every job, as GitHub gives each one\'s result, skipped included.', $job );
			} elseif ( $enabled && 'success' !== $result ) {
				$problems[] = sprintf( 'The job %1$s was selected and ended %2$s.', $job, $result );
			}
		}

		if ( null !== $suite && 'success' === self::result( $needs, $suite ) ) {
			$outputs = self::outputs( $declaration, $needs, $suite );

			foreach ( array( 'tests', 'assertions', 'skipped' ) as $count ) {
				if ( 1 !== preg_match( '/^\d+$/D', $outputs[ $count ] ?? '' ) ) {
					$problems[] = sprintf( 'The job %1$s reported no count of %2$s.', $suite, $count );
				}
			}

			if ( 'expected' !== ( $outputs['skip-set'] ?? '' ) ) {
				$problems[] = sprintf( 'The job %s skipped other tests than those the replay skips.', $suite );
			}
		}

		return array(
			'green'    => array() === $problems,
			'problems' => $problems,
			'summary'  => self::summary( $declaration, $needs, $parts, $title, $problems ),
		);
	}

	/**
	 * Writes the table for the run's summary.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param array<mixed>    $needs       The jobs' results and outputs.
	 * @param string          $parts       The parts the run selected.
	 * @param string          $title       The summary's title.
	 * @param string[]        $problems    Why the run is red; none when it is green.
	 * @return string The Markdown.
	 * @phpstan-param list<string> $problems
	 */
	private static function summary( LiveDeclaration $declaration, array $needs, string $parts, string $title, array $problems ): string {
		$guard = $declaration->guard();
		$lines = array( '## ' . $guard->redact( $title ) . ': ' . ( array() === $problems ? 'green' : 'red' ), '', sprintf( 'Parts: `%s`', $guard->redact( $parts ) ), '', '| What is proved | Job | Result | Counts |', '| --- | --- | --- | --- |' );

		foreach ( $declaration->proofs() as $proof => $job ) {
			$outputs = self::outputs( $declaration, $needs, $job );
			$lines[] = sprintf( '| %1$s | %2$s | %3$s | %4$s |', $proof, $job, self::shownResult( $declaration, $needs, $job, $outputs ), self::counts( $outputs ) );
		}

		$lines[] = '';
		$lines[] = '| Job | Adapter | SEOCart | Run facts |';
		$lines[] = '| --- | --- | --- | --- |';

		foreach ( array_keys( $declaration->jobs() ) as $job ) {
			$outputs = self::outputs( $declaration, $needs, $job );
			$lines[] = sprintf( '| %1$s | %2$s | %3$s | %4$s |', $job, self::head( $outputs['ext-head'] ?? '' ), self::head( $outputs['core-head'] ?? '' ), self::facts( $declaration, $outputs ) );
		}

		if ( array() !== $problems ) {
			$lines[] = '';

			foreach ( $problems as $problem ) {
				$lines[] = '- ' . $problem;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns a job's result as the table shows it: the result, or, for the optional part's job that succeeded saying its secret is absent, that it was skipped and why.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration       $declaration The extension's declaration.
	 * @param array<mixed>          $needs       The jobs' results and outputs.
	 * @param string                $job         The job.
	 * @param array<string, string> $outputs     The job's outputs.
	 * @return string The result shown.
	 */
	private static function shownResult( LiveDeclaration $declaration, array $needs, string $job, array $outputs ): string {
		$result   = self::result( $needs, $job );
		$optional = $declaration->optionalPart();

		if ( null !== $optional && $optional['job'] === $job && 'success' === $result && LiveDeclaration::ABSENT === ( $outputs[ $optional['output'] ] ?? '' ) ) {
			return 'skipped: ' . $optional['reason'];
		}

		return $result;
	}

	/**
	 * Returns a job's result: success, failure, cancelled or skipped; `missing` when the run has no such job.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $needs The jobs' results and outputs.
	 * @param string       $job   The job.
	 * @return string The result.
	 */
	private static function result( array $needs, string $job ): string {
		$result = $needs[ $job ]['result'] ?? null;

		return is_string( $result ) && 1 === preg_match( '/^[a-z_]{1,20}$/D', $result ) ? $result : 'missing';
	}

	/**
	 * Returns a job's outputs, each a short line of text the guard has seen.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param array<mixed>    $needs       The jobs' results and outputs.
	 * @param string          $job         The job.
	 * @return array<string, string> The outputs, by name.
	 */
	private static function outputs( LiveDeclaration $declaration, array $needs, string $job ): array {
		$outputs = array();

		foreach ( (array) ( $needs[ $job ]['outputs'] ?? array() ) as $name => $value ) {
			if ( is_string( $value ) ) {
				$outputs[ (string) $name ] = $declaration->guard()->redact( str_replace( array( "\n", '|' ), ' ', $value ) );
			}
		}

		return $outputs;
	}

	/**
	 * Describes a job's counts.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $outputs The job's outputs.
	 * @return string For example `17 tests, 307 assertions, 2 skipped`; empty without counts.
	 */
	private static function counts( array $outputs ): string {
		$counts = array();

		foreach ( array( 'tests', 'assertions', 'skipped' ) as $count ) {
			if ( isset( $outputs[ $count ] ) && '' !== $outputs[ $count ] ) {
				$counts[] = $outputs[ $count ] . ' ' . $count;
			}
		}

		return implode( ', ', $counts );
	}

	/**
	 * Describes a job's facts of the run: its nonce, then the facts the declaration names.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration       $declaration The extension's declaration.
	 * @param array<string, string> $outputs     The job's outputs.
	 * @return string The facts it reported.
	 */
	private static function facts( LiveDeclaration $declaration, array $outputs ): string {
		$facts = array();

		foreach ( array_merge( array( 'nonce' ), $declaration->facts() ) as $fact ) {
			if ( isset( $outputs[ $fact ] ) && '' !== $outputs[ $fact ] ) {
				$facts[] = $fact . ': ' . $outputs[ $fact ];
			}
		}

		return implode( '; ', $facts );
	}

	/**
	 * Shows a commit, or says it is not known.
	 *
	 * @since 0.2.0
	 *
	 * @param string $head The commit, as `git rev-parse` prints it.
	 * @return string The commit in code, or `not reported`.
	 */
	private static function head( string $head ): string {
		return 1 === preg_match( '/^[0-9a-f]{40}$/D', $head ) ? '`' . $head . '`' : 'not reported';
	}
}
