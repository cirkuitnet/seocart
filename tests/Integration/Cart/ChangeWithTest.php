<?php
/**
 * Tests the cart's one door for another module's write that rides the cart's version
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\CartTestCase;

/**
 * CartService::changeWith(): the closure runs inside the cart's transaction, after its compare-and-swap and only when it matched, and fails together with it.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class ChangeWithTest extends CartTestCase {

	/**
	 * Tests that a refused compare-and-swap never runs the closure: a stale version, a cart being placed, and no cart.
	 *
	 * Planted violation: in CartService::change(), call `$write( $cart->id )` before the
	 * compare-and-swap: the closure then runs for a write the cart refuses.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_compare_and_swap_never_runs_the_closure(): void {
		$cart  = $this->startCart( array( self::variant() => 1 ) );
		$runs  = 0;
		$write = static function () use ( &$runs ): void {
			++$runs;
		};

		$refusals = array(
			'stale'   => array( 2, CartError::VersionStale ),
			'placing' => array( 1, CartError::NotOpen ),
			'none'    => array( 1, CartError::NotFound ),
		);

		foreach ( $refusals as $case => list( $version, $code ) ) {
			if ( 'placing' === $case ) {
				$this->plantStatus( $cart->id, CartStatus::Placing, 41 );
			}

			if ( 'none' === $case ) {
				$this->tokens->presented = null;
			}

			try {
				$this->service->changeWith( $version, self::guest(), $write );
				$this->fail( 'The cart accepted a write it must refuse: ' . $case . '.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( $code, $refused->errorCode(), $case );
			}
		}

		$this->assertSame( 0, $runs, 'The closure ran for a write the cart refused.' );
		$this->assertSame( 1, $this->committedCart( $this->secondConnection(), $cart->id )['version'] ?? null );
	}

	/**
	 * Tests that the closure runs inside the cart's transaction, after the compare-and-swap moved the version on, with the cart's id, and that the answer is the cart at that version.
	 *
	 * @since 0.1.0
	 */
	public function test_the_closure_runs_inside_the_carts_transaction_after_its_compare_and_swap(): void {
		$shirt = self::variant();
		$cart  = $this->startCart( array( $shirt => 2 ) );
		$seen  = array();

		$this->price( array( $shirt => 1000 ) );

		$answer = $this->service->changeWith(
			1,
			self::guest(),
			function ( int $cartId ) use ( &$seen ): void {
				$seen = array(
					'cart'    => $cartId,
					'depth'   => $this->db->depth(),
					'version' => (int) $this->db->fetchValue( 'SELECT version FROM %i WHERE id = %d', $this->table( CartTables::CARTS ), $cartId ),
				);
			}
		);

		$this->assertSame(
			array(
				'cart'    => $cart->id,
				'depth'   => 1,
				'version' => 2,
			),
			$seen,
			'The closure did not run inside the transaction, after the compare-and-swap.'
		);
		$this->assertSame( 2, $answer['version'] );
		$this->assertSame( 2400, $answer['totals']['summary']['grand_minor'], 'The answer is not the cart\'s, priced: two at 10.00 with 20 % tax.' );
	}

	/**
	 * Tests that a closure that fails rolls the compare-and-swap back with it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_closure_that_fails_leaves_the_version_where_it_was(): void {
		$cart    = $this->startCart( array( self::variant() => 1 ) );
		$failure = new \RuntimeException( 'The other module refused its own write.' );

		try {
			$this->service->changeWith(
				1,
				self::guest(),
				static function () use ( $failure ): void {
					throw $failure;
				}
			);
			$this->fail( 'The closure\'s failure was swallowed.' );
		} catch ( \RuntimeException $thrown ) {
			$this->assertSame( $failure, $thrown );
		}

		$this->assertSame( 1, $this->committedCart( $this->secondConnection(), $cart->id )['version'] ?? null, 'The version moved on for a write that failed.' );
	}
}
