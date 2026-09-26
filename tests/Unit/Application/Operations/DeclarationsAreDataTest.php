<?php
/**
 * Tests that building the operation registry calls nothing, reads nothing and translates nothing (DRY rule 12)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;

/**
 * Declarations are data: the whole registry builds, and compiles, with WordPress stubbed out.
 *
 * The registry is built in a fresh PHP process by tests/Support/declarations-probe.php, where every
 * global function of the WordPress stubs is a counter, `$wpdb` records any use, and every file the
 * process opens is recorded. Building the production registry and the fixture operation, and
 * compiling each into every dialect, must then:
 *
 * - call no WordPress function at all, gettext included (each one is counted by name);
 * - touch `$wpdb` in no way;
 * - open no file but the class files of the declaration mechanism, the capability declaration, the
 *   stock adjustment's declaration with the two enums it reads, and the fixture — no settings file,
 *   no service, no container, no kernel, no database layer.
 *
 * The probe can also add an operation whose factory translates a string, reads an option and reads
 * a file; the second test runs it that way and requires each of the three to be reported, so a
 * probe that counts nothing cannot pass for a clean registry.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class DeclarationsAreDataTest extends TestCase {

	/**
	 * The only files the build may open: the class files these directories hold, and these class files.
	 *
	 * The inventory module keeps its services beside its declarations, so its declaration files are
	 * listed one by one: a declaration that loads the stock service or the stock repository is
	 * reported, not allowed with its directory.
	 *
	 * Planted violation: in InventoryOperations::adjustStock(), call class_exists( StockService::class ).
	 * The first test reports that the build opened src/Inventory/Application/StockService.php.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CLASS_DIRECTORIES = array(
		'src/Support/',
		'src/Application/Operations/',
		'src/Platform/Authorization/',
		'src/Platform/Settings/',
		'src/Platform/Secrets/',
		'src/Inventory/Application/InventoryOperations.php',
		'src/Inventory/Application/InventoryError.php',
		'src/Inventory/Domain/LedgerReason.php',
		'src/Cart/Interfaces/StoreApi/StoreOperations.php',
		'src/Cart/Interfaces/StoreApi/StoreApiError.php',
		'src/Cart/Interfaces/StoreApi/StoreRequestPolicy.php',
		'src/Platform/RateLimiter/RateLimit.php',
		'tests/Fixtures/Operations/',
	);

	/**
	 * Tests that the production registry and the fixture build and compile without calling or reading anything.
	 *
	 * @since 0.1.0
	 */
	public function test_the_registry_builds_with_wordpress_stubbed_out_and_touches_nothing(): void {
		$probe = self::probe( false );

		$this->assertContains( FixtureStockOperation::ID, $probe['operations'], 'The probe did not build the fixture, so its clean result would prove nothing.' );
		$this->assertGreaterThan( 3000, $probe['wordpress_functions'], 'The probe counted too few WordPress functions: the stubs were not read.' );
		$this->assertNotSame( array(), $probe['files_opened'], 'The probe recorded no file at all, not even the class files it loaded: the recording is broken.' );
		$this->assertSame( array(), self::problems( $probe ), "Building the operation registry is not pure data:\n  " . implode( "\n  ", self::problems( $probe ) ) . "\n" );
	}

	/**
	 * Tests that the probe reports a factory that translates, reads an option and reads a file.
	 *
	 * @since 0.1.0
	 */
	public function test_the_probe_reports_a_factory_that_calls_wordpress_or_reads_a_file(): void {
		$problems = self::problems( self::probe( true ) );

		$this->assertContains( 'called the WordPress function __() 1 time(s)', $problems );
		$this->assertContains( 'called the WordPress function get_option() 1 time(s)', $problems );
		$this->assertContains( 'opened composer.json', $problems );
	}

	/**
	 * Runs the probe in a fresh PHP process.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $with_planted_factory Whether to add the planted operation.
	 * @return array{operations: list<string>, wordpress_functions: int, wordpress_calls: array<string, int>, wpdb: list<string>, files_opened: list<string>} What the probe recorded.
	 */
	private static function probe( bool $with_planted_factory ): array {
		$command = array( PHP_BINARY, dirname( __DIR__, 3 ) . '/Support/declarations-probe.php' );

		if ( $with_planted_factory ) {
			$command[] = '--with-planted-factory';
		}

		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		self::assertIsResource( $process, 'The probe could not be started.' );

		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$status = proc_close( $process );

		self::assertSame( 0, $status, "The probe failed:\n" . $errors . $output );

		$probe = json_decode( $output, true );

		self::assertIsArray( $probe, "The probe printed no JSON:\n" . $output . $errors );

		return $probe;
	}

	/**
	 * Lists everything a probe recorded that a pure build would not do.
	 *
	 * @since 0.1.0
	 *
	 * @param array{operations: list<string>, wordpress_functions: int, wordpress_calls: array<string, int>, wpdb: list<string>, files_opened: list<string>} $probe What the probe recorded.
	 * @return list<string> The problems, empty for a pure build.
	 */
	private static function problems( array $probe ): array {
		$problems = array();

		foreach ( $probe['wordpress_calls'] as $function => $count ) {
			$problems[] = 'called the WordPress function ' . $function . '() ' . $count . ' time(s)';
		}

		foreach ( $probe['wpdb'] as $use ) {
			$problems[] = 'used $wpdb' . $use;
		}

		foreach ( $probe['files_opened'] as $file ) {
			$allowed = str_ends_with( $file, '.php' ) && array() !== array_filter(
				self::CLASS_DIRECTORIES,
				static fn( string $directory ): bool => str_starts_with( $file, $directory )
			);

			if ( ! $allowed ) {
				$problems[] = 'opened ' . $file;
			}
		}

		return $problems;
	}
}
