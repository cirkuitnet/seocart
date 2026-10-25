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
use SEOCart\Payment\Application\DeliveredValues;
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
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Logging\BodyDigest;
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
 *    row anywhere and is `payment.webhook_rejected`; the first REJECTIONS_LOGGED rejections of a
 *    client's hour are counted and logged, and a rejection whose read of the count shows
 *    REJECTIONS_LOGGED writes nothing. Two limits are accepted: rejections that all read one less
 *    may each send the count's conditional insert, all but one changing no row, so the extra
 *    statements are bounded by the rejections in flight at that instant; and a rejection at the
 *    turn of the hour may read the old hour's full count and go unlogged in the new one. The
 *    counter never refuses a delivery: behind a shared address every client is one, and refusing
 *    would let a flood stop the provider's own deliveries.
 * 3. While a migration the store needs is outstanding, `store.unavailable`, before the receipt.
 * 4. Every value the provider supplied is constrained (DeliveredValues) before anything is kept or
 *    logged: a type that is not an event name's shape is `other`, an identifier that could hold a
 *    card number is none, and a result that names no provider object is given one from its event.
 * 5. An event decided before is answered from its receipt, and nothing more is done: one read,
 *    whatever the storm.
 * 6. Otherwise one transaction, at READ COMMITTED as a placement's second unit of work runs, with
 *    the deadlock retry: the receipt is claimed as its first write (WebhookReceipts::claim()), then
 *    what the event reports is decided (decide()) through the money path, each path's own unit of
 *    work joining this one, and the receipt is settled with the decision; all commit together. A
 *    second delivery of the event waits on the receipt's key until the first commits, then finds
 *    it decided; a crash anywhere leaves neither the receipt nor the money, and the provider's
 *    next delivery processes the event once. A dispute and any event the store does not act on
 *    are ignored; a result is settled after its intent is read, which refuses an intent of another
 *    gateway or of the other mode: a delivery signed with a gateway's test secret never moves a
 *    live payment. A delivery that names an intent by its uuid and by a provider reference the
 *    intent does not hold, or that another intent holds, names two payments, and is ignored as
 *    naming none.
 *
 * Anything that is not a decision, such as a database failure or an adapter's exception, rolls
 * the transaction back, receipt and all, is reported `payment.webhook_faulted`, and is
 * `payment.webhook_not_settled`, so the provider sends it again.
 *
 * Never logged: a header, the body, the signature, or the secret. The rejection's line carries the
 * body's hash, in groups of eight joined by colons (BodyDigest), and its length; the decision's line the event's id and type, the decision, the intent
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
	 * Why a delivery's line says its result was ignored, beyond its receipt's word: it named one of the store's intents by its uuid and a provider reference that intent does not hold.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const IDS_DISAGREE = 'ids_disagree';

	/**
	 * Creates the receiver. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways           $gateways    The gateways, which read their own deliveries.
	 * @param WebhookReceipts    $receipts    The receipts, claimed and settled in the transaction that applies the event.
	 * @param SettlePlacement    $settlement  The placement's settlement: an authorization, or a capture or a void of a placement still waiting.
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
	 *                        nothing could be decided, and nothing was kept.
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
			return $this->settle( $envelope, DeliveredValues::reading( $reading, $envelope->mode ) );
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
	 * @param WebhookReading|null $reading  What the gateway read in it, or null when it could not read it; its event's id and
	 *                                      type are constrained before they are reported.
	 * @param \Throwable          $fault    What went wrong.
	 */
	private function fault( WebhookEnvelope $envelope, ?WebhookReading $reading, \Throwable $fault ): never {
		$this->report->error(
			'payment.webhook_faulted',
			array(
				'gateway_id' => $envelope->gatewayId,
				'mode'       => $envelope->mode->value,
				'event_id'   => null === $reading?->eventId ? null : DeliveredValues::printable( DeliveredValues::eventId( $reading->eventId ) ),
				'event_type' => null === $reading?->eventType ? null : DeliveredValues::type( $reading->eventType ),
				'exception'  => $fault,
			)
		);

		throw CodedException::because( PaymentError::WebhookNotSettled, array(), $fault );
	}

	/**
	 * Answers an event decided before from its receipt; otherwise decides it once, its receipt and its money committed together, and logs the decision.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  What the gateway read in it, constrained: ignored, or a result.
	 * @return ReceiptResult What was decided.
	 */
	private function settle( WebhookEnvelope $envelope, WebhookReading $reading ): ReceiptResult {
		$decided = $this->receipts->find( $envelope->gatewayId, $envelope->mode, (string) $reading->eventId )?->result;

		if ( null !== $decided ) {
			return $decided;
		}

		list( $result, $decision, $disputed, $disagreed ) = $this->decideInTransaction( $envelope, $reading );

		if ( null === $decision ) {
			return $result;
		}

		if ( WebhookReadingKind::Ignored === $reading->kind ) {
			$this->logIgnored( $envelope, $reading, $disputed );
		} else {
			$this->logDecided( $envelope, $reading, $decision, $disagreed );
		}

		return $result;
	}

	/**
	 * Runs decideOnce() in one transaction, at READ COMMITTED as a placement's second unit of work runs, run again from the start after a lost deadlock.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  What the gateway read in it, constrained.
	 * @return array{0: ReceiptResult, 1: ReceiptDecision|null, 2: IntentRef|null, 3: bool} What decideOnce() answered.
	 */
	private function decideInTransaction( WebhookEnvelope $envelope, WebhookReading $reading ): array {
		return $this->tx->transaction( fn(): array => $this->decideOnce( $envelope, $reading ), RetryPolicy::deadlocks(), Isolation::ReadCommitted );
	}

	/**
	 * Claims the event's receipt as the transaction's first write and, unless an earlier delivery decided it, decides the event and settles the receipt, in the caller's transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When a result reading carries no result.
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param WebhookReading  $reading  What the gateway read in it, constrained: ignored, or a result.
	 * @return array{0: ReceiptResult, 1: ReceiptDecision|null, 2: IntentRef|null, 3: bool} What was decided; the decision, or
	 *                                                                                  null when an earlier delivery made it;
	 *                                                                                  the store's intent a dispute names, or
	 *                                                                                  null; and whether a result was ignored
	 *                                                                                  because its two ids disagree.
	 */
	private function decideOnce( WebhookEnvelope $envelope, WebhookReading $reading ): array {
		$receipt = $this->receipts->claim( $envelope->gatewayId, $envelope->mode, (string) $reading->eventId, (string) $reading->eventType, $reading->occurredAt, hash( 'sha256', $envelope->rawBody ), $this->correlation->current() );

		if ( null !== $receipt->result ) {
			return array( $receipt->result, null, null, false );
		}

		$disputed  = null;
		$disagreed = false;

		if ( WebhookReadingKind::Ignored === $reading->kind ) {
			$disputed = $this->disputedIntent( $envelope, $reading );
			$decision = ReceiptDecision::ignored( (string) $reading->eventType, $disputed?->uuid );
		} else {
			list( $decision, $disagreed ) = $this->decide( $envelope, $reading->result ?? throw new \UnexpectedValueException( 'A result reading carries its result.' ), $reading->refundUuid );
		}

		$this->receipts->settle( $receipt->id, $decision );

		return array( $decision->result, $decision, $disputed, $disagreed );
	}

	/**
	 * Decides what a result reports, once its intent is read: the store's own intent of the address's gateway and mode, or none.
	 *
	 * A result whose two ids disagree (intentOf()) names none of the store's intents, and is ignored
	 * as one naming an unknown intent is; the caller is told, for the delivery's log line. What the
	 * money path refuses is raised as decideAbout() raises it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the gateway read a result of another provider.
	 *
	 * @param WebhookEnvelope $envelope   The delivery.
	 * @param GatewayResult   $result     The result it reports.
	 * @param string|null     $refundUuid For a refund, the uuid the provider echoed; null otherwise.
	 * @return array{0: ReceiptDecision, 1: bool} The decision, and whether the result was ignored because its two ids disagree.
	 */
	private function decide( WebhookEnvelope $envelope, GatewayResult $result, ?string $refundUuid ): array {
		if ( $result->provider !== $envelope->gatewayId ) {
			throw new \UnexpectedValueException( sprintf( 'The gateway %1$s read a result of the provider %2$s.', $envelope->gatewayId, $result->provider ) );
		}

		$intent = $this->intentOf( $envelope->gatewayId, '' === $result->intentUuid ? null : $result->intentUuid, $result->providerIntentId );

		if ( ! $intent instanceof IntentRef || $intent->gatewayId !== $envelope->gatewayId ) {
			return array( ReceiptDecision::ignored( ReceiptDecision::UNKNOWN_INTENT ), false === $intent );
		}

		return array( $this->decideAbout( $envelope, $result, $intent, $refundUuid ), false );
	}

	/**
	 * Decides what a result reports about the store's intent of the address's gateway: ignored when the intent is of the other mode, and otherwise applied.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException What the money path refuses, but an intent gone since it was read.
	 *
	 * @param WebhookEnvelope $envelope   The delivery.
	 * @param GatewayResult   $result     The result it reports.
	 * @param IntentRef       $intent     The intent it names, as read.
	 * @param string|null     $refundUuid For a refund, the uuid the provider echoed; null otherwise.
	 * @return ReceiptDecision The decision.
	 */
	private function decideAbout( WebhookEnvelope $envelope, GatewayResult $result, IntentRef $intent, ?string $refundUuid ): ReceiptDecision {
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
	 * An authorization is settled with its placement. A capture or a void of a placement still
	 * waiting for its answer is settled with the placement too, so it ends as the reconciliation
	 * run's query of the same result ends it, whichever reaches the store first: the capture of a
	 * payment the gateway was still deciding is the sale, which allocates the order's units, commits
	 * its promotion uses and converts its cart; a capture the payment's state cannot take, such as
	 * one made while the shopper was asked to act, is kept for a person with the units allocated; a
	 * void releases what the placement holds. The state is the intent's as read, before its lock: a
	 * placement settled since is one the settlement moves nothing of. A capture or a void of any
	 * other intent is applied through the money path alone (PaymentService::applyDelivered()). A
	 * void reported this way was asked by nobody of the store: an accepted order is parked for a
	 * person. A refund is recorded through the claim of the refund the provider echoed, or kept for
	 * a person when no open claim accounts for it (RefundService::recordProviderRefund()).
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
				return $this->settledWithPlacement( $result, $actor );

			case Operation::Capture:
			case Operation::Void:
				$voidReason = Operation::Void === $result->operation ? VoidReason::VoidedExternally : null;

				if ( in_array( $intent->status, IntentStatus::awaitingResult(), true ) ) {
					return $this->settledWithPlacement( $result, $actor, $voidReason );
				}

				return ReceiptDecision::ofApplication( $this->payments->applyDelivered( $result, $actor, $voidReason ) );

			default:
				return ReceiptDecision::ofRefund( $this->refunds->recordProviderRefund( $refundUuid, $result, $actor ), $intent->uuid );
		}
	}

	/**
	 * Settles a result with the placement of the order it is about, and writes down what the payment path did.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the settlement carries no application, which it always does.
	 *
	 * @param GatewayResult   $result     The result: an authorization, or a capture or a void of a placement still waiting
	 *                                    for its answer.
	 * @param Actor           $actor      The store, on no user's authority.
	 * @param VoidReason|null $voidReason Optional. Why the void was asked for; null for anything but a void. Default null.
	 * @return ReceiptDecision The decision.
	 */
	private function settledWithPlacement( GatewayResult $result, Actor $actor, ?VoidReason $voidReason = null ): ReceiptDecision {
		return ReceiptDecision::ofApplication( $this->settlement->apply( $result, $actor, $voidReason )->application ?? throw new \LogicException( 'A settled placement carries what the payment path did.' ) );
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
	 * Reads the intent a delivery names: by the store's uuid when it carries one, or else by the provider's reference, each constrained before; false when the two disagree.
	 *
	 * A delivery that carries both names one payment only when the intent of the uuid holds that
	 * reference, or holds none yet and no other intent of the gateway holds it: the provider's first
	 * word about the intent may come by webhook, before its answer to the store's call is applied.
	 * Otherwise the two ids name two payments: a provider's error, or one payment's event addressed
	 * with another's id, and nothing of it is the store's to apply. The intent's reference is read
	 * with the intent, so an intent that holds one costs no statement more; one that holds none
	 * costs the lookup of the reference, on the gateway's unique key of them.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $gatewayId        The address's gateway.
	 * @param string|null $intentUuid       The store's uuid of the intent, as the provider echoed it.
	 * @param string|null $providerIntentId The provider's reference to the intent.
	 * @return IntentRef|false|null The intent; false when the delivery's reference is not the one the intent of the
	 *                              uuid holds, or is another intent's; null when it names none of the store's.
	 */
	private function intentOf( string $gatewayId, ?string $intentUuid, ?string $providerIntentId ): IntentRef|false|null {
		if ( null === $intentUuid ) {
			return null === $providerIntentId ? null : $this->payments->intentByProvider( $gatewayId, $providerIntentId );
		}

		$intent = $this->payments->intentRef( $intentUuid );

		if ( null === $intent || null === $providerIntentId ) {
			return $intent;
		}

		if ( null !== $intent->providerIntentId ) {
			return $intent->providerIntentId === $providerIntentId ? $intent : false;
		}

		// The intent holds no reference yet: the reference is its own unless another intent holds it.
		$holder = $this->payments->intentByProvider( $gatewayId, $providerIntentId );

		return null === $holder || $holder->uuid === $intent->uuid ? $intent : false;
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

		return $intent instanceof IntentRef && $intent->gatewayId === $envelope->gatewayId && $intent->mode === $envelope->mode ? $intent : null;
	}

	/**
	 * Counts a rejected delivery and logs it, while the client's rejections this window are few enough: a wrong secret is the one misconfiguration the merchant must see.
	 *
	 * The line identifies the delivery by its body's hash, so it can be matched with the provider's delivery log.
	 * The hash is written by BodyDigest, in groups of eight joined by colons: written as one run of hexadecimal,
	 * about 1 in 232 would hold digits the logger's card-number check removes.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @param string          $reason   Why the gateway rejected it.
	 */
	private function noteRejection( WebhookEnvelope $envelope, string $reason ): void {
		$client = $this->identities->of( 0 );

		// Once the window's count reaches the bound, a flood costs one read each and writes nothing.
		if ( $this->limiter->peek( self::REJECTION_BUCKET, $client, self::REJECTION_WINDOW_SECONDS ) >= self::REJECTIONS_LOGGED ) {
			return;
		}

		// Counted atomically at the bound: of two rejections racing at REJECTIONS_LOGGED - 1, one is logged.
		if ( ! $this->limiter->countBelow( self::REJECTION_BUCKET, $client, self::REJECTION_WINDOW_SECONDS, self::REJECTIONS_LOGGED ) ) {
			return;
		}

		$this->log->warning(
			'payment.webhook_rejected',
			'A webhook delivery failed verification, and nothing of it was kept: a wrong webhook secret, or a clock far off, would explain it.',
			array(
				'gateway_id'  => $envelope->gatewayId,
				'mode'        => $envelope->mode->value,
				'reason'      => $reason,
				'body_sha256' => BodyDigest::of( $envelope->rawBody ),
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
	 * Logs what was decided about a result: at warning, and without either id, for a result ignored because its two ids disagree.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope  The delivery.
	 * @param WebhookReading  $reading   The gateway's reading: a result.
	 * @param ReceiptDecision $decision  What was decided.
	 * @param bool            $disagreed Whether the result was ignored because its two ids disagree.
	 */
	private function logDecided( WebhookEnvelope $envelope, WebhookReading $reading, ReceiptDecision $decision, bool $disagreed ): void {
		$ignored = ReceiptResult::Ignored === $decision->result;
		$context = self::context( $envelope, $reading ) + array(
			'result'         => $decision->result->value,
			'result_code'    => $decision->code,
			'intent_uuid'    => $decision->intentUuid,
			'transaction_id' => $decision->transactionId,
		);

		if ( $disagreed ) {
			$this->log->warning( 'payment.webhook_ignored', 'A webhook delivery named one of the store\'s payments by its id and a provider payment that payment does not hold: the two ids disagree, so nothing was applied.', $context + array( 'reason' => self::IDS_DISAGREE ) );

			return;
		}

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
			'event_id'   => null === $reading->eventId ? null : DeliveredValues::printable( $reading->eventId ),
			'event_type' => $reading->eventType,
		);
	}
}
