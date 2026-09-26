<?php
/**
 * Tests the client identity: stable per address and customer, a cart's apart, and a forwarded header only from trusted proxies
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\RateLimiter;

use PHPUnit\Framework\TestCase;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\ClientIdentity;
use SEOCart\Platform\RateLimiter\ObjectCacheRateLimiter;
use SEOCart\Platform\RateLimiter\TrustedClientIp;

/**
 * Who a client is, for the rate limiter, without WordPress.
 *
 * @since 0.1.0
 */
final class ClientIdentityTest extends TestCase {

	/**
	 * A cart token, in its written form.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TOKEN = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

	/**
	 * Tests that a client's identity is the same for the same address and customer, and differs when either changes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_client_is_stable_per_address_and_customer(): void {
		$base = ClientIdentity::ofClient( '192.0.2.1', 7, 'secret' )->key();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}\z/', $base );
		$this->assertSame( $base, ClientIdentity::ofClient( '192.0.2.1', 7, 'secret' )->key() );
		$this->assertNotSame( $base, ClientIdentity::ofClient( '192.0.2.2', 7, 'secret' )->key(), 'Another address.' );
		$this->assertNotSame( $base, ClientIdentity::ofClient( '192.0.2.1', 0, 'secret' )->key(), 'A guest.' );
		$this->assertNotSame( $base, ClientIdentity::ofClient( '192.0.2.1', 7, 'other secret' )->key(), 'Another key.' );
		$this->assertStringNotContainsString( '192.0.2.1', $base );
	}

	/**
	 * Tests that a cart is counted apart from every client, and that no two sets of parts are hashed alike.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_is_apart_and_the_parts_are_hashed_unambiguously(): void {
		$cart = ClientIdentity::ofCart( self::TOKEN, 'secret' )->key();

		$this->assertSame( $cart, ClientIdentity::ofCart( self::TOKEN, 'secret' )->key() );
		$this->assertNotSame( $cart, ClientIdentity::ofCart( strrev( self::TOKEN ), 'secret' )->key() );
		$this->assertNotSame( $cart, ClientIdentity::ofClient( self::TOKEN, 0, 'secret' )->key(), 'A cart and a client with the same text are two identities.' );
		$this->assertStringNotContainsString( self::TOKEN, $cart );
		$this->assertNotSame( ClientIdentity::ofClient( '192.0.2.1', 10, 'secret' )->key(), ClientIdentity::ofClient( '192.0.2.11', 0, 'secret' )->key() );
	}

	/**
	 * Tests that the service names a found cart apart from the client, under the same secret.
	 *
	 * @since 0.1.0
	 */
	public function test_the_service_names_a_found_cart_apart_from_the_client(): void {
		$identities = new ClientIdentities( new TrustedClientIp( array( 'REMOTE_ADDR' => '192.0.2.1' ) ), static fn(): string => 'secret' );
		$token      = CartToken::generate();

		$this->assertSame( ClientIdentity::ofCart( $token->value(), 'secret' )->key(), $identities->ofCart( $token )->key() );
		$this->assertNotSame( $identities->of( 0 )->key(), $identities->ofCart( $token )->key() );
	}

	/**
	 * Tests that the address is REMOTE_ADDR and a forwarded header changes nothing without trusted proxies.
	 *
	 * @since 0.1.0
	 */
	public function test_a_forwarded_header_changes_nothing_without_trusted_proxies(): void {
		$server = array(
			'REMOTE_ADDR'           => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR'  => '198.51.100.4',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.5',
		);

		$this->assertSame( '203.0.113.9', ( new TrustedClientIp( $server ) )->address() );
		$this->assertSame( '203.0.113.9', ( new TrustedClientIp( $server, '', 'CF-Connecting-IP' ) )->address() );
		$this->assertSame( self::identity( $server, '' ), self::identity( array( 'REMOTE_ADDR' => '203.0.113.9' ), '' ), 'The header must not change the identity.' );
	}

	/**
	 * Tests that the header is read only when REMOTE_ADDR is inside a trusted range.
	 *
	 * Planted violation: in TrustedClientIp::address(), drop `! $this->isTrusted( $remote ) ||`
	 * from the first condition, so the header is read whoever sent it. The request from an
	 * untrusted address then takes the header's address.
	 *
	 * @since 0.1.0
	 */
	public function test_the_header_is_read_only_from_a_trusted_proxy(): void {
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.4',
		);

