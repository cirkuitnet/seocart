<?php
/**
 * Tests the offline payment methods' declaration and answers
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
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
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Payment\Infrastructure\Gateway\CoreGatewayCurrencies;
use SEOCart\Payment\Infrastructure\Gateway\OfflineGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;

/**
 * Each offline payment method declares one live mode, three settings with defaults and the launch currencies, approves every money operation at once for the amount asked, and names its objects by the uuid they are of.
 *
 * Planted violation, shown red and removed: in OfflineGateway::authorize(), answer the promise
 * Pending: the approval's assertions fail.
 *
 * @since 0.2.0
 */
final class OfflineGatewayTest extends TestCase {

	/**
	 * The intent every request is about.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INTENT = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c';

	/**
	 * A refund of it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUND = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a9d';

	/**
	 * Tests the methods: three, in order, one per id, only cash on delivery paid on delivery.
	 *
	 * @since 0.2.0
	 */
	public function test_the_three_methods_and_their_ids(): void {
		$this->assertSame( array( 'bank_transfer', 'cheque', 'cash_on_delivery' ), OfflineMethod::ids() );
		$this->assertSame( OfflineMethod::ids(), array_map( static fn( OfflineGateway $gateway ): string => $gateway->describe()->id, OfflineGateway::all() ) );
		$this->assertSame( array( false, false, true ), array_map( static fn( OfflineMethod $method ): bool => $method->paysOnDelivery(), OfflineMethod::cases() ) );

		foreach ( OfflineMethod::ids() as $id ) {
			$this->assertTrue( OfflineMethod::isOffline( $id ), $id );
			$this->assertMatchesRegularExpression( GatewayDescriptor::ID_PATTERN, $id );
		}

		foreach ( array( 'stub', 'stripe', 'BANK_TRANSFER', '' ) as $other ) {
			$this->assertFalse( OfflineMethod::isOffline( $other ), $other );
		}
	}

	/**
	 * Tests the descriptor: live only, three settings with defaults, the launch currencies with the operations declared and none other, and a searchable profile.
	 *
	 * @since 0.2.0
	 */
	public function test_each_method_describes_itself(): void {
		$declared = array( Operations::AUTHORIZE, Operations::CAPTURE, Operations::VOID, Operations::REFUND, Operations::PARTIAL_REFUND, Operations::QUERY, Operations::QUERY_REFUND );

		foreach ( OfflineGateway::all() as $gateway ) {
			$descriptor = $gateway->describe();

			$this->assertSame( array( Mode::Live ), $descriptor->modes );
			$this->assertSame( GatewayDescriptor::TYPE_PAYMENTS, $descriptor->type );
			$this->assertSame( PaymentGateway::CONTRACT_VERSION, $descriptor->contract );
			$this->assertSame( array(), $descriptor->hosts );
			$this->assertEquals( new IdempotencyProfile( null, true, 0 ), $descriptor->idempotency );
			$this->assertSame( array(), $descriptor->secrets() );
			$this->assertSame(
				array(
					OfflineGateway::LABEL        => '',
					OfflineGateway::INSTRUCTIONS => '',
					OfflineGateway::WAIT_DAYS    => OfflineGateway::WAIT_DAYS_DEFAULT,
				),
				array_combine(
					array_map( static fn( FieldSpec $field ): string => $field->name(), $descriptor->settings ),
					array_map( static fn( FieldSpec $field ): int|string|null => $field->defaultValue(), $descriptor->settings )
				)
			);
			$this->assertSame( CoreGatewayCurrencies::LAUNCH, $descriptor->matrix->currencies() );

			foreach ( $descriptor->matrix->rows as $row ) {
				$this->assertSame( MatrixRow::ANY_COUNTRY, $row->accountCountry );
				$this->assertSame( $declared, array_values( array_filter( Operations::ALL, static fn( string $operation ): bool => $row->supports( $operation ) ) ), 'No partial or multiple capture, no charge without the customer, no customer action, no webhook.' );
			}

			$this->assertFalse( $descriptor->matrix->allows( Operations::AUTHORIZE, Currency::of( 'JPY' ), null ) );
		}
	}

