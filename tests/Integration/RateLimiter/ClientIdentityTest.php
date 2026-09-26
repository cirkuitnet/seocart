<?php
/**
 * Tests the client identity a WordPress process computes: stable, and a forwarded header honoured only through SEOCART_TRUSTED_PROXIES
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\RateLimiter;

use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\ClientIdentityCases;
use WP_UnitTestCase;

/**
 * The identity the kernel's ClientIdentities gives, keyed by the site's salt, in this process and
 * in probe processes: one without SEOCART_TRUSTED_PROXIES, as this one runs, and one that defines
 * it, as wp-config.php would.
 *
 * Planted violation: in TrustedClientIp::fromRequest(), read the proxies from
 * `SEOCART_TRUSTED_PROXIES_TYPO`. The probe with the constant then ignores the header.
 *
 * @since 0.1.0
 */
final class ClientIdentityTest extends WP_UnitTestCase {

	/**
	 * The server variables of the request, restored after each test.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private array $server;

	/**
	 * Saves the server variables.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->server = $_SERVER;
	}

	/**
	 * Restores the server variables.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$_SERVER = $this->server;

		parent::tear_down();
	}

	/**
	 * Tests that without the constant a forwarded header changes nothing, and the identity is stable.
	 *
	 * The identities of two processes are compared by what they equal, never with each other: the
	 * test site's salt can differ between processes, where a real site's does not.
	 *
	 * @since 0.1.0
	 */
	public function test_without_trusted_proxies_the_header_changes_nothing(): void {
		$here  = $this->identities();
		$probe = ChildProcessProbe::run( self::probe(), array( '-' ) )['identities'];

		foreach ( array(
			'this process'    => $here,
			'a probe process' => $probe,
		) as $where => $identities ) {
			$this->assertSame( $identities['remote only'], $identities['forwarded'], "In {$where}, a forwarded header changed the identity without trusted proxies." );
			$this->assertNotSame( $identities['remote only'], $identities['client direct'], "In {$where}, two addresses gave one identity." );
		}

		$this->assertSame( $here, $this->identities(), 'The same requests give the same identities again.' );
	}

	/**
	 * Tests that with the constant naming the proxy, the header names the client.
	 *
	 * @since 0.1.0
	 */
	public function test_with_the_proxy_trusted_the_header_names_the_client(): void {
		$here  = $this->identities();
		$probe = ChildProcessProbe::run( self::probe(), array( '203.0.113.0/24' ) )['identities'];

		$this->assertSame( $probe['client direct'], $probe['forwarded'], 'Through the trusted proxy, the client is the forwarded address.' );
		$this->assertNotSame( $probe['remote only'], $probe['forwarded'], 'Without a header, the proxy is the client.' );
		$this->assertSame( $here['remote only'], $here['forwarded'], 'This process, without the constant, still ignores the header.' );
	}

	/**
	 * Returns the identities this process computes for each case.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The identity keys, by case.
	 */
	private function identities(): array {
		$identities = array();

		foreach ( ClientIdentityCases::CASES as $case => $server ) {
			unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );

			$_SERVER = $server + $_SERVER;

			$identities[ $case ] = ClientIdentities::forRequest()->of( 0 )->key();
		}

		return $identities;
	}

	/**
	 * Returns the probe script's path.
	 *
	 * @since 0.1.0
	 *
	 * @return string The path.
	 */
	private static function probe(): string {
		return dirname( __DIR__, 2 ) . '/Support/client-identity-probe.php';
	}
}
