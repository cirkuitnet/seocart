<?php
/**
 * ReceiveWebhook: verifies a provider's webhook delivery, records it once, and settles what it reports through the one money path
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Order\Application\Orders;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Domain\Webhook\WebhookReceipts;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to the developer; they are never HTML.

/**
 * The receiver of the webhook route: one provider delivery, verified by its gateway before anything of it is read, recorded once by its event, and settled through the same money path the gateway's answer in the request takes.
 *
 * Owns one fact: what a delivery comes to. In order:
 *
 * 1. The gateway: one the registry does not know, one that declares no webhooks or not the
 *    address's mode, is `payment.webhook_gateway_unknown`; a live delivery while Safe Mode is on,
 *    or one whose credentials cannot be used, is `payment.gateway_unavailable`, before any secret
 *    is opened, so the provider retries later. An operator's kill switch is not read: it stops new
 *    payments, not the deliveries about payments the gateway already holds.
 * 2. The gateway reads the delivery (PaymentGateway::readWebhook()): it verifies the signature over
 *    the raw body, and the signed time, before it decodes anything. A rejected delivery leaves no
 *    row anywhere and is `payment.webhook_rejected`; the rejections are counted per client and
 *    hour, and only the first REJECTIONS_LOGGED of them are logged. The counter never refuses a
 *    delivery: behind a shared address every client is one, and refusing would let a flood stop
 *    the provider's own deliveries.
 * 3. While a migration the store needs is outstanding, `store.unavailable`, before the receipt.
 * 4. The receipt (WebhookReceipts::record()), in a statement of its own, before the money moves.
 *    An event decided before is answered from its receipt, and nothing more is done: two
 *    statements, whatever the storm.
 * 5. What the event reports (decide()), each path in one transaction of its own; then the receipt
 *    is settled with the decision, in a statement of its own. A dispute and any event the store
 *    does not act on are ignored; a result is settled after its intent is read, which refuses an
 *    intent of another gateway or of the other mode: a delivery signed with a gateway's test
 *    secret never moves a live payment.
 *
 * Anything that is not a decision, such as a database failure or an adapter's exception, leaves
 * the receipt undecided, is reported `payment.webhook_faulted`, and is `payment.webhook_not_settled`,
 * so the provider sends it again. A delivery of an event already undecided is processed again;
 * the ledger's key applies its money once.
 *
 * Never logged: a header, the body, the signature, or the secret. The rejection's line carries the
 * body's hash and length; the decision's line the event's id and type, the decision, the intent
 * and the ledger row.
 *
 * @since 0.2.0
 */
final class ReceiveWebhook {

	/**
	 * The name of the system actor a webhook acts as: the store, on no user's authority.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACTOR = 'webhook';

	/**
	 * How many rejected deliveries of one client are logged in a window; the rest are counted only.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const REJECTIONS_LOGGED = 20;

	/**
	 * What the rejections are counted under.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REJECTION_BUCKET = 'payment.webhook_rejected';

	/**
	 * The window the rejections are counted in, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const REJECTION_WINDOW_SECONDS = 3600;

	/**
	 * The longest event id a receipt holds, in bytes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EVENT_ID_MAX = 191;

	/**
	 * The longest event type a receipt holds, in bytes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EVENT_TYPE_MAX = 64;

	/**
	 * A reference the store compares with its own: printable ASCII with no space.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PRINTABLE = '/^[\x21-\x7e]+\z/';

	/**
	 * Creates the receiver. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways           $gateways    The gateways, which read their own deliveries.
	 * @param WebhookReceipts    $receipts    The receipts, recorded before the money moves and settled after.
	 * @param SettlePlacement    $settlement  The placement's settlement: an authorization, or the void of a placement.
	 * @param PaymentService     $payments    The one money path: the intent's read, and a capture or a void of its own.
	 * @param RefundService      $refunds     A refund's result, recorded through the refund's claim.
	 * @param Orders             $orders      The order a dispute is about.
	 * @param TransactionManager $tx          The unit of work, whose schema gate is asked.
	 * @param RateLimiter        $limiter     Counts the rejected deliveries.
	 * @param ClientIdentities   $identities  Who sent the delivery.
	 * @param Logger             $log         Writes the one line of each delivery.
	 * @param Reporter           $report      Reports a delivery that could not be settled.
	 * @param CorrelationId      $correlation The request's correlation id, kept on the receipt.
	 */
	public function __construct(
		private Gateways $gateways,
		private WebhookReceipts $receipts,
		private SettlePlacement $settlement,
		private PaymentService $payments,
		private RefundService $refunds,
		private Orders $orders,
		private TransactionManager $tx,
		private RateLimiter $limiter,
		private ClientIdentities $identities,
		private Logger $log,
		private Reporter $report,
		private CorrelationId $correlation
	) {
	}

