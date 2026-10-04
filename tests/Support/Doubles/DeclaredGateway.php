<?php
/**
 * DeclaredGateway: a gateway of the test's own declaration, answering as the stub does, that counts its calls
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

/**
 * A second gateway for the registry's tests: its descriptor is the test's, its answers the stub's under its own id, and every call it receives is counted with the mode it came in.
 *
 * Owns one fact: what a test needs of a gateway other than the stand-in. Before each call it
 * opens its credentials for the request's mode, as a real adapter reads its key, so a credential
 * that does not open fails the call before anything would be sent. A test that wants a different
 * answer sets `$query` (the answer to every status query) or `$available` (its own availability),
 * and one that changes the store at the moment availability is asked sets `$whenAsked`.
 *
 * @since 0.2.0
 */
final class DeclaredGateway implements PaymentGateway {

	/**
	 * Every call received: the method and the request's mode.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, mode: string}>
	 */
	public array $calls = array();

	/**
	 * What every status query answers; null to answer as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(PaymentQuery): (GatewayResult|null)|null
	 */
	public ?\Closure $query = null;

	/**
	 * Whether the gateway takes a payment where its matrix allows it.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * Runs when the gateway is asked whether it takes a payment, after the plugin's own checks passed; null for nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): void)|null
	 */
	public ?\Closure $whenAsked = null;

	/**
	 * The stub, whose answers this gateway gives under its own id.
	 *
	 * @since 0.2.0
	 *
	 * @var StubGateway
	 */
	private StubGateway $inner;

	/**
	 * Builds the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayDescriptor     $descriptor Its declaration.
	 * @param ExtensionContext|null $context    Optional. Its context, through which it opens its credentials before each call. Default none.
	 */
	public function __construct( private GatewayDescriptor $descriptor, private ?ExtensionContext $context = null ) {
		$this->inner = new StubGateway();
	}

	/**
	 * Declares a gateway: by default test and live modes, a secret key and an account country, and every operation but capturing in parts, charging without the customer and webhooks, in USD, GBP and EUR, for an account of any country.
	 *
	 * @since 0.2.0
	 *
	 * @param string                  $id         The id.
	 * @param array                   $modes      Optional. The modes. Default test and live.
	 * @param list<FieldSpec>|null    $settings   Optional. The settings. Default a secret key and an account country.
	 * @param CapabilityMatrix|null   $matrix     Optional. The matrix. Default as described.
	 * @param IdempotencyProfile|null $profile    Optional. The profile. Default searchable at once, with a day's keys.
	 * @param string                  $contract   Optional. The contract version written against. Default the plugin's.
	 * @param array                   $hosts      Optional. The external services it calls. Default none.
	 * @return GatewayDescriptor The descriptor.
	 *
	 * @phpstan-param list<Mode>           $modes
	 * @phpstan-param list<FieldSpec>|null $settings
	 * @phpstan-param list<OutboundHost>   $hosts
	 */
	public static function descriptor( string $id, array $modes = array( Mode::Test, Mode::Live ), ?array $settings = null, ?CapabilityMatrix $matrix = null, ?IdempotencyProfile $profile = null, string $contract = PaymentGateway::CONTRACT_VERSION, array $hosts = array() ): GatewayDescriptor {
		return new GatewayDescriptor(
			$id,
			static fn(): string => 'Declared gateway',
			GatewayDescriptor::TYPE_PAYMENTS,
			$contract,
			$modes,
			$settings ?? array(
				new FieldSpec( name: 'secret_key', type: FieldType::String, description: 'The provider\'s secret key.', label: static fn(): string => 'Secret key', example: 'sk_test_x', privacy: Privacy::Secret ),
				new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The account\'s country.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 ),
			),
			$matrix ?? self::matrix( array( 'USD', 'GBP', 'EUR' ), array_values( array_diff( Operations::ALL, array( Operations::MULTI_CAPTURE, Operations::OFF_SESSION, Operations::WEBHOOKS ) ) ) ),
			$profile ?? new IdempotencyProfile( 86400, true, 0 ),
			$hosts
		);
	}

