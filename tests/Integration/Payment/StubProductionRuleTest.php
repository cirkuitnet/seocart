<?php
/**
 * Tests that a production site offers the stand-in gateway only when it says so
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\DatabaseTestCase;

/**
 * The stand-in approves every payment, so a production site's registry does not offer it, unless SEOCART_STUB_GATEWAY is defined true, as the test suite defines it.
 *
 * Each side runs in a process of its own, booted as the suite boots, on the test site, whose
 * environment type is WordPress's default, `production`.
 *
 * Planted violation, shown red and removed: in Modules::paymentRegister(), pass the stand-in to the
 * registry whatever Gateways::stubAllowed() says: the production site without the constant then
 * offers it.
 *
 * @since 0.2.0
 */
final class StubProductionRuleTest extends DatabaseTestCase {

	/**
	 * The variable that has the integration bootstrap leave SEOCART_STUB_GATEWAY undefined.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WITHOUT = 'SEOCART_TESTS_WITHOUT_STUB_GATEWAY';

	/**
	 * Tests that the stand-in is absent from a production site without the constant, and present with it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_production_site_offers_the_stand_in_only_when_it_says_so(): void {
		$script = dirname( __DIR__, 2 ) . '/Support/Payment/stub-rule-probe.php';

		putenv( self::WITHOUT . '=1' );

		try {
			$without = ChildProcessProbe::run( $script );
		} finally {
			putenv( self::WITHOUT );
		}

		$with = ChildProcessProbe::run( $script );

		$this->assertSame(
			array(
				'environment_type' => 'production',
				'constant_defined' => false,
				'stub_mode'        => null,
			),
			$without,
			'A production site without the constant does not offer the stand-in.'
		);
		$this->assertSame(
			array(
				'environment_type' => 'production',
				'constant_defined' => true,
				'stub_mode'        => 'test',
			),
			$with,
			'With the constant, it does, in test mode.'
		);
	}
}