	/**
	 * Receives one delivery: verified, recorded once, and settled.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.webhook_gateway_unknown`; `payment.gateway_unavailable` with its reason;
	 *                        `payment.webhook_rejected`; `store.unavailable`; `payment.webhook_not_settled` when
	 *                        nothing could be decided, the receipt left undecided.
	 *
	 * @param WebhookEnvelope $envelope The delivery: the address's gateway and mode, the headers and the raw body.
	 * @return ReceiptResult What was decided about the event, now or by an earlier delivery of it.
	 */
	public function receive( WebhookEnvelope $envelope ): ReceiptResult {
		$gateway = $this->gatewayOf( $envelope );

		try {
			$reading = $gateway->readWebhook( $envelope );
		} catch ( \Throwable $fault ) {
			// A gateway answers a delivery it cannot verify as rejected; one that throws is broken.
			$this->fault( $envelope, null, $fault );
		}

		if ( WebhookReadingKind::Rejected === $reading->kind ) {
			$this->noteRejection( $envelope, (string) $reading->reason );

			CodedException::raise( PaymentError::WebhookRejected );
		}

		// Only a verified caller learns the store is closed for an upgrade.
		$this->tx->refuseWhileClosed();

		try {
			return $this->settle( $envelope, $reading );
		} catch ( \Throwable $fault ) {
			$this->fault( $envelope, $reading, $fault );
		}
	}

	/**
	 * Reports a delivery nothing could be decided about, and refuses it, so the provider sends it again.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException Always: `payment.webhook_not_settled`, with the fault as the previous exception.
	 *
	 * @param WebhookEnvelope     $envelope The delivery.
	 * @param WebhookReading|null $reading  What the gateway read in it, or null when it could not read it.
	 * @param \Throwable          $fault    What went wrong.
	 */
	private function fault( WebhookEnvelope $envelope, ?WebhookReading $reading, \Throwable $fault ): never {
		$this->report->error(
			'payment.webhook_faulted',
			array(
				'gateway_id' => $envelope->gatewayId,
				'mode'       => $envelope->mode->value,
				'event_id'   => $reading?->eventId,
				'event_type' => $reading?->eventType,
				'exception'  => $fault,
			)
		);

		throw CodedException::because( PaymentError::WebhookNotSettled, array(), $fault );
	}

