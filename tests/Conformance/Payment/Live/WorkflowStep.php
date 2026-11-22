<?php
/**
 * WorkflowStep: the steps of an extension's live workflow that read what the tests printed or recorded
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Turns what a step's tests printed or recorded into the step's outputs, its run list and its verdict, for bin/ci/extension-live.sh and its lib.
 *
 * Each method returns the exit status of the step, writes `name=value` outputs to the stream it
 * is given, which workflow-step.php points at GITHUB_OUTPUT, and says what is wrong as one
 * `::error::` line each on the messages stream. Nothing it writes holds more than counts, words
 * and ids, each through the guard of the extension's declaration.
 *
 * @since 0.2.0
 */
final class WorkflowStep {

	/**
	 * Copies a text, line by line as it comes, with every value shaped like a credential or a card number blanked out.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param resource        $in          Where the text comes from.
	 * @param resource        $out         Where it goes.
	 * @return int 0.
	 */
	public static function redact( LiveDeclaration $declaration, $in, $out ): int {
		$guard = $declaration->guard();

		for ( $line = fgets( $in ); false !== $line; $line = fgets( $in ) ) {
			fwrite( $out, $guard->redact( $line ) );
			fflush( $out );
		}

		return 0;
	}

	/**
	 * Prints what the shell steps need of the declaration: the release zip's slug, the live tests' group, or a live part's filter.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $what        `slug`, `group` or `filter`.
	 * @param string          $part        For `filter`, the live part.
	 * @param resource        $out         Where the value goes, on a line of its own.
	 * @param resource        $messages    Where the messages go.
	 * @return int 0, or 1 for something the declaration does not say.
	 */
	public static function describe( LiveDeclaration $declaration, string $what, string $part, $out, $messages ): int {
		$parts = $declaration->liveParts();
		$value = match ( $what ) {
			'slug'   => $declaration->slug(),
			'group'  => $declaration->liveGroup(),
			'filter' => $parts[ $part ]['filter'] ?? null,
			default  => null,
		};

		if ( null === $value ) {
			fwrite( $messages, $declaration->guard()->redact( sprintf( "The declaration says nothing of %s: slug, group, or the filter of a live part (%s).\n", trim( $what . ' ' . $part ), implode( ', ', array_keys( $parts ) ) ) ) );

			return 1;
		}

		fwrite( $out, $value . "\n" );

		return 0;
	}

	/**
	 * Checks the conformance suite's run against the provider, and gives its counts as outputs.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $output      What the run printed.
	 * @param resource        $outputs     Where the outputs go.
	 * @param resource        $messages    Where the messages go.
	 * @return int 0 when the run had the replay's tests and skips, 1 otherwise.
	 */
	public static function suite( LiveDeclaration $declaration, string $output, $outputs, $messages ): int {
		$problems  = SuiteOutput::conformanceProblems( $declaration, $output );
		$summaries = SuiteOutput::summaries( $output );
		$last      = end( $summaries );

		if ( false !== $last ) {
			self::write(
				$declaration,
				$outputs,
				array(
					'tests'      => $last['tests'],
					'assertions' => $last['assertions'],
					'skipped'    => $last['skipped'],
				)
			);
		}

		self::write( $declaration, $outputs, array( 'skip-set' => array() === $problems ? 'expected' : 'differs' ) );

		return self::problems( $declaration, $problems, $messages );
	}

	/**
	 * Adds to the run list every object the conformance suite created at the provider, read from the tapes it recorded, so the clean-up ends those it left open.
	 *
	 * Recording, the suite writes a scenario's exchanges anew when the scenario ends. A tape no newer
	 * than the mark the step made before the suite is an earlier recording's: its objects are not
	 * the run's, and are left out. Times are whole seconds, and the step starts the suite in the
	 * second after the mark's, so a tape from the mark's own second is left out too. Which objects
	 * an exchange created, and the store uuid each was created for, is the declaration's to say
	 * (LiveDeclaration::createdBy()); the clean-up checks the uuid against the provider's own copy
	 * before it ends anything. A scenario that failed before its end wrote no tape, so the objects
	 * it created are not found.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $tapes       The suite's tape directory.
	 * @param string          $mark        A file the step made a second before the suite ran.
	 * @param string          $runList     The run list, added to.
	 * @param resource        $messages    Where the messages go.
	 * @return int 0, or 1 when the mark or the run list is missing.
	 */
	public static function harvest( LiveDeclaration $declaration, string $tapes, string $mark, string $runList, $messages ): int {
		$since = is_file( $mark ) ? filemtime( $mark ) : false;

		if ( false === $since || ! is_file( $runList ) || ! is_writable( $runList ) ) {
			return self::problems( $declaration, array( 'The objects the suite created cannot be listed: the mark made before the suite, or the run list, is missing.' ), $messages );
		}

		$recorded = array();
		$files    = glob( $tapes . '/*/*.json' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( filemtime( $file ) > $since ) {
				$recorded[] = (array) json_decode( (string) file_get_contents( $file ), true );
			}
		}

		$created = $declaration->createdBy( $recorded );

		file_put_contents( $runList, implode( '', array_map( array( LiveRun::class, 'line' ), array_keys( $created ), $created ) ), FILE_APPEND | LOCK_EX );
		fwrite( $messages, $declaration->guard()->redact( sprintf( "The run list: %1\$d objects the suite created at the provider, read from the %2\$d tape files the run wrote.\n", count( $created ), count( $recorded ) ) ) );

		return 0;
	}

