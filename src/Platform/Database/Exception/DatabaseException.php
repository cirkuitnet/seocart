<?php
/**
 * DatabaseException: the base of every failure the Database module raises
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

use SEOCart\Platform\Database\StatementDiagnostic;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * A coded failure of the Database module; catch it to catch any of them.
 *
 * Owns one fact: which exceptions belong to the Database module. Each concrete subclass
 * raises exactly one case of DatabaseError, named in its CODE constant, and reads its typed
 * accessors from the context, whose keys are that case's placeholders. The SQL and the
 * server's error text are never in the context, which an adapter renders: they travel in a
 * StatementDiagnostic among the previous exceptions, and diagnostic() finds it.
 *
 * Subclasses are raised with raise(), or built with because() or a named builder where the
 * context is computed or a previous exception is carried. Callers decide on the class or on
 * errorCode(), never on the message, which is the code.
 *
 * @since 0.1.0
 */
abstract class DatabaseException extends CodedException {

	/**
	 * Returns the diagnostic of the statement behind this failure, if there is one.
	 *
	 * @since 0.1.0
	 *
	 * @return StatementDiagnostic|null The nearest diagnostic among the previous exceptions, or null.
	 */
	public function diagnostic(): ?StatementDiagnostic {
		for ( $previous = $this->getPrevious(); null !== $previous; $previous = $previous->getPrevious() ) {
			if ( $previous instanceof StatementDiagnostic ) {
				return $previous;
			}
		}

		return null;
	}
}
