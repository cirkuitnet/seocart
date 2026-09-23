<?php
/**
 * Reporter: the one reporter every module's failure reports are bound to
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a module reports into a log line.
 *
 * Owns one fact: how a report becomes a line. Modules report what they cannot throw through a
 * callable they are given, and each of the three shapes they use is a method here, so the
 * kernel binds every module to one object:
 *
 * - __invoke( string $code, array $context ) is the shape of the database layer, the migrator,
 *   the transaction guards, the event bridge and drainer, and the jobs runner. The line is a
 *   warning: the work that found the problem carried on.
 * - unexpected( \Throwable $failure, string $operationId ) is the operation invoker's, for a
 *   service that failed with something other than a coded error. The line is an error, under
 *   `operations.unexpected_failure`, with the operation id and the exception.
 * - internal( CodedException $error, ?string $correlationId ) is the error translator's, for a
 *   coded failure whose row is internal, such as a database error. The line is an error under
 *   the failure's own code, with its context and the exception chain; it is written under the
 *   correlation id the client was given.
 *
 * Every line carries the correlation id in force, whichever path it came by, and everything is
 * redacted by the logger: the statement and server text a database failure carries are written
 * without their values. The logger is resolved on the first report, not before, so binding the
 * reporter costs nothing and the database layer can be given it before the logger exists. If
 * it cannot be built, that is remembered and never tried again in the process; each report
 * lost for it is accounted for in the FallbackLog, one line for the reason and a count at the
 * end of the process. A report never throws.
 *
 *     new Database( $wpdb, $strict, $reporter );
 *     new OperationInvoker( $resolve, $translator, array( $reporter, 'unexpected' ) );
 *     new RestErrorTranslator( $table, $correlationProvider, array( $reporter, 'internal' ) );
 *
 * @since 0.1.0
 */
final class Reporter {

	/**
	 * Returns the logger, the first time a report needs it.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Logger
	 */
	private \Closure $resolve;

	/**
	 * The correlation id in force.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * The logger, once resolved.
	 *
	 * @since 0.1.0
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Why the logger could not be built, once that failed: the class of what was thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $unbuildable = null;

	/**
	 * Accounts for reports lost because the logger could not be built.
	 *
	 * @since 0.1.0
	 *
	 * @var FallbackLog
	 */
	private FallbackLog $fallback;

	/**
	 * Creates the reporter. Resolves nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param callable         $logger      Returns the logger; called on the first report.
	 * @param CorrelationId    $correlation The correlation id in force.
	 * @param FallbackLog|null $fallback    Optional. Accounts for reports lost when the logger cannot be
	 *                                      built; the kernel gives the reporter and the logger the same
	 *                                      one. Default null, a FallbackLog of its own over PHP's error log.
	 *
	 * @phpstan-param callable(): Logger $logger
	 */
	public function __construct( callable $logger, CorrelationId $correlation, ?FallbackLog $fallback = null ) {
		$this->resolve     = \Closure::fromCallable( $logger );
		$this->correlation = $correlation;
		$this->fallback    = $fallback ?? new FallbackLog();
	}

	/**
	 * Reports something the work carried on after: a code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $code    The module's report code, such as `events.listener_failed`.
	 * @param array<mixed> $context What the module knows about it.
	 */
	public function __invoke( string $code, array $context ): void {
		$this->write( Level::Warning, $code, 'Reported without stopping the work that found it.', $context );
	}

	/**
	 * Reports an operation whose service failed with something other than a coded error.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure     The failure.
	 * @param string     $operationId The operation it happened in.
	 */
	public function unexpected( \Throwable $failure, string $operationId ): void {
		$this->write(
			Level::Error,
			ReportCode::OperationFailed->value,
			'An operation failed unexpectedly; the client was given the generic internal error.',
			array(
				'operation_id' => $operationId,
				'exception'    => $failure,
			)
		);
	}

	/**
	 * Reports a coded failure the client learned nothing about but its code and the correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $error         The failure, with its previous exceptions.
	 * @param string|null    $correlationId The correlation id the client was given, or null.
	 */
	public function internal( CodedException $error, ?string $correlationId ): void {
		try {
			$this->correlation->scoped(
				$correlationId,
				function () use ( $error ): void {
					$this->write(
						Level::Error,
						(string) $error->errorCode()->value,
						'An internal error answered a request; the client was given its code and the correlation id only.',
						array( 'exception' => $error ) + $error->context()
					);
				}
			);
		} catch ( \Throwable $failure ) {
			// Only restoring the correlation id could throw here, and a report never does.
			unset( $failure );
		}
	}

	/**
	 * Writes a line through the logger, resolving it first if needed. Never throws.
	 *
	 * @since 0.1.0
	 *
	 * @param Level        $level   The level.
	 * @param string       $code    The machine code.
	 * @param string       $message A fixed sentence.
	 * @param array<mixed> $context The values.
	 */
	private function write( Level $level, string $code, string $message, array $context ): void {
		if ( null === $this->logger && null === $this->unbuildable ) {
			try {
				$this->logger = ( $this->resolve )();
			} catch ( \Throwable $failure ) {
				// Remembered for the rest of the process: building the logger is not tried again.
				$this->unbuildable = get_class( $failure );
			}
		}

		if ( null === $this->logger ) {
			$reason = sprintf( 'the logger could not be built (%s)', (string) $this->unbuildable );

			$this->fallback->lost( $reason, sprintf( 'SEOCart: a report (%1$s, %2$s) was lost: %3$s.', $level->value, Logger::isValidCode( $code ) ? $code : ReportCode::InvalidCode->value, $reason ) );

			return;
		}

		$this->logger->log( $level, $code, $message, $context );
	}
}
