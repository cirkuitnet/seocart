<?php
/**
 * Tests that the order, checkout and payment modules add no money up: they copy what the calculation produced
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\MoneyArithmeticScan;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Only the calculation produces totals, so nothing under src/Order, src/Checkout or src/Payment computes an amount, but the few files ALLOWED names.
 *
 * The rule adapters keep (the money-arithmetic sniff that runs on every `Interfaces` directory),
 * applied to the order module, the checkout that builds an order and the payment module that
 * settles it, by a scan of their tokens (MoneyArithmeticScan): no call of a method that computes
 * an amount, and no arithmetic operator in a statement that reads an amount's raw number. The two
 * lists of names are the sniff's own, read from its source, so the money API is listed in one
 * place. A new file of these modules is scanned as it lands.
 *
 * ALLOWED names the files that may compute: the payment domain's classes whose job is to decide
 * something from stored amounts, and the stand-in gateway, which makes up wrong amounts on purpose.
 * Each is shown to compute, so the list cannot outlive its reasons. The database adds up what it
 * stores, in the statements that also check it: the order's payment projection, an intent's
 * captured and refunded amounts, and a refund's caps. That is SQL, and the scan reads PHP.
 *
 * Planted violations, each shown red and removed:
 * - in Orders::insert(), publish `$order->totals->grandTotal->add( $order->totals->feeTotal )->minorUnits()`:
 *   an arithmetic call;
 * - in MysqlOrderRepository::insertLines(), write `$line->quantity * $line->unitPrice->minorUnits()`
 *   as the line subtotal: an operator beside an amount's raw number;
 * - in PlaceOrder::priced(), compare `$grand->minorUnits() - (int) $input['grand_total_minor']`
 *   with 0: an operator beside an amount's raw number in the checkout;
 * - in PaymentService::stateAfter(), decide a refund's state from
 *   `$intent->refunded->add( $result->amount )`: an arithmetic call in the payment's application layer;
 * - in RefundPlan::components(), keep a component only when
 *   `$portion->share->amount->gross()->minorUnits() - $portion->share->amount->tax()->minorUnits()`
 *   is not 0: an operator beside an amount's raw number in the refund's domain.
 *
 * @since 0.1.0
 */
final class NoMoneyArithmeticTest extends TestCase {

	/**
	 * The files allowed to compute an amount, each with why.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		'src/Payment/Domain/AmountCheck.php'             => 'It adds a tender to what the order has tendered, to decide whether an approval stays within the grand total.',
		'src/Payment/Domain/CaptureShare.php'            => 'It splits the intent\'s frozen base amount between what a partial capture takes and what it leaves, by largest remainder: a stored figure split, not a total computed.',
		'src/Payment/Domain/Projection.php'              => 'It adds a payment to the locked order\'s amounts, and a refund to the intent\'s, to decide which state the database\'s update will record.',
		'src/Payment/Domain/Refund/RefundAllocation.php' => 'It allocates each share from the stored figures less what earlier refunds returned, and adds stored figures and their shares up to state the refund document.',
		'src/Payment/Infrastructure/Gateway/StubGateway.php' => 'It is a stand-in gateway that answers with an amount one minor unit off on purpose, so that the tests can show such an approval parked for a person.',
	);

	/**
	 * Provides the modules that copy what the calculation produced: the order, the checkout that builds it, and the payment that settles it.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The source directory of each.
	 */
	public static function copyingModules(): array {
		return array(
			'order'    => array( 'src/Order' ),
			'checkout' => array( 'src/Checkout' ),
			'payment'  => array( 'src/Payment' ),
		);
	}

	/**
	 * Tests that no file of a module that copies the calculation's totals computes an amount, the allowed files aside.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider copyingModules
	 *
	 * @param string $directory The module's source directory.
	 */
	public function test_the_module_computes_no_amount( string $directory ): void {
		$found = array();

		$this->assertContains( 'add', MoneyArithmeticScan::arithmeticMethods(), 'The sniff\'s list was not read, so the scan would prove nothing.' );
		$this->assertContains( 'minorUnits', MoneyArithmeticScan::accessors(), 'The sniff\'s list was not read, so the scan would prove nothing.' );
		$this->assertNotSame( array(), PhpSource::files( $directory ), 'The scan read no file of ' . $directory . ', so it would prove nothing.' );

		foreach ( PhpSource::files( $directory ) as $file => $source ) {
			if ( isset( self::ALLOWED[ $file ] ) ) {
				continue;
			}

			foreach ( MoneyArithmeticScan::violations( $source ) as $violation ) {
				$found[] = "{$file}:{$violation}";
			}
		}

		$this->assertSame( array(), $found, 'Only the calculation produces totals; ' . $directory . ' copies them.' );
	}

	/**
	 * Tests that each allowed file does compute an amount, so allowing it is needed and the scan sees it.
	 *
	 * @since 0.1.0
	 */
	public function test_each_allowed_file_is_where_an_amount_is_computed(): void {
		$sources = array();

		foreach ( self::copyingModules() as list( $directory ) ) {
			$sources += PhpSource::files( $directory );
		}

		foreach ( array_keys( self::ALLOWED ) as $file ) {
			$this->assertArrayHasKey( $file, $sources, "{$file} is allowed, but the scan does not read it." );
			$this->assertNotSame( array(), MoneyArithmeticScan::violations( $sources[ $file ] ), "{$file} computes no amount any more, so it need not be allowed." );
		}
	}

	/**
	 * Tests that the scan finds each shape it looks for, so a clean result means something.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scan_finds_what_it_is_shown(): void {
		$this->assertCount( 1, MoneyArithmeticScan::violations( '<?php $total = $a->grandTotal->add( $b );' ) );
		$this->assertCount( 1, MoneyArithmeticScan::violations( '<?php $minor = $line->quantity * $line->unitPrice->minorUnits();' ) );
		$this->assertCount( 1, MoneyArithmeticScan::violations( '<?php $sum = array_sum( $amounts );' ) );
		$this->assertSame( array(), MoneyArithmeticScan::violations( '<?php $this->add( $x ); $rows[] = $amount->minorUnits(); $next = $index + 1;' ) );
		$this->assertSame( array(), MoneyArithmeticScan::violations( '<?php $this->stock->allocate( $group, $orderId, $lines );' ) );
		$this->assertCount( 1, MoneyArithmeticScan::violations( '<?php $parts = $this->grand->allocate( array( 1, 1 ) );' ) );
	}
}
