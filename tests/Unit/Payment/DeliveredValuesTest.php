<?php
/**
 * Tests the shapes the store keeps a provider's values in, and how it prints them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Payment\Application\DeliveredValues;
use SEOCart\Payment\Domain\Webhook\WebhookReceipts;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * A type is a provider's event name or `other`; an identifier is kept as the provider gave it when its characters are an identifier's, whatever it looks like, and as a stand-in otherwise; every identity the store derives begins with `~`, which no provider value keeps; and what is kept is printed without a card number.
 *
 * Planted violations, each shown red and removed:
 * - in DeliveredValues::identifier(), refuse what CardNumbers::contains() reads as a card number:
 *   a provider's numeric reference is no longer kept as it was given;
 * - in DeliveredValues::identity(), drop the mode: the same event id at the test and the live
 *   address is one ledger identity;
 * - in DeliveredValues::identifier(), let the synthetic mark through (drop the `str_starts_with()`
 *   test and add `~` to IDENTIFIER): a provider's value shaped like a stand-in is kept as one;
 * - in DeliveredValues::printable(), return the value as kept: a card-shaped reference is printed;
 * - in DeliveredValues::madeReference(), drop the mode test: `~event:prod:…`, which the store never
 *   makes, is taken for an identity it made;
 * - in DeliveredValues::madeReference(), drop the length test: an identity longer than the store
 *   makes is taken for one it made.
 *
 * @since 0.2.0
 */
final class DeliveredValuesTest extends TestCase {

	/**
	 * Tests the types kept as sent, and those kept as `other`.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_types
	 *
	 * @param string $sent The type a provider sent.
	 * @param string $kept The type kept.
	 */
	public function test_a_type_is_a_providers_event_name_or_other( string $sent, string $kept ): void {
		$this->assertSame( $kept, DeliveredValues::type( $sent ) );
	}

