<?php
/**
 * Tests the non-secret settings a mode no recording used is given
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\ModeSettings;

/**
 * A mode no recording used writes the recorded mode's name inside a value as its own, and keeps every other value as recorded.
 *
 * @since 0.2.0
 */
final class ModeSettingsTest extends TestCase {

	/**
	 * Returns recorded values, each with the value the live mode is given.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{int|string, int|string}> The recorded value, and the live mode's.
	 */
	public function values(): array {
		return array(
			'a publishable key'                        => array( 'pk_test_example', 'pk_live_example' ),
			'a long publishable key'                   => array( 'pk_test_51Example0123456789', 'pk_live_51Example0123456789' ),
			'the reference gateway\'s account country' => array( 'US', 'US' ),
			'another country'                          => array( 'GB', 'GB' ),
			'a word ending in the mode\'s name'        => array( 'UStest', 'UStest' ),
			'the mode\'s name alone'                   => array( 'test', 'test' ),
			'an enumerated choice'                     => array( 'automatic', 'automatic' ),
			'a number'                                 => array( 30, 30 ),
		);
	}

	/**
	 * Tests that the live mode is given the recorded value with `_test_` in it written `_live_`, and every other value as recorded, while the test mode is given the value as recorded.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider values
	 * @group conformance
	 *
	 * @param int|string $recorded The recorded value.
	 * @param int|string $live     The live mode's value.
	 */
	public function test_a_mode_no_recording_used_names_itself_where_the_value_names_the_recorded_one( int|string $recorded, int|string $live ): void {
		$this->assertSame( $live, ModeSettings::valueFor( $recorded, Mode::Live ) );
		$this->assertSame( $recorded, ModeSettings::valueFor( $recorded, Mode::Test ) );
	}

	/**
	 * Tests that an availability check reading a key's mode from its prefix offers the gateway in both modes with the value each is given, as rule 16 places a payment in each, and would not offer it in live mode with the recorded value.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_availability_check_that_reads_the_key_mode_passes_in_both_modes(): void {
		$recorded  = 'pk_test_51Example0123456789';
		$available = static fn( Mode $mode, string $key ): bool => str_starts_with( $key, 'pk_' . $mode->value . '_' );

		foreach ( array( Mode::Test, Mode::Live ) as $mode ) {
			$this->assertTrue( $available( $mode, (string) ModeSettings::valueFor( $recorded, $mode ) ), sprintf( 'The %s mode was given a key of another mode.', $mode->value ) );
		}

		$this->assertFalse( $available( Mode::Live, $recorded ), 'The recorded key reads as a live one.' );
	}
}
