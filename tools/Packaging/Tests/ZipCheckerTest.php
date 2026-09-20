<?php
/**
 * Tests for the fail-closed release zip checker
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\DistIgnore;
use SEOCart\Tools\Packaging\ZipChecker;
use ZipArchive;

/**
 * Hands the checker one good zip and many zips that are each wrong in one way.
 *
 * @since 0.1.0
 */
final class ZipCheckerTest extends TestCase {

	use RunsScripts;
	use TemporaryDirectory;

	/**
	 * Where the fixture zips keep Action Scheduler.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ACTION_SCHEDULER = 'seocart/vendor-scoped/woocommerce/action-scheduler/';

	/**
	 * Returns the entries of a zip that may be published: entry name mapped to contents.
	 *
	 * The Action Scheduler files are minimal stand-ins written for this test. They carry
	 * only the declarations the checker looks for.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	private function goodEntries(): array {
		return array(
			'seocart/seocart.php'                        => "<?php\n/**\n * Plugin Name: SEOCart\n * Version: 1.2.3\n */\n",
			'seocart/uninstall.php'                      => "<?php\n",
			'seocart/readme.txt'                         => "=== SEOCart ===\n",
			'seocart/LICENSE'                            => "GNU GENERAL PUBLIC LICENSE\n",
			'seocart/src/Platform/Kernel/Kernel.php'     => "<?php\nnamespace SEOCart\\Platform\\Kernel;\n",
			'seocart/vendor-scoped/autoload.php'         => "<?php\n",
			'seocart/vendor-scoped/composer/autoload_classmap.php' => "<?php\nreturn array(\n\t'SEOCart\\\\Vendor\\\\Acme\\\\Lib' => \$vendorDir . '/acme/lib/src/Lib.php',\n);\n",
			'seocart/vendor-scoped/acme/lib/src/Lib.php' => "<?php\nnamespace SEOCart\\Vendor\\Acme;\n",
			self::ACTION_SCHEDULER . 'action-scheduler.php' => "<?php\nif ( ! class_exists( 'ActionScheduler_Versions', false ) ) {\n\trequire_once __DIR__ . '/classes/ActionScheduler_Versions.php';\n}\n",
			self::ACTION_SCHEDULER . 'classes/ActionScheduler_Versions.php' => "<?php\nclass ActionScheduler_Versions {\n}\n",
			self::ACTION_SCHEDULER . 'classes/abstracts/ActionScheduler.php' => "<?php\nabstract class ActionScheduler {\n}\n",
			self::ACTION_SCHEDULER . 'classes/migration/Runner.php' => "<?php\nnamespace Action_Scheduler\\Migration;\n\nclass Runner {\n}\n",
		);
	}

	/**
	 * Writes a zip.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $entries   Entry name mapped to contents.
	 * @param string                $file_name File name of the zip.
	 * @return string Absolute path of the zip.
	 */
	private function zip( array $entries, string $file_name = 'seocart-1.2.3.zip' ): string {
		$path = $this->directory . '/' . $file_name;
		$zip  = new ZipArchive();

		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );

		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}

		$this->assertTrue( $zip->close() );

		return $path;
	}

	/**
	 * Asserts that the checker reports exactly the expected violations, each recognised by a fragment.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $fragments  One fragment per expected violation, in order.
	 * @param string[] $violations What the checker returned.
	 */
	private function assertViolations( array $fragments, array $violations ): void {
		$this->assertCount( count( $fragments ), $violations, implode( "\n", $violations ) );

		foreach ( $fragments as $index => $fragment ) {
			$this->assertStringContainsString( $fragment, $violations[ $index ] );
		}
	}

	/**
	 * Tests that a well-formed zip passes.
	 *
	 * @since 0.1.0
	 */
	public function test_good_zip_passes(): void {
		$this->assertSame( array(), ZipChecker::check( $this->zip( $this->goodEntries() ) ) );
	}

	/**
	 * Tests that every optional entry on the allow-list is accepted, and a docs directory inside a library too.
	 *
	 * @since 0.1.0
	 */
	public function test_optional_entries_pass(): void {
		$entries = $this->goodEntries() + array(
			'seocart/CHANGELOG.md'               => "# Changelog\n",
			'seocart/templates/emails/order.php' => "<?php\n",
			'seocart/languages/seocart.pot'      => "msgid \"\"\n",
			'seocart/build/blocks/cart/index.js' => "export {};\n",
			'seocart/vendor-scoped/acme/lib/docs/Pages.php' => "<?php\n",
		);

		$this->assertSame( array(), ZipChecker::check( $this->zip( $entries ) ) );
	}

	/**
	 * Tests that one added entry produces exactly the expected violations.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unwantedEntries
	 *
	 * @param string   $name      Name of the entry added to the good zip.
	 * @param string[] $fragments One fragment per expected violation, in order.
	 */
	public function test_unwanted_entry_fails( string $name, array $fragments ): void {
		$entries = $this->goodEntries() + array( $name => "Unwanted.\n" );

		$this->assertViolations( $fragments, ZipChecker::check( $this->zip( $entries ) ) );
	}

	/**
	 * Provides an entry name and the violations it must cause.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public function unwantedEntries(): array {
		return array(
			'tests at the top'                  => array(
				'seocart/tests/Unit/KernelTest.php',
				array( 'unexpected top-level entry seocart/tests/', 'forbidden path: seocart/tests (1 entries)' ),
			),
			'docs at the top'                   => array(
				'seocart/docs/adr/ADR-0008.md',
				array( 'unexpected top-level entry seocart/docs/' ),
			),
			'unscoped vendor at the top'        => array(
				'seocart/vendor/autoload.php',
				array( 'unexpected top-level entry seocart/vendor/', 'forbidden path: seocart/vendor (1 entries)' ),
			),
			'file that is not on the list'      => array(
				'seocart/composer.json',
				array( 'unexpected top-level entry seocart/composer.json' ),
			),
			'file with a listed directory name' => array(
				'seocart/templates',
				array( 'unexpected top-level entry seocart/templates.' ),
			),
			'tests inside a library'            => array(
				'seocart/vendor-scoped/acme/lib/Tests/LibTest.php',
				array( 'forbidden path: seocart/vendor-scoped/acme/lib/Tests (1 entries)' ),
			),
			'node_modules inside build'         => array(
				'seocart/build/node_modules/left-pad/index.js',
				array( 'forbidden path: seocart/build/node_modules (1 entries)' ),
			),
			'vendor inside a library'           => array(
				'seocart/vendor-scoped/acme/lib/vendor/autoload.php',
				array( 'forbidden path: seocart/vendor-scoped/acme/lib/vendor (1 entries)' ),
			),
			'Vendor namespace inside src'       => array(
				'seocart/src/Marketplace/Vendor/Profile.php',
				array( 'forbidden path: seocart/src/Marketplace/Vendor (1 entries)' ),
			),
			'git metadata'                      => array(
				'seocart/vendor-scoped/acme/lib/.git/config',
				array( 'forbidden path: seocart/vendor-scoped/acme/lib/.git (1 entries): hidden files' ),
			),
			'hidden file'                       => array(
				'seocart/src/.DS_Store',
				array( 'forbidden path: seocart/src/.DS_Store (1 entries): hidden files' ),
			),
			'the Strauss binary'                => array(
				'seocart/vendor-scoped/bin/strauss.phar',
				array( 'forbidden path: seocart/vendor-scoped/bin/strauss.phar (1 entries): development tooling' ),
			),
			'a PHPUnit configuration'           => array(
				'seocart/vendor-scoped/acme/lib/phpunit.xml.dist',
				array( 'forbidden path: seocart/vendor-scoped/acme/lib/phpunit.xml.dist (1 entries): development tooling' ),
			),
			'PHPUnit itself'                    => array(
				'seocart/vendor-scoped/phpunit/phpunit/src/Framework/TestCase.php',
				array( 'forbidden path: seocart/vendor-scoped/phpunit (1 entries): development tooling' ),
			),
			'a source map'                      => array(
				'seocart/build/blocks/cart/index.js.map',
				array( 'forbidden path: seocart/build/blocks/cart/index.js.map (1 entries): source maps' ),
			),
			'a second top-level folder'         => array(
				'seocart-pro/seocart-pro.php',
				array( 'every entry must sit inside the single top-level folder seocart/' ),
			),
			'a path that climbs out'            => array(
				'seocart/src/../../wp-config.php',
				array( 'every entry must sit inside the single top-level folder seocart/', 'forbidden path: seocart/src/..' ),
			),
		);
	}

	/**
	 * Tests that a forbidden directory is reported once, however many entries it holds.
	 *
	 * @since 0.1.0
	 */
	public function test_forbidden_directory_is_reported_once(): void {
		$entries = $this->goodEntries() + array(
			'seocart/src/tests/OneTest.php' => "<?php\n",
			'seocart/src/tests/TwoTest.php' => "<?php\n",
		);

		$this->assertViolations(
			array( 'forbidden path: seocart/src/tests (2 entries)' ),
			ZipChecker::check( $this->zip( $entries ) )
		);
	}

	/**
	 * Tests that a forbidden path comes with its remedy, and that the remedy works in the .distignore dialect.
	 *
	 * @since 0.1.0
	 */
	public function test_forbidden_path_names_the_pattern_that_excludes_it(): void {
		$entries = $this->goodEntries() + array( 'seocart/vendor-scoped/acme/lib/Tests/LibTest.php' => "<?php\n" );

		$violations = ZipChecker::check( $this->zip( $entries ) );

		$this->assertCount( 1, $violations );
		$this->assertSame( 1, preg_match( '/Exclude it with the line `([^`]+)` in \.distignore/', $violations[0], $matches ), $violations[0] );
		$this->assertSame( '/vendor-scoped/acme/lib/Tests', $matches[1] );
		$this->assertStringContainsString( 'rename it', $violations[0] );

		$ignore = DistIgnore::fromString( $matches[1] );

		$this->assertTrue( $ignore->excludes( 'vendor-scoped/acme/lib/Tests/LibTest.php' ) );
		$this->assertFalse( $ignore->excludes( 'vendor-scoped/acme/lib/src/Lib.php' ) );
	}

	/**
	 * Tests that each required entry is required.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider requiredEntries
	 *
	 * @param string $name     Entry removed from the good zip.
	 * @param string $fragment Fragment of the expected violation.
	 */
	public function test_missing_required_entry_fails( string $name, string $fragment ): void {
		$entries = $this->goodEntries();

		unset( $entries[ $name ] );

		$this->assertViolations( array( $fragment ), ZipChecker::check( $this->zip( $entries ) ) );
	}

	/**
	 * Provides a required entry and the violation its absence must cause.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function requiredEntries(): array {
		return array(
			'main file' => array( 'seocart/seocart.php', 'required entry seocart/seocart.php is missing' ),
			'uninstall' => array( 'seocart/uninstall.php', 'required entry seocart/uninstall.php is missing' ),
			'readme'    => array( 'seocart/readme.txt', 'required entry seocart/readme.txt is missing' ),
			'license'   => array( 'seocart/LICENSE', 'required entry seocart/LICENSE is missing' ),
			'source'    => array( 'seocart/src/Platform/Kernel/Kernel.php', 'required entry seocart/src/ is missing' ),
		);
	}

	/**
	 * Tests that a zip over the budget fails, and that a zip over the limit fails with a different message.
	 *
	 * @since 0.1.0
	 */
	public function test_size_is_checked_against_the_budget_and_the_limit(): void {
		$path  = $this->zip( $this->goodEntries() );
		$bytes = (int) filesize( $path );

		$this->assertSame( array(), ZipChecker::check( $path, $bytes, $bytes ) );

		$over_budget = ZipChecker::check( $path, $bytes - 1, $bytes );

		$this->assertViolations( array( 'over the project budget of ' . ( $bytes - 1 ) . ' bytes' ), $over_budget );
		$this->assertStringNotContainsString( 'will not accept', $over_budget[0] );

		$over_limit = ZipChecker::check( $path, $bytes - 2, $bytes - 1 );

		$this->assertViolations( array( 'over the WordPress.org hard limit of ' . ( $bytes - 1 ) . ' bytes' ), $over_limit );
		$this->assertStringNotContainsString( 'project budget', $over_limit[0] );
	}

	/**
	 * Tests the Action Scheduler rules of ADR-0008, one broken zip at a time.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider brokenActionScheduler
	 *
	 * @param array<string, string|null> $changes   Entry name mapped to new contents, or null to remove the entry.
	 * @param string[]                   $fragments One fragment per expected violation, in order.
	 */
	public function test_action_scheduler_must_be_present_unprefixed_and_not_autoloaded( array $changes, array $fragments ): void {
		$entries = $this->goodEntries();

		foreach ( $changes as $name => $contents ) {
			if ( null === $contents ) {
				unset( $entries[ $name ] );
			} else {
				$entries[ $name ] = $contents;
			}
		}

		$violations = ZipChecker::check( $this->zip( $entries ) );

		$this->assertViolations( $fragments, $violations );
		$this->assertStringContainsString( 'ADR-0008', $violations[0] );
	}

	/**
	 * Provides changes to the good zip and the violations they must cause.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, string|null>, list<string>}>
	 */
	public function brokenActionScheduler(): array {
		return array(
			'entry file missing'       => array(
				array( self::ACTION_SCHEDULER . 'action-scheduler.php' => null ),
				array( 'action-scheduler.php is missing' ),
			),
			'entry file prefixed'      => array(
				array( self::ACTION_SCHEDULER . 'action-scheduler.php' => "<?php\nif ( ! class_exists( 'SEOCart_Vendor_ActionScheduler_Versions', false ) ) {\n}\n" ),
				array( 'does not look for the class ActionScheduler_Versions under its real name' ),
			),
			'version class prefixed'   => array(
				array( self::ACTION_SCHEDULER . 'classes/ActionScheduler_Versions.php' => "<?php\nclass SEOCart_Vendor_ActionScheduler_Versions {\n}\n" ),
				array( 'classes/ActionScheduler_Versions.php does not declare the class ActionScheduler_Versions' ),
			),
			'main class missing'       => array(
				array( self::ACTION_SCHEDULER . 'classes/abstracts/ActionScheduler.php' => null ),
				array( 'classes/abstracts/ActionScheduler.php does not declare the class ActionScheduler;' ),
			),
			'namespace prefixed'       => array(
				array( self::ACTION_SCHEDULER . 'classes/migration/Runner.php' => "<?php\nnamespace SEOCart\\Vendor\\Action_Scheduler\\Migration;\n" ),
				array( 'declares the namespace SEOCart\\Vendor\\Action_Scheduler\\Migration' ),
			),
			'listed in the class map'  => array(
				array( 'seocart/vendor-scoped/composer/autoload_classmap.php' => "<?php\nreturn array(\n\t'ActionScheduler' => \$vendorDir . '/woocommerce/action-scheduler/classes/abstracts/ActionScheduler.php',\n);\n" ),
				array( 'autoload_classmap.php lists Action Scheduler files' ),
			),
			'listed in the static map' => array(
				array( 'seocart/vendor-scoped/composer/autoload_static.php' => "<?php\n// 'ActionScheduler' => __DIR__ . '/..' . '/woocommerce/action-scheduler/classes/abstracts/ActionScheduler.php'.\n" ),
				array( 'autoload_static.php lists Action Scheduler files' ),
			),
		);
	}

	/**
	 * Tests that the version in the file name must be the version in the header.
	 *
	 * @since 0.1.0
	 */
	public function test_file_name_version_must_match_the_header(): void {
		$this->assertViolations(
			array( 'the file name says 1.2.4 but the Version header of seocart/seocart.php inside the zip says 1.2.3' ),
			ZipChecker::check( $this->zip( $this->goodEntries(), 'seocart-1.2.4.zip' ) )
		);
	}

	/**
	 * Tests that a file name without a version fails.
	 *
	 * @since 0.1.0
	 */
	public function test_file_name_must_state_a_version(): void {
		$this->assertViolations(
			array( 'the file name release.zip is not of the form seocart-<version>.zip' ),
			ZipChecker::check( $this->zip( $this->goodEntries(), 'release.zip' ) )
		);
	}

	/**
	 * Tests that a main file without a Version header fails.
	 *
	 * @since 0.1.0
	 */
	public function test_header_must_state_a_version(): void {
		$entries = array( 'seocart/seocart.php' => "<?php\n// No header.\n" ) + $this->goodEntries();

		$this->assertViolations(
			array( 'seocart/seocart.php has no Version header' ),
			ZipChecker::check( $this->zip( $entries ) )
		);
	}

	/**
	 * Tests that an entry stored as a symbolic link fails.
	 *
	 * @since 0.1.0
	 */
	public function test_symbolic_link_entry_fails(): void {
		$path = $this->zip( $this->goodEntries() + array( 'seocart/src/Link.php' => '../../wp-config.php' ) );
		$zip  = new ZipArchive();

		$zip->open( $path );
		$zip->setExternalAttributesName( 'seocart/src/Link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
		$zip->close();

		$this->assertViolations( array( 'symbolic link: seocart/src/Link.php' ), ZipChecker::check( $path ) );
	}

	/**
	 * Tests that a file that is not a zip fails instead of passing with nothing to object to.
	 *
	 * @since 0.1.0
	 */
	public function test_unreadable_archive_fails(): void {
		$this->assertViolations(
			array( 'is not a readable zip archive' ),
			ZipChecker::check( $this->writeFile( 'seocart-1.2.3.zip', 'This is not a zip.' ) )
		);
		$this->assertViolations(
			array( 'does not exist' ),
			ZipChecker::check( $this->directory . '/seocart-9.9.9.zip' )
		);
	}

	/**
	 * Tests that the exit code of bin/check-zip.php follows the verdict, and that the size options reach the check.
	 *
	 * @since 0.1.0
	 */
	public function test_command_line_exit_code_follows_the_verdict(): void {
		$zip = $this->zip( $this->goodEntries() );

		$passed = $this->runScript( 'check-zip.php', array( $zip ) );

		$this->assertSame( 0, $passed['exit'], $passed['stderr'] );
		$this->assertStringContainsString( 'check-zip: OK', $passed['stdout'] );

		$over_budget = $this->runScript( 'check-zip.php', array( '--budget-bytes=1', $zip ) );

		$this->assertSame( 1, $over_budget['exit'] );
		$this->assertStringContainsString( 'check-zip: FAIL size:', $over_budget['stderr'] );
		$this->assertStringContainsString( 'over the project budget of 1 bytes', $over_budget['stderr'] );
		$this->assertStringContainsString( 'This zip must not be published.', $over_budget['stderr'] );

		$over_limit = $this->runScript( 'check-zip.php', array( $zip, '--budget-bytes=1', '--limit-bytes=1' ) );

		$this->assertSame( 1, $over_limit['exit'] );
		$this->assertStringContainsString( 'over the WordPress.org hard limit of 1 bytes', $over_limit['stderr'] );

		$missing = $this->runScript( 'check-zip.php', array( $this->directory . '/seocart-9.9.9.zip' ) );

		$this->assertSame( 1, $missing['exit'] );
		$this->assertStringContainsString( 'does not exist', $missing['stderr'] );
	}

	/**
	 * Tests that a command line the script does not understand is an error even for a zip that would pass.
	 *
	 * A mistyped option that was ignored would leave the gate green without the check the caller asked for.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider misusedCommandLines
	 *
	 * @param string[] $arguments Command-line arguments; `{zip}` stands for the path of a zip that passes.
	 * @param string   $fragment  Fragment of the expected message.
	 */
	public function test_command_line_misuse_exits_2( array $arguments, string $fragment ): void {
		$zip    = $this->zip( $this->goodEntries() );
		$result = $this->runScript( 'check-zip.php', str_replace( '{zip}', $zip, $arguments ) );

		$this->assertSame( 2, $result['exit'], $result['stderr'] );
		$this->assertStringContainsString( $fragment, $result['stderr'] );
		$this->assertStringContainsString( 'Usage: php bin/check-zip.php <zip>', $result['stderr'] );
		$this->assertSame( '', $result['stdout'] );
	}

	/**
	 * Provides command lines that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>, string}>
	 */
	public function misusedCommandLines(): array {
		return array(
			'no zip'                   => array( array(), 'expected exactly one zip, got 0' ),
			'two zips'                 => array( array( '{zip}', '{zip}' ), 'expected exactly one zip, got 2' ),
			'mistyped option'          => array( array( '{zip}', '--budget=1' ), 'unrecognized argument "--budget=1"' ),
			'option that is no number' => array( array( '{zip}', '--budget-bytes=abc' ), 'unrecognized argument "--budget-bytes=abc"' ),
			'option without a value'   => array( array( '--limit-bytes', '{zip}' ), 'unrecognized argument "--limit-bytes"' ),
			'budget over the limit'    => array( array( '{zip}', '--budget-bytes=2', '--limit-bytes=1' ), 'the budget cannot be larger than the limit' ),
		);
	}
}
