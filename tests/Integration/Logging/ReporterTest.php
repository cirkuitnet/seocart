<?php
/**
 * Tests that one reporter serves every module's report shape, with the correlation id, and redacts what a database failure quotes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\ReportCode as DatabaseReportCode;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Logging\ReportCode;
use SEOCart\Platform\Rest\RestErrorTranslator;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Logging\LogsTestCase;
use WP_Error;

/**
 * The reporter adapter, bound the way the kernel binds it: to the database layer, the operation
 * invoker and the REST error translator.
 *
 * - __invoke has the `callable( string $code, array $context ): void` shape of the database
 *   layer, the migrator, the transaction guards, the event bridge and drainer, and the jobs
 *   runner, and a real Database reports through it.
 * - unexpected() gets the invoker's non-coded failures, which reached the error log without a
 *   correlation id before; the line now carries the id the client was given.
 * - internal() gets the translator's internal failures, whose statement and server text were
 *   written to the error log as they were; the line now carries neither the email nor the card
 *   number they quoted.
 *
 * Planted violations: in Reporter::internal(), drop the scoped() call (the line carries the
 * request's own id, not the one the client was given); in Redactor::throwable(), write a
 * StatementDiagnostic's message as it is (the email and the card reach the line).
 *
 * @since 0.1.0
 */
final class ReporterTest extends LogsTestCase {

