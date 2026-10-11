<?php
/**
 * Tests that no SQL the plugin sends uses a construct MariaDB refuses
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * The plugin runs on MySQL and on MariaDB, and the integration suites run on MySQL alone: a construct only MySQL reads passes every one of them and fails on a MariaDB site.
 *
 * Every string literal under src is read, a run of literals joined by `.` read as one, and none
 * may hold a construct MariaDB 11.8 refuses with a syntax error or an unknown function. Each is
 * listed with its portable form:
 *
 * - `CAST( … AS JSON )` and `CONVERT( …, JSON )`: MariaDB has no JSON type. JSON_EXTRACT( …, '$' )
 *   parses a document on both.
 * - `FOR SHARE`: LOCK IN SHARE MODE takes the same lock on both.
 * - The JSON path operators `->` and `->>`: JSON_EXTRACT() and JSON_UNQUOTE( JSON_EXTRACT() ).
 * - `MEMBER OF ( … )`: JSON_CONTAINS().
 * - The row alias `VALUES ( … ) AS new`, read in ON DUPLICATE KEY UPDATE: VALUES( column ).
 * - `ANY_VALUE()` and `REGEXP_LIKE()`: an aggregate that states the value, and REGEXP.
 * - A word MariaDB reserves and MySQL does not (MARIADB_RESERVED), such as `portion` or `offset`,
 *   as an alias (`AS portion`) or a qualifier (`portion.id`): another name.
 *
 * Each construct was sent to MariaDB 11.8.9, which refused it, and each portable form to it and
 * to MySQL 8.4, which read it; the first test holds the patterns to that. The reserved words are
 * every keyword either server lists in information_schema.KEYWORDS that MariaDB 11.8.9 refused as
 * a bare alias and MySQL 8.4 took.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlIdempotencyKeys::SETTLE_ANSWER, `CAST( NULLIF( %s, '' ) AS JSON )` again in place of
 *   JSON_EXTRACT( NULLIF( %s, '' ), '$' ): the scan names the construct, the file and the line;
 * - in MysqlProductRepository::lockedPostTypeAndStatus(), `FOR SHARE` again in place of LOCK IN
 *   SHARE MODE, in a literal passed to fetchRow() rather than a constant: the scan names it;
 * - in MysqlRefundRepository::INSERT_CLAIM_LINES, the derived row's alias `portion` again in place
 *   of `given`: the scan names the reserved word;
 * - the one named on the test of literals split across `.`.
 *
 * @since 0.2.0
 */
final class PortableSqlTest extends TestCase {

