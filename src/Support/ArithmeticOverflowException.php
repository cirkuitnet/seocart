<?php
/**
 * ArithmeticOverflowException: a result that does not fit a PHP integer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reports an integer result that would not fit in 64 bits.
 *
 * This class owns one fact: that SEOCart's integer arithmetic is checked. PHP silently turns
 * an integer that overflows into a float, and a float in the money path is a defect, so every
 * operation that could overflow checks first and throws this instead. It is a programming
 * error, not a condition a customer can cause through valid input, so it is a LogicException
 * and has no row in the error table.
 *
 * @since 0.1.0
 */
final class ArithmeticOverflowException extends \LogicException {

	/**
	 * Creates the exception for an operation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $operation The operation that overflowed, for example 'Money::add()'.
	 * @return self The exception, ready to throw.
	 */
	public static function in( string $operation ): self {
		return new self( sprintf( 'The result of %s does not fit a 64-bit integer.', $operation ) );
	}
}
