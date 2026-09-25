<?php
/**
 * Tests the logger against a real `logs` table: what it writes, what it never writes, and where
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

use Random\Randomizer;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\DataRegistry\Contribution;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Logging\ReportCode;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Logging\DeclaredFields;
use SEOCart\Tests\Support\Logging\LogsTestCase;
use SEOCart\Tests\Support\Logging\TestCards;
use SEOCart\Tests\Support\SeededCases;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- These tests drop the log table and add a query filter on purpose, to make a write fail.

/**
 * The logger writes one redacted row per line, with the correlation id in force, and survives
 * everything a caller or the database can do to it.
 *
 * Each test names its planted violation, in src/Platform/Logging/.
 *
 * @since 0.1.0
 */
final class LoggerTest extends LogsTestCase {

	/**
	 * A correlation id a client sent.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CLIENT_ID = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

	/**
	 * A card network's published test number, for the one value that must be an integer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TEST_CARD = '4111111111111111';

	/**
	 * Tests that a line is written with every column, and that a line below the minimum is not written at all.
	 *
	 * Planted violation: in Logger::log(), drop the minimum-level check (the debug line is written).
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_is_written_with_every_column_and_one_below_the_minimum_is_not(): void {
		wp_set_current_user( 1 );

		$logger = $this->logger( null, Level::Info );

		$logger->debug( 'test.detail', 'Detail nobody asked for.' );
		$logger->warning( 'events.listener_slow', 'A listener took longer than a second.', array( 'ms' => 1500 ) );

		$line = $this->onlyLine();

		$this->assertSame( 'warning', $line['level'] );
		$this->assertSame( 'events', $line['channel'] );
		$this->assertSame( 'events.listener_slow', $line['machine_code'] );
		$this->assertSame( 'A listener took longer than a second.', $line['message'] );
		$this->assertSame( array( 'ms' => 1500 ), self::context( $line ) );
		$this->assertSame( SequentialIdGenerator::nth( 1 ), $line['correlation_id'] );
		$this->assertSame( '1', $line['user_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) $line['created_at'] );
		$this->assertSame( array(), $this->fallback );

		wp_set_current_user( 0 );

		$logger->info( 'plugin_event', 'No dot, no user, no context.' );

		$second = $this->lines()[1];

		$this->assertSame( 'plugin', $second['channel'] );
		$this->assertNull( $second['user_id'] );
		$this->assertNull( $second['context_json'] );
	}

	/**
	 * Tests, over generated card numbers, that none reaches the table from the message, a context
	 * value, a nested context value, an integer or an exception message, while a number that
	 * fails the checksum is kept.
	 *
	 * Planted violation: in Redactor::kept(), return the text before CardNumbers::scrub() (the
	 * message and every string value keep the card).
	 *
	 * @since 0.1.0
	 */
	public function test_a_card_number_never_reaches_the_table(): void {
		$logger = $this->logger();

		SeededCases::check(
			20260925,
			60,
			static function ( Randomizer $random ): array {
				$card      = TestCards::draw( $random, 13, 19 );
				$separator = array( '', ' ', '-', ' - ' )[ SeededCases::int( $random, 0, 3 ) ];

				return array( $card, implode( $separator, str_split( $card, 4 ) ) );
			},
			function ( string $card, string $written ) use ( $logger ): void {
				$order = '1234567890123456';

				$logger->error(
					'payments.declined',
					'Card ' . $written . ' declined for order ' . $order . '.',
					array(
						'card'      => $written,
						'as_number' => (int) self::TEST_CARD,
						'order'     => $order,
						'nested'    => array( 'payment' => array( 'raw' => 'pan=' . $written ) ),
						'exception' => new \RuntimeException( 'Gateway said: card ' . $written . ' is not valid.' ),
					)
				);

				$lines = $this->linesOf( 'payments.declined' );
				$line  = end( $lines );

				$this->assertStringNotContainsString( self::TEST_CARD, (string) preg_replace( '/\D/', '', (string) $line['context_json'] ), 'The card given as an integer reached the context.' );

				foreach ( array( 'message', 'context_json' ) as $column ) {
					$this->assertStringNotContainsString( $card, (string) preg_replace( '/\D/', '', (string) $line[ $column ] ), "The card reached the {$column} column." );
					$this->assertStringContainsString( CardNumbers::MARKER, (string) $line[ $column ] );
					$this->assertStringContainsString( $order, (string) $line[ $column ], "The order number, which fails the checksum, was destroyed in the {$column} column." );
				}
			}
		);

		$this->assertCount( 60, $this->linesOf( ReportCode::CardNumberRemoved->value ), 'Each line a card number was removed from is followed by one warning.' );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that a line a card number was removed from is followed by one warning naming its channel and code, and nothing of its content.
	 *
	 * The warning is written whatever the minimum level, on the same connection as the line, and
	 * carries the same correlation id. A line without a card number has none.
	 *
	 * Planted violations: in Logger::log(), never write the warning (the warnings are missing); in
	 * Logger::cardNumberRemoved(), add the offending line's message to the context (the context is
	 * no longer the two names); in Redactor::value(), leave an integer card number unflagged (the
	 * line carrying the card only as an integer has no warning).
	 *
	 * @since 0.1.0
	 */
	public function test_a_removed_card_number_is_followed_by_a_warning_naming_the_line(): void {
		$logger = $this->logger( null, Level::Error );

		$logger->error( 'payments.declined', 'Card ' . self::TEST_CARD . ' declined.', array( 'order' => 'SC-1001' ) );
		$logger->error( 'payments.refund_failed', 'No card in this one.', array( 'amount' => 1200 ) );
		$logger->error( 'payments.charged', 'Charged.', array( 'pan' => (int) self::TEST_CARD ) );
		$this->db->transaction( static fn() => $logger->error( 'payments.inside', 'Card 4111-1111-1111-1111.' ) );
		$logger->error( 'payments.redacted', 'The card is only in a redacted value.', array( 'user_id' => self::TEST_CARD ) );

		$lines    = $this->lines();
		$warnings = array_values( array_filter( $lines, static fn( array $line ): bool => ReportCode::CardNumberRemoved->value === $line['machine_code'] ) );
		$names    = array_map( static fn( array $line ): array => self::context( $line ), $warnings );

		$this->assertSame(
			array( 'payments.declined', ReportCode::CardNumberRemoved->value, 'payments.refund_failed', 'payments.charged', ReportCode::CardNumberRemoved->value, 'payments.inside', ReportCode::CardNumberRemoved->value, 'payments.redacted', ReportCode::CardNumberRemoved->value ),
			array_column( $lines, 'machine_code' )
		);
		$this->assertSame(
			array(
				array(
					'channel'      => 'payments',
					'machine_code' => 'payments.declined',
				),
				array(
					'channel'      => 'payments',
					'machine_code' => 'payments.charged',
				),
				array(
					'channel'      => 'payments',
					'machine_code' => 'payments.inside',
				),
				array(
					'channel'      => 'payments',
					'machine_code' => 'payments.redacted',
				),
			),
			$names
		);
		$this->assertSame( array( 'user_id' => Redactor::REDACTED ), self::context( $lines[7] ), 'The redacted value is replaced, and its card reported.' );

		foreach ( $warnings as $warning ) {
			$this->assertSame( 'warning', $warning['level'] );
			$this->assertSame( 'logging', $warning['channel'] );
			$this->assertSame( $lines[0]['correlation_id'], $warning['correlation_id'] );
			$this->assertDoesNotMatchRegularExpression( '/\d/', $warning['message'] . $warning['context_json'], 'The warning holds no digit at all.' );
		}

		$this->assertSame( 1, $this->separateOpened, 'The line inside the transaction and its warning went through the logger\'s own connection.' );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that a line that dropped a value too large or too deeply nested to check is followed by the warning, marked unchecked.
	 *
	 * `active_data_key` is the production secret. The first value is a million groups of one
	 * digit, the second a card twenty arrays down; both are dropped unread past the bound, and
	 * each line is followed by the warning with `unchecked`. A card seven arrays down is within
	 * the bound: it is found, and its warning is the plain one.
	 *
	 * Planted violations: in Logger::log(), warn on card_number_removed only (the unchecked
	 * warnings are missing); in Redactor::noteCardNumberIn(), return quietly past MAX_DEPTH (the
	 * deep line has no warning).
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_too_large_or_deep_to_check_is_followed_by_an_unchecked_warning(): void {
		$logger = $this->logger( null, Level::Error );
		$nest   = static function ( int $levels ): array {
			$value = array( self::TEST_CARD );

			for ( $level = 1; $level < $levels; $level++ ) {
				$value = array( $value );
			}

			return $value;
		};

		$logger->error( 'secrets.huge', 'A dropped value too large to check.', array( 'active_data_key' => str_repeat( '1 ', 1000000 ) ) );
		$logger->error( 'secrets.deep', 'A dropped value too deep to check.', array( 'active_data_key' => $nest( 20 ) ) );
		$logger->error( 'secrets.seven', 'A card seven arrays down.', array( 'active_data_key' => $nest( 7 ) ) );

		$lines    = $this->lines();
		$warnings = array_values( array_filter( $lines, static fn( array $line ): bool => ReportCode::CardNumberRemoved->value === $line['machine_code'] ) );

		$this->assertSame(
			array( 'secrets.huge', ReportCode::CardNumberRemoved->value, 'secrets.deep', ReportCode::CardNumberRemoved->value, 'secrets.seven', ReportCode::CardNumberRemoved->value ),
			array_column( $lines, 'machine_code' )
		);
		$this->assertSame(
			array(
				array(
					'channel'      => 'secrets',
					'machine_code' => 'secrets.huge',
					'unchecked'    => true,
				),
				array(
					'channel'      => 'secrets',
					'machine_code' => 'secrets.deep',
					'unchecked'    => true,
				),
				array(
					'channel'      => 'secrets',
					'machine_code' => 'secrets.seven',
				),
			),
			array_map( static fn( array $line ): array => self::context( $line ), $warnings )
		);
		$this->assertStringContainsString( 'too large or too deeply nested', (string) $warnings[0]['message'] );
		$this->assertStringContainsString( 'was removed', (string) $warnings[2]['message'] );
		$this->assertNull( $lines[0]['context_json'], 'The secret is dropped.' );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that a declared personal-data name is redacted and a declared secret dropped, by the declarations alone.
	 *
	 * A table is planted with a `pii` column `shopper_email`, and the fixture operation declares
	 * `audit_token` secret. Nothing else names them.
	 *
	 * Planted violation: in Redactor::entries(), write every value without looking the key up
	 * (the email and the token reach the table).
	 *
	 * @since 0.1.0
	 */
	public function test_declared_personal_data_is_redacted_and_secrets_are_dropped(): void {
		$operations = new OperationRegistry();
		FixtureStockOperation::register( $operations );

		$registry = new DataRegistry(
			new CapabilityDeclaration(),
			new Contribution(
				tables: array(
					new TableDefinition(
						'planted_customers',
						'Planted',
						'A table this test plants.',
						MutationPattern::Config,
						array(
							new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
							new ColumnSpec( 'shopper_email', 'varchar(191)', Classification::Pii, 'Planted personal data.', erasure: ColumnSpec::ERASE_DESTROY ),
						),
						array( 'id' ),
						array(),
						array(),
						'permanent',
						array()
					),
				)
			)
		);

		$this->logger( Redactor::fromDeclarations( $registry, ...DeclaredFields::ofOperations( $operations ) ) )->warning(
			'test.privacy',
			'A line carrying personal data and a secret.',
			array(
				'shopper_email' => 'jane.doe@example.com',
				'audit_token'   => 'planted-secret-token-value',
				'order_number'  => 'SC-1001',
				'details'       => array( 'Shopper_Email' => 'jane.doe@example.com' ),
			)
		);

		$line = $this->onlyLine();

		$this->assertSame(
			array(
				'shopper_email' => Redactor::REDACTED,
				'order_number'  => 'SC-1001',
				'details'       => array( 'Shopper_Email' => Redactor::REDACTED ),
			),
			self::context( $line )
		);
		$this->assertStringNotContainsString( 'jane.doe', (string) $line['context_json'] );
		$this->assertStringNotContainsString( 'planted-secret-token-value', (string) $line['context_json'] );
	}

	/**
	 * Tests that every line carries the correlation id in force: minted, accepted from the client, or scoped.
	 *
	 * Planted violation: in Logger::line(), stamp a fresh id per line instead of current().
	 *
	 * @since 0.1.0
	 */
	public function test_every_line_carries_the_correlation_id_in_force(): void {
		$logger = $this->logger();

		$logger->info( 'test.first', 'Minted.' );

		$this->correlation->accept( self::CLIENT_ID );

		$logger->info( 'test.second', 'Accepted.' );
		$this->correlation->scoped( SequentialIdGenerator::nth( 9 ), static fn() => $logger->info( 'test.third', 'Scoped, as a job handler or a delivered event runs.' ) );
		$logger->info( 'test.fourth', 'Back to the request.' );

		$this->assertSame(
			array( SequentialIdGenerator::nth( 1 ), self::CLIENT_ID, SequentialIdGenerator::nth( 9 ), self::CLIENT_ID ),
			array_column( $this->lines(), 'correlation_id' )
		);
	}

	/**
	 * Tests that a line logged inside a transaction is committed at once, on the logger's own
	 * connection, and survives the rollback of the unit of work that logged it.
	 *
	 * Another connection sees the line while the transaction is still open, which is what a
	 * process that dies inside the transaction leaves behind. Outside a transaction the line
	 * goes through the site's connection and no second connection is opened.
	 *
	 * Planted violation: in Logger::log(), always write on `$this->db` (the lines are rolled
	 * back with the unit of work, and B sees nothing while it is open).
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_logged_inside_a_rolled_back_transaction_is_kept(): void {
		$b      = $this->secondConnection();
		$logger = $this->logger();
		$count  = fn(): int => (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->logsTable() ) );

		$logger->info( 'test.outside', 'Outside any transaction.' );

		$this->assertSame( 0, $this->separateOpened, 'Outside a transaction the site\'s connection writes.' );

		try {
			$this->db->transaction(
				function () use ( $logger, $count ): void {
					$this->insertRow( 1, 'rolled back' );
					$logger->error( 'test.inside', 'Inside the unit of work.' );

					$this->assertSame( 2, $count(), 'Another connection sees the line while the transaction is open.' );

					$this->db->transaction(
						static function () use ( $logger ): void {
							$logger->error( 'test.savepoint', 'Inside a savepoint.' );
						}
					);

					throw new \DomainException( 'The unit of work fails.' );
				}
			);
		} catch ( \DomainException $failure ) {
			$this->assertSame( 'The unit of work fails.', $failure->getMessage() );
		}

		$this->assertSame( 0, (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->rowsTable() ) ), 'The unit of work was rolled back.' );
		$this->assertSame( array( 'test.outside', 'test.inside', 'test.savepoint' ), array_column( $this->lines(), 'machine_code' ), 'Every line is kept.' );
		$this->assertSame( 1, $this->separateOpened, 'The logger\'s own connection is opened once.' );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests the production LogConnection: a line logged inside a rolled-back transaction is kept.
	 *
	 * Planted violation: in LogConnection::database(), return a Database over the global `$wpdb`
	 * (the line joins the transaction and is rolled back).
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_connection_keeps_a_line_through_a_rollback(): void {
		$logger = $this->logger( null, Level::Debug, false );

		try {
			$this->db->transaction(
				static function () use ( $logger ): void {
					$logger->error( 'test.inside', 'Inside the unit of work.' );

					throw new \DomainException( 'The unit of work fails.' );
				}
			);
		} catch ( \DomainException $failure ) {
			unset( $failure );
		}

		$this->assertSame( array( 'test.inside' ), array_column( $this->lines(), 'machine_code' ) );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that the logger never throws, never logs from inside itself, and says why a line was lost in one fallback line without its values.
	 *
	 * Planted violations: in Logger::log(), rethrow what the catch receives (the missing table
	 * fails the test); drop the `writing` guard (the query filter below logs forever).
	 *
	 * @since 0.1.0
	 */
	public function test_the_logger_never_throws_and_never_logs_from_inside_itself(): void {
		global $wpdb;

		$logger = $this->logger();
		$calls  = 0;

		// A filter that logs while the logger writes, as a careless listener on `query` would.
		$filter = static function ( $query ) use ( $logger, &$calls ) {
			if ( is_string( $query ) && str_contains( $query, 'seocart_logs' ) && ++$calls < 5 ) {
				$logger->error( 'test.recursion', 'Logged from inside a write.' );
			}

			return $query;
		};

		add_filter( 'query', $filter );
		$logger->info( 'test.outer', 'The line being written.' );
		remove_filter( 'query', $filter );

		$this->assertSame( array( 'test.outer' ), array_column( $this->lines(), 'machine_code' ) );
		$this->assertCount( 1, $this->fallback );
		$this->assertStringContainsString( 'test.recursion', $this->fallback[0] );
		$this->assertStringContainsString( 'was dropped', $this->fallback[0] );

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->logsTable() ) );

		$logger->error( 'test.lost', 'Card 4111 1111 1111 1111 and jane.doe@example.com.', array( 'email' => 'jane.doe@example.com' ) );

		$this->assertCount( 2, $this->fallback );
		$this->assertStringContainsString( 'a log line (error, test.lost, correlation id ' . SequentialIdGenerator::nth( 1 ) . ') could not be written: database.query_failed (MySQL error 1146)', $this->fallback[1] );
		$this->assertStringNotContainsString( 'jane.doe', $this->fallback[1] );
		$this->assertStringNotContainsString( '4111', $this->fallback[1] );
	}

