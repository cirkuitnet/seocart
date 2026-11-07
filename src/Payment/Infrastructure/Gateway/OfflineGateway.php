<?php
/**
 * OfflineGateway: an offline payment method as a gateway, its answers derived from the store's own references
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Gateway;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * One of the offline payment methods, bank transfer, cheque or cash on delivery, as a payment gateway: money moves outside any provider, and a person records what moved.
 *
 * Owns one fact: how an offline payment method answers the payment contract. It calls no service,
 * keeps no record and calls no WordPress function: every answer is derived from the references the
 * store sends, so the same request answered again is the same result.
 *
 * - An authorization is the shopper's promise to pay, approved at once for the amount asked, so the
 *   order is accepted and its stock allocated while it waits for the money. The payment token is
 *   ignored: a shopper sends none.
 * - A capture is the payment received, which a person records with the day it arrived; it is
 *   approved for the whole amount authorized, as the matrix declares no partial capture.
 * - A void ends the promise; a refund, in full or in part, is money returned outside a provider.
 * - A status query of an intent it was never asked to authorize says it has no record of it; of one
 *   it authorized, the authorization. Asked what became of a refund, it cannot say: it keeps no
 *   record, so a refund whose answer was lost waits for a person, who settles its claim.
 *
 * It names its objects by the intent's uuid, or the refund's: `{id}-pi-{uuid}` is its reference to
 * the intent, and `{id}-promise-{uuid}`, `{id}-received-{uuid}`, `{id}-void-{uuid}` and
 * `{id}-refund-{refund uuid}` the authorization, the capture, the void and each refund.
 *
 * It has one mode, live: a mode is a set of credentials, and an offline method has none. While
 * Safe Mode is on it is not offered, and its open payments wait until Safe Mode ends. Its settings,
 * each with a default, are the name shoppers see (LABEL), the instructions they are given
 * (INSTRUCTIONS) and the days an order may wait for its payment before the merchant is reminded
 * (WAIT_DAYS). It sets up no webhook and reads none.
 *
 * @since 0.2.0
 */
final class OfflineGateway implements PaymentGateway {

	/**
	 * The setting that holds the name shoppers see; empty for the method's own name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LABEL = 'label';

	/**
	 * The setting that holds what the shopper is told to do to pay.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSTRUCTIONS = 'instructions';

	/**
	 * The setting that holds how many days an order may wait for its payment before the merchant is reminded of it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WAIT_DAYS = 'wait_days';

	/**
	 * The longest name shoppers see, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LABEL_MAX_LENGTH = 60;

	/**
	 * The longest instructions, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const INSTRUCTIONS_MAX_LENGTH = 2000;

	/**
	 * The days an order waits for its payment before the merchant is reminded, unless the merchant says otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const WAIT_DAYS_DEFAULT = 7;

	/**
	 * The most days a merchant may let an order wait for its payment before being reminded.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const WAIT_DAYS_MAX = 90;

	/**
	 * The operations every offline method declares, for each of its currencies.
	 *
	 * No partial or multiple capture: a payment received is recorded whole. No charge without the
	 * customer, no customer action and no webhook: nothing happens at a provider.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const OPERATIONS = array(
		Operations::AUTHORIZE,
		Operations::CAPTURE,
		Operations::VOID,
		Operations::REFUND,
		Operations::PARTIAL_REFUND,
		Operations::QUERY,
		Operations::QUERY_REFUND,
	);

	/**
	 * Creates the gateway of one offline payment method.
	 *
	 * @since 0.2.0
	 *
	 * @param OfflineMethod $method The method.
	 */
	public function __construct( private OfflineMethod $method ) {
	}

	/**
	 * Returns a gateway for each offline payment method, in the order they register.
	 *
	 * @since 0.2.0
	 *
	 * @return list<self> Bank transfer, cheque and cash on delivery.
	 */
	public static function all(): array {
		return array_map( static fn( OfflineMethod $method ): self => new self( $method ), OfflineMethod::cases() );
	}