	/**
	 * The words MariaDB 11.8 reserves and MySQL 8.4 does not, as a pattern's alternation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MARIADB_RESERVED = 'current_role|delete_domain_id|do_domain_ids|ignore_domain_ids|master_demote_to_replica|master_demote_to_slave|offset|page_checksum|parse_vcol_expr|portion|ref_system_id|returning|sql_after_gtids|sql_before_gtids|stats_auto_recalc|stats_persistent|stats_sample_pages|vector';

	/**
	 * The constructs MariaDB refuses, by the name a failure gives each: a pattern over a literal's text, as PHP reads it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const REFUSED = array(
		'CAST( … AS JSON )'           => '/\bCAST\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*?\bAS\s+JSON\s*\)/i',
		'CONVERT( …, JSON )'          => '/\bCONVERT\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*?,\s*JSON\s*\)/i',
		'FOR SHARE'                   => '/\bFOR\s+SHARE\b/',
		'the JSON operator -> or ->>' => '/[\w`)\]]\s*->>?\s*[\'"]\$/',
		'MEMBER OF'                   => '/\bMEMBER\s+OF\s*\(/i',
		'the row alias VALUES … AS'   => '/\bVALUES\s*(?<row>\((?:[^()\'"]++|\'[^\']*\'|"[^"]*"|(?&row))*\))(?:\s*,\s*(?&row))*\s+AS\s+[A-Za-z_]\w*/i',
		'ANY_VALUE()'                 => '/\bANY_VALUE\s*\(/i',
		'REGEXP_LIKE()'               => '/\bREGEXP_LIKE\s*\(/i',
		'a word MariaDB reserves'     => '/\bAS\s+(?:' . self::MARIADB_RESERVED . ')\b|(?<![\w$`])(?:' . self::MARIADB_RESERVED . ')\.(?=[A-Za-z_])/i',
	);

	/**
	 * Tests that each pattern finds its construct and leaves the portable form alone.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_statements
	 *
	 * @param string $sql      A statement.
	 * @param string $expected The construct it uses, or '' for a portable one.
	 */
	public function test_each_construct_is_found_and_its_portable_form_is_not( string $sql, string $expected ): void {
		$this->assertSame( '' === $expected ? array() : array( $expected ), array_keys( self::refused( $sql ) ), $sql );
	}

	/**
	 * Statements MariaDB 11.8.9 refused, each beside a form both servers read.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string}> The statement, and the construct it uses or ''.
	 */
	public static function data_statements(): array {
		return array(
			'cast as json'               => array( "UPDATE t SET j = JSON_SET( j, '$.a', CAST( NULLIF( %s, '' ) AS JSON ) )", 'CAST( … AS JSON )' ),
			'json_extract of a document' => array( "UPDATE t SET j = JSON_SET( j, '$.a', JSON_EXTRACT( NULLIF( %s, '' ), '$' ) )", '' ),
			'cast as char'               => array( 'SELECT CAST( COALESCE( n, 0 ) AS CHAR ) FROM t', '' ),
			'convert to json'            => array( "SELECT CONVERT( '{}', JSON )", 'CONVERT( …, JSON )' ),
			'convert using'              => array( 'SELECT CONVERT( name USING utf8mb4 ) FROM t', '' ),
			'for share'                  => array( 'SELECT id FROM t WHERE id = %d FOR SHARE', 'FOR SHARE' ),
			'lock in share mode'         => array( 'SELECT id FROM t WHERE id = %d LOCK IN SHARE MODE', '' ),
			'for update skip locked'     => array( 'SELECT id FROM t WHERE id = %d FOR UPDATE SKIP LOCKED', '' ),
			'arrow'                      => array( "SELECT j->'$.a' FROM t", 'the JSON operator -> or ->>' ),
			'double arrow'               => array( "SELECT t.j ->> '$.a' FROM t", 'the JSON operator -> or ->>' ),
			'json_unquote'               => array( "SELECT JSON_UNQUOTE( JSON_EXTRACT( j, '$.a' ) ) FROM t", '' ),
			'member of'                  => array( 'SELECT id FROM t WHERE 3 MEMBER OF ( j )', 'MEMBER OF' ),
			'row alias'                  => array( 'INSERT INTO t ( id, n ) VALUES ( %d, COALESCE( NULLIF( %d, 0 ), 1 ) ) AS new ON DUPLICATE KEY UPDATE n = new.n', 'the row alias VALUES … AS' ),
			'values function'            => array( 'INSERT INTO t ( id, n ) VALUES ( %d, %d ) ON DUPLICATE KEY UPDATE n = VALUES( n )', '' ),
			'derived table alias'        => array( 'INSERT INTO t ( id, n ) SELECT d.id, d.n FROM ( SELECT 1 AS id, 2 AS n ) AS d ON DUPLICATE KEY UPDATE n = d.n', '' ),
			'any_value'                  => array( 'SELECT ANY_VALUE( n ) FROM t GROUP BY id', 'ANY_VALUE()' ),
			'regexp_like'                => array( "SELECT id FROM t WHERE REGEXP_LIKE( name, '^a' )", 'REGEXP_LIKE()' ),
			'regexp'                     => array( "SELECT id FROM t WHERE name REGEXP '^a'", '' ),
			'a reserved alias'           => array( 'INSERT INTO t ( id ) SELECT portion.id FROM ( SELECT %d AS id ) AS portion', 'a word MariaDB reserves' ),
			'a reserved column alias'    => array( 'SELECT TIMESTAMPDIFF( SECOND, a, b ) MOD 60 AS offset FROM t', 'a word MariaDB reserves' ),
			'another alias'              => array( 'INSERT INTO t ( id ) SELECT given.id FROM ( SELECT %d AS id ) AS given', '' ),
			'offset as a keyword'        => array( 'SELECT id FROM t ORDER BY id LIMIT 10 OFFSET 20', '' ),
		);
	}

	/**
	 * Tests that no string literal under src holds a construct MariaDB refuses.
	 *
	 * @since 0.2.0
	 */
	public function test_no_sql_under_src_uses_a_construct_mariadb_refuses(): void {
		$found = array();

		foreach ( PhpSource::files( 'src' ) as $path => $source ) {
			$found = array_merge( $found, self::findings( $path, $source ) );
		}

		$this->assertSame( array(), $found, 'SQL only MySQL reads fails on a MariaDB site; write the portable form this test\'s class description names.' );
	}

	/**
	 * Tests that a construct split across literals joined by `.` is found as one statement, whatever white space, line break or comment stands between a literal and the `.`.
	 *
	 * Planted violation: in literalRuns(), read every token (`\PhpToken::tokenize( $source )` in
	 * place of PhpSource::tokens()), white space and comments included: a run then ends at the space
	 * before the `.`, and each split construct goes unseen.
	 *
	 * @since 0.2.0
	 */
	public function test_a_construct_split_across_joined_literals_is_found(): void {
		$source = "<?php\n"
			. "const SPACED = 'SELECT id FROM t WHERE id = 1 FOR ' . 'SHARE';\n"
			. "const COMMENTED = 'SELECT id FROM t WHERE id = 1 FOR '\n\t/* the lock */ . 'SHARE';\n"
			. "const BROKEN = 'SELECT id FROM t WHERE id = 1 FOR ' // the lock\n\t. 'SHARE';\n";

		$this->assertSame(
			array( 'split.php:2 uses FOR SHARE', 'split.php:3 uses FOR SHARE', 'split.php:5 uses FOR SHARE' ),
			array_map( static fn( string $finding ): string => (string) strstr( $finding, ', which', true ), self::findings( 'split.php', $source ) )
		);
	}

	/**
	 * Finds the constructs MariaDB refuses in one file's string literals.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path   The file, as the findings name it.
	 * @param string $source Its PHP source.
	 * @return list<string> Each construct found, with its line and the words around it.
	 */
	private static function findings( string $path, string $source ): array {
		$found = array();

		foreach ( self::literalRuns( $source ) as $run ) {
			foreach ( self::refused( $run['text'] ) as $construct => $offset ) {
				$found[] = sprintf( '%1$s:%2$d uses %3$s, which MariaDB refuses: %4$s', $path, self::lineAt( $run, $offset ), $construct, self::excerpt( $run['text'], $offset ) );
			}
		}

		return $found;
	}

	/**
	 * Finds the constructs MariaDB refuses in a text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text, as PHP reads the literal.
	 * @return array<string, int> The byte offset of each construct's first use, by its name.
	 */
	private static function refused( string $text ): array {
		$found = array();

		foreach ( self::REFUSED as $construct => $pattern ) {
			if ( 1 === preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
				$found[ $construct ] = $match[0][1];
			}
		}

		return $found;
	}

	/**
	 * Reads a file's string literals, a run of literals joined by `.` as one text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source The PHP source.
	 * @return list<array{text: string, parts: list<array{start: int, line: int}>}> Each run: its text, and where each of its
	 *                                                                             literals starts in it and in the file.
	 */
	private static function literalRuns( string $source ): array {
		$runs    = array();
		$current = null;
		$joined  = false;

		foreach ( PhpSource::tokens( $source ) as $token ) {
			if ( $token->is( array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ) ) ) {
				if ( null !== $current && ! $joined ) {
					$runs[]  = $current;
					$current = null;
				}

				$current ??= array(
					'text'  => '',
					'parts' => array(),
				);

				$current['parts'][] = array(
					'start' => strlen( $current['text'] ),
					'line'  => $token->line,
				);
				$current['text']   .= self::valueOf( $token );
				$joined             = false;
				continue;
			}

			// An interpolated literal's quotes and variables, and the `.` between literals, keep the run going.
			$joined = null !== $current && ( '.' === $token->text || '"' === $token->text || $token->is( array( T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_STRING_VARNAME, T_START_HEREDOC, T_END_HEREDOC ) ) );

			if ( ! $joined && null !== $current ) {
				$runs[]  = $current;
				$current = null;
			}
		}

		if ( null !== $current ) {
			$runs[] = $current;
		}

		return $runs;
	}

	/**
	 * Returns a literal's text as PHP reads it: its quotes removed and its escapes resolved, so `\'` in a single-quoted literal is the quote SQL sees.
	 *
	 * @since 0.2.0
	 *
	 * @param \PhpToken $token A string literal, or the text part of an interpolated one.
	 * @return string The text.
	 */
	private static function valueOf( \PhpToken $token ): string {
		$raw = $token->text;

		if ( $token->is( T_ENCAPSED_AND_WHITESPACE ) ) {
			return stripcslashes( $raw );
		}

		$body = substr( $raw, 1, -1 );

		if ( '"' === $raw[0] ) {
			return stripcslashes( $body );
		}

		// A single-quoted literal knows two escapes: the backslash and the quote.
		return strtr(
			$body,
			array(
				'\\\\' => '\\',
				'\\\'' => '\'',
			)
		);
	}

	/**
	 * Returns the line of the source an offset into a run's text is on.
	 *
	 * @since 0.2.0
	 *
	 * @param array{text: string, parts: list<array{start: int, line: int}>} $run    The run.
	 * @param int                                                            $offset A byte offset into its text.
	 * @return int The line.
	 */
	private static function lineAt( array $run, int $offset ): int {
		$part = $run['parts'][0];

		foreach ( $run['parts'] as $candidate ) {
			if ( $candidate['start'] <= $offset ) {
				$part = $candidate;
			}
		}

		return $part['line'] + substr_count( substr( $run['text'], $part['start'], $offset - $part['start'] ), "\n" );
	}

	/**
	 * Returns the words around an offset, on one line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text   The text.
	 * @param int    $offset Where the construct starts.
	 * @return string About sixty characters, from a little before it.
	 */
	private static function excerpt( string $text, int $offset ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', substr( $text, max( 0, $offset - 20 ), 60 ) ) );
	}
}
