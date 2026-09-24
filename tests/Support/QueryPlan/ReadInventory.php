<?php
/**
 * ReadInventory: the SELECT statements a module's source writes, found without running it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

/**
 * The reads a module can send, so that the query-plan run can prove it sent, and judged, each one.
 *
 * Owns one fact: which reads a module's source holds. Every string literal under the module's
 * directory that begins with SELECT is the head of one read: the whole statement, or its first
 * piece when the rest is appended at run time, such as an IN list. A head matches a statement
 * the run sent when the statement's shape begins with the head's shape, each table token of the
 * head — `%i`, or a `{name}` the statement expands — standing for any table.
 *
 * Compared with the run both ways: a head no statement matches is a read the run did not send;
 * a statement of the run that names one of the module's tables and matches no head is a read
 * of those tables from somewhere the inventory does not look.
 *
 * @since 0.1.0
 */
final class ReadInventory {

	/**
	 * What stands for a table in a head's shape.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TABLE = '{table}';

	/**
	 * Finds the heads of the reads under a directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory The module's source directory.
	 * @return array<string, string> Each head's shape, keyed by where it is written: `File.php:line`.
	 */
	public static function of( string $directory ): array {
		$heads = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source files of the plugin.
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match( '/^[\'"]\s*SELECT\b/i', $token[1] ) ) {
					$heads[ $file->getFilename() . ':' . $token[2] ] = self::shapeOf( stripcslashes( substr( $token[1], 1, -1 ) ) );
				}
			}
		}

		ksort( $heads );

		return $heads;
	}

	/**
	 * Lists the heads no statement of the run matches: reads the run did not send.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $heads      The heads, as of() returns them.
	 * @param Statement[]           $statements The plugin SELECTs the run sent.
	 * @return list<string> One line per head: where it is written, and its shape.
	 *
	 * @phpstan-param list<Statement> $statements
	 */
	public static function unsent( array $heads, array $statements ): array {
		$unsent = array();

		foreach ( $heads as $where => $head ) {
			$matched = array_filter( $statements, static fn( Statement $statement ): bool => self::matches( $head, $statement ) );

			if ( array() === $matched ) {
				$unsent[] = $where . ' ' . $head;
			}
		}

		return $unsent;
	}

	/**
	 * Lists the statements of the run that name one of the tables and match no head: reads from outside the inventory.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $heads      The heads, as of() returns them.
	 * @param Statement[]           $statements The plugin SELECTs the run sent.
	 * @param string[]              $tables     The full names of the module's tables.
	 * @return list<string> The shapes of those statements.
	 *
	 * @phpstan-param list<Statement> $statements
	 * @phpstan-param list<string>    $tables
	 */
	public static function unknown( array $heads, array $statements, array $tables ): array {
		$unknown = array();

		foreach ( $statements as $statement ) {
			$named   = array() !== array_intersect( array_values( $statement->tables() ), $tables );
			$matched = array_filter( $heads, static fn( string $head ): bool => self::matches( $head, $statement ) );

			if ( $named && array() === $matched ) {
				$unknown[] = $statement->shape();
			}
		}

		return array_values( array_unique( $unknown ) );
	}

	/**
	 * Tells whether a statement's shape begins with a head's shape.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $head      A head's shape.
	 * @param Statement $statement A statement of the run.
	 * @return bool True when it does.
	 */
	private static function matches( string $head, Statement $statement ): bool {
		return 1 === preg_match( '/^' . str_replace( preg_quote( self::TABLE, '/' ), '\S+', preg_quote( $head, '/' ) ) . '/', $statement->shape() );
	}

	/**
	 * Returns the shape of a head: its placeholders given values, and its table tokens a stand-in, then shaped as a sent statement is.
	 *
	 * @since 0.1.0
	 *
	 * @param string $head The literal's text.
	 * @return string The shape.
	 */
	private static function shapeOf( string $head ): string {
		$text = str_replace( array( '{list}', '%d', '%s' ), array( '0', '0', "'x'" ), $head );
		$text = (string) preg_replace( '/%i|\{[a-z_]+\}/', self::TABLE, $text );

		return ( new Statement( $text, '' ) )->shape();
	}
}
