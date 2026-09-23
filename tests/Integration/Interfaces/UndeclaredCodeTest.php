<?php
/**
 * Tests which undeclared error codes the invoker reports to the developer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStoreError;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * A code an operation does not declare is reported to the developer with `_doing_it_wrong`, which
 * WordPress prints into the response under `WP_DEBUG_DISPLAY`. Two kinds of code are exempt: an
 * internal code, which any operation can meet and the translator reports to the log anyway, and a
 * code whose row says any write may raise it, on an operation that changes the store. On a
 * read-only operation that second kind must be declared.
 *
 * The test framework fails a test on a `_doing_it_wrong` it does not expect, so a test that expects
 * none proves the exemption.
 *
 * @since 0.1.0
 */
final class UndeclaredCodeTest extends WP_UnitTestCase {

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
	 * Tests that an undeclared internal code raises no notice, and still reaches the reporter.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_internal_code_is_reported_without_a_notice(): void {
		$surfaces = $this->surfaces(
			array( FixtureStockOperation::class, 'definition' ),
			DatabaseError::LockNotAcquired,
			array(
				'name'   => 'stock',
				'waited' => 3000,
			)
		);

		$response = $surfaces->rest( 'POST', '/fixture-stock/' . FixtureStockOperation::EXAMPLE_ITEM . '/adjustments', array( 'delta' => 1 ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'database.lock_not_acquired', $response->get_data()['code'] );
		$this->assertCount( 1, $surfaces->reportedInternal, 'The translator reports the internal failure.' );
		$this->assertSame( DatabaseError::LockNotAcquired, $surfaces->reportedInternal[0]['error']->errorCode() );
	}

	/**
	 * Tests that a code any write may raise needs no declaration on an operation that changes the store.
	 *
	 * @since 0.1.0
	 */
	public function test_an_any_write_code_raises_no_notice_on_a_changing_operation(): void {
		$surfaces = $this->surfaces( array( FixtureStockOperation::class, 'definition' ), FixtureStoreError::Unavailable, array() );
		$response = $surfaces->rest( 'POST', '/fixture-stock/' . FixtureStockOperation::EXAMPLE_ITEM . '/adjustments', array( 'delta' => 1 ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame(
			'{"code":"fixture_store.unavailable","message":"The store is being updated. Try again in a minute.","data":{"status":503,"details":{},"correlation_id":"' . OperationSurfaces::CORRELATION_ID . '"}}',
			wp_json_encode( $response->get_data() ),
			'The code is public: the client reads its message.'
		);
	}

	/**
	 * Tests that a read-only operation must still declare a code any write may raise.
	 *
	 * @since 0.1.0
	 */
	public function test_an_any_write_code_raises_the_notice_on_a_read_only_operation(): void {
		$this->setExpectedIncorrectUsage( OperationInvoker::class . '::invoke' );

		$surfaces = $this->surfaces( array( self::class, 'readOnlyDefinition' ), FixtureStoreError::Unavailable, array() );
		$response = $surfaces->rest( 'GET', '/fixture-stock/' . FixtureStockOperation::EXAMPLE_ITEM );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'fixture_store.unavailable', $response->get_data()['code'] );
	}

	/**
	 * Declares a read-only variant of the fixture, served with GET.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function readOnlyDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.show_stock',
			label: $fixture->label(),
			summary: 'Shows the stock level of one fixture item.',
			input: array( $fixture->input()[0] ),
			output: $fixture->output(),
			capability: $fixture->capability(),
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-stock/{item_id}' )
		);
	}

	/**
	 * Registers one operation whose service always fails with one code.
	 *
	 * @since 0.1.0
	 *
	 * @param callable                       $factory Builds the operation's definition.
	 * @param ErrorCode                      $code    The code the service fails with.
	 * @param array<string, int|string|bool> $context The failure's context.
	 * @return OperationSurfaces The surfaces.
	 *
	 * @phpstan-param callable(): OperationDefinition $factory
	 */
	private function surfaces( callable $factory, ErrorCode $code, array $context ): OperationSurfaces {
		$registry = new OperationRegistry();
		$registry->add( $factory()->id(), $factory );

		return new OperationSurfaces(
			$registry,
			new class( $code, $context ) {

				/**
				 * The code the service fails with.
				 *
				 * @var ErrorCode
				 */
				private ErrorCode $code;

				/**
				 * The failure's context.
				 *
				 * @var array<string, int|string|bool>
				 */
				private array $context;

				/**
				 * Keeps the failure.
				 *
				 * @param ErrorCode                      $code    The code.
				 * @param array<string, int|string|bool> $context The context.
				 */
				public function __construct( ErrorCode $code, array $context ) {
					$this->code    = $code;
					$this->context = $context;
				}

				/**
				 * Fails with the code.
				 *
				 * @param array<string, mixed> $input The input.
				 * @return never
				 */
				public function adjust( array $input ): never {
					unset( $input );

					CodedException::raise( $this->code, $this->context );
				}
			}
		);
	}
}
