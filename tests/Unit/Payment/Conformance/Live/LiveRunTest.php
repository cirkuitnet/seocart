<?php
/**
 * Tests the files one run of a live workflow writes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\LiveRun;

/**
 * A fact reported is one safe line through the declaration's guard, and a report reads back its last value of each; the run list holds a provider's id and a store uuid per line, written and read here alone; a file no variable names is refused, naming the settings step that creates it.
 *
 * @since 0.2.0
 */
final class LiveRunTest extends TestCase {

	/**
	 * The file the test's variable names.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * Makes the file.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->file = (string) tempnam( sys_get_temp_dir(), 'seocart-live-run-' );
	}

	/**
	 * Removes the file and the variables.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		putenv( LiveRun::REPORT );
		putenv( LiveRun::RUN_LIST );

		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}

		parent::tearDown();
	}

	/**
	 * Tests that a report keeps one line per fact, readable by its owner alone, and reads back the last value of each.
	 *
	 * @since 0.2.0
	 */
	public function test_a_report_is_written_and_read_back(): void {
		$declaration = StatedDeclaration::fixture();

		putenv( LiveRun::REPORT . '=' . $this->file );

		LiveRun::report( $declaration, 'notifications', 1 );
		LiveRun::report( $declaration, 'settled', "2 of 2\nmore" );
		LiveRun::report( $declaration, 'notifications', 2 );

		$this->assertSame( 0600, fileperms( $this->file ) & 0777 );
		$this->assertSame(
			array(
				'notifications' => '2',
				'settled'       => '2 of 2 more',
			),
			LiveRun::facts( (string) file_get_contents( $this->file ) )
		);
	}

	/**
	 * Tests that a fact shaped like a credential or a card number is refused before it is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_fact_shaped_like_a_credential_is_refused(): void {
		putenv( LiveRun::REPORT . '=' . $this->file );

		foreach ( array( 'fpk_test_' . bin2hex( random_bytes( 12 ) ), '4012888888881881' ) as $value ) {
			try {
				LiveRun::report( StatedDeclaration::fixture(), 'key', $value );
				$this->fail( 'A credential was reported.' );
			} catch ( \LogicException $refused ) {
				$this->assertStringNotContainsString( $value, $refused->getMessage() );
			}
		}

		$this->assertSame( '', file_get_contents( $this->file ) );
	}

	/**
	 * Tests that the run list's lines are written and read back, of either provider's ids, each once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_run_list_is_written_and_read_back(): void {
		putenv( LiveRun::RUN_LIST . '=' . $this->file );

		LiveRun::append( LiveRun::RUN_LIST, LiveRun::line( 'pi_3Abc0123456789', '0199c0de-0000-7000-8000-00000000a001' ) );
		LiveRun::append( LiveRun::RUN_LIST, LiveRun::line( '5O190127TN364715T', '0199c0de-0000-7000-8000-00000000a002' ) );
		LiveRun::append( LiveRun::RUN_LIST, "a line of another shape\n" );
		LiveRun::append( LiveRun::RUN_LIST, LiveRun::line( 'pi_3Abc0123456789', '0199c0de-0000-7000-8000-00000000a001' ) );

		$this->assertSame( 0600, fileperms( $this->file ) & 0777 );
		$this->assertSame(
			array(
				'pi_3Abc0123456789' => '0199c0de-0000-7000-8000-00000000a001',
				'5O190127TN364715T' => '0199c0de-0000-7000-8000-00000000a002',
			),
			LiveRun::runList( (string) file_get_contents( $this->file ) )
		);
	}

	/**
	 * Tests that a line of any other shape than an id and a uuid is refused, as the reader would leave it out.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider notLines
	 *
	 * @param string $id   The provider's id.
	 * @param string $uuid The store uuid.
	 */
	public function test_a_line_of_another_shape_is_refused( string $id, string $uuid ): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'The run list holds a provider\'s id and a store uuid, and nothing else.' );

		LiveRun::line( $id, $uuid );
	}

	/**
	 * Returns ids and uuids that make no line of the run list.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> The id and the uuid; by name.
	 */
	public static function notLines(): array {
		return array(
			'an id with a space'    => array( 'pi_1 2', '0199c0de-0000-7000-8000-00000000a001' ),
			'an id with a newline'  => array( "pi_1\nrm", '0199c0de-0000-7000-8000-00000000a001' ),
			'no id'                 => array( '', '0199c0de-0000-7000-8000-00000000a001' ),
			'a uuid in capitals'    => array( 'pi_1', '0199C0DE-0000-7000-8000-00000000A001' ),
			'a uuid with no dashes' => array( 'pi_1', '0199c0de000070008000000000000a001' ),
		);
	}

	/**
	 * Tests that a file no variable names is refused, naming the variable and the step that creates the file.
	 *
	 * @since 0.2.0
	 */
	public function test_a_file_no_variable_names_is_refused(): void {
		putenv( LiveRun::REPORT );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The live tests write to the file SEOCART_LIVE_REPORT names, which the live workflow\'s settings step creates.' );

		LiveRun::file( LiveRun::REPORT );
	}
}
