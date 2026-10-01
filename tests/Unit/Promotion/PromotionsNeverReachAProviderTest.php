<?php
/**
 * Tests that the promotion module never reaches a rate or tax provider, a network or a conversion
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Promotion;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * No file of the promotion module names a quoter, an exchange rate, HTTP or a conversion context, and its domain names nothing outside the domains.
 *
 * Promotions are evaluated to intents from facts the calculation already has; they never call a
 * shipping or tax provider, never read a rate, and never change a total. That holds by
 * structure: the module does not name what it would need to.
 *
 * Planted violation, shown red and removed: import `SEOCart\Pricing\Application\TaxQuoter` into
 * Evaluator. The scan names it, with the file and the line.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class PromotionsNeverReachAProviderTest extends TestCase {

	/**
	 * What a promotion must never name: a provider, a rate, the network or a conversion.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FORBIDDEN = '/Quoter|ExchangeRate|Http|ConversionContext/';

	/**
	 * Tests that no file under src/Promotion names a quoter, an exchange rate, HTTP or a conversion context.
	 *
	 * @since 0.1.0
	 */
	public function test_no_promotion_file_names_a_provider_a_rate_or_the_network(): void {
		$sources = PhpSource::files( 'src/Promotion' );
		$named   = array();

		$this->assertNotSame( array(), $sources, 'The scan found no promotion source, so it proves nothing.' );

		foreach ( $sources as $file => $source ) {
			foreach ( PhpSource::classNames( $source ) as $name ) {
				if ( 1 === preg_match( self::FORBIDDEN, $name['name'] ) ) {
					$named[] = $file . ':' . $name['line'] . ' ' . $name['name'];
				}
			}
		}

		$this->assertSame( array(), $named, 'Promotions are evaluated from facts the calculation already has: they never reach a provider, a rate or the network.' );
	}

	/**
	 * Tests that the promotion domain names nothing but the domains: no infrastructure, no platform, no application service.
	 *
	 * @since 0.1.0
	 */
	public function test_the_promotion_domain_names_only_the_domains(): void {
		$outside = array();

		foreach ( PhpSource::files( 'src/Promotion/Domain' ) as $file => $source ) {
			foreach ( PhpSource::classNames( $source ) as $name ) {
				if ( str_contains( $name['name'], '\\' ) && 1 !== preg_match( '/^SEOCart\\\\(?:Promotion|Pricing)\\\\Domain\\\\|^SEOCart\\\\Support\\\\/', $name['name'] ) ) {
					$outside[] = $file . ':' . $name['line'] . ' ' . $name['name'];
				}
			}
		}

		$this->assertSame( array(), $outside, 'The promotion domain is pure: it names its own domain, the calculation\'s and Support, nothing else.' );
	}
}