	/**
	 * Types as providers send them, and what is kept.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string}> The type sent, and the type kept.
	 */
	public static function data_types(): array {
		return array(
			'dotted'                => array( 'payment_intent.succeeded', 'payment_intent.succeeded' ),
			'capitals'              => array( 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED' ),
			'one word'              => array( 'dispute', 'dispute' ),
			'a card number'         => array( '4242424242424242', DeliveredValues::OTHER ),
			'a digit inside'        => array( 'charge.4242', DeliveredValues::OTHER ),
			'a space'               => array( 'charge succeeded', DeliveredValues::OTHER ),
			'empty'                 => array( '', DeliveredValues::OTHER ),
			'a dot first'           => array( '.charge', DeliveredValues::OTHER ),
			'longer than a receipt' => array( str_repeat( 'a', WebhookReceipts::EVENT_TYPE_LENGTH + 1 ), DeliveredValues::OTHER ),
			'as long as a receipt'  => array( str_repeat( 'a', WebhookReceipts::EVENT_TYPE_LENGTH ), str_repeat( 'a', WebhookReceipts::EVENT_TYPE_LENGTH ) ),
		);
	}

	/**
	 * Tests the identifiers kept as given, and those that are none.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_identifiers
	 *
	 * @param string      $sent The identifier a provider sent.
	 * @param string|null $kept The identifier kept, or null.
	 */
	public function test_an_identifier_is_kept_as_given_whatever_it_looks_like( string $sent, ?string $kept ): void {
		$this->assertSame( $kept, DeliveredValues::identifier( $sent ) );
	}

	/**
	 * Identifiers as providers send them, and what is kept.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string|null}> The identifier sent, and the one kept or null.
	 */
	public static function data_identifiers(): array {
		return array(
			'a prefixed object'           => array( 'ch_3MmlLrLkdIwHu7ix0snN0B15', 'ch_3MmlLrLkdIwHu7ix0snN0B15' ),
			'letters and digits'          => array( '8AB12345CD678901E', '8AB12345CD678901E' ),
			'digits, as a transaction'    => array( '60123456789', '60123456789' ),
			'digits that pass for a card' => array( '4242424242424242', '4242424242424242' ),
			'a card-shaped run in an id'  => array( 'evt_4242424242424242', 'evt_4242424242424242' ),
			'a uuid'                      => array( '01a127e5-c619-7b7d-810b-6e5db19c3aba', '01a127e5-c619-7b7d-810b-6e5db19c3aba' ),
			'a stand-in\'s shape'         => array( '~sha256:' . str_repeat( 'a', 64 ), null ),
			'an event identity\'s shape'  => array( '~event:test:evt_1', null ),
			'the mark alone'              => array( '~', null ),
			'a space'                     => array( 'ch 1', null ),
			'a quote'                     => array( "ch_1'", null ),
			'a separator first'           => array( '-ch_1', null ),
			'empty'                       => array( '', null ),
		);
	}

	/**
	 * Tests that a value that is not an identifier is kept as the same stand-in each time it is sent, never as itself, and that a provider's value shaped like a synthetic identity is its own stand-in, never that identity.
	 *
	 * @since 0.2.0
	 */
	public function test_a_provider_value_never_takes_a_synthetic_identity(): void {
		$standIn  = DeliveredValues::eventId( 'evt 1' );
		$identity = DeliveredValues::identity( Mode::Test, 'evt_1' );

		$this->assertSame( '~sha256:' . hash( 'sha256', 'evt 1' ), $standIn );
		$this->assertSame( $standIn, DeliveredValues::eventId( 'evt 1' ), 'The same id, the same stand-in.' );
		$this->assertSame( '~event:test:evt_1', $identity );
		$this->assertNotSame( $standIn, DeliveredValues::eventId( $standIn ), 'An event id shaped like a stand-in is a stand-in of its own.' );
		$this->assertNotSame( $identity, DeliveredValues::reference( $identity ), 'A provider object shaped like an event identity is a stand-in of its own.' );
		$this->assertSame( 'event:test:evt_1', DeliveredValues::reference( 'event:test:evt_1' ), 'The identity\'s shape without the mark is an identifier, and meets no identity.' );
		$this->assertSame( 'evt_1Abc', DeliveredValues::eventId( 'evt_1Abc' ) );
		$this->assertStringStartsWith( DeliveredValues::STAND_IN, DeliveredValues::eventId( str_repeat( 'e', WebhookReceipts::EVENT_ID_LENGTH + 1 ) ), 'An id longer than a receipt keeps stands in.' );
		$this->assertSame( str_repeat( 'e', WebhookReceipts::EVENT_ID_LENGTH ), DeliveredValues::eventId( str_repeat( 'e', WebhookReceipts::EVENT_ID_LENGTH ) ) );
		$this->assertNull( DeliveredValues::reference( null ) );
	}

	/**
	 * Tests that every shape the store makes in place of a provider's value is taken for its own, and nothing else is: not a provider's value that begins with the mark, an identity of no mode, or one longer than the store makes.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_shape_the_store_makes_is_taken_for_its_own(): void {
		$made = array(
			(string) DeliveredValues::reference( 'cap 1' ),
			DeliveredValues::identity( Mode::Test, 'evt_1' ),
			DeliveredValues::identity( Mode::Live, DeliveredValues::eventId( 'evt 1' ) ),
			DeliveredValues::identity( Mode::Live, str_repeat( 'e', WebhookReceipts::EVENT_ID_LENGTH ) ),
		);

		foreach ( $made as $value ) {
			$this->assertSame( $value, DeliveredValues::madeReference( $value ), $value );
		}

		$this->assertSame( $made[0], DeliveredValues::madeStandIn( $made[0] ) );
		$this->assertNull( DeliveredValues::madeStandIn( $made[1] ), 'An event\'s identity is no stand-in.' );

		$others = array(
			'~charge/1',
			'~evt/1',
			'~',
			'~sha256:abc',
			'~sha256:' . strtoupper( hash( 'sha256', 'cap 1' ) ),
			'~event:prod:evt_1',
			'~event:test:',
			'~event:test:evt 1',
			'~event:test:' . str_repeat( 'e', WebhookReceipts::EVENT_ID_LENGTH ),
			'cap_1',
		);

		foreach ( $others as $value ) {
			$this->assertNull( DeliveredValues::madeReference( $value ), $value );
		}
	}

	/**
	 * Tests the ledger identity of an event: by mode and id, within a receipt's event id, and a uuid echoed is a uuid or none.
	 *
	 * @since 0.2.0
	 */
	public function test_an_events_identity_names_its_mode_and_fits_the_ledger(): void {
		$long = str_repeat( 'e', WebhookReceipts::EVENT_ID_LENGTH );

		$this->assertSame( '~event:live:evt_1', DeliveredValues::identity( Mode::Live, 'evt_1' ) );
		$this->assertMatchesRegularExpression( '/^~event:live:~sha256:[0-9a-f]{64}\z/', DeliveredValues::identity( Mode::Live, $long ) );
		$this->assertLessThanOrEqual( WebhookReceipts::EVENT_ID_LENGTH, strlen( DeliveredValues::identity( Mode::Live, $long ) ) );
		$this->assertSame( '01a127e5-c619-7b7d-810b-6e5db19c3aba', DeliveredValues::uuid( '01A127E5-C619-7B7D-810B-6E5DB19C3ABA' ) );
		$this->assertNull( DeliveredValues::uuid( '4242424242424242' ) );
		$this->assertNull( DeliveredValues::uuid( null ) );
	}

	/**
	 * Tests that what is kept is printed without a card number, a synthetic identity as it is, and a value of no kept shape withheld.
	 *
	 * @since 0.2.0
	 */
	public function test_what_is_kept_is_printed_without_a_card_number(): void {
		$this->assertSame( 'evt_' . CardNumbers::MARKER, DeliveredValues::printable( 'evt_4242424242424242' ) );
		$this->assertSame( CardNumbers::MARKER, DeliveredValues::printable( '4242424242424242' ) );
		$this->assertSame( '60123456789', DeliveredValues::printable( '60123456789' ), 'A transaction number that reads as no card is printed.' );
		$this->assertSame( DeliveredValues::eventId( 'evt 1' ), DeliveredValues::printable( DeliveredValues::eventId( 'evt 1' ) ), 'A stand-in is printed as it is.' );
		$this->assertSame( '~event:test:evt_1', DeliveredValues::printable( '~event:test:evt_1' ) );
		$this->assertSame( DeliveredValues::WITHHELD, DeliveredValues::printable( "evt 1'; DROP" ) );
	}

	/**
	 * Tests that the receipts' columns are declared from the widths the shapes read, and that an event's identity fits the ledger: its provider object is at least as long as a receipt's event id.
	 *
	 * Planted violation: declare WebhookReceipts::EVENT_ID_LENGTH as 255: an event's identity could
	 * then outgrow the ledger's provider object.
	 *
	 * @since 0.2.0
	 */
	public function test_the_columns_hold_what_the_shapes_keep(): void {
		$this->assertSame(
			array( 'varchar(' . WebhookReceipts::EVENT_ID_LENGTH . ')', 'varchar(' . WebhookReceipts::EVENT_TYPE_LENGTH . ')' ),
			array( self::typeOf( WebhookReceiptTables::receipts(), 'event_id' ), self::typeOf( WebhookReceiptTables::receipts(), 'event_type' ) )
		);
		$this->assertGreaterThanOrEqual( WebhookReceipts::EVENT_ID_LENGTH, (int) preg_replace( '/\D/', '', self::typeOf( PaymentTables::transactions(), 'provider_object_id' ) ), 'An event\'s identity fits the ledger\'s provider object.' );
	}

	/**
	 * Tests that an answer in the request and the same answer delivered keep the provider's references the same way, and that a delivered result naming no provider object is given its event's identity.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_and_its_delivery_keep_the_same_references(): void {
		$answer    = new GatewayResult( 'stub', Operation::Refund, Outcome::Approved, '01a127e5-c619-7b7d-810b-6e5db19c3aba', Money::of( 500, Currency::of( 'EUR' ) ), '4242424242424242', 'pi 1', 'card_declined' );
		$asked     = DeliveredValues::canonical( $answer );
		$delivered = DeliveredValues::reading( WebhookReading::result( 'evt_1', 'refund.approved', null, $answer, '4111111111111111' ), Mode::Test );
		$nameless  = DeliveredValues::reading( WebhookReading::result( 'evt_2', 'refund.approved', null, new GatewayResult( 'stub', Operation::Refund, Outcome::Approved, 'not-a-uuid', Money::of( 500, Currency::of( 'EUR' ) ) ), null ), Mode::Test );
		$dispute   = DeliveredValues::reading( WebhookReading::ignored( 'evt_3', 'dispute.created', WebhookReading::DISPUTE, null, '01a127e5-c619-7b7d-810b-6e5db19c3aba', 'pi_1' ), Mode::Live );
		$unknown   = DeliveredValues::reading( WebhookReading::ignored( 'evt_4', '4242424242424242', '4242 4242 4242 4242', null ), Mode::Live );

		$this->assertSame( array( '4242424242424242', '~sha256:' . hash( 'sha256', 'pi 1' ), 'card_declined' ), array( $asked->providerObjectId, $asked->providerIntentId, $asked->errorCode ) );
		$this->assertSame( array( $asked->providerObjectId, $asked->providerIntentId, $asked->errorCode ), array( $delivered->result?->providerObjectId, $delivered->result?->providerIntentId, $delivered->result?->errorCode ), 'The delivery keeps what the answer keeps.' );
		$this->assertNull( $delivered->refundUuid, 'A refund uuid is a uuid or none.' );
		$this->assertSame( array( '', '~event:test:evt_2' ), array( $nameless->result?->intentUuid, $nameless->result?->providerObjectId ) );
		$this->assertSame( array( WebhookReading::DISPUTE, '01a127e5-c619-7b7d-810b-6e5db19c3aba', 'pi_1' ), array( $dispute->reason, $dispute->intentUuid, $dispute->providerIntentId ) );
		$this->assertSame( array( DeliveredValues::OTHER, DeliveredValues::OTHER ), array( $unknown->eventType, $unknown->reason ) );
	}

	/**
	 * Returns the declared type of a table's column.
	 *
	 * @since 0.2.0
	 *
	 * @param TableDefinition $table  The table.
	 * @param string          $column The column.
	 * @return string Its type, such as `varchar(191)`.
	 */
	private static function typeOf( TableDefinition $table, string $column ): string {
		foreach ( $table->columns() as $spec ) {
			if ( $column === $spec->name() ) {
				return $spec->type();
			}
		}

		self::fail( sprintf( '%1$s declares no column %2$s.', $table->name(), $column ) );
	}
}
