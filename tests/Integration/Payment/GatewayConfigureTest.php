<?php
/**
 * Tests `wp seocart gateway configure`: who may, which files it reads, which values it takes, and what it writes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use SEOCart\Tests\Support\Payment\SwappedFile;

/**
 * Configure reads one JSON object of a mode's settings from a regular file only its owner can read, as a user who may manage the store's secrets, and writes the mode's values with each credential sealed, leaving the other mode and the mode setting as they were; a setting a later file leaves out keeps its value. It refuses, writing nothing: without a user, or one who may not (before the file is opened), a file another user can read or write, a link, a list, an unknown setting, a value its setting refuses (never quoting it), a file that leaves a required setting out, and a file changed between its check and its reading.
 *
 * Planted violations, each shown red and removed:
 * - in GatewayCommand::readSettings(), refuse only a file every user can read: the file readable
 *   by its group is read;
 * - in GatewayConfiguration::changes(), name the value in the message of a value refused: the
 *   scan of what was printed finds it;
 * - in GatewayCommand::readSettings(), check the path and then read it by its name, as before: the
 *   link and the larger file put in the checked file's place are read;
 * - in GatewayCommand::readSettings(), read the open file to its end: the file grown once open is
 *   read.
 *
 * @since 0.2.0
 */
final class GatewayConfigureTest extends PaymentTestCase {

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The command.
	 *
	 * @since 0.2.0
	 *
	 * @var GatewayCommandRecorder
	 */
	private GatewayCommandRecorder $command;

	/**
	 * The files and links the test wrote.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Builds the kernel and has `second` register through the action.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		$this->kernel  = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );
		$this->command = new GatewayCommandRecorder( $this->kernel );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( self::descriptor(), $registry->context( 'second' ) ) );
			}
		);
	}

	/**
	 * Deletes the files, the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests each refusal: nothing is written, and no value is printed.
	 *
	 * @since 0.2.0
	 */
	public function test_each_refusal_writes_nothing(): void {
		$admin   = $this->userWithRole( 'administrator' )->userId();
		$manager = $this->userWithRole( 'seocart_manager' )->userId();
		$valid   = self::values();

		$this->assertRefused( $this->file( $valid ), 0, 'authorization.denied:', 'Without --user.' );
		$this->assertSame( 'This action changes the store, so it runs only as a user: run it with --user=<login>.', $this->command->last() );

		// The capability is checked before the file is opened: this file would be refused for its mode.
		$this->assertRefused( $this->file( $valid, 0644 ), $manager, 'authorization.denied:', 'A user who may not manage secrets.' );

		foreach ( array( 0640, 0604, 0660, 0606, 0620 ) as $mode ) {
			$this->assertRefused( $this->file( $valid, $mode ), $admin, 'can be read or written by other users than its owner', sprintf( 'A file of mode %04o.', $mode ) );
		}

		$link = $this->file( $valid ) . '.link';

		symlink( $this->files[ count( $this->files ) - 1 ], $link );
		$this->files[] = $link;

		$this->assertRefused( $link, $admin, 'is a symbolic link', 'A link.' );
		$this->assertRefused( '/nonexistent/second.json', $admin, 'does not exist', 'No file.' );
		$this->assertRefused( $this->file( array_values( $valid ) ), $admin, 'does not hold one JSON object', 'A list.' );
		$this->assertRefused( $this->file( $valid + array( 'publishable_key' => 'pk_test_x' ) ), $admin, 'declares no setting publishable_key', 'An unknown setting.' );
		$this->assertRefused( $this->file( array( GatewayDescriptor::ACCOUNT_COUNTRY => 'United States' ) + $valid ), $admin, 'second_test_account_country', 'A value its setting refuses.' );
		$this->assertRefused( $this->file( array( 'secret_key' => 'sk_test_planted' ) ), $admin, 'account_country missing', 'A required setting left out.' );

		$this->assertSame( array(), GatewayKernel::storedDocument( 'second' ), 'Nothing was written.' );
		$this->assertStringNotContainsString( 'United States', $this->command->printed(), 'A refused value was printed.' );
		$this->assertStringNotContainsString( 'sk_test_planted', $this->command->printed(), 'A credential was printed.' );
	}

