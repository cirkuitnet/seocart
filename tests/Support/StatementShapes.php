<?php
/**
 * StatementShapes: the statements of a query log with their literals taken out, to compare the work of two requests
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Turns the statements two requests sent into what must be equal when the requests do the same work.
 *
 * Owns one fact for the tests: what "the same statements" means when two requests differ only in
 * the values they carry. Every quoted string and every number standing alone becomes `?`, and runs
 * of white space become one space; the tables, the columns, the clauses and their order stay. Two
 * requests whose answers must not tell an address with an account from one without, or a key that
 * is someone else's from one never seen, send logs whose shapes are equal in count and in order.
 *
 * @since 0.2.0
 */
final class StatementShapes {

	/**
	 * Cannot be called: the shapes are a static function.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the shape of every statement of a log, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The statements.
	 * @return list<string> Their shapes.
	 */
	public static function of( QueryLog $log ): array {
		return array_map( self::shape( ... ), $log->sqls() );
	}

	/**
	 * Returns a statement's shape: its literals as `?`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sql The statement.
	 * @return string The shape.
	 */
	public static function shape( string $sql ): string {
		return trim( (string) preg_replace( array( "/'(?:[^'\\\\]|\\\\.)*'/s", '/(?<![\w.])-?\d+(?:\.\d+)?(?![\w.])/', '/\s+/' ), array( '?', '?', ' ' ), $sql ) );
	}
}
