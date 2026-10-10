<?php
/**
 * Tests how a webhook receipt writes down what the money path did
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Webhook;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\Refund\ProviderRefundKind;
use SEOCart\Payment\Domain\Refund\ProviderRefundOutcome;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;

/**
 * Every kind the money path or the refund service can come to has its decision: each table below is walked over its enum's cases(), so a new kind without a row fails.
 *
 * Planted violation: in ReceiptDecision::ofApplication(), map ApplicationKind::Stale to
 * ReceiptResult::Duplicate, as a reader who took nothing changed for one decision would: the stale
 * row then fails.
 *
 * @since 0.2.0
 */
final class ReceiptDecisionTest extends TestCase {

	/**
	 * The decision and the word each kind is written down with; the word of a stale result is the intent's state, of a mismatch its reason.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{0: ReceiptResult, 1: string|null}>
	 */
	private const EXPECTED = array(
		'applied'         => array( ReceiptResult::Applied, 'applied' ),
		'duplicate'       => array( ReceiptResult::Duplicate, null ),
		'mismatch'        => array( ReceiptResult::Unapplied, 'late_approval' ),
		'declined'        => array( ReceiptResult::Applied, 'declined' ),
		'requires_action' => array( ReceiptResult::Applied, 'requires_action' ),
		'pending'         => array( ReceiptResult::Applied, 'pending' ),
		'stale'           => array( ReceiptResult::Stale, 'authorized' ),
	);

	/**
	 * The decision and the word each outcome of a refund's result is written down with; money kept for a person carries its reason, an ignored result why.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{0: ReceiptResult, 1: string|null}>
	 */
	private const REFUNDS = array(
		'recorded'     => array( ReceiptResult::Applied, 'applied' ),
		'declined'     => array( ReceiptResult::Applied, 'declined' ),
		'unreconciled' => array( ReceiptResult::Unapplied, 'payment_unrecorded' ),
		'duplicate'    => array( ReceiptResult::Duplicate, null ),
		'unexpected'   => array( ReceiptResult::Unapplied, 'external_refund' ),
		'reversed'     => array( ReceiptResult::Unapplied, 'refund_reversed' ),
		'ignored'      => array( ReceiptResult::Ignored, ProviderRefundOutcome::NO_CLAIM ),
	);

	/**
	 * Tests that each outcome of a refund's result is written down as the table says, with the intent, and the ledger row of all but an ignored one.
	 *
	 * @since 0.2.0
	 */
	public function test_every_refund_outcome_is_written_down_with_its_word(): void {
		$reasons = array(
			'unreconciled' => 'payment_unrecorded',
			'unexpected'   => 'external_refund',
			'reversed'     => 'refund_reversed',
			'ignored'      => ProviderRefundOutcome::NO_CLAIM,
		);

		foreach ( ProviderRefundKind::cases() as $kind ) {
			$this->assertArrayHasKey( $kind->value, self::REFUNDS, "The outcome {$kind->value} has no decision." );

			$decision = ReceiptDecision::ofRefund( new ProviderRefundOutcome( $kind, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8d', 78, $reasons[ $kind->value ] ?? null ), '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c' );

			$this->assertSame( self::REFUNDS[ $kind->value ], array( $decision->result, $decision->code ), "The outcome {$kind->value}." );
			$this->assertSame( array( '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', ProviderRefundKind::Ignored === $kind ? null : 78 ), array( $decision->intentUuid, $decision->transactionId ), "The outcome {$kind->value}." );
		}
	}

	/**
	 * Tests that each kind the money path comes to is written down as the table says, with its intent and ledger row.
	 *
	 * @since 0.2.0
	 */
	public function test_every_kind_is_written_down_with_its_word(): void {
		foreach ( ApplicationKind::cases() as $kind ) {
			$this->assertArrayHasKey( $kind->value, self::EXPECTED, "The kind {$kind->value} has no decision." );

			$decision = ReceiptDecision::ofApplication( self::application( $kind ) );

			$this->assertSame( self::EXPECTED[ $kind->value ], array( $decision->result, $decision->code ), "The kind {$kind->value}." );
			$this->assertSame( array( '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 77 ), array( $decision->intentUuid, $decision->transactionId ) );
		}
	}

	/**
	 * Tests that an ignored event is written down with its word and no row, and that a word longer than the receipt holds is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_an_ignored_event_and_an_overlong_word(): void {
		$ignored = ReceiptDecision::ignored( ReceiptDecision::MODE_MISMATCH, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c' );

		$this->assertSame( array( ReceiptResult::Ignored, 'mode_mismatch', '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', null ), array( $ignored->result, $ignored->code, $ignored->intentUuid, $ignored->transactionId ) );

		$this->expectException( \InvalidArgumentException::class );

		ReceiptDecision::ignored( str_repeat( 'x', ReceiptDecision::CODE_MAX_LENGTH + 1 ) );
	}

	/**
	 * Builds what the money path did, of a kind, about an authorized intent; a mismatch says why it was kept.
	 *
	 * @since 0.2.0
	 *
	 * @param ApplicationKind $kind The kind.
	 * @return Application The outcome.
	 */
	private static function application( ApplicationKind $kind ): Application {
		$reason = ApplicationKind::Mismatch === $kind ? 'late_approval' : null;

		return new Application( $kind, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', Operation::Authorize, 77, 5, 'hold-5', IntentStatus::Authorized, IntentStatus::Authorized, PaymentStatus::Authorized, PaymentStatus::Authorized, null, $reason );
	}
}
