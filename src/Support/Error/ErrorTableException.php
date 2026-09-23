<?php
/**
 * ErrorTableException: an error catalog, row or context that breaks the error model's rules
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a mistake in how errors are declared or raised.
 *
 * This class owns one fact: that the one error table refuses to be built wrong. A malformed
 * code, a status outside 4xx and 5xx, a code with no row or two, a code declared by two
 * modules, or an error raised with context that does not match its row's placeholders is a
 * programming error found while the table is composed or the error is raised — never a
 * condition a client can cause — so this is a LogicException with no row of its own.
 *
 * @since 0.1.0
 */
final class ErrorTableException extends \LogicException {

	/**
	 * Creates the exception with a message naming what is wrong.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $format A sprintf() format describing the mistake.
	 * @param string|int ...$values The codes, class names or counts the format names.
	 * @return self The exception, ready to throw.
	 */
	public static function because( string $format, string|int ...$values ): self {
		return new self( vsprintf( $format, $values ) );
	}
}
