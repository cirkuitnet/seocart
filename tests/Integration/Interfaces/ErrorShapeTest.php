<?php
/**
 * Tests that an error has one shape on the REST route and from the ability, and that the command prints its correlation id
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_Error;
use WP_UnitTestCase;

/**
 * The fixture operation, wired as the kernel will wire it, fails on all three surfaces: with its
 * declared code, with a database failure, and by WordPress refusing the request. The REST body's
 * data and the ability's WP_Error data must be the same `{ status, details, correlation_id }`,
 * and the command must print `<code>: <message>` and the correlation id.
 *
 * @since 0.1.0
 */
final class ErrorShapeTest extends WP_UnitTestCase {

	/**
	 * The statement the database failure carries, which names a customer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const STATEMENT = "UPDATE `wp_seocart_stock` SET on_hand = 2 WHERE note = 'Ada Lovelace'";

	/**
	 * Logs in a user who may adjust stock.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );

		wp_set_current_user( $user->ID );
	}

	/**
	 * Discards the REST server and the Abilities registries.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that a declared failure has the same code, message and data on the REST route and from
	 * the ability, and that the command prints the code, the message and the correlation id.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failure_has_one_shape_on_every_surface(): void {
		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$outcomes = ( new OperationSurfaces( $registry ) )->everywhere(
			FixtureStockOperation::definition(),
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => -10,
			)
		);
		$expected = '{"code":"fixture_stock.insufficient","message":"You asked to remove 10, but only 5 are in stock.","data":{"status":409,"details":{"requested":10,"available":5},"correlation_id":"' . OperationSurfaces::CORRELATION_ID . '"}}';

		$this->assertSame( 409, $outcomes['rest']['result']->get_status() );
		$this->assertSame( $expected, wp_json_encode( $outcomes['rest']['result']->get_data() ) );
		$this->assertSame( $expected, self::abilityBody( $outcomes['ability']['result'] ) );
		$this->assertSame(
			'fixture_stock.insufficient: You asked to remove 10, but only 5 are in stock. (correlation id: ' . OperationSurfaces::CORRELATION_ID . ')',
			$outcomes['cli']['result']['failure']
		);
	}

	/**
	 * Tests that the command prints no correlation id when the request has none.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_prints_no_correlation_id_when_there_is_none(): void {
		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$surfaces                = new OperationSurfaces( $registry );
		$surfaces->correlationId = null;

		$outcome = $surfaces->cli( FixtureStockOperation::COMMAND, array( FixtureStockOperation::EXAMPLE_ITEM ), array( 'delta' => '-10' ) );

		$this->assertSame( 'fixture_stock.insufficient: You asked to remove 10, but only 5 are in stock.', $outcome['failure'] );
	}

	/**
	 * Tests that a database failure reaches every surface only as its code, its status, a generic
	 * message and the correlation id, and reaches the reporter once per surface.
	 *
	 * @since 0.1.0
	 */
	public function test_a_database_failure_reaches_no_client_on_any_surface(): void {
		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.adjust_contended_stock', array( self::class, 'contendedDefinition' ) );

		$surfaces = new OperationSurfaces(
			$registry,
			new class() {

				/**
				 * Fails the way a deadlock does.
				 *
				 * @param array<string, mixed> $input The input.
				 * @return never
				 *
				 * @throws QueryFailed Always.
				 */
				public function adjust( array $input ): never {
					unset( $input );

					throw QueryFailed::fromErrno( 1213, '40001', ErrorShapeTest::statement(), 'Deadlock found when trying to get lock; try restarting transaction', true );
				}
			}
		);
		$outcomes = $surfaces->everywhere(
			self::contendedDefinition(),
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => 1,
			)
		);
		$expected = '{"code":"database.transaction_retryable","message":"The operation failed because of an internal error. The site administrator can find the details in the error log.","data":{"status":503,"details":{},"correlation_id":"' . OperationSurfaces::CORRELATION_ID . '"}}';

		$this->assertSame( 503, $outcomes['rest']['result']->get_status() );
		$this->assertSame( $expected, wp_json_encode( $outcomes['rest']['result']->get_data() ) );
		$this->assertSame( $expected, self::abilityBody( $outcomes['ability']['result'] ) );
		$this->assertStringStartsWith( 'database.transaction_retryable: The operation failed because of an internal error.', (string) $outcomes['cli']['result']['failure'] );

		foreach ( $outcomes as $surface => $outcome ) {
			foreach ( array( 'Lovelace', 'UPDATE', 'Deadlock', '1213', '40001' ) as $private ) {
				$this->assertStringNotContainsString( $private, $outcome['text'], "{$surface}: {$private} reached the client." );
			}
		}

