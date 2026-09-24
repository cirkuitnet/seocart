<?php
/**
 * RunningProbe: a probe process started by ChildProcessProbe, watched while it runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use PHPUnit\Framework\Assert;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test reads a child process's pipe and result file, which WP_Filesystem does not offer.

/**
 * A probe process that is still running: the test can watch it, and then collect its report.
 *
 * Owns one fact: the life of one probe process, from its start to its report. Everything it
 * prints, standard error included, is kept for the failure message.
 *
 * @since 0.1.0
 */
final class RunningProbe {

	/**
	 * The probe script's name, for messages.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The file the probe writes its report to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $resultFile;

	/**
	 * The process.
	 *
	 * @since 0.1.0
	 *
	 * @var resource
	 */
	private $process;

	/**
	 * The process's output, standard error included.
	 *
	 * @since 0.1.0
	 *
	 * @var resource
	 */
	private $output;

	/**
	 * What the process printed so far.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $printed = '';

	/**
	 * The exit status, once the process was seen to end.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $exitStatus = null;

	/**
	 * Whether finish() collected the process.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Starts the process. Only ChildProcessProbe::start() calls this.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name       The probe script's name.
	 * @param string $command    The shell command, standard error sent to standard output.
	 * @param string $resultFile The file the probe writes its report to.
	 */
	public function __construct( string $name, string $command, string $resultFile ) {
		$this->name       = $name;
		$this->resultFile = $resultFile;

		// Pipes only: a `file` descriptor would go through the stream wrapper Patchwork replaces in the unit suite.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			Assert::fail( 'The probe ' . $name . ' could not be started.' );
		}

		// A probe reads nothing.
		fclose( $pipes[0] );

		$this->process = $process;
		$this->output  = $pipes[1];

		stream_set_blocking( $this->output, false );
	}

	/**
	 * Waits at most the given time for the process to print or end, and tells whether it has ended.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timeoutMs How long to wait, in milliseconds.
	 * @return bool True when the process has ended.
	 */
	public function watch( int $timeoutMs ): bool {
		if ( null !== $this->exitStatus ) {
			return true;
		}

		$read   = array( $this->output );
		$write  = null;
		$except = null;

		if ( ! feof( $this->output ) && stream_select( $read, $write, $except, 0, $timeoutMs * 1000 ) > 0 ) {
			$this->printed .= (string) fread( $this->output, 65536 );
		}

		$status = proc_get_status( $this->process );

		if ( ! $status['running'] ) {
			// The exit status is reported once; proc_close() may not report it again.
			$this->exitStatus = (int) $status['exitcode'];

			return true;
		}

		return false;
	}

	/**
	 * Returns the report the probe has written so far, for a failure message; empty when none.
	 *
	 * @since 0.1.0
	 *
	 * @return string The report as written.
	 */
	public function reportSoFar(): string {
		return is_file( $this->resultFile ) ? (string) file_get_contents( $this->resultFile ) : '';
	}

	/**
	 * Returns what the process printed so far.
	 *
	 * @since 0.1.0
	 *
	 * @return string The output.
	 */
	public function output(): string {
		return $this->printed;
	}

	/**
	 * Waits for the process to end and returns its report, failing the test when it failed or wrote none.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The decoded report.
	 */
	public function finish(): array {
		stream_set_blocking( $this->output, true );

		$this->printed .= (string) stream_get_contents( $this->output );

		fclose( $this->output );

		$this->finished = true;

		$closed = proc_close( $this->process );
		$status = $this->exitStatus ?? $closed;
		$report = is_file( $this->resultFile ) ? (string) file_get_contents( $this->resultFile ) : '';

		if ( is_file( $this->resultFile ) ) {
			unlink( $this->resultFile );
		}

		if ( 0 !== $status || '' === $report ) {
			Assert::fail( 'The probe ' . $this->name . " failed with exit status {$status}. Its output:\n" . $this->printed . "\n" );
		}

		return json_decode( $report, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Kills a process no one collected, such as the probe of a test that failed while it waited.
	 *
	 * Otherwise PHP would wait for it here, and it may be waiting for a lock the failed test still
	 * holds. Its connection closes with it, and the server rolls back its open transaction.
	 *
	 * @since 0.1.0
	 */
	public function __destruct() {
		if ( $this->finished ) {
			return;
		}

		proc_terminate( $this->process, 9 );
		fclose( $this->output );
		proc_close( $this->process );

		if ( is_file( $this->resultFile ) ) {
			unlink( $this->resultFile );
		}
	}
}
