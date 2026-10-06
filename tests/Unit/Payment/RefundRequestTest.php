<?php
/**
 * Tests what a refund may be asked for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\RequestKey;

/**
 * A refund asks for units of lines, the shipping, or both, never an amount, with a reason a refund document can hold; its canonical form is the same whatever order its lines are named in; and the key it is sent with is kept as two SHA-256 hashes.
 *
 * @since 0.1.0
 */
final class RefundRequestTest extends TestCase {

	/**
	 * The order every request names.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ORDER = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b';

	/**
	 * Tests that a request of lines and the shipping keeps them, in the order given.
	 *
	 * @since 0.1.0
	 */
	public function test_a_request_keeps_its_lines_in_order(): void {
		$request = new RefundRequest( self::ORDER, array( new RefundLineRequest( 'line-b', 2, true ), new RefundLineRequest( 'line-a', 1 ) ), true, 'customer_return' );

		$this->assertSame( array( 'line-b', 'line-a' ), $request->lineUuids() );
		$this->assertSame( array( true, false ), array( $request->lines[0]->restock, $request->lines[1]->restock ) );
		$this->assertSame( array(), ( new RefundRequest( self::ORDER, array(), true, 'shipping_late' ) )->lineUuids(), 'The shipping alone may be asked for.' );
	}

	/**
	 * Tests that the canonical form names the lines in the order of their identifiers, whatever order they were asked in, with the shipping, the reason and the note; and that an empty note is no note.
	 *
	 * @since 0.2.0
	 */
	public function test_the_canonical_form_sorts_the_lines_and_keeps_the_rest(): void {
		$asked     = new RefundRequest( self::ORDER, array( new RefundLineRequest( 'line-b', 2, true ), new RefundLineRequest( 'line-a', 1 ) ), true, 'damaged', 'Crushed.' );
		$reordered = new RefundRequest( self::ORDER, array( new RefundLineRequest( 'line-a', 1 ), new RefundLineRequest( 'line-b', 2, true ) ), true, 'damaged', 'Crushed.' );

		$this->assertSame(
			array(
				'order_uuid'  => self::ORDER,
				'lines'       => array(
					array(
						'line_uuid' => 'line-a',
						'quantity'  => 1,
						'restock'   => false,
					),
					array(
						'line_uuid' => 'line-b',
						'quantity'  => 2,
						'restock'   => true,
					),
				),
				'shipping'    => true,
				'reason_code' => 'damaged',
				'note'        => 'Crushed.',
			),
			$asked->canonical()
		);
		$this->assertSame( $asked->canonical(), $reordered->canonical(), 'The order the lines are named in does not change the request.' );
		$this->assertNull( ( new RefundRequest( self::ORDER, array(), true, 'damaged', '' ) )->note, 'An empty note is no note.' );
	}

	/**
	 * Tests that a key is kept as two SHA-256 hashes in lower-case hexadecimal, and that anything else is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_is_kept_as_two_sha256_hashes(): void {
		$hash = hash( 'sha256', 'key' );
		$key  = new RequestKey( $hash, hash( 'sha256', 'request' ) );

		$this->assertSame( array( $hash, hash( 'sha256', 'request' ) ), array( $key->keyHash, $key->fingerprint ) );

		foreach ( array( 'not a hash', strtoupper( $hash ), $hash . '0' ) as $wrong ) {
			foreach ( array( array( $wrong, $hash ), array( $hash, $wrong ) ) as $pair ) {
				try {
					new RequestKey( ...$pair );
					$this->fail( 'A key was kept as ' . $wrong . '.' );
				} catch ( \InvalidArgumentException $refused ) {
					$this->assertStringContainsString( 'SHA-256', $refused->getMessage() );
				}
			}
		}
	}

	/**
	 * Tests that a request that asks for nothing, names a line twice, gives a reason a document cannot hold, or asks for no unit, is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refused
	 *
	 * @param callable $request Builds the request.
	 */
	public function test_a_request_it_cannot_be_is_refused( callable $request ): void {
		$this->expectException( \InvalidArgumentException::class );

		$request();
	}

	/**
	 * Returns the requests that cannot be.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: callable}> The builders.
	 */
	public static function refused(): array {
		return array(
			'nothing asked for'    => array( static fn() => new RefundRequest( self::ORDER, array(), false, 'customer_return' ) ),
			'a line named twice'   => array( static fn() => new RefundRequest( self::ORDER, array( new RefundLineRequest( 'line-a', 1 ), new RefundLineRequest( 'line-a', 1 ) ), false, 'customer_return' ) ),
			'a reason with spaces' => array( static fn() => new RefundRequest( self::ORDER, array(), true, 'Customer return' ) ),
			'a reason too long'    => array( static fn() => new RefundRequest( self::ORDER, array(), true, str_repeat( 'a', 65 ) ) ),
			'no unit of a line'    => array( static fn() => new RefundLineRequest( 'line-a', 0 ) ),
		);
	}
}
