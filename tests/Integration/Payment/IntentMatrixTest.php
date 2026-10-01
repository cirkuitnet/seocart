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

use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Every statement that changes an intent's state lands from exactly the states IntentTransitions allows it to be entered from.
 *
 * For each state that a statement enters, an intent is planted in each of the nine states, with
 * amounts that let only the state decide, and the statement that enters the target is sent; it
 * must change the intent iff the table allows the pair, and a refused statement changes nothing.
 * Nothing in the test names a pair: adding a row to the table changes the expectation and the
 * statement's IN list together. The two states no statement enters are proven unreachable:
 * `created`, which nothing may be entered from, is only an intent's first state; `voided` has no
 * statement until voiding arrives with the gateway call that makes one.
 *
 * Planted violations, each shown red and removed:
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

		$this->assertSame( 63, $pairs, 'Seven states have a statement that enters them, each tried from all nine.' );
		$this->assertSame( $expected, $outcomes );
	}

	/**
	 * Tests that the two states no statement enters cannot be entered: `created` from nothing, and `voided` by no statement yet.
	 *
	 * @since 0.1.0
	 */
	public function test_no_statement_enters_created_or_voided(): void {
		$this->assertSame( array(), IntentTransitions::allowedFrom( IntentStatus::Created ), 'An intent is created in its first state and never returns to it.' );

		$writers = array();

		foreach ( ( new \ReflectionClass( MysqlPaymentRepository::class ) )->getConstants() as $name => $statement ) {
			if ( is_string( $statement ) && 1 === preg_match( "/^\\s*UPDATE\\b.*\\bstatus\\s*=\\s*'(created|voided)'/is", $statement ) ) {
				$writers[] = $name;
			}
		}

		$this->assertSame( array(), $writers, 'No update moves an intent to created or voided.' );
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
		);
		$outcomes   = array();

		foreach ( $approvals as $name => list( $operation, $from, $to ) ) {
			foreach ( array( 'EUR/USD', 'USD/EUR', 'USD/USD' ) as $currencies ) {
				list( $currency, $baseCurrency ) = explode( '/', $currencies );

				$uuid   = $this->plant( $from, $to );
				$result = new GatewayResult( 'stub', $operation, Outcome::Approved, $uuid, Money::of( self::AMOUNT, Currency::of( $currency ) ), 'stub-currency-' . $uuid );
				$landed = $this->db->transaction( static fn(): bool => $repository->applyApproval( $repository->lock( $uuid ) ?? self::fail( 'The planted intent is gone.' ), $result, Money::of( self::AMOUNT, Currency::of( $baseCurrency ) ) ) );

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
			Money::of( $minor, Currency::of( 'USD' ) )
		);

		return match ( $to ) {
			IntentStatus::Authorized        => $approval( Operation::Authorize, self::AMOUNT ),
			IntentStatus::Captured          => $approval( Operation::Capture, self::AMOUNT ),
			IntentStatus::PartiallyRefunded => $approval( Operation::Refund, 1000 ),
			IntentStatus::Refunded          => $approval( Operation::Refund, self::AMOUNT ),
			IntentStatus::Failed            => static fn( PaymentIntent $intent ): bool => $repository->applyDecline( $intent->id ),
			IntentStatus::RequiresAction,
			IntentStatus::Processing        => static fn( PaymentIntent $intent ): bool => $repository->await( $intent->id, $to, null, 900 ),
			IntentStatus::Created,
			IntentStatus::Voided            => null,
		};
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
