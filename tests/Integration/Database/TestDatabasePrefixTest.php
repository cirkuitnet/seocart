<?php
/**
 * Tests reading a test configuration's database settings and dropping every table of its prefix
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Database;

use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\TestDatabasePrefix;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This test creates fixture tables directly to stand in for a killed run's leftovers.

/**
 * `readConfig()` is proven against the real configuration this very process is running under:
 * WordPress has already turned its tokens into the `DB_*` constants and `$wpdb->base_prefix`, so
 * the parsed values are checked against those, not against a hand-built fixture. `dropAll()` is
 * proven against the real database too, but under a prefix these tests own, never `wptests_`:
 * dropping every real table out from under the suite that is running this test would end the run.
 *
 * Planted violation: in TestDatabasePrefix::readConfig(), replace the call to
 * `self::withoutComments( $source )` with `$source`, so comments are never stripped.
 * test_read_config_ignores_a_commented_out_define_above_the_real_one then fails: the pattern now
 * matches the commented-out line too, and readConfig() refuses the file as holding the token
 * twice instead of returning the real value.
 *
 * Planted violation: in TestDatabasePrefix::dropAll(), change `str_starts_with( $tableName,
 * $config['prefix'] )` to `str_contains( $tableName, $config['prefix'] )`.
 * test_it_drops_only_tables_of_the_given_prefix then fails, because the decoy whose name merely
 * contains the prefix, not starting with it, is dropped too.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class TestDatabasePrefixTest extends DatabaseTestCase {

	/**
	 * A prefix these tests own for their own fixture tables, distinct from any real one.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PREFIX = 'zzz_test_database_prefix_';

	/**
	 * Every fixture table this test created, dropped in tear_down() whether or not the test left
	 * them behind.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $fixtures = array();

	/**
	 * Every temporary configuration file this test wrote, deleted in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $tempFiles = array();

	/**
	 * Drops whichever fixture tables are still there and deletes any temporary file, then defers
	 * to the base class.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->fixtures as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		$this->fixtures = array();

		foreach ( $this->tempFiles as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		$this->tempFiles = array();

		parent::tear_down();
	}

	/**
	 * Tests that the parser reads the same settings WordPress itself is already running under.
	 *
	 * @since 0.1.0
	 */
	public function test_read_config_matches_what_this_process_actually_connected_with(): void {
		global $wpdb;

		$path = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );

		self::assertIsString( $path, 'The running process always sets this.' );

		$config = TestDatabasePrefix::readConfig( $path );

		$this->assertSame( DB_NAME, $config['name'] );
		$this->assertSame( DB_USER, $config['user'] );
		// Never assertSame() a secret: a failing assertion prints both sides of the diff, and the
		// password would then reach the test report.
		$this->assertTrue( DB_PASSWORD === $config['password'], 'The password read differs.' );
		$this->assertSame( DB_HOST, $config['host'] );
		$this->assertSame( $wpdb->base_prefix, $config['prefix'] );
	}

	/**
	 * Tests that a configuration file missing one of the five tokens is refused by name.
	 *
	 * @since 0.1.0
	 */
	public function test_read_config_names_the_missing_token(): void {
		$path = $this->writeTempConfig(
			"<?php\n" .
			"define( 'DB_NAME', 'x' );\n" .
			"define( 'DB_USER', 'x' );\n" .
			"define( 'DB_PASSWORD', 'x' );\n" .
			"// DB_HOST is missing.\n" .
			"\$table_prefix = 'x_';\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'host' );

		TestDatabasePrefix::readConfig( $path );
	}

	/**
	 * Tests that a commented-out `define()` and a commented-out `$table_prefix`, each above the
	 * real one, are never read as the real value: this is exactly the shape a file that used to
	 * point somewhere else, edited by hand, takes.
	 *
	 * @since 0.1.0
	 */
	public function test_read_config_ignores_a_commented_out_define_above_the_real_one(): void {
		$path = $this->writeTempConfig(
			"<?php\n" .
			"// define( 'DB_NAME', 'wrong_name' );\n" .
			"// define( 'DB_USER', 'wrong_user' );\n" .
			"// define( 'DB_PASSWORD', 'wrong_password' );\n" .
			"// define( 'DB_HOST', 'wrong_host' );\n" .
			"// \$table_prefix = 'wrong_';\n" .
			"define( 'DB_NAME', 'right_name' );\n" .
			"define( 'DB_USER', 'right_user' );\n" .
			"define( 'DB_PASSWORD', 'right_password' );\n" .
			"define( 'DB_HOST', 'right_host' );\n" .
			"\$table_prefix = 'right_';\n"
		);

		$config = TestDatabasePrefix::readConfig( $path );

		$this->assertSame(
			array(
				'name'     => 'right_name',
				'user'     => 'right_user',
				'password' => 'right_password',
				'host'     => 'right_host',
				'prefix'   => 'right_',
			),
			$config
		);
	}

	/**
	 * Tests that a token defined twice, for real, is refused rather than one occurrence being
	 * guessed at: reading the wrong one of two real values could run this against any database.
	 *
	 * @since 0.1.0
	 */
	public function test_read_config_refuses_a_token_defined_more_than_once(): void {
		$path = $this->writeTempConfig(
			"<?php\n" .
			"define( 'DB_NAME', 'first_name' );\n" .
			"define( 'DB_NAME', 'second_name' );\n" .
			"define( 'DB_USER', 'x' );\n" .
			"define( 'DB_PASSWORD', 'x' );\n" .
			"define( 'DB_HOST', 'x' );\n" .
			"\$table_prefix = 'x_';\n"
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'more than once' );

		TestDatabasePrefix::readConfig( $path );
	}

	/**
	 * Tests that an empty prefix is refused rather than matching the whole database.
	 *
	 * @since 0.1.0
	 */
	public function test_drop_all_refuses_an_empty_prefix(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'empty' );

		TestDatabasePrefix::dropAll(
			array(
				'name'     => DB_NAME,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'host'     => DB_HOST,
				'prefix'   => '',
			)
		);
	}

	/**
	 * Tests that `dropAll()` removes exactly the tables of the given prefix and nothing else,
	 * against the real database, under a prefix that cannot collide with anything real. Two of
	 * the survivors are chosen to catch a looser match than `str_starts_with()`: one where the
	 * prefix sits inside the name rather than at its start (would pass `str_contains()`), and one
	 * where the prefix's own `_` is replaced by another character (would pass an unescaped `LIKE`,
	 * where `_` matches any one character) — lower-case, because a server with
	 * `lower_case_table_names` on folds a mixed-case name before this test ever sees it back, and
	 * a decoy that changed case under everyone's feet would fail this test for a reason that has
	 * nothing to do with what it checks.
	 *
	 * @since 0.1.0
	 */
	public function test_it_drops_only_tables_of_the_given_prefix(): void {
		global $wpdb;

		$this->fixtures = array(
			self::PREFIX . 'options',
			self::PREFIX . 'seocart_migrations',
		);

		$survivors = array(
			'zzz_outside_the_prefix_decoy',
			'x' . self::PREFIX . 'decoy',
			'zzzqtest_database_prefix_decoy',
		);

		foreach ( array_merge( $this->fixtures, $survivors ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id bigint unsigned NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB', $table ) );
		}

		$dropped = TestDatabasePrefix::dropAll(
			array(
				'name'     => DB_NAME,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'host'     => DB_HOST,
				'prefix'   => self::PREFIX,
			)
		);

		$expected = $this->fixtures;
		sort( $expected );
		$this->assertSame( $expected, $dropped );

		$existing = (array) $wpdb->get_col( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' );

		foreach ( $this->fixtures as $table ) {
			$this->assertNotContains( $table, $existing, "{$table} should have been dropped." );
		}

		foreach ( $survivors as $table ) {
			$this->assertContains( $table, $existing, "{$table} should not have been touched." );
		}

		// The fixtures already dropped; tear_down() must only clean up the survivors.
		$this->fixtures = $survivors;
	}

	/**
	 * Tests one `DB_HOST` value against `hostProvider()`'s expected split.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider hostProvider
	 *
	 * @param string                                        $host     The `DB_HOST` value.
	 * @param array{0: string, 1: int|null, 2: string|null} $expected The hostname, then the port or the socket.
	 */
	public function test_parse_host( string $host, array $expected ): void {
		$this->assertSame( $expected, TestDatabasePrefix::parseHost( $host ) );
	}

	/**
	 * Provides `DB_HOST` values and the hostname/port/socket split each one must produce.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: array{0: string, 1: int|null, 2: string|null}}>
	 */
	public static function hostProvider(): array {
		return array(
			'empty'                 => array( '', array( 'localhost', null, null ) ),
			'bare host'             => array( 'db.example.test', array( 'db.example.test', null, null ) ),
			'host and port'         => array( 'db.example.test:3306', array( 'db.example.test', 3306, null ) ),
			'localhost and socket'  => array( 'localhost:/ramdisk/mysql.sock', array( 'localhost', null, '/ramdisk/mysql.sock' ) ),
			'colon with no host'    => array( ':/tmp/mysql.sock', array( 'localhost', null, '/tmp/mysql.sock' ) ),
			'bracketed ipv6 alone'  => array( '[::1]', array( '::1', null, null ) ),
			'bracketed ipv6 + port' => array( '[::1]:3306', array( '::1', 3306, null ) ),
		);
	}

	/**
	 * Writes a temporary configuration file, tracked for cleanup in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @param string $contents The file's contents.
	 * @return string The file's path.
	 */
	private function writeTempConfig( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'seocart-test-config-' );
		self::assertIsString( $path );

		file_put_contents( $path, $contents );

		$this->tempFiles[] = $path;

		return $path;
	}
}