	/**
	 * Records the event once and, unless it was decided before, decides it and settles its receipt.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the gateway read an event id or type a receipt cannot hold.
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  What the gateway read in it: ignored, or a result.
	 * @return ReceiptResult What was decided.
	 */
	private function settle( WebhookEnvelope $envelope, WebhookReading $reading ): ReceiptResult {
		$eventId   = (string) $reading->eventId;
		$eventType = (string) $reading->eventType;

		if ( ! self::fits( $eventId, self::EVENT_ID_MAX ) || ! self::fits( $eventType, self::EVENT_TYPE_MAX ) ) {
			throw new \UnexpectedValueException( sprintf( 'The gateway %s read an event id or type that is not printable ASCII of the length a receipt holds.', $envelope->gatewayId ) );
		}

		$receipt = $this->receipts->record( $envelope->gatewayId, $envelope->mode, $eventId, $eventType, $reading->occurredAt, hash( 'sha256', $envelope->rawBody ), $this->correlation->current() );

		if ( null !== $receipt->result ) {
			return $receipt->result;
		}

		if ( WebhookReadingKind::Ignored === $reading->kind ) {
			$disputed = $this->disputedIntent( $envelope, $reading );

			$this->receipts->settle( $receipt->id, ReceiptDecision::ignored( $eventType, $disputed?->uuid ) );
			$this->logIgnored( $envelope, $reading, $disputed );

			return ReceiptResult::Ignored;
		}

		$decision = $this->decide( $envelope, $reading->result ?? throw new \UnexpectedValueException( 'A result reading carries its result.' ), $reading->refundUuid );

		$this->receipts->settle( $receipt->id, $decision );
		$this->logDecided( $envelope, $reading, $decision );

		return $decision->result;
	}

	/**
	 * Decides what a result reports, once its intent is read: the store's own intent of the address's gateway and mode, or none.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the gateway read a result of another provider.
	 * @throws CodedException            What the money path refuses, but an intent gone since it was read.
	 *
	 * @param WebhookEnvelope $envelope   The delivery.
	 * @param GatewayResult   $result     The result it reports.
	 * @param string|null     $refundUuid For a refund, the uuid the provider echoed; null otherwise.
	 * @return ReceiptDecision The decision.
	 */
	private function decide( WebhookEnvelope $envelope, GatewayResult $result, ?string $refundUuid ): ReceiptDecision {
		if ( $result->provider !== $envelope->gatewayId ) {
			throw new \UnexpectedValueException( sprintf( 'The gateway %1$s read a result of the provider %2$s.', $envelope->gatewayId, $result->provider ) );
		}

		$intent = $this->intentOf( $envelope->gatewayId, '' === $result->intentUuid ? null : $result->intentUuid, $result->providerIntentId );

		if ( null === $intent || $intent->gatewayId !== $envelope->gatewayId ) {
			return ReceiptDecision::ignored( ReceiptDecision::UNKNOWN_INTENT );
		}

		if ( $intent->mode !== $envelope->mode ) {
			return ReceiptDecision::ignored( ReceiptDecision::MODE_MISMATCH, $intent->uuid );
		}

		try {
			return $this->apply( '' === $result->intentUuid ? $result->forIntent( $intent->uuid ) : $result, $intent, $refundUuid );
		} catch ( CodedException $gone ) {
			// The intent was read a moment ago: only its removal since could end here, and then it is not the store's.
			if ( PaymentError::IntentNotFound !== $gone->errorCode() ) {
				throw $gone;
			}

			return ReceiptDecision::ignored( ReceiptDecision::UNKNOWN_INTENT );
		}
	}

	/**
	 * Applies a result through the one money path, as the store, and writes down what it came to.
	 *
	 * An authorization is settled with its placement. A void of a placement still waiting for its
	 * answer is settled with the placement too, which releases what it holds; a void of an accepted
	 * order, and a capture, are applied in a transaction of their own. A void reported this way was
	 * asked by nobody of the store: an accepted order is parked for a person. A refund is recorded
	 * through the claim of the refund the provider echoed, or kept for a person when no open claim
	 * accounts for it (RefundService::recordProviderRefund()).
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the placement's settlement carries no application, which it always does.
	 *
	 * @param GatewayResult $result     The result, about the intent.
	 * @param IntentRef     $intent     The intent, as read.
	 * @param string|null   $refundUuid For a refund, the uuid the provider echoed; null otherwise.
	 * @return ReceiptDecision The decision.
	 */
	private function apply( GatewayResult $result, IntentRef $intent, ?string $refundUuid ): ReceiptDecision {
		$actor = Actor::system( self::ACTOR, 0 );

		switch ( $result->operation ) {
			case Operation::Authorize:
				return ReceiptDecision::ofApplication( $this->settlement->apply( $result, $actor )->application ?? throw new \LogicException( 'A settled authorization carries what the payment path did.' ) );

			case Operation::Void:
				if ( in_array( $intent->status, IntentStatus::awaitingResult(), true ) ) {
					return ReceiptDecision::ofApplication( $this->settlement->apply( $result, $actor, VoidReason::VoidedExternally )->application ?? throw new \LogicException( 'A settled void carries what the payment path did.' ) );
				}

				return ReceiptDecision::ofApplication( $this->payments->applyDelivered( $result, $actor, VoidReason::VoidedExternally ) );

			case Operation::Capture:
				return ReceiptDecision::ofApplication( $this->payments->applyDelivered( $result, $actor ) );

			default:
				return ReceiptDecision::ofRefund( $this->refunds->recordProviderRefund( $refundUuid, $result, $actor ), $intent->uuid );
		}
	}

