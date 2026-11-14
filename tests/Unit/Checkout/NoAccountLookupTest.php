<?php
/**
 * Tests that the checkout path never asks whether an address or a name has an account
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Guards the rule that whether an address has an account changes neither an answer nor the work a request does.
 *
 * A checkout that looks up the address it is given tells anyone who types addresses which have
 * accounts, by its answers or by its work, and an order attached to an account by an address
 * belongs to whoever registered the address first. The cart, the checkout, the order module and
 * the customer module therefore never name a function of WordPress that answers whether an
 * address or a user name has an account: an order belongs to a customer only because the
 * authenticated user placed it. The statement test of the email paths is this test's twin: a
 * lookup served from a cache would send no statement and still decide an answer.
 *
 * Planted violation: in PlaceOrder::placeInside(), look the order's email up with
 * `get_user_by( 'email', … )`. The test names the file and the line.
 *
 * @since 0.2.0
 */
final class NoAccountLookupTest extends TestCase {

	/**
	 * The functions that answer whether an address or a name has an account.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN = array( 'get_user_by', 'email_exists', 'username_exists' );

	/**
	 * The directories scanned: the cart, the checkout, the order module and the customer module.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const DIRECTORIES = array( 'src/Cart', 'src/Checkout', 'src/Order', 'src/Customer' );

	/**
	 * Tests that no file of the checkout path names a function that looks an account up.
	 *
	 * @since 0.2.0
	 */
	public function test_no_checkout_source_looks_an_account_up(): void {
		$files = array();

		foreach ( self::DIRECTORIES as $directory ) {
			$files += PhpSource::files( $directory );
		}

		$this->assertArrayHasKey( 'src/Checkout/Application/PlaceOrder.php', $files, 'The scan did not find the placement, so an empty result would prove nothing.' );
		$this->assertArrayHasKey( 'src/Checkout/Application/UpdateCheckoutSession.php', $files, 'The scan did not find the session write, so an empty result would prove nothing.' );
		$this->assertArrayHasKey( 'src/Customer/Application/Customers.php', $files, 'The scan did not find the customer records\' service, so an empty result would prove nothing.' );

		$found = array();

		foreach ( $files as $file => $source ) {
			foreach ( token_get_all( $source ) as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
					continue;
				}

				$name = strtolower( trim( $token[1], "\\'\"" ) );

				if ( in_array( $name, self::FORBIDDEN, true ) ) {
					$found[] = $file . ':' . $token[2] . ' ' . $name;
				}
			}
		}

		$this->assertSame( array(), $found, 'The checkout path looks an account up by an address or a name. An order belongs to a customer because the authenticated user placed it, never because an address matches.' );
	}
}
