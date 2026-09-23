<?php
/**
 * RoundingMode: the rules by which a number loses digits
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Names how a number is rounded when it must lose digits.
 *
 * This enum owns one fact: which rounding rules SEOCart implements, and the name each one is
 * stored under in `currencies.rounding_mode`. Rounding happens only where code names one of
 * these cases; no operation rounds on its own.
 *
 * Only the modes that a documented rule needs exist:
 *
 * - HalfUp rounds to the nearest value and a tie away from zero: 2.5 becomes 3 and -2.5
 *   becomes -3. It is the default of `currencies.rounding_mode`, and so the rounding of a
 *   converted price, of tax computed from a rate and of a cash rounding step. Because a tie
 *   moves away from zero, rounding a negated amount gives the negated result, so a refund
 *   rounds exactly as the charge did.
 * - TowardZero drops the extra digits: 2.7 becomes 2 and -2.7 becomes -2. Largest-remainder
 *   allocation (ADR-0004) starts every share at its exact proportion rounded toward zero.
 *
 * Half-even, floor and ceiling are not implemented because no documented rule uses them. A
 * mode is added together with the rule that needs it.
 *
 * @since 0.1.0
 */
enum RoundingMode: string {

	/**
	 * To the nearest value; a tie rounds away from zero.
	 *
	 * @since 0.1.0
	 */
	case HalfUp = 'half_up';

	/**
	 * Drops the extra digits, which moves the value toward zero.
	 *
	 * @since 0.1.0
	 */
	case TowardZero = 'toward_zero';
}
