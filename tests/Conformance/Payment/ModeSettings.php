<?php
/**
 * ModeSettings: the non-secret settings of a mode no recording used
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\Payment\Mode;

/**
 * Gives a mode no recording used its own value of a non-secret setting, from the recorded mode's.
 *
 * Owns one fact: a recording is made in one mode, the test mode, so a non-secret setting has a
 * recorded value for that mode alone, and every other mode the gateway declares takes its value
 * from there. A provider names a key's mode inside it, between underscores (`pk_test_`,
 * `pk_live_`), and an adapter may offer itself only when its key is of the payment's mode: so the
 * recorded mode's name between underscores is written as the other mode's, and every other value
 * (a country, an enumerated choice, a number) is the same in every mode. Secrets take no part:
 * the test's placeholder() gives each mode its own.
 *
 * @since 0.2.0
 */
final class ModeSettings {

	/**
	 * The mode a recording is made in, whose non-secret settings a tape holds.
	 *
	 * @since 0.2.0
	 *
	 * @var Mode
	 */
	public const RECORDED = Mode::Test;

	/**
	 * Returns a mode's value of a non-secret setting, from the recorded mode's.
	 *
	 * @since 0.2.0
	 *
	 * @param int|string $recorded The recorded mode's value.
	 * @param Mode       $mode     The mode.
	 * @return int|string For another mode, a text with `_test_` in it written with `_live_` in its place; any other value as it was recorded.
	 */
	public static function valueFor( int|string $recorded, Mode $mode ): int|string {
		if ( self::RECORDED === $mode || is_int( $recorded ) ) {
			return $recorded;
		}

		return str_replace( '_' . self::RECORDED->value . '_', '_' . $mode->value . '_', $recorded );
	}
}