	/**
	 * Declares an external service a test gateway calls, on the host `api.{name}.example`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The service's name: lower-case letters, the id of its declaration.
	 * @return OutboundHost The declaration.
	 */
	public static function host( string $name ): OutboundHost {
		return new OutboundHost(
			id: $name,
			service: 'Example ' . $name,
			purpose: 'A provider a test gateway calls.',
			endpoint: 'https://api.' . $name . '.example/v1/*',
			dataSent: 'The amount of the payment and its references. No card data.',
			sentWhen: 'When the test asks the gateway.',
			termsUrl: 'https://example.com/terms',
			privacyUrl: 'https://example.com/privacy'
		);
	}

	/**
	 * Builds a matrix of one row per currency, for an account of any country.
	 *
	 * @since 0.2.0
	 *
	 * @param array $currencies The currencies.
	 * @param array $operations The operations of every row.
	 * @return CapabilityMatrix The matrix.
	 *
	 * @phpstan-param list<string> $currencies
	 * @phpstan-param list<string> $operations
	 */
	public static function matrix( array $currencies, array $operations ): CapabilityMatrix {
		return new CapabilityMatrix( array_map( static fn( string $code ): MatrixRow => new MatrixRow( Currency::of( $code ), MatrixRow::ANY_COUNTRY, $operations ), $currencies ) );
	}

	/**
	 * Returns the test's declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return $this->descriptor;
	}

	/**
	 * Says whether it takes a payment, as the test set it.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool `$available`.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		unset( $context );

		if ( null !== $this->whenAsked ) {
			( $this->whenAsked )();
		}

		return $this->available;
	}

	/**
	 * Authorizes as the stub does, under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$this->called( __FUNCTION__, $request->mode );

		return $this->own( $this->inner->authorize( $request ) );
	}

	/**
	 * Captures as the stub does, under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$this->called( __FUNCTION__, $request->mode );

		return $this->own( $this->inner->capture( $request ) );
	}

	/**
	 * Voids as the stub does, under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		$this->called( __FUNCTION__, $request->mode );

		return $this->own( $this->inner->void( $request ) );
	}

	/**
	 * Refunds as the stub does, under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->called( __FUNCTION__, $request->mode );

		return $this->own( $this->inner->refund( $request ) );
	}

	/**
	 * Answers a status query as the test set it, or as the stub does, under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null The answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$this->called( __FUNCTION__, $query->mode );

		return null === $this->query ? $this->own( $this->inner->query( $query ) ) : ( $this->query )( $query );
	}

	/**
	 * Says what became of a refund as the stub does, under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult|null The answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$this->called( __FUNCTION__, $request->mode );

		return $this->own( $this->inner->queryRefund( $request ) );
	}

	/**
	 * Rejects every delivery, as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading Rejected.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		return $this->inner->readWebhook( $envelope );
	}

	/**
	 * Counts a call, after opening the credentials of its mode as an adapter would before sending anything.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @param Mode   $mode   The request's mode.
	 */
	private function called( string $method, Mode $mode ): void {
		if ( null !== $this->context ) {
			foreach ( $this->descriptor->secrets() as $field ) {
				$this->context->settings( $mode )->secret( $field->name() );
			}
		}

		$this->calls[] = array(
			'method' => $method,
			'mode'   => $mode->value,
		);
	}

	/**
	 * Gives the stub's answer under this gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult|null $result The stub's answer.
	 * @return GatewayResult|null The same answer, from this gateway.
	 */
	private function own( ?GatewayResult $result ): ?GatewayResult {
		if ( null === $result ) {
			return null;
		}

		return new GatewayResult( $this->descriptor->id, $result->operation, $result->outcome, $result->intentUuid, $result->amount, $result->providerObjectId, $result->providerIntentId, $result->errorCode, $result->settlement );
	}
}
