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
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Payment\Domain\OfflinePaymentEvidence;
use SEOCart\Support\Schema\Privacy;

/**
 * A statement of a payment received offline holds a calendar day written YYYY-MM-DD, and an optional reference and note of the lengths the table keeps; whether the day is between the day the order was placed and today is judged in the store's time zone.
 *
 * Planted violations, each shown red and removed:
 * - in OfflinePaymentEvidence::isBefore(), drop the conversion to the store's time zone: the day the
 *   order was placed is read in UTC, and a store at UTC+13 refuses the day it placed an order on;
 * - in OfflinePaymentEvidence::isDay(), parse without the round trip through format(): 2025-02-29
 *   is taken as 1 March;
 * - in OfflinePaymentEvidence::isAfter(), drop the conversion to the store's time zone: the day is
 *   read in the zone of the instant, UTC, and a store at UTC+13 cannot record today's payment.
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
	 * Tests that the capture operation declares the statement's fields by the names the service reads and with the lengths the statement and its table keep.
	 *
	 * @since 0.2.0
	 */
	public function test_the_capture_declares_what_a_statement_holds(): void {
		$declared = array();

		foreach ( PaymentOperations::capturePayment()->input() as $field ) {
			$declared[ $field->name() ] = array( $field->maxLength(), $field->privacy() );
		}

		$this->assertSame(
			array(
				OfflinePaymentEvidence::RECEIVED_ON => array( OfflinePaymentEvidence::DAY_LENGTH, Privacy::Public ),
				OfflinePaymentEvidence::REFERENCE   => array( OfflinePaymentEvidence::REFERENCE_MAX_LENGTH, Privacy::Pii ),
				OfflinePaymentEvidence::NOTE        => array( OfflinePaymentEvidence::NOTE_MAX_LENGTH, Privacy::Pii ),
			),
			array_intersect_key( $declared, array_flip( array( OfflinePaymentEvidence::RECEIVED_ON, OfflinePaymentEvidence::REFERENCE, OfflinePaymentEvidence::NOTE ) ) )
		);
	}

	/**
	 * Tests the day judged against now in a store whose zone is UTC: today and before are not after it, tomorrow is, whatever the zone the instant is given in.
	 *
	 * @since 0.2.0
	 */
	public function test_in_a_store_on_utc_a_day_after_the_utc_day_is_after_today(): void {
		$utc = new \DateTimeZone( 'UTC' );
		$now = new \DateTimeImmutable( '2026-11-07 23:30:00', $utc );

		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-06' ) )->isAfter( $now, $utc ) );
		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-07' ) )->isAfter( $now, $utc ) );
		$this->assertTrue( ( new OfflinePaymentEvidence( '2026-11-08' ) )->isAfter( $now, $utc ) );
		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-07' ) )->isAfter( $now->setTimezone( new \DateTimeZone( 'Pacific/Auckland' ) ), $utc ), 'The instant is the same in whatever zone it is given in.' );
	}

	/**
	 * Tests the day the order was placed: the day of its creation is accepted, the day before it is not, and a mistyped year is far before it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_day_before_the_order_was_placed_is_before_it(): void {
		$utc    = new \DateTimeZone( 'UTC' );
		$placed = new \DateTimeImmutable( '2026-11-07 23:30:00.123456', $utc );

		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-07' ) )->isBefore( $placed, $utc ), 'The day the order was placed is accepted.' );
		$this->assertFalse( ( new OfflinePaymentEvidence( '2026-11-08' ) )->isBefore( $placed, $utc ) );
		$this->assertTrue( ( new OfflinePaymentEvidence( '2026-11-06' ) )->isBefore( $placed, $utc ), 'The day before is refused.' );
		$this->assertTrue( ( new OfflinePaymentEvidence( '2025-11-07' ) )->isBefore( $placed, $utc ), 'A mistyped year is refused.' );
		$this->assertTrue( ( new OfflinePaymentEvidence( '0000-01-01' ) )->isBefore( $placed, $utc ) );
	}

	/**
	 * Tests that the day the order was placed is the store's calendar day of its creation: east of UTC an order created late in the UTC day was placed on the next, west of it on the day before.
	 *
	 * @since 0.2.0
	 */
	public function test_the_day_the_order_was_placed_is_the_stores_calendar_day(): void {
		$cases = array(
			'UTC+13, 12:00 UTC is 01:00 next day'   => array( '+13:00', '2026-11-07 12:00:00', array( '2026-11-08', '2026-11-09' ), array( '2026-11-07', '2026-11-06' ) ),
			'UTC+13, 10:59 UTC is 23:59 same day'   => array( '+13:00', '2026-11-07 10:59:59', array( '2026-11-07' ), array( '2026-11-06' ) ),
			'UTC-10, 02:00 UTC is 16:00 day before' => array( '-10:00', '2026-11-08 02:00:00', array( '2026-11-07', '2026-11-08' ), array( '2026-11-06' ) ),
			'UTC-10, 10:00 UTC is 00:00 same day'   => array( '-10:00', '2026-11-08 10:00:00', array( '2026-11-08' ), array( '2026-11-07' ) ),
			'a named zone, Auckland in NZDT'        => array( 'Pacific/Auckland', '2026-11-07 12:00:00', array( '2026-11-08' ), array( '2026-11-07' ) ),
		);

		foreach ( $cases as $case => list( $zone, $instant, $accepted, $refused ) ) {
			$timeZone = new \DateTimeZone( $zone );
			$placed   = new \DateTimeImmutable( $instant, new \DateTimeZone( 'UTC' ) );

			foreach ( $accepted as $day ) {
				$this->assertFalse( ( new OfflinePaymentEvidence( $day ) )->isBefore( $placed, $timeZone ), sprintf( '%1$s: %2$s is accepted.', $case, $day ) );
			}

			foreach ( $refused as $day ) {
				$this->assertTrue( ( new OfflinePaymentEvidence( $day ) )->isBefore( $placed, $timeZone ), sprintf( '%1$s: %2$s is before the day the order was placed.', $case, $day ) );
			}
		}
	}

	/**
	 * Tests that "today" is the store's calendar day: east of UTC the store's day is ahead of the UTC day, west of it behind.
	 *
	 * With the store at UTC+13 and the UTC clock at 22:00 on day D, it is 11:00 on D+1 at the store:
	 * D+1 is accepted and D+2 refused. With the store at UTC-10 and the UTC clock at 02:00 on D, it
	 * is 16:00 on D-1 at the store: D-1 is accepted and D, which is UTC's today, refused. At 22:00
	 * UTC on D the store at UTC-10 is at 12:00 on D: D is accepted and D+1 refused.
	 *
	 * @since 0.2.0
	 */
	public function test_today_is_the_stores_calendar_day_not_the_utc_day(): void {
		$cases = array(
			'UTC+13, 22:00 UTC on 11-07'       => array( '+13:00', '2026-11-07 22:00:00', array( '2026-11-07', '2026-11-08' ), array( '2026-11-09' ) ),
			'UTC+13, just before its midnight' => array( '+13:00', '2026-11-07 10:59:59', array( '2026-11-07' ), array( '2026-11-08' ) ),
			'UTC+13, at its midnight'          => array( '+13:00', '2026-11-07 11:00:00', array( '2026-11-08' ), array( '2026-11-09' ) ),
			'UTC-10, 02:00 UTC on 11-07'       => array( '-10:00', '2026-11-07 02:00:00', array( '2026-11-05', '2026-11-06' ), array( '2026-11-07', '2026-11-08' ) ),
			'UTC-10, 22:00 UTC on 11-07'       => array( '-10:00', '2026-11-07 22:00:00', array( '2026-11-06', '2026-11-07' ), array( '2026-11-08' ) ),
			'a named zone, Auckland in NZDT'   => array( 'Pacific/Auckland', '2026-11-07 22:00:00', array( '2026-11-08' ), array( '2026-11-09' ) ),
		);

		foreach ( $cases as $case => list( $zone, $instant, $accepted, $refused ) ) {
			$timeZone = new \DateTimeZone( $zone );
			$now      = new \DateTimeImmutable( $instant, new \DateTimeZone( 'UTC' ) );

			foreach ( $accepted as $day ) {
				$this->assertFalse( ( new OfflinePaymentEvidence( $day ) )->isAfter( $now, $timeZone ), sprintf( '%1$s: %2$s is accepted.', $case, $day ) );
			}

			foreach ( $refused as $day ) {
				$this->assertTrue( ( new OfflinePaymentEvidence( $day ) )->isAfter( $now, $timeZone ), sprintf( '%1$s: %2$s is after the store\'s today.', $case, $day ) );
			}
		}
	}
}
