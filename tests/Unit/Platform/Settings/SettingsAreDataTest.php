<?php
/**
 * Tests that the settings declarations and the settings operations are built without WordPress
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Settings;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\SettingsSnapshot;

/**
 * Declarations are data: the settings registry is pure.
 *
 * The production registry and both settings operations are built, and every schema dialect is
 * compiled from them, in a child PHP process that has loaded Composer's autoloader and nothing
 * else. A WordPress call anywhere in that — a translation outside a label closure, an option read
 * in a factory — is a fatal error there, which fails this test. The answers must equal the ones
 * this process gets, so the probe cannot pass by building less.
 *
 * Planted violation: in InternationalSettings::settings(), before the return, add
 * `__( 'Base currency', 'seocart' );`. The probe then dies with "Call to undefined function __()".
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class SettingsAreDataTest extends TestCase {

	/**
	 * Tests that the declarations are built, compiled and read in a process without WordPress.
	 *
	 * @since 0.1.0
	 */
	public function test_the_settings_are_built_in_a_process_without_wordpress(): void {
		$report = ChildProcessProbe::run( dirname( __DIR__, 4 ) . '/tests/Support/settings-probe.php' );

		$this->assertFalse( $report['wordpress_loaded'], 'The probe loaded WordPress, so it cannot show that the settings need none.' );
		$this->assertSame( array(), $report['wordpress_functions'], 'WordPress functions exist in the probe process, so a call to one would not fail there.' );
		$this->assertSame( json_decode( (string) json_encode( SettingsSnapshot::take() ), true ), $report['snapshot'], 'The settings answered differently in a process without WordPress.' );
		$this->assertArrayHasKey( 'seocart_international_base_currency', $report['snapshot']['options'], 'The probe did not build the production list.' );
		$this->assertSame( array( 'settings.get_settings', 'settings.update_settings' ), array_keys( $report['snapshot']['operations'] ), 'The probe did not build both operations.' );
	}
}
