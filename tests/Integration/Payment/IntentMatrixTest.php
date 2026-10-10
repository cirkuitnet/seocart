<?php
/**
 * Tests the intent state machine as the database enforces it, pair by pair, generated from its table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Every statement that changes an intent's state lands from exactly the states IntentTransitions allows it to be entered from.
 *
 * For each state that a statement enters, an intent is planted in each of the nine states, with
 * amounts that let only the state decide, and the statement that enters the target is sent; it
 * must change the intent iff the table allows the pair, and a refused statement changes nothing.
 * Nothing in the test names a pair: adding a row to the table changes the expectation and the
 * statement's IN list together. The one state no statement enters is proven unreachable:
 * `created`, which nothing may be entered from, is only an intent's first state.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlPaymentRepository::APPLY_VOID, write `AND ( status IN ({list}) OR status = 'captured' )`:
 *   a captured intent is voided;
 * - in MysqlPaymentRepository::APPLY_DECLINE, write `AND ( status IN ({list}) OR status = 'captured' )`:
 *   a captured intent fails;
 * - in MysqlPaymentRepository::applyApproval(), bind the intent's own currencies, as before: a
 *   result in EUR, or a base amount in EUR, lands on an intent in USD.
 *
 * @since 0.1.0
 */
final class IntentMatrixTest extends PaymentTestCase {

	/**
	 * Every amount an intent is planted with: enough that only its state decides.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const AMOUNT = 3080;

	/**
	 * What a refund in the answers' matrix gives back: part of what a captured intent captured.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const REFUND = 1000;

	/**
	 * How many intents the test planted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $planted = 0;

	/**
	 * Tests every pair: a statement entering a state lands iff the table allows the pair, and a refused one changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_each_statement_lands_iff_the_table_allows_the_pair(): void {
		$repository = new MysqlPaymentRepository( $this->db, new SequentialIdGenerator( 800000 ) );
		$outcomes   = array();
		$expected   = array();
		$pairs      = 0;

		foreach ( IntentStatus::cases() as $to ) {
			$writer = $this->writerOf( $repository, $to );

			if ( null === $writer ) {
				continue;
			}

			foreach ( IntentStatus::cases() as $from ) {
				$uuid   = $this->plant( $from, $to );
				$landed = $this->db->transaction( static fn(): bool => $writer( $repository->lock( $uuid ) ?? self::fail( "The planted {$from->value} intent is gone." ) ) );
				$status = (string) $this->intentRow( $uuid )['status'];
				$pair   = "{$from->value} -> {$to->value}";

				$outcomes[ $pair ] = $landed && $to->value === $status ? 'lands' : ( $from->value === $status ? 'refused' : "changed to {$status}" );
				$expected[ $pair ] = IntentTransitions::isAllowed( $from, $to ) ? 'lands' : 'refused';
				++$pairs;
			}
		}

		$this->assertSame( 72, $pairs, 'Eight states have a statement that enters them, each tried from all nine.' );
		$this->assertSame( $expected, $outcomes );
	}

	/**
	 * Tests every answer a gateway gives against an intent in every state, through the one money path: what each comes to is generated from IntentTransitions, never written down here.
	 *
	 * An approval is stale where STALE_APPROVALS says, kept for a person (a mismatch) where the
	 * table refuses the state it enters, and applied otherwise. A decline is stale where DECLINABLE
	 * does not name the state, and applied otherwise, a refund's always. A wait applies where the
	 * table lets the intent wait, is a duplicate in the state it reports, and is stale anywhere else.
	 * A stale answer writes no ledger row; a kept one writes one that moved nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_each_answer_is_decided_from_the_intents_state(): void {
		$outcomes = array();
		$expected = array();

		foreach ( self::answers() as $name => list( $operation, $outcome ) ) {
			foreach ( IntentStatus::cases() as $state ) {
				list( $order, $uuid ) = $this->plantWithOrder( $state );

				$cell   = $name . ' for ' . $state->value;
				$object = in_array( $outcome, Outcome::moneyFacts(), true ) ? 'stub-answer-' . $uuid : null;
				$result = new GatewayResult( 'stub', $operation, $outcome, $uuid, Money::of( Operation::Refund === $operation ? self::REFUND : self::AMOUNT, Currency::of( 'USD' ) ), $object );

				try {
					$kind = $this->db->transaction( fn() => $this->payments->applyGatewayResult( $result, self::system(), null, VoidReason::Other ) )->kind->value;
				} catch ( CodedException $refused ) {
					$kind = 'refused ' . $refused->errorCode()->value;
				}

				$rows = $this->ledgerOf( $order );

				$outcomes[ $cell ] = $kind . ' ' . count( $rows ) . ( array() === $rows ? '' : ' applied=' . $rows[0]['applied'] );
				$expected[ $cell ] = self::expectedOf( $operation, $outcome, $state );
			}
		}

		$this->assertCount( 90, $outcomes, 'Ten answers, each against all nine states.' );
		$this->assertSame( $expected, $outcomes );
	}

	/**
	 * Tests that the one state no statement enters cannot be entered, `created`, from nothing; and that one statement enters `voided`, the void's.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A void enters `voided`.
	 */
	public function test_no_statement_enters_created_and_one_enters_voided(): void {
		$this->assertSame( array(), IntentTransitions::allowedFrom( IntentStatus::Created ), 'An intent is created in its first state and never returns to it.' );

		$writers = array();

		foreach ( ( new \ReflectionClass( MysqlPaymentRepository::class ) )->getConstants() as $name => $statement ) {
			if ( is_string( $statement ) && 1 === preg_match( "/^\\s*UPDATE\\b.*\\bstatus\\s*=\\s*'(created|voided)'/is", $statement, $state ) ) {
				$writers[ $name ] = $state[1];
			}
		}

		$this->assertSame( array( 'APPLY_VOID' => 'voided' ), $writers, 'No update moves an intent to created; only the void moves one to voided.' );
	}