		$this->assertSame( '198.51.100.4', ( new TrustedClientIp( $server, '203.0.113.0/24' ) )->address(), 'REMOTE_ADDR is a trusted proxy.' );
		$this->assertSame( '203.0.113.9', ( new TrustedClientIp( $server, '203.0.114.0/24' ) )->address(), 'REMOTE_ADDR is not a trusted proxy.' );
		$this->assertSame( '198.51.100.4', ( new TrustedClientIp( $server, '203.0.113.9' ) )->address(), 'A single address is a range of one.' );
		$this->assertNotSame( self::identity( $server, '203.0.113.0/24' ), self::identity( $server, '' ) );
	}

	/**
	 * Tests that X-Forwarded-For is walked from the right past the trusted proxies, and stops at anything that is not an address.
	 *
	 * @since 0.1.0
	 */
	public function test_the_forwarded_chain_is_walked_from_the_right(): void {
		$proxies = '10.0.0.0/8, 203.0.113.0/24';
		$chain   = static fn( string $header ): string => ( new TrustedClientIp(
			array(
				'REMOTE_ADDR'          => '10.1.2.3',
				'HTTP_X_FORWARDED_FOR' => $header,
			),
			$proxies
		) )->address();

		$this->assertSame( '198.51.100.4', $chain( '192.0.2.66, 198.51.100.4, 203.0.113.5' ), 'The client is the first untrusted address from the right; what it claimed to the left is ignored.' );
		$this->assertSame( '198.51.100.4', $chain( '198.51.100.4' ) );
		$this->assertSame( '10.1.2.3', $chain( 'garbage, 203.0.113.5' ), 'Anything that is not an address ends the walk at REMOTE_ADDR.' );
		$this->assertSame( '10.1.2.3', $chain( '203.0.113.5, 10.9.9.9' ), 'A chain of trusted proxies only names no client.' );
		$this->assertSame( '10.1.2.3', $chain( '' ) );
	}

	/**
	 * Tests the named header of a CDN, and that an unparsable range is ignored.
	 *
	 * @since 0.1.0
	 */
	public function test_a_named_header_and_an_unparsable_range(): void {
		$server = array(
			'REMOTE_ADDR'           => '173.245.48.10',
			'HTTP_CF_CONNECTING_IP' => '2001:db8:1:2:3:4:5:6',
			'HTTP_X_FORWARDED_FOR'  => '198.51.100.4',
		);

		$this->assertSame( '2001:db8:1:2::', ( new TrustedClientIp( $server, '173.245.48.0/20', 'CF-Connecting-IP' ) )->address() );
		$this->assertSame( '173.245.48.10', ( new TrustedClientIp( $server, '173.245.48.0/33, not-a-range, 173.245.48.0/x', 'CF-Connecting-IP' ) )->address() );
	}

	/**
	 * Tests that an IPv6 address counts by its /64, and an IPv4 address in IPv6 form as the IPv4 address.
	 *
	 * @since 0.1.0
	 */
	public function test_addresses_are_normalized(): void {
		$address = static fn( string $remote ): string => ( new TrustedClientIp( array( 'REMOTE_ADDR' => $remote ) ) )->address();

		$this->assertSame( '2001:db8:1:2::', $address( '2001:db8:1:2:ffff:1:2:3' ) );
		$this->assertSame( $address( '2001:db8:1:2::1' ), $address( '2001:db8:1:2:aaaa::9' ), 'One /64 is one client.' );
		$this->assertNotSame( $address( '2001:db8:1:2::1' ), $address( '2001:db8:1:3::1' ) );
		$this->assertSame( '192.0.2.1', $address( '::ffff:192.0.2.1' ) );
		$this->assertSame( '', $address( 'not an address' ), 'On the command line there is no REMOTE_ADDR.' );
		$this->assertSame( '', ( new TrustedClientIp( array() ) )->address() );
	}

	/**
	 * Tests that the service derives its key once, on the first identity, and names the current client.
	 *
	 * @since 0.1.0
	 */
	public function test_the_key_is_derived_once_on_first_use(): void {
		$derived    = 0;
		$identities = new ClientIdentities(
			new TrustedClientIp( array( 'REMOTE_ADDR' => '192.0.2.1' ) ),
			static function () use ( &$derived ): string {
				++$derived;

				return 'secret';
			}
		);

		$this->assertSame( 0, $derived, 'Building the service must derive nothing.' );
		$this->assertSame( ClientIdentity::ofClient( '192.0.2.1', 7, 'secret' )->key(), $identities->of( 7 )->key() );
		$identities->of( 0 );
		$this->assertSame( 1, $derived );
	}

	/**
	 * Tests the object cache's selection: it counts only when it is persistent and increments.
	 *
	 * @since 0.1.0
	 */
	public function test_the_object_cache_counts_only_when_persistent_and_incrementing(): void {
		$this->assertTrue( ObjectCacheRateLimiter::isUsable( true, true ) );
		$this->assertFalse( ObjectCacheRateLimiter::isUsable( true, false ) );
		$this->assertFalse( ObjectCacheRateLimiter::isUsable( false, true ) );
		$this->assertFalse( ObjectCacheRateLimiter::isUsable( false, false ) );
	}

	/**
	 * Returns the identity key of a guest without a token, for some server variables and trusted proxies.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $server  The server variables.
	 * @param string                $proxies The trusted proxies.
	 * @return string The key.
	 */
	private static function identity( array $server, string $proxies ): string {
		return ( new ClientIdentities( new TrustedClientIp( $server, $proxies ), static fn(): string => 'secret' ) )->of( 0 )->key();
	}
}
