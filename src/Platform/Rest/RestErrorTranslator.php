<?php
/**
 * RestErrorTranslator: turns a coded failure into the WP_Error every surface answers with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Rest;

use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Error\ErrorTableException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The error translator of the REST routes, the abilities and the WP-CLI commands.
 *
 * This class owns one fact: what a client learns about a failure. For a coded failure it looks the
 * code up in the one error table:
 *
 * - a public row gives `WP_Error( code, message, data )`, the message being the row rendered with
 *   the error's context — which OperationInvoker has already redacted — and the data
 *   ErrorShape's `{ status, details, correlation_id }`, the details being that context;
 * - an internal row, such as every database failure, gives the row's code and status with a
 *   generic message, empty details and the correlation id. The full error — code, context and
 *   previous exceptions, where a database failure keeps its statement — goes to the reporter,
 *   never to the client;
 * - a code the table does not hold is a wiring mistake: it is reported and answered like an
 *   internal failure, with the generic internal error.
 *
 * The correlation id comes from the provider the kernel injects, and every error carries it, so
 * a client can quote it and an administrator can find the log line. The reporter defaults to
 * one line in PHP's error log until the logging module provides one.
 *
 *     $translator = new RestErrorTranslator( $error_table, $correlation_id_provider );
 *     $invoker    = new OperationInvoker( $resolve, $translator );
 *
 * @since 0.1.0
 */
final class RestErrorTranslator implements ErrorTranslator {

	/**
	 * The one error table.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTable
	 */
	private ErrorTable $errors;

	/**
	 * Returns the correlation id of the request being served, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): (string|null)
	 */
	private \Closure $correlationId;

	/**
	 * Receives every internal failure, with the correlation id the client was given.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(CodedException, string|null): void
	 */
	private \Closure $report;

	/**
	 * Creates the translator.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorTable    $errors         The one error table, composed from every catalog.
	 * @param callable      $correlation_id Returns the correlation id of the request being served,
	 *                                      or null when there is none. Called once per error.
	 * @param callable|null $report         Optional. Receives an internal failure and the
	 *                                      correlation id the client was given. Default: one line
	 *                                      in PHP's error log, until the logging module provides
	 *                                      the reporter.
	 *
	 * @phpstan-param callable(): (string|null)                          $correlation_id
	 * @phpstan-param (callable(CodedException, string|null): void)|null $report
	 */
	public function __construct( ErrorTable $errors, callable $correlation_id, ?callable $report = null ) {
		$this->errors        = $errors;
		$this->correlationId = \Closure::fromCallable( $correlation_id );
		$this->report        = null === $report ? \Closure::fromCallable( array( self::class, 'logInternal' ) ) : \Closure::fromCallable( $report );
	}

	/**
	 * Translates a coded failure.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $error The failure, its context already redacted.
	 * @return WP_Error The error: the row's code, status and message for a public row, its details
	 *                  the placeholders' values and the structured details; the row's code and
	 *                  status with a generic message and no details for an internal one.
	 */
	public function translate( CodedException $error ): WP_Error {
		$correlation_id = $this->correlationId();

		try {
			$row = $this->errors->definitionFor( $error->errorCode() );
		} catch ( ErrorTableException $missing ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'The error code %1$s is not in the error table the translator was given: compose the table with its catalog. The client got the generic internal error.', (string) $error->errorCode()->value ) ),
				'0.1.0'
			);

			( $this->report )( $error, $correlation_id );

			return $this->generic( self::INTERNAL_ERROR, self::INTERNAL_STATUS, $correlation_id );
		}

		if ( $row->isInternal() ) {
			( $this->report )( $error, $correlation_id );

			return $this->generic( (string) $error->errorCode()->value, $row->httpStatus(), $correlation_id );
		}

		return new WP_Error(
			(string) $error->errorCode()->value,
			$row->render( $error->context() ),
			ErrorShape::data( $row->httpStatus(), $error->context() + $error->details(), $correlation_id )
		);
	}

	/**
	 * Returns the generic internal error, for a failure no client caused.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error The error, with a generic message, no details and the correlation id.
	 */
	public function unexpected(): WP_Error {
		return $this->generic( self::INTERNAL_ERROR, self::INTERNAL_STATUS, $this->correlationId() );
	}

	/**
	 * Gives an error WordPress raised on a plugin route exactly the three data members.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error.
	 * @return WP_Error The error: its status, everything else WordPress put in its data as details,
	 *                  and the correlation id. An error already in that shape is unchanged.
	 */
	public function conform( WP_Error $error ): WP_Error {
		return ErrorShape::conform( $error, $this->correlationId() );
	}

	/**
	 * Writes an internal failure to PHP's error log: the default reporter.
	 *
	 * The line names the code, the correlation id the client was given, the context and each
	 * previous exception — for a database failure, the statement and the server's text. It is
	 * written for the site's administrator and never reaches a client.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $error          The failure.
	 * @param string|null    $correlation_id The correlation id the client was given, or null.
	 */
	public static function logInternal( CodedException $error, ?string $correlation_id ): void {
		$causes = '';

		for ( $cause = $error->getPrevious(); null !== $cause; $cause = $cause->getPrevious() ) {
			$causes .= '; caused by ' . get_class( $cause ) . ': ' . $cause->getMessage();
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the default reporter of internal failures, until the logging module provides one; the line never reaches a client.
		error_log( sprintf( 'SEOCart: internal error %1$s (correlation id %2$s): %3$s%4$s', (string) $error->errorCode()->value, $correlation_id ?? 'none', (string) wp_json_encode( $error->context() ), $causes ) );
	}

	/**
	 * Builds an error that tells the client nothing but its code, its status and the correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $code           The error code.
	 * @param int         $status         The HTTP status.
	 * @param string|null $correlation_id The correlation id.
	 * @return WP_Error The error.
	 */
	private function generic( string $code, int $status, ?string $correlation_id ): WP_Error {
		return new WP_Error(
			$code,
			__( 'The operation failed because of an internal error. The site administrator can find the details in the error log.', 'seocart' ),
			ErrorShape::data( $status, array(), $correlation_id )
		);
	}

	/**
	 * Asks the provider for the correlation id of the request being served.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when the provider has none or answers with anything but
	 *                     a non-empty string.
	 */
	private function correlationId(): ?string {
		$id = ( $this->correlationId )();

		return is_string( $id ) && '' !== $id ? $id : null;
	}
}
