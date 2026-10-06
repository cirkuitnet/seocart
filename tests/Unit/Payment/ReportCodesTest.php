<?php
/**
 * Tests that what the payment gateways' registry and the not-found gate report are log codes, never codes of the error table
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Application\GatewayConfiguration;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Error\ErrorTable;

/**
 * A refused registration, an incompatible gateway, an ignored "not found" and a webhook endpoint a provider did not set up are lines in the log for a person to read; none is an answer a client receives, so none may share a code with the error table, where a reader would look for it first.
 *
 * Planted violation, shown red and removed: make PaymentService::NOT_FOUND_IGNORED
 * `payment.gateway_unavailable`: it is then an error code too.
 *
 * @since 0.2.0
 */
final class ReportCodesTest extends TestCase {

	/**
	 * Tests each report code is a valid log code and no code of the error table.
	 *
	 * @since 0.2.0
	 */
	public function test_the_report_codes_are_log_codes_and_no_error_codes(): void {
		$errors = array_map( static fn( ErrorDefinition $row ): string => (string) $row->code()->value, ErrorTable::compose( ...Modules::ERROR_CATALOGS )->definitions() );

		$this->assertContains( PaymentError::GatewayUnavailable->value, $errors, 'The error table was not read, so the comparison would prove nothing.' );

		foreach ( array( Gateways::REJECTED, Gateways::INCOMPATIBLE, PaymentService::NOT_FOUND_IGNORED, GatewayConfiguration::PROVISIONING_FAILED ) as $code ) {
			$this->assertTrue( Logger::isValidCode( $code ), $code );
			$this->assertNotContains( $code, $errors, $code . ' is a code of the error table.' );
		}
	}
}
