<?php
/**
 * SettledClaim: how a person's settlement of a refund claim ended
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Payment\Domain\Refund\ClaimState;

defined( 'ABSPATH' ) || exit;

/**
 * A refund claim a person settled: how it ended, whether the gateway or the person's statement decided it, what the gateway said, and whether the order now holds money a person must reconcile.
 *
 * Owns one fact: the settle operation's answer, and the words of what the gateway said when it
 * was asked once more: `approved` or `declined`, which decide; `not_found` when it made no such
 * refund, and `cannot_say`, which leave it to the statement; and `unavailable` when it could not be
 * asked at all, with why after a colon when the plugin knows, such as `unavailable:not_registered`
 * for a gateway whose plugin was removed.
 *
 * @since 0.2.0
 */
final readonly class SettledClaim {

	/**
	 * The gateway made the refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const APPROVED = 'approved';

	/**
	 * The gateway declined the refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DECLINED = 'declined';

	/**
	 * The gateway says it made no refund under the claim's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * The gateway cannot say what became of the refund, or has not decided.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANNOT_SAY = 'cannot_say';

	/**
	 * The gateway could not be asked.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * The gateway's answer decided how the claim ended.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BY_GATEWAY = 'gateway';

	/**
	 * The person's statement decided how the claim ended.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BY_STATEMENT = 'statement';

	/**
	 * Records the settlement.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $refundUuid           The refund's uuid.
	 * @param string     $orderUuid            The order refunded.
	 * @param ClaimState $state                How the claim ended: recorded, declined or unreconciled.
	 * @param string     $decidedBy            BY_GATEWAY or BY_STATEMENT.
	 * @param string     $gatewayReading       What the gateway said when it was asked once more.
	 * @param bool       $hasUnreconciledMoney Whether the order holds money a person must reconcile, once the settlement committed.
	 */
	public function __construct(
		public string $refundUuid,
		public string $orderUuid,
		public ClaimState $state,
		public string $decidedBy,
		public string $gatewayReading,
		public bool $hasUnreconciledMoney
	) {
	}

	/**
	 * Returns whether a reading of the gateway decides how a claim ends: a refund it made or declined.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reading What the gateway said.
	 * @return bool True for APPROVED and DECLINED.
	 */
	public static function decides( string $reading ): bool {
		return self::APPROVED === $reading || self::DECLINED === $reading;
	}

	/**
	 * Returns the settlement as the settle operation answers it, keyed by wire name.
	 *
	 * @since 0.2.0
	 *
	 * @return array{refund_uuid: string, order_uuid: string, state: string, decided_by: string, gateway_reading: string, has_unreconciled_money: bool} The answer.
	 */
	public function toArray(): array {
		return array(
			'refund_uuid'            => $this->refundUuid,
			'order_uuid'             => $this->orderUuid,
			'state'                  => $this->state->value,
			'decided_by'             => $this->decidedBy,
			'gateway_reading'        => $this->gatewayReading,
			'has_unreconciled_money' => $this->hasUnreconciledMoney,
		);
	}
}
