<?php
/**
 * Tests what a person may state of a payment received offline
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\OfflinePaymentEvidence;

/**
 * A statement of a payment received offline holds a calendar day written YYYY-MM-DD, and an optional reference and note of the lengths the table keeps; whether the day is after today is judged in UTC.
 *
 * Planted violation, shown red and removed: in OfflinePaymentEvidence::isDay(), parse without the
 * round trip through format(): 2025-02-29 is taken as 1 March.
 *
 * @since 0.2.0
 */
final class OfflinePaymentEvidenceTest extends TestCase {

	/**
	 * Tests the days a statement takes, and those it does not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_day_is_a_calendar_day_written_yyyy_mm_dd(): void {
		foreach ( array( '2024-02-29', '2026-11-07', '1999-12-31' ) as $day ) {
			$this->assertTrue( OfflinePaymentEvidence::isDay( $day ), $day );
		}

		foreach ( array( '2025-02-29', '2026-13-01', '2026-11-31', '2026-2-1', '07-11-2026', '2026-11-07 ', '2026-11-07T00:00', '' ) as $day ) {
			$this->assertFalse( OfflinePaymentEvidence::isDay( $day ), $day );
			$this->assertSame( OfflinePaymentEvidence::RECEIVED_ON, OfflinePaymentEvidence::invalidField( $day, null, null ), $day );
		}
	}

	/**
	 * Tests the reference and the note: none, or one to the length the table keeps, never empty.
	 *
	 * @since 0.2.0
	 */
	public function test_a_reference_and_a_note_are_none_or_of_the_lengths_kept(): void {
		$this->assertNull( OfflinePaymentEvidence::invalidField( '2026-11-07', null, null ) );
		$this->assertNull( OfflinePaymentEvidence::invalidField( '2026-11-07', str_repeat( 'é', OfflinePaymentEvidence::REFERENCE_MAX_LENGTH ), str_repeat( 'n', OfflinePaymentEvidence::NOTE_MAX_LENGTH ) ) );
		$this->assertSame( OfflinePaymentEvidence::REFERENCE, OfflinePaymentEvidence::invalidField( '2026-11-07', '', null ) );
		$this->assertSame( OfflinePaymentEvidence::REFERENCE, OfflinePaymentEvidence::invalidField( '2026-11-07', str_repeat( 'r', OfflinePaymentEvidence::REFERENCE_MAX_LENGTH + 1 ), null ) );
		$this->assertSame( OfflinePaymentEvidence::NOTE, OfflinePaymentEvidence::invalidField( '2026-11-07', null, str_repeat( 'n', OfflinePaymentEvidence::NOTE_MAX_LENGTH + 1 ) ) );

		$this->expectException( \InvalidArgumentException::class );

		new OfflinePaymentEvidence( '2026-11-07', null, '' );
	}

	/**
	 * Tests the day judged against now, in UTC: today and before are not after it, tomorrow is, whatever the zone the instant is given in.
	 *
	 * @since 0.2.0
	 */
	public function test_a_day_after_today_is_judged_in_utc(): void {
		$now = new \DateTimeImmutable( '2026-11-07 23:30:00', new \DateTimeZone( 'UTC' ) );

		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-06' ) )->isAfter( $now ) );
		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-07' ) )->isAfter( $now ) );
		$this->assertTrue( ( new OfflinePaymentEvidence( '2026-11-08' ) )->isAfter( $now ) );
		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-07' ) )->isAfter( $now->setTimezone( new \DateTimeZone( 'Pacific/Auckland' ) ) ), 'The same instant in another zone is the same day in UTC.' );
	}
}
