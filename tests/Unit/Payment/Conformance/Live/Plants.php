<?php
/**
 * Plants: the edits a test makes to a file's lines in memory, to break one fact and see it found
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

/**
 * Inserts, replaces and removes the line a pattern finds, failing the test when none does: a plant must break the file.
 *
 * @since 0.2.0
 */
trait Plants {

	/**
	 * Inserts a line after the first that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line after which it goes.
	 * @param string   $line    The line.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function after( array $lines, string $pattern, string $line ): array {
		array_splice( $lines, self::find( $lines, $pattern ) + 1, 0, array( $line ) );

		return $lines;
	}

	/**
	 * Replaces the first line that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line replaced.
	 * @param string   $line    The line put in its place.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function replaced( array $lines, string $pattern, string $line ): array {
		$lines[ self::find( $lines, $pattern ) ] = $line;

		return $lines;
	}

	/**
	 * Removes the first line that matches, or the last.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The line removed.
	 * @param bool     $last    Optional. True to remove the last line that matches. Default false.
	 * @return list<string> The lines, planted.
	 * @phpstan-param list<string> $lines
	 */
	private static function without( array $lines, string $pattern, bool $last = false ): array {
		array_splice( $lines, $last ? count( $lines ) - 1 - self::find( array_reverse( $lines ), $pattern ) : self::find( $lines, $pattern ), 1 );

		return $lines;
	}

	/**
	 * Finds the first line that matches, failing the test when none does.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines   The lines.
	 * @param string   $pattern The pattern.
	 * @return int The line's index.
	 * @phpstan-param list<string> $lines
	 */
	private static function find( array $lines, string $pattern ): int {
		foreach ( $lines as $at => $line ) {
			if ( 1 === preg_match( $pattern, $line ) ) {
				return $at;
			}
		}

		self::fail( sprintf( 'The plant found no line matching %s.', $pattern ) );
	}

	/**
	 * Returns a workflow written four spaces a level re-indented to two, a step's keys still two past its dash, as YAML requires.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The workflow's lines.
	 * @return list<string> The lines, at two spaces a level.
	 * @phpstan-param list<string> $lines
	 */
	private static function twoSpaces( array $lines ): array {
		$steps = null;

		foreach ( $lines as $at => $line ) {
			$width = strlen( $line ) - strlen( ltrim( $line, ' ' ) );

			if ( null !== $steps && '' !== trim( $line ) && $width <= $steps ) {
				$steps = null;
			}

			// Inside a job's steps, what stands past a step's dash moves with the dash, two columns after it.
			$lines[ $at ] = str_repeat( ' ', null !== $steps && $width > $steps + 4 ? intdiv( $width, 2 ) + 1 : intdiv( $width, 2 ) ) . ltrim( $line, ' ' );

			if ( 1 === preg_match( '/^(\s*)steps:\s*$/', $line, $match ) ) {
				$steps = strlen( $match[1] );
			}
		}

		return $lines;
	}

	/**
	 * Returns a workflow with one job's steps written as a list at its `steps:` key's own indentation, as YAML allows.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The workflow's lines.
	 * @param string   $job   The job.
	 * @return list<string> The lines.
	 * @phpstan-param list<string> $lines
	 */
	private static function indentless( array $lines, string $job ): array {
		$steps = self::find( $lines, '/^    ' . preg_quote( $job, '/' ) . ':$/' );
		$total = count( $lines );

		while ( 1 !== preg_match( '/^        steps:$/', $lines[ $steps ] ) ) {
			++$steps;
		}

		for ( $at = $steps + 1; $at < $total; $at++ ) {
			if ( '' === trim( $lines[ $at ] ) ) {
				continue;
			}

			if ( ! str_starts_with( $lines[ $at ], '         ' ) ) {
				break;
			}

			$lines[ $at ] = substr( $lines[ $at ], 4 );
		}

		return $lines;
	}

	/**
	 * Returns a file's lines.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file.
	 * @return list<string> The lines.
	 */
	private static function linesOf( string $file ): array {
		$lines = file( $file, FILE_IGNORE_NEW_LINES );

		self::assertIsArray( $lines, $file . ' cannot be read.' );

		return $lines;
	}
}
