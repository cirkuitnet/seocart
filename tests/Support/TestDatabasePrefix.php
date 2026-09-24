<?php
/**
 * TestDatabasePrefix: drops every table of the configured prefix before WordPress is loaded
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * A killed `WP_MULTISITE=1` run can leave four kinds of state behind that the next run then
 * depends on: a deleted site's own tables; the main site's plugin tables, which a later
 * `WP_UnitTestCase`-based test then treats as already installed; pending scheduled actions; and
 * roles a fresh install's `populate_roles()` writes back from what WordPress read into memory
 * before its own installer dropped the table they came from. Dropping every table of the
 * configured prefix, before WordPress is loaded in this process at all, removes all four at
 * once: there is nothing left for that later boot to read or reuse.
 *
 * The test configuration's own template already says its database is "used by nothing else:
 * installing the test site DROPS ITS TABLES"; this is the same hazard, extended to every table
 * of the prefix rather than only the ones WordPress's own installer knows about.
 *
 * `$wpdb` does not exist yet at the point this runs, so the connection is a plain `mysqli`, in
 * the same mode WordPress's own `wpdb::db_connect()` uses (`mysqli_report( MYSQLI_REPORT_OFF )`,
 * so a failure is a return value here, never a thrown `mysqli_sql_exception`).
 *
 * @since 0.1.0
 */
final class TestDatabasePrefix {

	/**
	 * The five tokens the configuration template promises, each on its own `define()` line, and
	 * the table prefix, a plain assignment. Every value is a single-quoted string with no quote,
	 * backslash or line break inside it (the template forbids exactly that), so a plain,
	 * non-executing pattern is enough; there is no need to run the file to read it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const TOKEN_PATTERNS = array(
		'name'     => '/define\(\s*\'DB_NAME\'\s*,\s*\'([^\']*)\'\s*\)/',
		'user'     => '/define\(\s*\'DB_USER\'\s*,\s*\'([^\']*)\'\s*\)/',
		'password' => '/define\(\s*\'DB_PASSWORD\'\s*,\s*\'([^\']*)\'\s*\)/',
		'host'     => '/define\(\s*\'DB_HOST\'\s*,\s*\'([^\']*)\'\s*\)/',
		'prefix'   => '/\$table_prefix\s*=\s*\'([^\']*)\'/',
	);

	/**
	 * Reads the database settings a test configuration file declares, without executing it.
	 *
	 * Never `require`s the file: wp-phpunit's own bootstrap loads it again later (as the real
	 * target of a fixed shim it always requires), with a plain `require`, not `require_once`, so
	 * a first, earlier load here would make every `define()` in it run twice and warn on the
	 * second. Reading the source as text and matching each token avoids that entirely.
	 *
	 * Comments are stripped first, with PHP's own tokenizer, so a commented-out `define()` or
	 * `$table_prefix` line above the real one is never read as the real one: a plain regular
	 * expression over the raw source cannot tell a comment from code, and this file is exactly
	 * the kind that collects a commented-out previous value above a current one. Each token must
	 * then appear exactly once in what is left; two real, uncommented matches means the file is
	 * not shaped the way this class assumes, and reading either one could run against the wrong
	 * database, so both are refused rather than one being guessed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The configuration file's path.
	 * @return array{name: string, user: string, password: string, host: string, prefix: string} The settings.
	 *
	 * @throws \RuntimeException If the file cannot be read, a token is missing, or a token appears more than once.
	 */
	public static function readConfig( string $path ): array {
		$source = file_get_contents( $path );

		if ( false === $source ) {
			throw new \RuntimeException( "TestDatabasePrefix could not read {$path}." );
		}

		$source = self::withoutComments( $source );

		$values = array();

		foreach ( self::TOKEN_PATTERNS as $key => $pattern ) {
			$count = preg_match_all( $pattern, $source, $matches );

			if ( 0 === $count ) {
				throw new \RuntimeException( "TestDatabasePrefix could not find {$key} in {$path}." );
			}

			if ( $count > 1 ) {
				throw new \RuntimeException( "TestDatabasePrefix found {$key} more than once in {$path}; refusing to guess which one is real." );
			}

			$values[ $key ] = $matches[1][0];
		}

		return array(
			'name'     => $values['name'],
			'user'     => $values['user'],
			'password' => $values['password'],
			'host'     => $values['host'],
			'prefix'   => $values['prefix'],
		);
	}

