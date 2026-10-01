<?php
/**
 * Tests the kernel's wiring of the payment module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * The production container resolves each payment port to its production class without a query, and doctor runs the payment check.
 *
 * The service's collaborators from other modules, the event publisher and the transaction
 * manager, are resolved before the count: the job queue behind the publisher reads its lock mode
 * when it is built, which is that module's cost, not the payment module's. The module adds no
 * hook; the idle budget test holds it to that.
 *
 * Planted violations, each shown red and removed:
 * - in Modules::paymentRegister(), leave out the PaymentGateway binding: the service cannot be
 *   built;
 * - in Modules::paymentRegister(), leave out the RefundRepository binding: the refund service
 *   cannot be built;
 * - in Modules::loggingRegister(), leave PaymentLedgerCheck out of Doctor's binding: doctor does
 *   not run the payment check.
 *
 * @since 0.1.0
 */
final class PaymentWiringTest extends DatabaseTestCase {

	/**
	 * Tests that each port resolves to its production class without a query.
	 *
	 * @since 0.1.0
	 */
	public function test_each_port_resolves_to_its_production_class_without_a_query(): void {
		$container = KernelContainer::build( $this->db, $this->reporter() );
		$resolved  = array();

		$container->get( EventPublisher::class );

		$log = $this->captureQueries(
			static function () use ( $container, &$resolved ): void {
				foreach ( array( PaymentRepository::class, PaymentGateway::class, PaymentService::class, PaymentLedgerCheck::class, RefundRepository::class, RefundService::class ) as $port ) {
					$resolved[ $port ] = get_class( $container->get( $port ) );
				}
			}
		);

		$this->assertSame(
			array(
				PaymentRepository::class  => MysqlPaymentRepository::class,
				PaymentGateway::class     => StubGateway::class,
				PaymentService::class     => PaymentService::class,
				PaymentLedgerCheck::class => PaymentLedgerCheck::class,
				RefundRepository::class   => MysqlRefundRepository::class,
				RefundService::class      => RefundService::class,
			),
			$resolved
		);
		$this->assertQueryCount( 0, $log, 'Building the payment module\'s services' );
	}

	/**
	 * Tests that doctor runs the payment check, once.
	 *
	 * @since 0.1.0
	 */
	public function test_doctor_runs_the_payment_check(): void {
		$names = array_map( static fn( Check $check ): string => $check->name(), KernelContainer::build( $this->db, $this->reporter() )->get( Doctor::class )->checks() );

		$this->assertSame( array( PaymentLedgerCheck::NAME ), array_values( array_intersect( $names, array( PaymentLedgerCheck::NAME ) ) ) );
	}
}
