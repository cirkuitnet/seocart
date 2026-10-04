<?php
/**
 * Tests the declaration of an external service
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Http;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\OutboundHost;

/**
 * A declaration is complete, single-line and https-only, and names one fixed host.
 *
 * @since 0.2.0
 */
final class OutboundHostTest extends TestCase {

	/**
	 * Returns a complete, valid declaration's fields.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $overrides Optional. Fields to replace, by parameter name. Default none.
	 * @return array<string, mixed> The fields, by parameter name.
	 */
	public static function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => 'example-rates',
				'service'    => 'Example Rates',
				'purpose'    => 'Example Rates is a currency exchange-rate service. SEOCart uses it to refresh exchange rates.',
				'endpoint'   => 'https://api.example.com/v1/rates/*',
				'dataSent'   => 'The store currency code. No personal data.',
				'sentWhen'   => 'Once a day, and only after an administrator enables automatic exchange rates.',
				'termsUrl'   => 'https://example.com/terms',
				'privacyUrl' => 'https://example.com/privacy',
			),
			$overrides
		);
	}

	/**
	 * Tests that the host is read from the endpoint, and the timeout defaults to fifteen seconds.
	 *
	 * @since 0.2.0
	 */
	public function test_the_host_is_read_from_the_endpoint(): void {
		$host = new OutboundHost( ...self::fields() );

		$this->assertSame( 'api.example.com', $host->host );
		$this->assertSame( 15, $host->timeoutSeconds );
		$this->assertSame( 'api.example.com', ( new OutboundHost( ...self::fields( array( 'endpoint' => 'https://api.example.com' ) ) ) )->host );
		$this->assertSame( 30, ( new OutboundHost( ...self::fields( array( 'timeoutSeconds' => 30 ) ) ) )->timeoutSeconds );
	}

	/**
	 * Provides declarations with one defect each.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<string, mixed>}> Field overrides, keyed by the defect.
	 */
	public static function invalidDeclarations(): array {
		return array(
			'id not kebab-case'          => array( array( 'id' => 'Example Rates' ) ),
			'empty text'                 => array( array( 'dataSent' => ' ' ) ),
			'multi-line text'            => array( array( 'purpose' => "One.\n== Changelog ==" ) ),
			'insecure terms link'        => array( array( 'termsUrl' => 'http://example.com/terms' ) ),
			'insecure privacy link'      => array( array( 'privacyUrl' => 'http://example.com/privacy' ) ),
			'endpoint over http'         => array( array( 'endpoint' => 'http://api.example.com/v1/rates' ) ),
			'endpoint without a scheme'  => array( array( 'endpoint' => 'api.example.com' ) ),
			'endpoint with a port'       => array( array( 'endpoint' => 'https://api.example.com:8443/v1/rates' ) ),
			'endpoint with credentials'  => array( array( 'endpoint' => 'https://user:secret@api.example.com/v1/rates' ) ),
			'endpoint with a query'      => array( array( 'endpoint' => 'https://api.example.com/v1/rates?key=1' ) ),
			'endpoint with a fragment'   => array( array( 'endpoint' => 'https://api.example.com/v1/rates#x' ) ),
			'endpoint on an upper host'  => array( array( 'endpoint' => 'https://API.example.com/v1/rates' ) ),
			'endpoint on a varying host' => array( array( 'endpoint' => 'https://*.example.com/v1/rates' ) ),
			'endpoint on a single label' => array( array( 'endpoint' => 'https://localhost/v1/rates' ) ),
			'timeout of zero'            => array( array( 'timeoutSeconds' => 0 ) ),
		);
	}

	/**
	 * Tests that a declaration breaking a rule cannot be constructed.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider invalidDeclarations
	 *
	 * @param array<string, mixed> $overrides The defect.
	 */
	public function test_an_invalid_declaration_is_refused( array $overrides ): void {
		$this->expectException( \InvalidArgumentException::class );

		new OutboundHost( ...self::fields( $overrides ) );
	}
}