	/**
	 * Tests a file changed between its check and its reading: a link or a larger file put in its place, and the file grown once open, are each refused before anything is read from them.
	 *
	 * One process cannot time another's rename between two of its own calls, so SwappedFile stands in
	 * for the filesystem: a check of its path sees the file checked, opening it gives the file put
	 * in its place, and a growing file grows right after fstat() answered for it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_file_changed_between_its_check_and_its_reading_is_refused(): void {
		$admin   = $this->userWithRole( 'administrator' )->userId();
		$checked = $this->file( self::values() );
		$other   = $this->file( array( 'secret_key' => 'sk_test_swapped' ) + self::values() );
		$larger  = $this->file( array( 'secret_key' => 'sk_test_swapped' ) + self::values() );
		$link    = $other . '.link';

		symlink( $other, $link );
		$this->files[] = $link;
		file_put_contents( $larger, str_repeat( ' ', GatewayCommand::MAX_FILE_BYTES ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test's own temporary file.

		SwappedFile::register();

		try {
			$this->assertRefused( SwappedFile::replaced( $checked, $link ), $admin, 'was replaced between its check and its opening', 'A link put in its place.' );
			$this->assertRefused( SwappedFile::replaced( $checked, $larger ), $admin, 'was replaced between its check and its opening', 'A larger file put in its place.' );
			$this->assertRefused( SwappedFile::growing( $this->file( self::values() ), GatewayCommand::MAX_FILE_BYTES ), $admin, 'is larger than ' . GatewayCommand::MAX_FILE_BYTES . ' bytes', 'The file grown once open.' );
		} finally {
			SwappedFile::unregister();
		}

		$this->assertSame( array(), GatewayKernel::storedDocument( 'second' ), 'Nothing was written.' );
		$this->assertStringNotContainsString( 'sk_test_swapped', $this->command->printed(), 'A credential was printed.' );
	}

	/**
	 * Tests the write: the mode's values, each credential sealed, the other mode and the mode setting untouched, and a setting a later file leaves out kept.
	 *
	 * @since 0.2.0
	 */
	public function test_configure_writes_the_modes_values_and_keeps_what_a_later_file_leaves_out(): void {
		$admin = $this->userWithRole( 'administrator' )->userId();

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configureFrom( $this->file( self::values() + array( 'statement_suffix' => 'SHOP' ) ), $admin ) );
		$this->assertSame( 'Saved the test settings of second (its settings are at version 1).', $this->command->lines[0] );
		$this->assertSame( 'Webhook endpoint: skipped.', $this->command->lines[1] );

		$values = GatewayKernel::storedDocument( 'second' )['values'] ?? array();

		$this->assertSame( array( 'second_test_secret_key', 'second_test_account_country', 'second_test_statement_suffix' ), array_keys( $values ), 'Only the mode\'s values: the other mode and the mode setting are untouched.' );
		$this->assertStringStartsWith( 'v1:', (string) $values['second_test_secret_key'], 'The credential is stored sealed.' );
		$this->assertStringNotContainsString( 'sk_test_planted', (string) wp_json_encode( GatewayKernel::storedDocument( 'second' ) ), 'The plain credential is nowhere in the option.' );
		$this->assertSame( array( 'US', 'SHOP' ), array( $values['second_test_account_country'], $values['second_test_statement_suffix'] ) );

		$this->assertSame( GatewayCommand::EXIT_OK, $this->configureFrom( $this->file( array( 'secret_key' => 'sk_test_rotated' ) + self::values() ), $admin ) );

		$after = GatewayKernel::storedDocument( 'second' );

		$this->assertSame( 2, $after['version'] ?? null );
		$this->assertSame( 'SHOP', $after['values']['second_test_statement_suffix'] ?? null, 'A setting the file leaves out keeps its value.' );
		$this->assertNotSame( $values['second_test_secret_key'], $after['values']['second_test_secret_key'] ?? null, 'The credential given is replaced.' );
		$this->assertSame( 'sk_test_rotated', $this->kernel->get( Gateways::class )->context( 'second' )->settings( Mode::Test )->secret( 'secret_key' ) );
	}

	/**
	 * Declares `second`: test and live modes, a secret key, an account country, and a statement suffix with a default.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	private static function descriptor(): GatewayDescriptor {
		return DeclaredGateway::descriptor(
			'second',
			array( Mode::Test, Mode::Live ),
			array(
				new FieldSpec( name: 'secret_key', type: FieldType::String, description: 'The provider\'s secret key.', label: static fn(): string => 'Secret key', example: 'sk_test_x', privacy: Privacy::Secret ),
				new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The account\'s country.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 ),
				new FieldSpec( name: 'statement_suffix', type: FieldType::String, description: 'What the shopper\'s statement shows after the store\'s name.', label: static fn(): string => 'Statement suffix', example: 'SHOP', default_value: 'SC', max_length: 10 ),
			)
		);
	}

	/**
	 * Returns the settings a valid file gives.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The values, by declared name.
	 */
	private static function values(): array {
		return array(
			'secret_key'                       => 'sk_test_planted',
			GatewayDescriptor::ACCOUNT_COUNTRY => 'US',
		);
	}

	/**
	 * Writes a settings file of a mode of permissions.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $values      What the file holds, encoded as JSON.
	 * @param int          $permissions Optional. The file's permissions. Default 0600.
	 * @return string The file's path.
	 */
	private function file( array $values, int $permissions = 0600 ): string {
		$file = (string) wp_tempnam( 'seocart-gateway' );

		$this->files[] = $file;

		file_put_contents( $file, (string) wp_json_encode( $values ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test's own temporary file.
		chmod( $file, $permissions ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The permissions the case is about.

		return $file;
	}

	/**
	 * Runs configure for test mode from a file, as a user.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file.
	 * @param int    $user The user; 0 for none.
	 * @return int The exit code.
	 */
	private function configureFrom( string $file, int $user ): int {
		return $this->command->run(
			array( 'configure', 'second' ),
			array(
				'mode'      => 'test',
				'from-file' => $file,
			),
			$user
		);
	}

	/**
	 * Asserts that configure refuses a file, exits 1, and says why.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file    The file.
	 * @param int    $user    The user; 0 for none.
	 * @param string $message What the refusal says, in part.
	 * @param string $what    What the file is, for a failure's message.
	 */
	private function assertRefused( string $file, int $user, string $message, string $what ): void {
		$lines = count( $this->command->lines );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $this->configureFrom( $file, $user ), $what );
		$this->assertStringContainsString( $message, implode( "\n", array_slice( $this->command->lines, $lines ) ), $what );
	}
}