	/**
	 * Returns the gateway a delivery is addressed to, able to read it now.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.webhook_gateway_unknown` for a gateway not registered, one that declares no
	 *                        webhooks or not the address's mode; `payment.gateway_unavailable` with the reason for a
	 *                        live delivery while Safe Mode is on, and for credentials that cannot be used.
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return PaymentGateway The gateway.
	 */
	private function gatewayOf( WebhookEnvelope $envelope ): PaymentGateway {
		try {
			$descriptor = $this->gateways->descriptor( $envelope->gatewayId );
		} catch ( CodedException $unknown ) {
			throw CodedException::because( PaymentError::WebhookGatewayUnknown, array(), $unknown );
		}

		if ( ! self::receivesWebhooks( $descriptor ) ) {
			CodedException::raise( PaymentError::WebhookGatewayUnknown );
		}

		try {
			// The mode first, then Safe Mode, and only then the secrets: a refusal opens none.
			return $this->gateways->get( $envelope->gatewayId, $envelope->mode );
		} catch ( CodedException $unavailable ) {
			$reason = (string) ( $unavailable->context()['reason'] ?? '' );

			if ( str_ends_with( $reason, '_mode_not_declared' ) || 'not_registered' === $reason ) {
				throw CodedException::because( PaymentError::WebhookGatewayUnknown, array(), $unavailable );
			}

			throw $unavailable;
		}
	}