	/**
	 * Describes the method: live mode only, its three settings, and every operation but capturing in parts, charging without the customer, customer action and webhooks, in the launch currencies.
	 *
	 * Its "provider" is the store's own reference to the intent, so it can always be searched, at
	 * once, and has no key to forget.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return new GatewayDescriptor(
			$this->method->value,
			$this->method->label(),
			GatewayDescriptor::TYPE_PAYMENTS,
			PaymentGateway::CONTRACT_VERSION,
			array( Mode::Live ),
			self::settings(),
			new CapabilityMatrix( array_map( static fn( string $code ): MatrixRow => new MatrixRow( Currency::of( $code ), MatrixRow::ANY_COUNTRY, self::OPERATIONS ), CoreGatewayCurrencies::LAUNCH ) ),
			new IdempotencyProfile( null, true, 0 ),
			// An offline method calls no external service.
			array()
		);
	}

	/**
	 * Tells whether the method can take a payment: always, where its matrix allows it.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool True.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		unset( $context );

		return true;
	}

	/**
	 * Accepts the shopper's promise to pay: an approved authorization of the amount asked.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The intent; its payment token is ignored.
	 * @return GatewayResult The approval.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->authorization( $request->intentUuid, $request->amount );
	}

	/**
	 * Records the payment received: an approved capture of the amount asked.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The intent and the amount.
	 * @return GatewayResult The approval.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return new GatewayResult( $this->method->value, Operation::Capture, Outcome::Approved, $request->intentUuid, $request->amount, $this->name( 'received', $request->intentUuid ), $request->providerIntentId );
	}

	/**
	 * Ends the shopper's promise to pay: an approved void, reporting the amount it released.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The intent and why it is voided.
	 * @return GatewayResult The approval.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return new GatewayResult( $this->method->value, Operation::Void, Outcome::Approved, $request->intentUuid, $request->amount, $this->name( 'void', $request->intentUuid ), $request->providerIntentId );
	}

	/**
	 * Records money returned outside a provider: an approved refund of the amount asked, named by the refund's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The intent, the amount and the refund's uuid.
	 * @return GatewayResult The approval.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return new GatewayResult( $this->method->value, Operation::Refund, Outcome::Approved, $request->intentUuid, $request->amount, $this->name( 'refund', $request->refundUuid ), $request->providerIntentId );
	}

	/**
	 * Says that it cannot say what became of a refund.
	 *
	 * The method keeps no record of the refunds it recorded, so it cannot tell one whose answer was
	 * lost from one whose request never reached it. The refund's claim then waits for a person, who
	 * settles it with the refund claim's settlement: `refunded`, naming what was returned, such as
	 * the number of the cheque sent back, and the amount; or `not_refunded`, after which the same
	 * units can be refunded again. Nothing moved anywhere on the method's account, so either
	 * statement is safe.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null Null: it cannot say.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		return null;
	}

	/**
	 * Says where an intent stands: no record of one it was never asked to authorize, and the authorization of one it was.
	 *
	 * An intent with no reference of the method's was never answered by it: it says so, as a
	 * declined authorization `not_found`, which ends the placement. An intent with the method's own
	 * reference was authorized, as every authorization is; a reference that is not its own is one it
	 * cannot say anything about.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The authorization, or the decline `not_found`; null for a reference that is not the method's.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		if ( null === $query->providerIntentId ) {
			return new GatewayResult( $this->method->value, Operation::Authorize, Outcome::Declined, $query->intentUuid, $query->amount, $this->name( 'not-found', $query->intentUuid ), null, PaymentGateway::NOT_FOUND );
		}

		return $this->name( 'pi', $query->intentUuid ) === $query->providerIntentId ? $this->authorization( $query->intentUuid, $query->amount ) : null;
	}

	/**
	 * Rejects every webhook delivery: no provider sends one for an offline method, so none can be verified as its own.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery, unread.
	 * @return WebhookReading Rejected `bad_signature`.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		unset( $envelope );

		return WebhookReading::rejected( WebhookReading::BAD_SIGNATURE );
	}

	/**
	 * Returns the approved authorization of an intent, named by the intent's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @param Money  $amount     The amount asked.
	 * @return GatewayResult The approval.
	 */
	private function authorization( string $intentUuid, Money $amount ): GatewayResult {
		return new GatewayResult( $this->method->value, Operation::Authorize, Outcome::Approved, $intentUuid, $amount, $this->name( 'promise', $intentUuid ), $this->name( 'pi', $intentUuid ) );
	}

	/**
	 * Names one of the method's objects: the method's id, what the object is, and the uuid it is of.
	 *
	 * @since 0.2.0
	 *
	 * @param string $what What the object is, such as `received`.
	 * @param string $uuid The intent's uuid, or the refund's.
	 * @return string For example `bank_transfer-received-{uuid}`.
	 */
	private function name( string $what, string $uuid ): string {
		return $this->method->value . '-' . $what . '-' . $uuid;
	}

	/**
	 * Declares the method's settings, each with a default, so the method is configured as soon as it is registered.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The name shoppers see, the instructions and the days of the wait.
	 */
	private static function settings(): array {
		return array(
			new FieldSpec(
				name: self::LABEL,
				type: FieldType::String,
				description: 'The name shoppers see for the payment method; empty for the method\'s own name.',
				label: static fn(): string => __( 'Name shown to shoppers', 'seocart' ),
				example: 'Pay by bank transfer',
				default_value: '',
				max_length: self::LABEL_MAX_LENGTH
			),
			new FieldSpec(
				name: self::INSTRUCTIONS,
				type: FieldType::String,
				description: 'What the shopper is told to do to pay, in plain text, shown once the order is placed: where to send the money and what to quote with it, or a word on paying the courier.',
				label: static fn(): string => __( 'Instructions', 'seocart' ),
				example: 'Pay by transfer to account 12345678, quoting your order number.',
				default_value: '',
				max_length: self::INSTRUCTIONS_MAX_LENGTH,
				privacy: Privacy::Public
			),
			new FieldSpec(
				name: self::WAIT_DAYS,
				type: FieldType::Integer,
				description: 'How many days an order may wait for its payment before doctor and Site Health remind the merchant of it.',
				label: static fn(): string => __( 'Days to wait for the payment', 'seocart' ),
				example: self::WAIT_DAYS_DEFAULT,
				default_value: self::WAIT_DAYS_DEFAULT,
				minimum: 1,
				maximum: self::WAIT_DAYS_MAX
			),
		);
	}
}
