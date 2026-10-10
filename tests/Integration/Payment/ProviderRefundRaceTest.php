<?php
/**
 * Tests a person's settlement of a refund's claim racing the provider's own word of the same refund: whichever takes the intent's lock first ends the claim, and the other meets it ended
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\ProviderRefundKind;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\RunningProbe;

/**
 * Two real connections, never a pause: one side runs here, and a barrier just before its ledger row starts the other in a process of its own, then returns once the server shows that process waiting for the intent's lock under which a claim ends.
 *
 * The claim is left open as a real one is: the refund was asked while the gateway could not be
 * reached. The person states the refund was made, as `re_stated`; the provider's word names that
 * refund, or the one it made under the claim's uuid.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class ProviderRefundRaceTest extends RefundTestCase {

	/**
	 * Why the person says so.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'The provider\'s dashboard shows the refund, made the day the request timed out.';

	/**
	 * The ledger row's insert, before which the barrier starts the other side.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LEDGER_ROW = '/^INSERT INTO \S*payment_transactions\b/';

	/**
	 * The state the server shows a locking read waiting for a row lock in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WAITING = 'statistics';

	/**
	 * The gateway the claims were asked of, which cannot say what became of them.
	 *
	 * @since 0.2.0
	 *
	 * @var RememberingGateway
	 */
	private RememberingGateway $provider;

	/**
	 * The refund service over that gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var RefundService
	 */
	private RefundService $service;

	/**
	 * The other side, once the barrier started it.
	 *
	 * @since 0.2.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probe = null;

	/**
	 * Builds the gateway and the service over it.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->provider        = new RememberingGateway( new StubGateway() );
		$this->provider->knows = false;
		$this->service         = $this->refundsOver( $this->db, $this->ids, $this->provider );
	}

	/**
	 * Tests that the provider's word arriving while a person settles the claim waits for the intent's lock, then meets the claim the settlement ended: the refund the person named is a duplicate, and one they did not name is money kept for a person, never a second refund recorded.
	 *
	 * Planted violation: in RefundService::recordClaimed(), drop lockOpenClaim(): the delivery no
	 * longer waits for the claim's lock, nor reads the claim under it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_providers_word_waiting_for_a_settlement_meets_the_claim_it_ended(): void {
		foreach ( array(
			'the refund the person named' => array( true, ProviderRefundKind::Duplicate ),
			'another refund'              => array( false, ProviderRefundKind::Unexpected ),
		) as $case => list( $theStated, $expected ) ) {
			list( $order, $intent, $uuid, $amount ) = $this->claimed();
			$this->probe                            = null;
			$stated                                 = 're_stated_' . substr( $uuid, -12 );
			$named                                  = $theStated ? $stated : 'stub-re-' . $uuid;
			$currency                               = (string) $this->claimRow( $uuid )['currency'];

			$this->beforeStatement(
				self::LEDGER_ROW,
				function () use ( $intent, $uuid, $amount, $currency, $named ): void {
					$this->probe = $this->startDeliveryProbe( $intent, $uuid, $amount, $currency, $named );

					$this->awaitProbeWaiting( $this->probe, $this->claimLock( $intent->uuid ), self::WAITING );
				}
			);

			$settled = $this->service->settleClaim( $uuid, new ClaimStatement( true, self::NOTE, $stated, $amount ), $this->manager() );

			$this->assertNotNull( $this->probe, $case . ': the provider\'s word never raced the settlement.' );
			$this->assertSame( 'recorded', $settled->state->value, $case );
			$this->assertSame( $expected->value, $this->probe->finish()['delivered'] ?? null, $case );
			$this->assertSame( array( $stated ), $this->appliedRefunds( $order->id ), $case . ': never a second refund recorded.' );
			$this->assertCount( 1, $this->refundRows( $order->id ), $case );
			$this->assertSame( 'recorded', (string) $this->claimRow( $uuid )['state'], $case );
		}
	}

	/**
	 * Tests that a person's settlement arriving while the provider's word is recorded waits for the intent's lock, then is refused as ended, recorded, with nothing written.
	 *
	 * Planted violation: in RefundService::settleLocked(), drop lockOpenClaim(): the settlement no
	 * longer waits for the claim's lock, nor reads the claim under it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_waiting_for_the_providers_word_is_refused_as_ended(): void {
		list( $order, $intent, $uuid, $amount ) = $this->claimed();
		$this->probe                            = null;
		$manager                                = $this->manager();

		$this->beforeStatement(
			self::LEDGER_ROW,
			function () use ( $intent, $uuid, $amount, $manager ): void {
				$this->probe = $this->startSettleProbe( $uuid, new ClaimStatement( true, self::NOTE, 're_stated', $amount ), $manager );

				$this->awaitProbeWaiting( $this->probe, $this->claimLock( $intent->uuid ), self::WAITING );
			}
		);

		$delivered = $this->service->recordProviderRefund( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved ), Actor::system( 'webhook', 0 ) );

		$this->assertNotNull( $this->probe, 'The settlement never raced the provider\'s word.' );
		$this->assertSame( ProviderRefundKind::Recorded->value, $delivered->kind->value, 'What the provider\'s word came to: ' . (string) wp_json_encode( $delivered ) );

		$report = $this->probe->finish();

		$this->assertSame( array( PaymentError::RefundClaimEnded->value, array( 'state' => 'recorded' ) ), array( $report['refused'] ?? $report, $report['context'] ?? null ) );
		$this->assertSame( array( 'stub-re-' . $uuid ), $this->appliedRefunds( $order->id ) );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertNull( $this->claimRow( $uuid )['statement'], 'The settlement was not noted.' );
	}

	/**
	 * Places a paid order and leaves a refund of one unit claimed and open, as the gateway could not be reached.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: \SEOCart\Order\Domain\InsertedOrder, 1: \SEOCart\Payment\Domain\IntentRef, 2: string, 3: int} The order, its intent, the claim's uuid and its amount.
	 */
	private function claimed(): array {
		list( $order, $intent ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		return array( $order, $intent, $uuid, (int) $this->claimRow( $uuid )['amount_minor'] );
	}

	/**
	 * Returns the intent's lock under which a claim ends, as the server receives it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return string The statement.
	 */
	private function claimLock( string $intentUuid ): string {
		return $this->rawRefund( MysqlRefundRepository::LOCK_FOR_CLAIM, MysqlRefundRepository::NEVER_RECONCILED, (int) $this->intentRow( $intentUuid )['id'] );
	}

	/**
	 * Reads a claim's row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The claim.
	 * @return array<string, mixed> The row.
	 */
	private function claimRow( string $uuid ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );
	}

	/**
	 * Lists the provider objects of an order's refund rows applied to the ledger, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The objects.
	 */
	private function appliedRefunds( int $orderId ): array {
		return array_values( array_map( static fn( array $row ): string => (string) $row['provider_object_id'], array_filter( $this->refundLedgerRows( $orderId ), static fn( array $row ): bool => '1' === (string) $row['applied'] ) ) );
	}
}
