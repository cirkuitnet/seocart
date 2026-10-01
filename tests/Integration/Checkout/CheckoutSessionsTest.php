<?php
/**
 * Tests the stored checkout sessions: the upsert of the details and what it does to the quotes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\FrozenQuotes;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * A session's details are upserted in the caller's transaction and always drop its quotes; quotes are kept for the cart version they were taken at, never over a later version's, and dropped again on demand.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class CheckoutSessionsTest extends CheckoutTestCase {

	/**
	 * Tests that the first write creates the session, a second replaces every detail, clearing the ones it leaves out, and a cart has one session.
	 *
	 * @since 0.1.0
	 */
	public function test_the_details_are_created_then_replaced_whole(): void {
		$this->assertNull( $this->sessions->find( 7 ) );

		$this->save( 7, new CheckoutDetails( self::address( 'GB', 'ada@example.com' ), self::address( 'DE' ), 'flat', 'stub' ) );

		$session = $this->sessions->find( 7 );

		$this->assertInstanceOf( CheckoutSession::class, $session );
		$this->assertSame( 7, $session->cartId );
		$this->assertTrue( self::address( 'GB', 'ada@example.com' )->equals( $session->details->billingAddress ?? self::address( 'US' ) ) );
		$this->assertTrue( self::address( 'DE' )->equals( $session->details->shippingAddress ?? self::address( 'US' ) ) );
		$this->assertSame( array( 'flat', 'stub', 0, null ), array( $session->details->shippingMethodKey, $session->details->paymentMethodKey, $session->quotedAtCartVersion, $session->quotes ) );

		$this->save( 7, new CheckoutDetails( null, self::address( 'FR' ), null, null ) );

		$replaced = $this->sessions->find( 7 );

		$this->assertNotNull( $replaced );
		$this->assertNull( $replaced->details->billingAddress, 'A write that leaves the billing address out kept the old one.' );
		$this->assertTrue( self::address( 'FR' )->equals( $replaced->details->shippingAddress ?? self::address( 'US' ) ) );
		$this->assertSame( array( null, null ), array( $replaced->details->shippingMethodKey, $replaced->details->paymentMethodKey ) );
		$this->assertSame( 1, $this->checkoutRows( CheckoutTables::SESSIONS ) );
	}

	/**
	 * Tests the meaning of quoted_at_cart_version: quotes are current at exactly their version, a write of the details drops them, and so does invalidateQuotes(), which keeps the method chosen.
	 *
	 * Planted violation: in MysqlCheckoutSessions::SAVE, leave `quoted_at_cart_version = 0` and
	 * the two quote columns out of ON DUPLICATE KEY UPDATE: a write of a new address then keeps the
	 * quotes taken for the old one, still current at their version.
	 *
	 * @since 0.1.0
	 */
	public function test_quotes_are_current_at_their_version_and_dropped_by_a_write(): void {
		$quotes = new FrozenQuotes(
			array(
				'method_key' => 'flat',
				'rate_minor' => 500,
			),
			self::digest( 'tax quote' )
		);

		$this->save( 7, new CheckoutDetails( null, self::address( 'GB' ), 'flat', null ) );

		$this->assertTrue( $this->sessions->storeQuotes( 7, 3, $quotes ) );

		$quoted = $this->sessions->find( 7 );

		$this->assertNotNull( $quoted );
		$this->assertSame( 3, $quoted->quotedAtCartVersion );
		$this->assertEquals( $quotes, $quoted->quotes );
		$this->assertTrue( $quoted->quotesAreCurrentAt( 3 ) );
		$this->assertFalse( $quoted->quotesAreCurrentAt( 4 ), 'Quotes of version 3 were current for version 4.' );

		$this->save( 7, new CheckoutDetails( null, self::address( 'DE' ), 'flat', null ) );

		$written = $this->sessions->find( 7 );

		$this->assertNotNull( $written );
		$this->assertSame( array( 0, null ), array( $written->quotedAtCartVersion, $written->quotes ), 'A write of the details kept the quotes.' );
		$this->assertFalse( $written->quotesAreCurrentAt( 0 ) );

		$this->assertTrue( $this->sessions->storeQuotes( 7, 5, $quotes ) );
		$this->assertTrue( $this->db->transaction( fn(): bool => $this->sessions->invalidateQuotes( 7 ) ) );

		$invalidated = $this->sessions->find( 7 );

		$this->assertNotNull( $invalidated );
		$this->assertSame( array( 0, null, 'flat' ), array( $invalidated->quotedAtCartVersion, $invalidated->quotes, $invalidated->details->shippingMethodKey ) );
		$this->assertFalse( $this->db->transaction( fn(): bool => $this->sessions->invalidateQuotes( 8 ) ), 'A cart without a session had quotes to drop.' );
	}

	/**
	 * Tests that quotes are kept for their version unless the session holds a later version's, and never for a cart without a session.
	 *
	 * Planted violation: in MysqlCheckoutSessions::STORE_QUOTES, drop
	 * `AND quoted_at_cart_version <= %d` (and its value): a slow calculation of version 2 then
	 * replaces the quotes of version 3.
	 *
	 * @since 0.1.0
	 */
	public function test_quotes_of_an_older_version_never_replace_a_later_ones(): void {
		$later = new FrozenQuotes( null, self::digest( 'tax quote of version 3' ) );
		$older = new FrozenQuotes( array( 'method_key' => 'flat' ), self::digest( 'tax quote of version 2' ) );

		$this->assertFalse( $this->sessions->storeQuotes( 7, 3, $later ), 'Quotes were kept for a cart without a session.' );

		$this->save( 7, new CheckoutDetails( null, null, null, null ) );

		$this->assertTrue( $this->sessions->storeQuotes( 7, 3, $later ) );
		$this->assertFalse( $this->sessions->storeQuotes( 7, 2, $older ) );
		$this->assertTrue( $this->sessions->storeQuotes( 7, 3, $later ), 'Storing the same version again was refused.' );

		$session = $this->sessions->find( 7 );

		$this->assertNotNull( $session );
		$this->assertSame( 3, $session->quotedAtCartVersion );
		$this->assertEquals( $later, $session->quotes );
	}

	/**
	 * Tests that the details and the quotes' invalidation are written only inside a transaction, before any statement.
	 *
	 * Planted violation: remove the requireTransaction() call from MysqlCheckoutSessions::save():
	 * the details then commit without the cart's version moving on.
	 *
	 * @since 0.1.0
	 */
	public function test_the_writes_behind_the_carts_version_run_only_inside_a_transaction(): void {
		$steps = array(
			'save'             => fn() => $this->sessions->save( 7, new CheckoutDetails( null, null, null, null ) ),
			'invalidateQuotes' => fn() => $this->sessions->invalidateQuotes( 7 ),
		);

		foreach ( $steps as $step => $run ) {
			$log = $this->captureQueries(
				function () use ( $run, $step ): void {
					try {
						$run();
						$this->fail( $step . '() ran outside a transaction.' );
					} catch ( \LogicException $refused ) {
						$this->assertStringContainsString( 'transaction', $refused->getMessage() );
					}
				}
			);

			$this->assertQueryCount( 0, $log, $step . '() outside a transaction' );
		}

		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ) );
	}

	/**
	 * Saves a cart's details in a transaction of their own.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $cartId  The cart.
	 * @param CheckoutDetails $details The details.
	 */
	private function save( int $cartId, CheckoutDetails $details ): void {
		$this->db->transaction( fn() => $this->sessions->save( $cartId, $details ) );
	}
}