	/**
	 * Tests that an approval's statement changes the intent only when the result is in the intent's currency and the base amount in its base currency.
	 *
	 * The service checks both before it sends the statement; the statement checks them again.
	 *
	 * @since 0.1.0
	 */
	public function test_an_approval_lands_only_in_the_intents_currencies(): void {
		$repository = new MysqlPaymentRepository( $this->db, new SequentialIdGenerator( 810000 ) );
		$approvals  = array(
			'authorize' => array( Operation::Authorize, IntentStatus::Created, IntentStatus::Authorized ),
			'capture'   => array( Operation::Capture, IntentStatus::Authorized, IntentStatus::Captured ),
			'refund'    => array( Operation::Refund, IntentStatus::Captured, IntentStatus::Refunded ),
			'void'      => array( Operation::Void, IntentStatus::Authorized, IntentStatus::Voided ),
		);
		$outcomes   = array();

		foreach ( $approvals as $name => list( $operation, $from, $to ) ) {
			foreach ( array( 'EUR/USD', 'USD/EUR', 'USD/USD' ) as $currencies ) {
				list( $currency, $baseCurrency ) = explode( '/', $currencies );

				$uuid   = $this->plant( $from, $to );
				$result = new GatewayResult( 'stub', $operation, Outcome::Approved, $uuid, Money::of( self::AMOUNT, Currency::of( $currency ) ), 'stub-currency-' . $uuid );
				$landed = $this->db->transaction( static fn(): bool => $repository->applyApproval( $repository->lock( $uuid ) ?? self::fail( 'The planted intent is gone.' ), $result, Money::of( self::AMOUNT, Currency::of( $baseCurrency ) ), VoidReason::Other ) );

				$outcomes[ "{$name} {$currencies}" ] = $landed ? (string) $this->intentRow( $uuid )['status'] : 'refused';
			}
		}

		$this->assertSame(
			array(
				'authorize EUR/USD' => 'refused',
				'authorize USD/EUR' => 'refused',
				'authorize USD/USD' => 'authorized',
				'capture EUR/USD'   => 'refused',
				'capture USD/EUR'   => 'refused',
				'capture USD/USD'   => 'captured',
				'refund EUR/USD'    => 'refused',
				'refund USD/EUR'    => 'refused',
				'refund USD/USD'    => 'refunded',
				'void EUR/USD'      => 'refused',
				'void USD/EUR'      => 'refused',
				'void USD/USD'      => 'voided',
			),
			$outcomes,
			'Each intent is in USD with a USD base: a result or a base amount in EUR changes nothing.'
		);
	}

