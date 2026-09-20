<?php
/**
 * Tests the recorder that catches PHP errors raised while the plugin loads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\ErrorRecorder;

/**
 * Proves that the recorder sees real PHP errors, skips silenced ones, and gives the handler back.
 *
 * @since 0.1.0
 */
final class ErrorRecorderTest extends TestCase {

	/**
	 * The error reporting level to restore after each test.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $errorReporting;

	/**
	 * Starts every test with an empty record and with every level reported, as under WP_DEBUG.
	 *
	 * The recorder skips a level that is not reported, so the level PHP happens to be configured
	 * with on the machine running the tests must not decide what these tests see.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->errorReporting = error_reporting( E_ALL );

		ErrorRecorder::reset();
	}

	/**
	 * Leaves no record behind for the next test.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		ErrorRecorder::reset();

		error_reporting( $this->errorReporting );

		parent::tearDown();
	}

	/**
	 * Returns the error handler that is currently installed, without changing it.
	 *
	 * @since 0.1.0
	 *
	 * @return callable|null The handler, or null when PHP's own handling is in effect.
	 */
	private function currentErrorHandler() {
		$handler = set_error_handler( static fn(): bool => true );

		restore_error_handler();

		return $handler;
	}

	/**
	 * Tests that a real error raised between start() and stop() is recorded with its origin.
	 *
	 * @since 0.1.0
	 */
	public function test_an_error_raised_while_recording_is_recorded_with_its_origin(): void {
		/*
		 * The recorder lets PHP go on to handle the error, so PHP would print or log it in the
		 * middle of the test output. WP_DEBUG_DISPLAY and WP_DEBUG_LOG mean nothing here: WordPress
		 * is not loaded in a unit test.
		 */
		// phpcs:disable WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.IniSet.log_errors_Disallowed
		$display = ini_set( 'display_errors', '0' );
		$log     = ini_set( 'log_errors', '0' );

		ErrorRecorder::start();

		try {
			trigger_error( 'planted deprecation', E_USER_DEPRECATED );
			$line = __LINE__ - 1;
		} finally {
			ErrorRecorder::stop();

			ini_set( 'display_errors', (string) $display );
			ini_set( 'log_errors', (string) $log );
			// phpcs:enable WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.IniSet.log_errors_Disallowed
		}

		$this->assertSame(
			array(
				array(
					'severity' => E_USER_DEPRECATED,
					'message'  => 'planted deprecation',
					'file'     => __FILE__,
					'line'     => $line,
				),
			),
			ErrorRecorder::records()
		);
	}

	/**
	 * Tests that stop() hands error handling back to whoever had it before start().
	 *
	 * @since 0.1.0
	 */
	public function test_stop_restores_the_previous_error_handler(): void {
		$before = $this->currentErrorHandler();

		ErrorRecorder::start();

		$this->assertSame( array( ErrorRecorder::class, 'record' ), $this->currentErrorHandler() );

		ErrorRecorder::stop();

		$this->assertSame( $before, $this->currentErrorHandler() );
	}

	/**
	 * Tests that the handler returns false, which tells PHP to carry on with its own handling.
	 *
	 * @since 0.1.0
	 */
	public function test_recording_does_not_swallow_the_error(): void {
		$this->assertFalse( ErrorRecorder::record( E_WARNING, 'a warning', '/plugin/src/Foo.php', 12 ) );
		$this->assertCount( 1, ErrorRecorder::records() );
	}

	/**
	 * Tests that an error silenced with the `@` operator is not recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_silenced_error_is_not_recorded(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the silence operator is the behaviour under test.
		@ErrorRecorder::record( E_WARNING, 'silenced on purpose', '/wordpress/wp-includes/functions.php', 40 );

		$this->assertSame( array(), ErrorRecorder::records() );
	}

	/**
	 * Tests the report printed when loading raised errors.
	 *
	 * @since 0.1.0
	 */
	public function test_describe_prints_level_message_file_and_line(): void {
		ErrorRecorder::record( E_USER_DEPRECATED, 'old thing', '/plugin/seocart.php', 32 );
		ErrorRecorder::record( E_WARNING, 'bad thing', '/plugin/src/Foo.php', 12 );
		ErrorRecorder::record( E_NOTICE, 'odd thing', '/plugin/src/Bar.php', 7 );
		ErrorRecorder::record( E_USER_ERROR, 'fatal thing', '/plugin/src/Baz.php', 3 );

		$this->assertSame(
			"  Deprecated: old thing in /plugin/seocart.php:32\n"
			. "  Warning: bad thing in /plugin/src/Foo.php:12\n"
			. "  Notice: odd thing in /plugin/src/Bar.php:7\n"
			. '  Error (level 256): fatal thing in /plugin/src/Baz.php:3',
			ErrorRecorder::describe( ErrorRecorder::records() )
		);
	}
}
