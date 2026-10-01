<?php
/**
 * Tests that the order module adds no money up: it copies what the calculation produced
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
 * Only the calculation produces totals, so nothing under src/Order computes an amount.
 *
 * The rule adapters keep (the money-arithmetic sniff that runs on every `Interfaces` directory),
 * applied to the order module by a scan of its tokens (MoneyArithmeticScan): no call of a method
 * that computes an amount, and no arithmetic operator in a statement that reads an amount's raw
 * number. The two lists of names are the sniff's own, read from its source, so the money API is
 * listed in one place. The payment projection is added up by the database, in the one statement
 * that also checks it; that is SQL, and the scan reads PHP.
 *
 * Planted violations, each shown red and removed:
 * - in Orders::insert(), publish `$order->totals->grandTotal->add( $order->totals->feeTotal )->minorUnits()`:
 *   an arithmetic call;
 * - in MysqlOrderRepository::insertLines(), write `$line->quantity * $line->unitPrice->minorUnits()`
 *   as the line subtotal: an operator beside an amount's raw number.
 *
 * @since 0.1.0
 */
final class NoMoneyArithmeticTest extends TestCase {

	/**
	 * Tests that no file under src/Order computes an amount.
	 *
	 * @since 0.1.0
	 */
	public function test_the_order_module_computes_no_amount(): void {
		$found = array();

		$this->assertContains( 'add', MoneyArithmeticScan::arithmeticMethods(), 'The sniff\'s list was not read, so the scan would prove nothing.' );
		$this->assertContains( 'minorUnits', MoneyArithmeticScan::accessors(), 'The sniff\'s list was not read, so the scan would prove nothing.' );

		foreach ( PhpSource::files( 'src/Order' ) as $file => $source ) {
			foreach ( MoneyArithmeticScan::violations( $source ) as $violation ) {
				$found[] = "{$file}:{$violation}";
			}
		}

		$this->assertSame( array(), $found, 'Only the calculation produces totals; the order module copies them.' );
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
	}
}