	/**
	 * The correlation id a translator's provider gives the client, when it differs from the one in force.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CLIENT_ID = '0192a3b4-0000-7000-8000-0000000000c1';

	/**
	 * Tests that __invoke has the shape every module's reporter has, and a real Database reports through it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_reporter_has_the_shape_the_modules_use_and_the_database_reports_through_it(): void {
		global $wpdb;

		$invoke = new \ReflectionMethod( Reporter::class, '__invoke' );

		$this->assertSame(
			array( 'string $code', 'array $context' ),
			array_map( static fn( \ReflectionParameter $p ): string => (string) $p->getType() . ' $' . $p->getName(), $invoke->getParameters() )
		);
		$this->assertSame( 'void', (string) $invoke->getReturnType() );

		$reporter  = $this->reporterOver( $this->logger() );
		$reporting = new Database( $wpdb, true, $reporter );

		$reporting->transaction(
			static function () use ( $reporting ): void {
				$reporting->afterCommit(
					static function (): void {
						throw new \RuntimeException( 'A listener of the commit failed.' );
					}
				);
			}
		);

		$line = $this->onlyLine();

		$this->assertSame( DatabaseReportCode::AfterCommitFailed->value, $line['machine_code'] );
		$this->assertSame( 'database', $line['channel'] );
		$this->assertSame( 'warning', $line['level'] );
		$this->assertSame( SequentialIdGenerator::nth( 1 ), $line['correlation_id'] );
		$this->assertSame(
			array(
				'exception' => \RuntimeException::class,
				'message'   => 'A listener of the commit failed.',
			),
			self::context( $line )
		);
	}

	/**
	 * Tests that an operation's unexpected failure is logged under the correlation id the client was given.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unexpected_operation_failure_is_logged_with_the_clients_correlation_id(): void {
		$reporter   = $this->reporterOver( $this->logger() );
		$translator = new RestErrorTranslator( ErrorTable::compose( DatabaseError::class ), fn(): string => $this->correlation->current(), array( $reporter, 'internal' ) );
		$service    = new class() {

			/**
			 * Fails the way a programming error or a broken dependency does.
			 *
			 * @throws \RuntimeException Always.
			 *
			 * @return never
			 */
			public function adjust(): never {
				throw new \RuntimeException( 'Could not charge 4111 1111 1111 1111.' );
			}
		};
		$invoker    = new OperationInvoker( static fn(): object => $service, $translator, array( $reporter, 'unexpected' ) );

		$error = $invoker->invoke(
			new CompiledOperation( FixtureStockOperation::definition() ),
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => 1,
			),
			Actor::user( 1 )
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( ErrorTranslator::INTERNAL_ERROR, $error->get_error_code() );

		$lines   = $this->lines();
		$line    = $lines[0];
		$context = self::context( $line );

		$this->assertSame( array( ReportCode::OperationFailed->value, ReportCode::CardNumberRemoved->value ), array_column( $lines, 'machine_code' ), 'The card in the exception message is removed and reported.' );
		$this->assertSame( 'operations', $line['channel'] );
		$this->assertSame( 'error', $line['level'] );
		$this->assertSame( $error->get_error_data()['correlation_id'], $line['correlation_id'], 'The line carries the id the client was given.' );
		$this->assertSame( FixtureStockOperation::ID, $context['operation_id'] );
		$this->assertSame( \RuntimeException::class, $context['exception']['class'] );
		$this->assertSame( 'Could not charge ' . CardNumbers::MARKER . '.', $context['exception']['message'] );
	}

	/**
	 * Tests that an internal failure is logged under the id the client was given, without the values its statement and the server quoted.
	 *
	 * @since 0.1.0
	 */
	public function test_an_internal_failure_is_logged_without_the_values_it_quoted(): void {
		$reporter   = $this->reporterOver( $this->logger() );
		$translator = new RestErrorTranslator( ErrorTable::compose( DatabaseError::class ), static fn(): string => self::CLIENT_ID, array( $reporter, 'internal' ) );

		$error = $translator->translate(
			QueryFailed::fromErrno(
				1062,
				'23000',
				"INSERT INTO `wp_seocart_orders` ( email, note ) VALUES ( 'jane.doe@example.com', 'card 4111 1111 1111 1111' )",
				"Duplicate entry 'jane.doe@example.com' for key 'email'",
				false
			)
		);

		$this->assertSame( DatabaseError::DuplicateKey->value, $error->get_error_code() );
		$this->assertSame( self::CLIENT_ID, $error->get_error_data()['correlation_id'] );

		$line    = $this->onlyLine();
		$context = self::context( $line );

		$this->assertSame( DatabaseError::DuplicateKey->value, $line['machine_code'] );
		$this->assertSame( 'database', $line['channel'] );
		$this->assertSame( self::CLIENT_ID, $line['correlation_id'], 'The line carries the id the client was given.' );
		$this->assertSame( SequentialIdGenerator::nth( 1 ), $this->correlation->current(), 'The request\'s own id is back afterwards.' );
		$this->assertSame( 1062, $context['errno'] );
		$this->assertSame( 'INSERT INTO `wp_seocart_orders` ( email, note ) VALUES ( ?, ? )', $context['exception']['previous']['statement'] );
		$this->assertSame( 'Duplicate entry ? for key ?', $context['exception']['previous']['server_message'] );
		$this->assertStringNotContainsString( 'jane.doe', (string) $line['context_json'] );
		$this->assertStringNotContainsString( '4111111111111111', (string) preg_replace( '/\D/', '', (string) $line['context_json'] ) );
	}

	/**
	 * Tests that the reporter resolves the logger on the first report only, and that a logger that cannot be built is tried once and its losses rate-limited.
	 *
	 * Fifty reports reach a reporter whose logger cannot be built: the builder runs once, one
	 * line names the reason, and one count of the fifty is written when the process ends.
	 *
	 * Planted violations: in Reporter::write(), let the builder's exception through (the test
	 * errors); forget the failure (the builder runs fifty times).
	 *
	 * @since 0.1.0
	 */
	public function test_the_logger_is_resolved_on_the_first_report_and_a_failure_to_build_it_never_throws(): void {
		$logger   = $this->logger();
		$resolved = 0;
		$reporter = new Reporter(
			static function () use ( $logger, &$resolved ): Logger {
				++$resolved;

				return $logger;
			},
			$this->correlation
		);

		$this->assertSame( 0, $resolved, 'Binding the reporter builds nothing.' );

		$reporter( 'events.listener_slow', array( 'ms' => 1200 ) );
		$reporter( 'events.listener_slow', array( 'ms' => 1300 ) );

		$this->assertSame( 1, $resolved );
		$this->assertCount( 2, $this->lines() );

		$builds = 0;
		$broken = new Reporter(
			static function () use ( &$builds ): Logger {
				++$builds;

				throw new \RuntimeException( 'The container could not build the logger.' );
			},
			$this->correlation,
			$this->fallbackLog()
		);

		for ( $report = 0; $report < 50; $report++ ) {
			$broken( 'events.listener_failed', array( 'card' => '4111111111111111' ) );
		}

		$this->assertSame( 1, $builds, 'Building the logger is not tried again.' );
		$this->assertCount( 1, $this->fallback, 'One line for the reason.' );
		$this->assertStringContainsString( 'SEOCart: a report (warning, events.listener_failed) was lost: the logger could not be built (RuntimeException).', $this->fallback[0] );
		$this->assertCount( 1, $this->atShutdown );

		( $this->atShutdown[0] )();

		$this->assertCount( 2, $this->fallback );
		$this->assertStringContainsString( 'SEOCart: 50 log lines could not be written in this process', $this->fallback[1] );
		$this->assertStringNotContainsString( '4111', implode( "\n", $this->fallback ) );
	}

	/**
	 * Builds a reporter over a logger.
	 *
	 * @since 0.1.0
	 *
	 * @param Logger $logger The logger.
	 * @return Reporter The reporter, sharing the test's correlation id.
	 */
	private function reporterOver( Logger $logger ): Reporter {
		return new Reporter( static fn(): Logger => $logger, $this->correlation );
	}
}
