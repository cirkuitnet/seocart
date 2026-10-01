<?php
/**
 * Tests doctor's rates check: the current exchange-rate version against the newest stored, and its repair
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Infrastructure\Doctor\RateVersionCheck;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * Runs the kernel's rates check, and its repair, on a site installed at the code's schema head.
 *
 * Each step builds its container afresh, as a new request would, so what one step recorded is read
 * back from the installation record.
 *
 * Planted violations, each shown red and removed:
 *
 * - in the kernel's binding of the check, repair through the saves' recording, which never moves
 *   the version back: a current version with no rates stored stays current after --repair, and
 *   the ahead test fails;
 * - in RateVersionCheck::run(), pass whenever the current version is not behind the newest stored:
 *   a current version with no rates stored is never reported, and the ahead test fails.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class RateVersionCheckTest extends KernelTestCase {

	use PricesInCurrencies;

	/**
	 * Creates the pricing tables, on a site installed at the code's schema head.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();
	}

	/**
	 * Tests that the check passes while the current version is the newest stored, or none is stored and none is current, and that doctor runs it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_newest_stored_version_being_current_passes(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() ) );

		$this->assertTrue( $this->check()->run()->passed, 'No rate stored and none current.' );

		$this->saveThroughTheKernel( '0.91230' );

		$result = $this->check()->run();

		$this->assertTrue( $result->passed );
		$this->assertSame( 'The current exchange-rate version, 1, is the newest stored.', $result->summary );
		$this->assertContains( RateVersionCheck::NAME, array_map( static fn( Check $check ): string => $check->name(), $this->doctor()->checks() ) );
	}

	/**
	 * Tests that a save that committed and was never made current is reported, and made current by --repair.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_never_made_current_is_reported_and_repaired(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() ) );
		$this->saveThroughTheKernel( '0.91230' );

		// A save whose request ended between its commit and its recording.
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.92' ) ), Actor::user( 0 ) );

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( array( 'Exchange-rate version 2 is stored, but version 1 is current: a save committed and was never made current, so carts still price at the older rates. --repair makes version 2 current.' ), $result->findings );

		$check = $this->check();

		$check->run();

		$this->assertSame( array( 'made version 2 current in place of version 1' ), $check->repair()->changes );
		$this->assertSame( 2, $this->storedRateVersion() );
		$this->assertTrue( $this->check()->run()->passed );
		$this->assertSame( array(), $this->check()->repair()->changes, 'A second repair found something to change.' );
	}

	/**
	 * Tests that a current version with no rates stored is reported, moved back by --repair alone, and that saves are made current again afterwards.
	 *
	 * @since 0.1.0
	 */
	public function test_a_current_version_ahead_of_the_stored_rates_is_moved_back_by_repair(): void {
		// The rates were restored from an empty copy while version 5 was current; the save that follows stores version 1, which is never made current.
		$this->plantRecord( self::installedRecord( self::codeHead() )->withRateVersion( 5 ) );
		$this->saveThroughTheKernel( '0.91230' );

		$this->assertSame( 5, $this->storedRateVersion() );

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( array( 'Exchange-rate version 5 is current, but the newest stored is version 1: carts in other currencies are refused, and later saves are not made current. --repair makes version 1 current.' ), $result->findings );

		$check = $this->check();

		$check->run();

		$this->assertSame( array( 'made version 1 current in place of version 5' ), $check->repair()->changes );
		$this->assertSame( 1, $this->storedRateVersion(), 'Only a repair moves the current version back.' );

		$this->saveThroughTheKernel( '0.92' );

		$this->assertSame( 2, $this->storedRateVersion(), 'A save after the repair was not made current.' );
		$this->assertTrue( $this->check()->run()->passed );
	}

	/**
	 * Tests that a current version with no rate stored at all is reported, and that --repair leaves no version current.
	 *
	 * @since 0.1.0
	 */
	public function test_a_current_version_with_no_rate_stored_is_cleared_by_repair(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() )->withRateVersion( 3 ) );

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( array( 'Exchange-rate version 3 is current, but no rate is stored: carts in other currencies are refused, and later saves are not made current. --repair makes no version current.' ), $result->findings );
		$this->assertSame( array( 'made no version current in place of version 3' ), $this->check()->repair()->changes );
		$this->assertNull( $this->storedRateVersion() );
		$this->assertTrue( $this->check()->run()->passed );
	}

	/**
	 * Saves a set of one EUR rate through the kernel's writer, which makes it current.
	 *
	 * @since 0.1.0
	 *
	 * @param string $rate The rate.
	 */
	private function saveThroughTheKernel( string $rate ): void {
		$rates = $this->container()->get( ExchangeRates::class );

		$this->assertInstanceOf( ExchangeRates::class, $rates );

		$rates->saveVersion( array( self::rateTo( 'EUR', $rate ) ), Actor::user( 0 ) );
	}

	/**
	 * Returns the kernel's rates check, in a container of its own.
	 *
	 * @since 0.1.0
	 *
	 * @return RateVersionCheck The check.
	 */
	private function check(): RateVersionCheck {
		$check = $this->container()->get( RateVersionCheck::class );

		$this->assertInstanceOf( RateVersionCheck::class, $check );

		return $check;
	}

	/**
	 * Returns the kernel's doctor.
	 *
	 * @since 0.1.0
	 *
	 * @return Doctor The doctor.
	 */
	private function doctor(): Doctor {
		$doctor = $this->container()->get( Doctor::class );

		$this->assertInstanceOf( Doctor::class, $doctor );

		return $doctor;
	}
}
