<?php
/**
 * LiveOnlyGateway: a gateway that calls no service and has live mode alone, answering as the stub does, that refuses a call in any other mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;

/**
 * A gateway that declares no host and live mode alone, as an offline payment method does, with the stub's operations and webhooks, and that answers a call in any mode it does not declare so that the conformance suite fails.
 *
 * Owns one fact: that such a gateway is spoken to in live mode. In live mode it answers as the
 * stub does, under its own id (DeclaredGateway). Asked in a mode its descriptor does not declare,
 * it answers nothing the suite can pass: every call but the webhook reading throws a LogicException
 * that names the call and the mode, which the suite reports with the rule that made the call; a
 * webhook delivery, which a gateway may never throw on, is rejected with that same sentence as
 * its reason, which the suite prints when the delivery should have been read.
 *
 * @since 0.2.0
 */
final class LiveOnlyGateway implements PaymentGateway {

	/**
	 * The gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = 'live_only';

	/**
	 * The gateway that answers in live mode: the stub's answers under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @var DeclaredGateway
	 */
	private DeclaredGateway $inner;

	/**
	 * Builds the gateway: the stub's matrix and idempotency profile, live mode alone, no settings and no host.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		$stub        = ( new StubGateway() )->describe();
		$this->inner = new DeclaredGateway( DeclaredGateway::descriptor( self::ID, array( Mode::Live ), array(), $stub->matrix, $stub->idempotency ) );
	}

	/**
	 * Returns the gateway's declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return $this->inner->describe();
	}

	/**
	 * Says it takes a payment, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool True.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		$this->refuseUndeclared( __FUNCTION__, $context->mode );

		return $this->inner->isAvailable( $context );
	}

	/**
	 * Authorizes as the stub does, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$this->refuseUndeclared( __FUNCTION__, $request->mode );

		return $this->inner->authorize( $request );
	}

	/**
	 * Captures as the stub does, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$this->refuseUndeclared( __FUNCTION__, $request->mode );

		return $this->inner->capture( $request );
	}

	/**
	 * Voids as the stub does, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		$this->refuseUndeclared( __FUNCTION__, $request->mode );

		return $this->inner->void( $request );
	}

	/**
	 * Refunds as the stub does, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->refuseUndeclared( __FUNCTION__, $request->mode );

		return $this->inner->refund( $request );
	}

	/**
	 * Says what became of a refund as the stub does, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult|null The answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$this->refuseUndeclared( __FUNCTION__, $request->mode );

		return $this->inner->queryRefund( $request );
	}

	/**
	 * Answers a status query as the stub does, in a mode it declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException In a mode it does not declare.
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null The answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$this->refuseUndeclared( __FUNCTION__, $query->mode );

		return $this->inner->query( $query );
	}

	/**
	 * Reads a delivery as the stub does, in a mode it declares; in another, rejects it with the refusal as its reason, since a gateway never throws on a delivery.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading The reading.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		$refusal = $this->refusal( __FUNCTION__, $envelope->mode );

		return null === $refusal ? $this->inner->readWebhook( $envelope ) : WebhookReading::rejected( $refusal );
	}

	/**
	 * Throws the refusal of a call in a mode the gateway does not declare.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the mode is not declared.
	 *
	 * @param string $call The method called.
	 * @param Mode   $mode The mode it was called in.
	 */
	private function refuseUndeclared( string $call, Mode $mode ): void {
		$refusal = $this->refusal( $call, $mode );

		if ( null !== $refusal ) {
			throw new \LogicException( $refusal . '.' );
		}
	}

	/**
	 * Says why a call in a mode is refused: the one place the modes the gateway answers in are decided, which are those its descriptor declares.
	 *
	 * @since 0.2.0
	 *
	 * @param string $call The method called.
	 * @param Mode   $mode The mode it was called in.
	 * @return string|null The refusal, naming the call and the mode; null when the mode is declared.
	 */
	private function refusal( string $call, Mode $mode ): ?string {
		return in_array( $mode, $this->describe()->modes, true ) ? null : sprintf( '%1$s() was asked in %2$s mode, which the gateway %3$s does not declare', $call, $mode->value, self::ID );
	}
}