	/**
	 * Returns the repository call that enters a state, or null when no statement enters it.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlPaymentRepository $repository The repository.
	 * @param IntentStatus           $to         The state.
	 * @return (\Closure(PaymentIntent): bool)|null The call, given the intent as locked.
	 */
	private function writerOf( MysqlPaymentRepository $repository, IntentStatus $to ): ?\Closure {
		$approval = static fn( Operation $operation, int $minor ): \Closure => static fn( PaymentIntent $intent ): bool => $repository->applyApproval(
			$intent,
			new GatewayResult( 'stub', $operation, Outcome::Approved, $intent->uuid, Money::of( $minor, Currency::of( 'USD' ) ), 'stub-matrix-' . $intent->uuid ),
			Money::of( $minor, Currency::of( 'USD' ) ),
			VoidReason::Other
		);

		return match ( $to ) {
			IntentStatus::Authorized        => $approval( Operation::Authorize, self::AMOUNT ),
			IntentStatus::Captured          => $approval( Operation::Capture, self::AMOUNT ),
			IntentStatus::PartiallyRefunded => $approval( Operation::Refund, 1000 ),
			IntentStatus::Refunded          => $approval( Operation::Refund, self::AMOUNT ),
			IntentStatus::Failed            => static fn( PaymentIntent $intent ): bool => $repository->applyDecline( $intent->id ),
			IntentStatus::RequiresAction,
			IntentStatus::Processing        => static fn( PaymentIntent $intent ): bool => $repository->await( $intent->id, $to, null, 900 ),
			IntentStatus::Voided            => $approval( Operation::Void, self::AMOUNT ),
			IntentStatus::Created           => null,
		};
	}

	/**
	 * Returns every answer a gateway gives: each operation approved and declined, and the two waits.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: Operation, 1: Outcome}> The operation and the outcome, by name.
	 */
	private static function answers(): array {
		$answers = array();

		foreach ( Operation::cases() as $operation ) {
			foreach ( array( Outcome::Approved, Outcome::Declined ) as $outcome ) {
				$answers[ $outcome->value . ' ' . $operation->value ] = array( $operation, $outcome );
			}
		}

		return $answers + array(
			'requires_action' => array( Operation::Authorize, Outcome::RequiresAction ),
			'pending'         => array( Operation::Authorize, Outcome::Pending ),
		);
	}

	/**
	 * Returns what an answer comes to for an intent in a state, from IntentTransitions alone: the kind, the ledger rows written, and whether the first moved money.
	 *
	 * @since 0.2.0
	 *
	 * @param Operation    $operation The answer's operation.
	 * @param Outcome      $outcome   The answer's outcome.
	 * @param IntentStatus $state     The intent's state.
	 * @return string For example `mismatch 1 applied=0`, or `stale 0`.
	 */
	private static function expectedOf( Operation $operation, Outcome $outcome, IntentStatus $state ): string {
		$waits = array(
			Outcome::RequiresAction->value => array( IntentStatus::RequiresAction, 'requires_action' ),
			Outcome::Pending->value        => array( IntentStatus::Processing, 'pending' ),
		);

		if ( isset( $waits[ $outcome->value ] ) ) {
			list( $to, $kind ) = $waits[ $outcome->value ];

			return $state === $to ? 'duplicate 0' : ( IntentTransitions::isAllowed( $state, $to ) ? $kind . ' 0' : 'stale 0' );
		}

		if ( Outcome::Declined === $outcome ) {
			return IntentTransitions::isStaleDecline( $operation, $state ) ? 'stale 0' : 'declined 1 applied=1';
		}

		$entered = array(
			'authorize' => IntentStatus::Authorized,
			'capture'   => IntentStatus::Captured,
			'refund'    => IntentStatus::PartiallyRefunded,
			'void'      => IntentStatus::Voided,
		);

		if ( IntentTransitions::isStaleApproval( $operation, $state ) ) {
			return 'stale 0';
		}

		return IntentTransitions::isAllowed( $state, $entered[ $operation->value ] ) ? 'applied 1 applied=1' : 'mismatch 1 applied=0';
	}