	/**
	 * Tests that a line inside a transaction is not written on the site's connection when the logger's own cannot be opened, and that it is not tried again.
	 *
	 * Planted violation: in Logger::separate(), forget the failure (the opener is called twice).
	 *
	 * @since 0.1.0
	 */
	public function test_an_unavailable_connection_is_not_retried_and_nothing_joins_the_transaction(): void {
		$opened = 0;
		$logger = $this->logger(
			null,
			Level::Debug,
			true,
			static function () use ( &$opened ): ?Database {
				++$opened;

				return null;
			}
		);

		$this->db->transaction(
			static function () use ( $logger ): void {
				$logger->error( 'test.first', 'First.' );
				$logger->error( 'test.second', 'Second.' );
			}
		);

		$this->assertSame( 1, $opened );
		$this->assertSame( array(), $this->lines(), 'Nothing joined the transaction.' );
		$this->assertCount( 1, $this->fallback, 'One line for the reason; the second loss is only counted.' );
		$this->assertStringContainsString( 'test.first', $this->fallback[0] );
		$this->assertStringContainsString( 'own connection could not be opened', $this->fallback[0] );
	}

	/**
	 * Tests that the fallback writes one line per failure reason, and one count of every lost line at the end of the process, only if any was lost.
	 *
	 * Planted violations: in FallbackLog::lost(), write a line for every loss (three lines for
	 * one reason); register the end-of-process count in the constructor (a logger that lost
	 * nothing registered it); register it at every loss (registered five times).
	 *
	 * @since 0.1.0
	 */
	public function test_the_fallback_writes_one_line_per_reason_and_a_final_count(): void {
		global $wpdb;

		$logger = $this->logger();

		$logger->info( 'test.written', 'Written.' );

		$this->assertSame( array(), $this->atShutdown, 'A logger that lost nothing registers nothing for the end of the process.' );

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->logsTable() ) );

		$logger->error( 'test.lost_one', 'Lost.' );
		$logger->error( 'test.lost_two', 'Lost.' );
		$logger->error( 'test.lost_three', 'Lost.' );

		$this->assertCount( 1, $this->fallback, 'Three losses for one reason: one line.' );
		$this->assertStringContainsString( 'test.lost_one', $this->fallback[0] );
		$this->assertStringContainsString( 'database.query_failed (MySQL error 1146)', $this->fallback[0] );

		// A second reason: a line logged while the next one is being written.
		$nested = false;
		$filter = static function ( $query ) use ( $logger, &$nested ) {
			if ( ! $nested && is_string( $query ) && str_contains( $query, 'seocart_logs' ) ) {
				$nested = true;
				$logger->error( 'test.nested', 'Logged from inside a write.' );
			}

			return $query;
		};

		add_filter( 'query', $filter );
		$logger->error( 'test.lost_four', 'Lost.' );
		remove_filter( 'query', $filter );

		$this->assertCount( 2, $this->fallback, 'The new reason gets its line; the fourth loss for the first reason does not.' );
		$this->assertStringContainsString( 'test.nested', $this->fallback[1] );
		$this->assertCount( 1, $this->atShutdown, 'The count is registered once, at the first loss.' );

		( $this->atShutdown[0] )();
		( $this->atShutdown[0] )();

		$this->assertCount( 3, $this->fallback, 'The count is written once.' );
		$this->assertStringContainsString( 'SEOCart: 5 log lines could not be written in this process', $this->fallback[2] );
	}

	/**
	 * Tests that a code that is not a static machine code is stored as `logging.invalid_code`, kept nowhere, and reported under WP_DEBUG.
	 *
	 * The codes: one with a secret in it, one with a card number, one with capitals and a space,
	 * one too long, and one with a digit; then one written while the table is missing, which
	 * reaches the fallback instead.
	 *
	 * Planted violations: in Logger::log(), keep the code given in the context (the secret is
	 * stored); CODE_PATTERN admitting digits again (`auth.abc123secret` is stored as its own code);
	 * no _doing_it_wrong() (the expected incorrect usage is missing).
	 *
	 * @since 0.1.0
	 */
	public function test_an_invalid_code_is_stored_as_invalid_and_kept_nowhere(): void {
		global $wpdb;

		$this->setExpectedIncorrectUsage( Logger::class . '::log' );

		$logger = $this->logger();
		$given  = array( 'auth.abc123secret', 'payments.4111111111111111', 'Not A Code!', 'events.' . str_repeat( 'a', 60 ), 'jobs.v2_ran' );

		foreach ( $given as $code ) {
			$logger->warning( $code, 'A line with a code built from a value.', array( 'order' => 'SC-1001' ) );
		}

		$lines = $this->lines();

		$this->assertSame( array_fill( 0, 5, ReportCode::InvalidCode->value ), array_column( $lines, 'machine_code' ) );
		$this->assertSame( array_fill( 0, 5, 'logging' ), array_column( $lines, 'channel' ) );
		$this->assertSame( array( 'order' => 'SC-1001' ), self::context( $lines[0] ), 'The code given is not kept in the context.' );

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->logsTable() ) );

		$logger->error( 'auth.abc123secret', 'Lost.' );

		$this->assertCount( 1, $this->fallback );
		$this->assertStringContainsString( ReportCode::InvalidCode->value, $this->fallback[0] );

		// Only the columns a code could reach: a row's id, timestamp and correlation id are digits that contain any short fragment by chance.
		$written = array_map( static fn( array $line ): array => array_diff_key( $line, array_flip( array( 'id', 'created_at', 'correlation_id' ) ) ), $lines );
		$stored  = (string) wp_json_encode( $written ) . implode( "\n", $this->fallback );

		foreach ( array( 'abc123secret', '4111', 'Not A Code', 'aaaaaaaaaa', 'v2_ran' ) as $kept ) {
			$this->assertStringNotContainsString( $kept, $stored );
		}

		$this->assertTrue( Logger::isValidCode( 'events.listener_failed' ) );
		$this->assertTrue( Logger::isValidCode( 'seocart_internal_error' ) );
	}
}