	/**
	 * Tells whether a gateway declares webhooks in any cell of its capability matrix.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayDescriptor $descriptor The gateway's descriptor.
	 * @return bool True when one of its rows supports `webhooks`.
	 */
	private static function receivesWebhooks( GatewayDescriptor $descriptor ): bool {
		foreach ( $descriptor->matrix->rows as $row ) {
			if ( $row->supports( Operations::WEBHOOKS ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads the intent a delivery names: by the store's uuid when it carries one, or else by the provider's reference; a reference that is not printable ASCII names none.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $gatewayId        The address's gateway.
	 * @param string|null $intentUuid       The store's uuid of the intent, as the provider echoed it.
	 * @param string|null $providerIntentId The provider's reference to the intent.
	 * @return IntentRef|null The intent, or null when it names none of the store's.
	 */
	private function intentOf( string $gatewayId, ?string $intentUuid, ?string $providerIntentId ): ?IntentRef {
		if ( null !== $intentUuid ) {
			return self::fits( $intentUuid, 36 ) ? $this->payments->intentRef( $intentUuid ) : null;
		}

		return null !== $providerIntentId && self::fits( $providerIntentId, 191 ) ? $this->payments->intentByProvider( $gatewayId, $providerIntentId ) : null;
	}

	/**
	 * Returns the store's intent a dispute names, of the address's gateway and mode; null for anything else.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  The gateway's reading: ignored.
	 * @return IntentRef|null The intent disputed, or null.
	 */
	private function disputedIntent( WebhookEnvelope $envelope, WebhookReading $reading ): ?IntentRef {
		if ( WebhookReading::DISPUTE !== $reading->reason ) {
			return null;
		}

		$intent = $this->intentOf( $envelope->gatewayId, $reading->intentUuid, $reading->providerIntentId );

		return null !== $intent && $intent->gatewayId === $envelope->gatewayId && $intent->mode === $envelope->mode ? $intent : null;
	}

	/**
	 * Tells whether a reference is printable ASCII with no space, at most a length long: what the store's columns compare.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value     The reference.
	 * @param int    $maxLength The longest it may be, in bytes.
	 * @return bool True when it fits.
	 */
	private static function fits( string $value, int $maxLength ): bool {
		return strlen( $value ) <= $maxLength && 1 === preg_match( self::PRINTABLE, $value );
	}

	/**
	 * Counts a rejected delivery and logs it, while the client's rejections this window are few enough: a wrong secret is the one misconfiguration the merchant must see.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param string          $reason   Why the gateway rejected it.
	 */
	private function noteRejection( WebhookEnvelope $envelope, string $reason ): void {
		if ( $this->limiter->hit( self::REJECTION_BUCKET, $this->identities->of( 0 ), self::REJECTION_WINDOW_SECONDS ) > self::REJECTIONS_LOGGED ) {
			return;
		}

		$this->log->warning(
			'payment.webhook_rejected',
			'A webhook delivery failed verification, and nothing of it was kept: a wrong webhook secret, or a clock far off, would explain it.',
			array(
				'gateway_id'  => $envelope->gatewayId,
				'mode'        => $envelope->mode->value,
				'reason'      => $reason,
				'body_sha256' => hash( 'sha256', $envelope->rawBody ),
				'body_length' => strlen( $envelope->rawBody ),
			)
		);
	}

	/**
	 * Logs an event the store does not act on: at info, or at warning, with the order, for a dispute.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  The gateway's reading: ignored.
	 * @param IntentRef|null  $disputed The store's intent a dispute names, or null.
	 */
	private function logIgnored( WebhookEnvelope $envelope, WebhookReading $reading, ?IntentRef $disputed ): void {
		$context = self::context( $envelope, $reading ) + array( 'reason' => $reading->reason );

		if ( WebhookReading::DISPUTE !== $reading->reason ) {
			$this->log->info( 'payment.webhook_ignored', 'A webhook delivery reported an event the store does not act on.', $context );

			return;
		}

		$context['intent_uuid'] = $disputed?->uuid;
		$context['order_uuid']  = null === $disputed ? null : ( $this->orders->statusOf( $disputed->orderId )['uuid'] ?? null );

		$this->log->warning( 'payment.webhook_ignored', 'A webhook delivery reported a dispute: the store records it and changes nothing, and a person decides what to do.', $context );
	}

	/**
	 * Logs what was decided about a result.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  The gateway's reading: a result.
	 * @param ReceiptDecision $decision What was decided.
	 */
	private function logDecided( WebhookEnvelope $envelope, WebhookReading $reading, ReceiptDecision $decision ): void {
		$ignored = ReceiptResult::Ignored === $decision->result;
		$context = self::context( $envelope, $reading ) + array(
			'result'         => $decision->result->value,
			'result_code'    => $decision->code,
			'intent_uuid'    => $decision->intentUuid,
			'transaction_id' => $decision->transactionId,
		);

		if ( $ignored ) {
			$this->log->info( 'payment.webhook_ignored', 'A webhook delivery reported a payment that is not one of this address\'s: nothing was applied.', $context );

			return;
		}

		$this->log->info( 'payment.webhook_received', 'A webhook delivery was settled through the money path.', $context );
	}

	/**
	 * Returns what every line of a delivery names: the address and the event, never a header or the body.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  The gateway's reading.
	 * @return array{gateway_id: string, mode: string, event_id: string|null, event_type: string|null} The context.
	 */
	private static function context( WebhookEnvelope $envelope, WebhookReading $reading ): array {
		return array(
			'gateway_id' => $envelope->gatewayId,
			'mode'       => $envelope->mode->value,
			'event_id'   => $reading->eventId,
			'event_type' => $reading->eventType,
		);
	}
}