	/**
	 * Places a USD order with its intent, and moves both to a state with the amounts it has there: authorized, captured, and for a refund's states a refund.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentStatus $state The state.
	 * @return array{0: int, 1: string} The order's id, and the intent's uuid.
	 */
	private function plantWithOrder( IntentStatus $state ): array {
		list( $order, $intent ) = $this->placeWithIntent( NewOrders::forTwoLines( 'USD', 'USD' ) );

		$authorized = in_array( $state, array( IntentStatus::Authorized, IntentStatus::Captured, IntentStatus::PartiallyRefunded, IntentStatus::Refunded ), true ) ? self::AMOUNT : 0;
		$captured   = in_array( $state, array( IntentStatus::Captured, IntentStatus::PartiallyRefunded, IntentStatus::Refunded ), true ) ? self::AMOUNT : 0;
		$refunded   = array(
			IntentStatus::PartiallyRefunded->value => self::REFUND,
			IntentStatus::Refunded->value          => self::AMOUNT,
		)[ $state->value ] ?? 0;

		$this->db->execute(
			'UPDATE %i SET status = %s, authorized_minor = %d, base_authorized_minor = %d, captured_minor = %d, base_captured_minor = %d, refunded_minor = %d, base_refunded_minor = %d WHERE uuid = %s',
			$this->table( PaymentTables::INTENTS ),
			$state->value,
			$authorized,
			$authorized,
			$captured,
			$captured,
			$refunded,
			$refunded,
			$intent->uuid
		);
		$this->db->execute(
			'UPDATE %i SET authorized_minor = %d, base_authorized_minor = %d, paid_minor = %d, base_paid_minor = %d, refunded_minor = %d, base_refunded_minor = %d, due_minor = %d WHERE id = %d',
			$this->table( OrderTables::ORDERS ),
			$authorized,
			$authorized,
			$captured,
			$captured,
			$refunded,
			$refunded,
			self::AMOUNT - $captured + $refunded,
			$order->id
		);

		return array( $order->id, $intent->uuid );
	}

	/**
	 * Plants an intent in a state, with the amounts the statement entering the target needs to be decided by the state alone.
	 *
	 * An intent to be captured has its whole amount authorized and nothing captured; one to be
	 * refunded has it captured and nothing refunded.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus $from The state.
	 * @param IntentStatus $to   The target.
	 * @return string The intent's uuid.
	 */
	private function plant( IntentStatus $from, IntentStatus $to ): string {
		$order    = ++$this->planted;
		$uuid     = SequentialIdGenerator::nth( 880000 + $order );
		$captured = in_array( $to, array( IntentStatus::PartiallyRefunded, IntentStatus::Refunded ), true ) ? self::AMOUNT : 0;

		$this->db->execute(
			"INSERT INTO %i SET uuid = %s, order_id = %d, gateway_id = 'stub', status = %s, amount_minor = %d, currency = 'USD', conversion_context_id = 1, base_currency = 'USD', base_amount_minor = %d, "
				. 'authorized_minor = %d, base_authorized_minor = %d, captured_minor = %d, base_captured_minor = %d, created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)',
			$this->table( PaymentTables::INTENTS ),
			$uuid,
			$order,
			$from->value,
			self::AMOUNT,
			self::AMOUNT,
			self::AMOUNT,
			self::AMOUNT,
			$captured,
			$captured
		);

		return $uuid;
	}
}