	/**
	 * Checks a run of a part's live tests, which must all run and pass, none skipped, and gives its counts and its report's facts as outputs.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $part        The part: a live part of the declaration.
	 * @param string          $output      What the run printed.
	 * @param string          $report      The run's report.
	 * @param resource        $outputs     Where the outputs go.
	 * @param resource        $messages    Where the messages go.
	 * @return int 0 when every live test of the part passed, 1 otherwise.
	 */
	public static function live( LiveDeclaration $declaration, string $part, string $output, string $report, $outputs, $messages ): int {
		$problems = SuiteOutput::liveProblems( $declaration, $output, $part );
		$last     = SuiteOutput::summaries( $output );
		$last     = end( $last );

		if ( false !== $last ) {
			self::write(
				$declaration,
				$outputs,
				array(
					'tests'      => $last['tests'],
					'assertions' => $last['assertions'],
					'skipped'    => $last['skipped'],
				)
			);
		}

		$facts = LiveRun::facts( $report );

		self::write( $declaration, $outputs, array_intersect_key( $facts, array_flip( $declaration->facts() ) ) );

		foreach ( $facts as $key => $value ) {
			fwrite( $messages, $declaration->guard()->redact( sprintf( "%s: %s\n", $key, $value ) ) );
		}

		return self::problems( $declaration, $problems, $messages );
	}

	/**
	 * Says whether the secret the declaration's optional part needs is set, as an output, never the secret: without it, the part runs nothing and says why.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $job         The job the step runs in, which must be the optional part's.
	 * @param string          $secret      The secret's value, as the step's env gives it; empty when the secret is not set.
	 * @param resource        $outputs     Where the outputs go.
	 * @param resource        $messages    Where the messages go.
	 * @return int 0, or 1 when the job is not the optional part's.
	 */
	public static function optional( LiveDeclaration $declaration, string $job, #[\SensitiveParameter] string $secret, $outputs, $messages ): int {
		$optional = $declaration->optionalPart();

		if ( null === $optional || $optional['job'] !== $job ) {
			return self::problems( $declaration, array( sprintf( 'The job %1$s is not the optional part of %2$s, which is %3$s.', $job, LiveDeclaration::FILE, null === $optional ? 'none' : $optional['job'] ) ), $messages );
		}

		$present = '' !== trim( $secret );

		self::write( $declaration, $outputs, array( $optional['output'] => $present ? LiveDeclaration::PRESENT : LiveDeclaration::ABSENT ) );

		if ( ! $present ) {
			fwrite( $messages, $declaration->guard()->redact( sprintf( "::notice title=The %1\$s part::The %1\$s part is skipped: %2\$s. The other parts run.\n", $job, $optional['reason'] ) ) );
		}

		return 0;
	}

	/**
	 * Judges the run from its jobs' results and outputs, and writes its summary.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $needs       The jobs' results and outputs, as `toJSON(needs)` gives them.
	 * @param string          $parts       The run's `parts` input.
	 * @param string          $title       The summary's title, the workflow's name; empty for the workflow's basename.
	 * @param resource        $summary     Where the summary goes.
	 * @param resource        $messages    Where the messages go.
	 * @return int 0 for a green run, 1 for a red one.
	 */
	public static function verdict( LiveDeclaration $declaration, string $needs, string $parts, string $title, $summary, $messages ): int {
		$verdict = Verdict::judge( $declaration, (array) json_decode( $needs, true ), $parts, '' === $title ? $declaration->basename() : $title );

		fwrite( $summary, $verdict['summary'] );

		return self::problems( $declaration, $verdict['problems'], $messages );
	}

	/**
	 * Writes outputs, one `name=value` line each.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration           $declaration The extension's declaration.
	 * @param resource                  $outputs     Where they go.
	 * @param array<string, string|int> $values      The values, by name.
	 */
	private static function write( LiveDeclaration $declaration, $outputs, array $values ): void {
		foreach ( $values as $name => $value ) {
			fwrite( $outputs, $declaration->guard()->redact( sprintf( "%s=%s\n", $name, str_replace( array( "\r", "\n" ), ' ', (string) $value ) ) ) );
		}
	}

	/**
	 * Writes each problem as an error the workflow shows, and returns the step's status.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $problems    The problems.
	 * @param resource        $messages    Where they go.
	 * @return int 0 for none, 1 otherwise.
	 * @phpstan-param list<string> $problems
	 */
	private static function problems( LiveDeclaration $declaration, array $problems, $messages ): int {
		foreach ( $problems as $problem ) {
			fwrite( $messages, $declaration->guard()->redact( '::error::' . $problem . "\n" ) );
		}

		return array() === $problems ? 0 : 1;
	}
}