		$this->assertCount( 3, $surfaces->reportedInternal, 'Each surface reports the failure once.' );

		foreach ( $surfaces->reportedInternal as $report ) {
			$this->assertSame( DatabaseError::TransactionRetryable, $report['error']->errorCode() );
			$this->assertSame( OperationSurfaces::CORRELATION_ID, $report['correlation_id'] );
		}

		$this->assertSame( array(), $surfaces->reported, 'A coded failure is the translator\'s to report, not the invoker\'s.' );
	}

	/**
	 * Tests that WordPress's own refusals on the route carry the same data members.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refusal_by_wordpress_carries_the_shape(): void {
		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$surfaces = new OperationSurfaces( $registry );
		$route    = '/fixture-stock/' . FixtureStockOperation::EXAMPLE_ITEM . '/adjustments';

		$invalid = $surfaces->rest( 'POST', $route, array( 'delta' => 'abc' ) )->get_data();

		$this->assertSame( 'rest_invalid_param', $invalid['code'] );
		$this->assertExactlyTheMembers( $invalid['data'], 'rest_invalid_param' );
		$this->assertSame( OperationSurfaces::CORRELATION_ID, $invalid['data']['correlation_id'] );
		$this->assertSame( array( 'params', 'param_codes' ), array_keys( (array) $invalid['data']['details'] ), 'WordPress\'s params stay at their name; its own details are flattened into param_codes, so nothing named details nests inside details.' );
		$this->assertArrayHasKey( 'delta', ( (array) $invalid['data']['details'] )[ ErrorShape::PARAM_CODES ], 'WordPress\'s error code for the invalid parameter is kept.' );

		$missing = $surfaces->rest( 'POST', $route, array( 'reason' => 'recount' ) )->get_data();

		$this->assertSame( 'rest_missing_callback_param', $missing['code'] );
		$this->assertExactlyTheMembers( $missing['data'], 'rest_missing_callback_param' );
		$this->assertSame( '{"status":400,"details":{"params":["delta"]},"correlation_id":"' . OperationSurfaces::CORRELATION_ID . '"}', wp_json_encode( $missing['data'] ) );

		wp_set_current_user( 0 );

		$forbidden = $surfaces->rest( 'POST', $route, array( 'delta' => 1 ) );

		$this->assertSame( 401, $forbidden->get_status() );
		$this->assertExactlyTheMembers( $forbidden->get_data()['data'], 'rest_forbidden' );
		$this->assertSame( '{"status":401,"details":{},"correlation_id":"' . OperationSurfaces::CORRELATION_ID . '"}', wp_json_encode( $forbidden->get_data()['data'] ) );
	}

	/**
	 * Asserts that error data has exactly the three members: none missing, none extra.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $data  The `data` of an error body.
	 * @param string $label What the data belongs to, for the messages.
	 */
	private function assertExactlyTheMembers( $data, string $label ): void {
		$members = array( 'status', 'details', 'correlation_id' );

		$this->assertIsArray( $data, $label );
		$this->assertSame( array(), array_values( array_diff( $members, array_keys( $data ) ) ), "{$label}: members are missing." );
		$this->assertSame( array(), array_values( array_diff( array_keys( $data ), $members ) ), "{$label}: the data has members beyond the three." );
		$this->assertSame( $members, array_keys( $data ), "{$label}: the members are not in order." );
	}

	/**
	 * Declares the fixture with a database code it may fail with, the one that says "try again".
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function contendedDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.adjust_contended_stock',
			label: $fixture->label(),
			summary: 'Adjusts the stock level of one fixture item, and meets a deadlock.',
			input: $fixture->input(),
			output: $fixture->output(),
			capability: $fixture->capability(),
			resource_field: null,
			errors: array( DatabaseError::TransactionRetryable ),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-contended-stock/{item_id}/adjustments', WriteMethod::Post ),
			ability: 'fixture-adjust-contended-stock',
			cli: new CliBinding( array( 'fixture-contended-stock', 'adjust' ), array( 'item_id' ) )
		);
	}

	/**
	 * Returns the statement the database failure carries.
	 *
	 * @since 0.1.0
	 *
	 * @return string The statement.
	 */
	public static function statement(): string {
		return self::STATEMENT;
	}

	/**
	 * Returns the body the Abilities API sends for an ability's error, the way the REST API writes it.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result What the ability returned.
	 * @return string The JSON body.
	 */
	private static function abilityBody( $result ): string {
		self::assertInstanceOf( WP_Error::class, $result );

		return (string) wp_json_encode( rest_convert_error_to_response( $result )->get_data() );
	}
}
