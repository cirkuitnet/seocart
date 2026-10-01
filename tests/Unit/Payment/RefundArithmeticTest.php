<?php
/**
 * Tests that a refund adds no money up but in RefundAllocation, which adds stored figures to state a refund document
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\MoneyArithmeticScan;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Only the calculation produces totals; a refund shares out what an order stored, and only RefundAllocation adds those shares up.
 *
 * The scan of the order module (MoneyArithmeticScan), applied to the refund's code, named by
 * directory and by file: the refund's domain under src/Payment/Domain/Refund, the refund service,
 * the refund repository and the refund tables. A new file of the refund's domain is scanned as it
 * lands; a refund file elsewhere is added to REFUND_FILES. One class is allowed, RefundAllocation, and only it: it allocates each refund's shares from the
 * stored figures less what earlier refunds returned, and adds them up to state the document. The
 * refund's caps are added up by the database, in the statements that also check them; that is
 * SQL, and the scan reads PHP.
 *
 * Planted violation, shown red and removed: in RefundService::checkCaps(), check the shipping
 * against `$shipping->stored->net()->subtract( $shipping->share->amount->net() )` instead of the
 * shipping returned: an arithmetic call outside RefundAllocation.
 *
 * @since 0.1.0
 */
final class RefundArithmeticTest extends TestCase {

	/**
	 * The directory of the refund's domain: every file under it is the refund's code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REFUND_DIRECTORY = 'src/Payment/Domain/Refund/';

	/**
	 * The refund's code outside that directory, file by file.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const REFUND_FILES = array(
		'src/Payment/Application/RefundService.php',
		'src/Payment/Infrastructure/MysqlRefundRepository.php',
		'src/Payment/Infrastructure/RefundTables.php',
	);

	/**
	 * The one class of the refund allowed to add money up, with why.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		'src/Payment/Domain/Refund/RefundAllocation.php' => 'It allocates each share from the stored figures less what earlier refunds returned, and adds stored figures and their shares up to state the refund document.',
	);

	/**
	 * Tests that no file of the refund computes an amount, RefundAllocation aside.
	 *
	 * @since 0.1.0
	 */
	public function test_the_refund_computes_no_amount_but_in_its_allocation(): void {
		$found   = array();
		$scanned = array();

		foreach ( PhpSource::files( 'src/Payment' ) as $file => $source ) {
			if ( ! self::isRefundCode( $file ) || isset( self::ALLOWED[ $file ] ) ) {
				continue;
			}

			$scanned[] = $file;

			foreach ( MoneyArithmeticScan::violations( $source ) as $violation ) {
				$found[] = "{$file}:{$violation}";
			}
		}

		foreach ( self::REFUND_FILES as $file ) {
			$this->assertContains( $file, $scanned, 'The scan must find each file it names, or it proves nothing.' );
		}

		$this->assertContains( self::REFUND_DIRECTORY . 'RefundPlan.php', $scanned, 'The scan must find the refund\'s domain.' );
		$this->assertSame( array(), $found, 'A refund shares out stored figures; only RefundAllocation adds them up.' );
	}

	/**
	 * Tests that the allowed class does add money up, so allowing it is needed and the scan sees it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_allocation_is_where_the_adding_up_is(): void {
		foreach ( array_keys( self::ALLOWED ) as $file ) {
			$this->assertNotSame( array(), MoneyArithmeticScan::violations( PhpSource::files( 'src/Payment' )[ $file ] ?? '' ), "{$file} adds money up, which is why it is allowed." );
		}
	}

	/**
	 * Tells whether a file under src/Payment is the refund's code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The file, from the repository's root.
	 * @return bool True for a file under the refund's domain, and for each file REFUND_FILES names.
	 */
	private static function isRefundCode( string $file ): bool {
		return str_starts_with( $file, self::REFUND_DIRECTORY ) || in_array( $file, self::REFUND_FILES, true );
	}
}
