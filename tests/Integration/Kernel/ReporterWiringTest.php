<?php
/**
 * Tests the logging module as the kernel wires it: the reporter, the logger, the redactor and the correlation id
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * What a module reports reaches the log: the one Reporter the kernel binds writes a line through
 * the logger, under the request's correlation id, redacted by the declarations the kernel
 * collects; and the id a client sends is the request's id when it is a UUID.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Modules::declaredFields(), leave out the settings' fields: the secret setting's value
 *   reaches the stored line.
 * - In Modules::loggingRegister(), leave out the `accept()` call: the client's request id is not
 *   the correlation id.
 *
 * @since 0.1.0
 */
final class ReporterWiringTest extends KernelTestCase {

	/**
	 * A request id a client might send.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REQUEST_ID = '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b';

	/**
	 * Forgets the request header.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_REQUEST_ID'] );

		parent::tear_down();
	}

	/**
	 * Tests that a report becomes a log line, under the correlation id in force, with a secret setting's value redacted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_report_becomes_a_redacted_log_line_under_the_correlation_id(): void {
		$container = $this->container();

		$container->get( Lifecycle::class )->activate();
		$container->get( Reporter::class )(
			'kernel.wiring_check',
			array(
				SecretKeys::ACTIVE => 'planted-secret-value',
				'count'            => 3,
			)
		);

		$row = $this->db->fetchRow( 'SELECT correlation_id, context_json FROM %i WHERE machine_code = %s', $this->db->table( 'logs' ), 'kernel.wiring_check' );

		$this->assertIsArray( $row, 'The report did not reach the log.' );
		$this->assertSame( $container->get( CorrelationId::class )->current(), $row['correlation_id'] );
		$this->assertStringContainsString( '"count":3', (string) $row['context_json'] );
		$this->assertStringNotContainsString( 'planted-secret-value', (string) $row['context_json'], 'A secret setting\'s value reached the log.' );
	}

	/**
	 * Tests that the request id a client sends is the correlation id when it is a UUID, and is ignored otherwise.
	 *
	 * @since 0.1.0
	 */
	public function test_the_clients_request_id_is_the_correlation_id(): void {
		$_SERVER['HTTP_X_REQUEST_ID'] = strtoupper( self::REQUEST_ID );

		$this->assertSame( self::REQUEST_ID, $this->container()->get( CorrelationId::class )->current() );

		$_SERVER['HTTP_X_REQUEST_ID'] = 'not-a-uuid';

		$this->assertNotSame( 'not-a-uuid', $this->container()->get( CorrelationId::class )->current() );
	}
}
