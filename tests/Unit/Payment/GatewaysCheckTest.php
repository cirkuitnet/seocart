<?php
/**
 * Tests that doctor's gateways check shares its names and its day with the kernel and the checkout's check
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Infrastructure\Doctor\CheckoutChecks;
use SEOCart\Payment\Infrastructure\Doctor\GatewaysCheck;
use SEOCart\Platform\Kernel\SiteHealth;

/**
 * The gateways' Site Health test is listed by the kernel under the id the check gives its result, and a payment waits a day before either doctor check calls it critical.
 *
 * @since 0.2.0
 */
final class GatewaysCheckTest extends TestCase {

	/**
	 * Tests the shared id and the shared day.
	 *
	 * @since 0.2.0
	 */
	public function test_the_check_shares_its_test_id_and_its_day(): void {
		$this->assertSame( SiteHealth::GATEWAYS_TEST, GatewaysCheck::TEST );
		$this->assertSame( CheckoutChecks::PENDING_SECONDS, GatewaysCheck::WAITED_SECONDS );
	}
}
