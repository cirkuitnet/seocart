<?php
/**
 * SchemaException: a declaration, or a value checked against one, that breaks the schema rules
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a field, resource schema or operation declared wrong, or a service result that does not
 * match the output its operation declares.
 *
 * This class owns one fact: that the declaration mechanism refuses to be used wrong, loudly and
 * at once. Every case is a programming error found while code declares or serves an operation —
 * never a condition a client can cause — so this is a LogicException with no error-table row.
 *
 * Raise it with raise(), which builds the exception and then throws it, as CodedException::raise()
 * does: its messages name declarations (fields, resources, operations, capabilities) for the
 * developer who wrote them, never request data, and are never rendered as HTML, so a throw site
 * needs no escaping and no ignore comment.
 *
 * @since 0.1.0
 */
final class SchemaException extends \LogicException {

	/**
	 * Throws the exception with a message naming what is wrong.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException Always.
	 *
	 * @param string     $format    A sprintf() format describing the mistake.
	 * @param string|int ...$values The names or numbers the format names.
	 * @return never
	 */
	public static function raise( string $format, string|int ...$values ): never {
		$exception = new self( vsprintf( $format, $values ) );

		throw $exception;
	}
}