	/**
	 * Drops every table of the given prefix in the given database, and reports what it dropped.
	 *
	 * Matches by comparing the start of each table name to the prefix in PHP (`str_starts_with()`),
	 * never with a `LIKE` pattern and never with `str_contains()`: a `LIKE` pattern would need `_`
	 * and `%` inside the prefix escaped to stay literal, and a table whose name merely contains the
	 * prefix somewhere past its first character is not one this prefix owns.
	 *
	 * @since 0.1.0
	 *
	 * @param array{name: string, user: string, password: string, host: string, prefix: string} $config As `readConfig()` returns.
	 * @return list<string> The dropped table names, sorted.
	 *
	 * @throws \RuntimeException If the prefix is empty, or the connection, the listing or a drop fails.
	 */
	public static function dropAll( array $config ): array {
		if ( '' === $config['prefix'] ) {
			throw new \RuntimeException( 'TestDatabasePrefix::dropAll() refuses to run with an empty table prefix: that would match every table in the database.' );
		}

		list( $hostname, $port, $socket ) = self::parseHost( $config['host'] );

		/*
		 * $wpdb does not exist yet; see the class docblock. Every mysqli_* call below is the one
		 * place in the test suite allowed to reach past it, so each is named to phpcs individually
		 * rather than silencing the sniff for the rest of the method.
		 */
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- $wpdb does not exist yet; see the class docblock.
		mysqli_report( MYSQLI_REPORT_OFF );

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- $wpdb does not exist yet; see the class docblock.
		$mysqli = mysqli_init();

		if ( false === $mysqli ) {
			throw new \RuntimeException( 'TestDatabasePrefix could not initialise a MySQL connection.' );
		}

		if ( ! $mysqli->real_connect( $hostname, $config['user'], $config['password'], $config['name'], $port ?? 0, $socket ?? '' ) ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- $wpdb does not exist yet; see the class docblock.
			throw new \RuntimeException( 'TestDatabasePrefix could not connect to the test database: ' . mysqli_connect_error() );
		}

		$result = $mysqli->query( 'SHOW TABLES' );

		if ( ! $result instanceof \mysqli_result ) {
			$error = $mysqli->error;
			$mysqli->close();

			throw new \RuntimeException( "TestDatabasePrefix could not list the test database's tables: {$error}" );
		}

		$stale = array();

		while ( true ) {
			$row = $result->fetch_row();

			if ( null === $row ) {
				break;
			}

			$tableName = (string) $row[0];

			if ( str_starts_with( $tableName, $config['prefix'] ) ) {
				$stale[] = $tableName;
			}
		}

		sort( $stale );

		foreach ( $stale as $table ) {
			if ( ! $mysqli->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' ) ) {
				$error = $mysqli->error;
				$mysqli->close();

				throw new \RuntimeException( "TestDatabasePrefix could not drop {$table}: {$error}" );
			}
		}

		$mysqli->close();

		return $stale;
	}

	/**
	 * Splits a `DB_HOST` value into a hostname and, at most, one of a port or a socket path.
	 *
	 * Supports the forms the configuration template's own comment promises: a bare host, `host:port`,
	 * and `host:/path/to/socket` (the form this project's own dev tooling generates). A bracketed
	 * IPv6 host (`[::1]:3306`) is also read correctly. Anything after a colon that is neither all
	 * digits nor an absolute path is still passed on as a socket path, the same latitude `wpdb`'s
	 * own host parsing gives it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $host The `DB_HOST` value.
	 * @return array{0: string, 1: int|null, 2: string|null} The hostname, then the port or the socket, whichever applies.
	 */
	public static function parseHost( string $host ): array {
		if ( '' === $host ) {
			return array( 'localhost', null, null );
		}

		if ( 1 === preg_match( '/^\[(?<host>[^\]]+)\](?::(?<rest>.+))?$/', $host, $matches ) ) {
			$hostname = $matches['host'];
			$rest     = $matches['rest'] ?? '';
		} elseif ( 1 === preg_match( '/^(?<host>[^:]*):(?<rest>.+)$/', $host, $matches ) ) {
			$hostname = '' === $matches['host'] ? 'localhost' : $matches['host'];
			$rest     = $matches['rest'];
		} else {
			return array( $host, null, null );
		}

		if ( '' === $rest ) {
			return array( $hostname, null, null );
		}

		if ( str_starts_with( $rest, '/' ) ) {
			return array( $hostname, null, $rest );
		}

		if ( 1 === preg_match( '/^\d+$/', $rest ) ) {
			return array( $hostname, (int) $rest, null );
		}

		return array( $hostname, null, $rest );
	}

	/**
	 * Removes every comment PHP's own tokenizer finds, replacing each with one space.
	 *
	 * A space, not nothing: two tokens a comment used to separate must not end up joined into
	 * one word by removing the comment outright. Nothing here is executed; `token_get_all()`
	 * only reads the grammar.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source PHP source, starting with `<?php`.
	 * @return string The same source with every `T_COMMENT` and `T_DOC_COMMENT` token blanked.
	 */
	private static function withoutComments( string $source ): string {
		$stripped = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					$stripped .= ' ';

					continue;
				}

				$stripped .= $token[1];

				continue;
			}

			$stripped .= $token;
		}

		return $stripped;
	}
}