	/**
	 * Tests the answers: every money operation approved at once for the amount asked, each named by its uuid, the same when asked again.
	 *
	 * @since 0.2.0
	 */
	public function test_each_money_operation_is_approved_for_the_amount_asked(): void {
		$gateway  = new OfflineGateway( OfflineMethod::Cheque );
		$amount   = Money::of( 4250, Currency::of( 'GBP' ) );
		$promised = $gateway->authorize( new PaymentRequest( self::INTENT, $amount, null, Mode::Live, '01928c3e-0000-7000-8000-0000000000a1', '1001' ) );

		$this->assertSame(
			array( 'cheque', Operation::Authorize, Outcome::Approved, self::INTENT, 4250, 'GBP', 'cheque-promise-' . self::INTENT, 'cheque-pi-' . self::INTENT, null ),
			self::shape( $promised )
		);
		$this->assertEquals( $promised, $gateway->authorize( new PaymentRequest( self::INTENT, $amount, 'anything', Mode::Live, '01928c3e-0000-7000-8000-0000000000a1', '1001' ) ), 'A token is ignored.' );

		$received = $gateway->capture( new CaptureRequest( self::INTENT, 'cheque-pi-' . self::INTENT, $amount, Mode::Live ) );
		$this->assertSame( array( 'cheque', Operation::Capture, Outcome::Approved, self::INTENT, 4250, 'GBP', 'cheque-received-' . self::INTENT, 'cheque-pi-' . self::INTENT, null ), self::shape( $received ) );
		$this->assertEquals( $received, $gateway->capture( new CaptureRequest( self::INTENT, 'cheque-pi-' . self::INTENT, $amount, Mode::Live ) ), 'The same capture asked again is the same result.' );

		$voided = $gateway->void( new VoidRequest( self::INTENT, 'cheque-pi-' . self::INTENT, $amount, Mode::Live, 'customer_request' ) );
		$this->assertSame( array( 'cheque', Operation::Void, Outcome::Approved, self::INTENT, 4250, 'GBP', 'cheque-void-' . self::INTENT, 'cheque-pi-' . self::INTENT, null ), self::shape( $voided ) );

		$part     = Money::of( 1000, Currency::of( 'GBP' ) );
		$refunded = $gateway->refund( new GatewayRefund( self::INTENT, 'cheque-pi-' . self::INTENT, $part, self::REFUND, Mode::Live ) );
		$this->assertSame( array( 'cheque', Operation::Refund, Outcome::Approved, self::INTENT, 1000, 'GBP', 'cheque-refund-' . self::REFUND, 'cheque-pi-' . self::INTENT, null ), self::shape( $refunded ) );
		$this->assertNull( $gateway->queryRefund( new GatewayRefund( self::INTENT, 'cheque-pi-' . self::INTENT, $part, self::REFUND, Mode::Live ) ), 'It keeps no record of its refunds, so it cannot say.' );
	}

	/**
	 * Tests the status query: no record of an intent it never answered, the authorization of one it did, nothing of a reference not its own.
	 *
	 * @since 0.2.0
	 */
	public function test_a_query_answers_from_the_reference(): void {
		$gateway = new OfflineGateway( OfflineMethod::BankTransfer );
		$amount  = Money::of( 900, Currency::of( 'EUR' ) );
		$unseen  = $gateway->query( new PaymentQuery( self::INTENT, null, $amount, Mode::Live ) );

		$this->assertNotNull( $unseen );
		$this->assertSame( array( 'bank_transfer', Operation::Authorize, Outcome::Declined, self::INTENT, 900, 'EUR', 'bank_transfer-not-found-' . self::INTENT, null, PaymentGateway::NOT_FOUND ), self::shape( $unseen ) );

		$known = $gateway->query( new PaymentQuery( self::INTENT, 'bank_transfer-pi-' . self::INTENT, $amount, Mode::Live ) );
		$this->assertEquals( $gateway->authorize( new PaymentRequest( self::INTENT, $amount, null, Mode::Live, '01928c3e-0000-7000-8000-0000000000a1', '1001' ) ), $known, 'An intent it authorized is found authorized.' );

		$this->assertNull( $gateway->query( new PaymentQuery( self::INTENT, 'cheque-pi-' . self::INTENT, $amount, Mode::Live ) ), 'Another method\'s reference is none of its own.' );
	}

	/**
	 * Tests that a webhook delivery is rejected, unread.
	 *
	 * @since 0.2.0
	 */
	public function test_every_webhook_delivery_is_rejected(): void {
		$reading = ( new OfflineGateway( OfflineMethod::CashOnDelivery ) )->readWebhook( new WebhookEnvelope( 'cash_on_delivery', Mode::Live, array(), '{"id":"evt_1"}', new \DateTimeImmutable( '2026-10-03 12:00:00', new \DateTimeZone( 'UTC' ) ) ) );

		$this->assertSame( WebhookReadingKind::Rejected, $reading->kind );
		$this->assertSame( WebhookReading::BAD_SIGNATURE, $reading->reason );
	}

	/**
	 * Returns what an answer says, field by field.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The answer.
	 * @return array{string, Operation, Outcome, string, int, string, string|null, string|null, string|null} The provider, operation, outcome, intent, amount, currency, object, reference and error code.
	 */
	private static function shape( GatewayResult $result ): array {
		return array( $result->provider, $result->operation, $result->outcome, $result->intentUuid, $result->amount->minorUnits(), $result->amount->currency()->code(), $result->providerObjectId, $result->providerIntentId, $result->errorCode );
	}
}
