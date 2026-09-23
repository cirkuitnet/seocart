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

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * A coded failure of the Database module; catch it to catch any of them.
 *
 * Owns one fact: which exceptions belong to the Database module. Each concrete subclass
 * raises exactly one case of DatabaseError, named in its CODE constant, and reads its typed
 * accessors from the context, whose keys are that case's placeholders. Subclasses are raised
 * with raise(), or built with because() or a named builder where the context is computed or
 * a previous exception is carried; callers decide on the class or on errorCode(), never on
 * the message, which is the code.
 *
 * @since 0.1.0
 */
abstract class DatabaseException extends CodedException {
}
