<?php
/**
 * UndescribableGateway: a gateway whose declaration cannot be built
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;

/**
 * A gateway whose declaration cannot be built, for the tests that the registry refuses it without harm.
 *
 * Owns one fact: a malformed declaration, by default an id no settings group or intent can hold;
 * a test may give another. Every call it would answer is the stub's.
 *
 * @since 0.2.0
 */
final class UndescribableGateway implements PaymentGateway {

	use DecoratesGateway {
		describe as private describeInner;
	}

	/**
	 * The stub, whose answers it gives.
	 *
	 * @since 0.2.0
	 *
	 * @var StubGateway
	 */
	private StubGateway $inner;

	/**
	 * Builds the declaration, which throws.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): GatewayDescriptor
	 */
	private \Closure $declaration;

	/**
	 * Builds it.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure|null $declaration Optional. Builds a declaration the descriptor refuses. Default one with an id that is not one.
	 *
	 * @phpstan-param (\Closure(): GatewayDescriptor)|null $declaration
	 */
	public function __construct( ?\Closure $declaration = null ) {
		$this->inner       = new StubGateway();
		$this->declaration = $declaration ?? static fn(): GatewayDescriptor => DeclaredGateway::descriptor( 'Not-An-Id', array( Mode::Test ), array() );
	}

	/**
	 * Describes itself with a declaration the descriptor refuses.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException Always.
	 *
	 * @return GatewayDescriptor Never.
	 */
	public function describe(): GatewayDescriptor {
		return ( $this->declaration )();
	}

	/**
	 * Authorizes as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Contracts\Payment\PaymentRequest $request The request.
	 * @return \SEOCart\Contracts\Payment\GatewayResult The answer.
	 */
	public function authorize( \SEOCart\Contracts\Payment\PaymentRequest $request ): \SEOCart\Contracts\Payment\GatewayResult {
		return $this->inner->authorize( $request );
	}

	/**
	 * Captures as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Contracts\Payment\CaptureRequest $request The request.
	 * @return \SEOCart\Contracts\Payment\GatewayResult The answer.
	 */
	public function capture( \SEOCart\Contracts\Payment\CaptureRequest $request ): \SEOCart\Contracts\Payment\GatewayResult {
		return $this->inner->capture( $request );
	}

	/**
	 * Refunds as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Contracts\Payment\GatewayRefund $request The request.
	 * @return \SEOCart\Contracts\Payment\GatewayResult The answer.
	 */
	public function refund( \SEOCart\Contracts\Payment\GatewayRefund $request ): \SEOCart\Contracts\Payment\GatewayResult {
		return $this->inner->refund( $request );
	}

	/**
	 * Answers a query as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Contracts\Payment\PaymentQuery $query The query.
	 * @return \SEOCart\Contracts\Payment\GatewayResult|null The answer.
	 */
	public function query( \SEOCart\Contracts\Payment\PaymentQuery $query ): ?\SEOCart\Contracts\Payment\GatewayResult {
		return $this->inner->query( $query );
	}

	/**
	 * Says what became of a refund as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Contracts\Payment\GatewayRefund $request The refund.
	 * @return \SEOCart\Contracts\Payment\GatewayResult The answer.
	 */
	public function queryRefund( \SEOCart\Contracts\Payment\GatewayRefund $request ): \SEOCart\Contracts\Payment\GatewayResult {
		return $this->inner->queryRefund( $request );
	}
}
