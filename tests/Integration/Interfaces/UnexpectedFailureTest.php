<?php
/**
 * Tests that an unexpected exception never reaches a client, on any surface
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_Error;
use WP_UnitTestCase;

/**
 * A service that fails with anything but a coded error has failed in a way no client caused and no
 * client may read about: its message can hold a query, a path or a value. The invoker reports it
 * through its reporter and answers with one generic internal error, the same on every surface.
 *
 * WordPress itself would catch the exception of an ability's callback and return its message to
 * `wp-abilities/v1` clients; the invoker never lets it get that far.
 *
 * @since 0.1.0
 */
final class UnexpectedFailureTest extends WP_UnitTestCase {

	/**
	 * What the failing service says, which no client may read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LEAK = 'SELECT secret FROM wp_users WHERE token = 42';

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
	 * Tests that every surface answers with the generic internal error, and the reporter gets the exception.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unexpected_exception_reaches_no_client_and_is_reported(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );

		wp_set_current_user( $user->ID );

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$surfaces = new OperationSurfaces(
			$registry,
			new class() {

				/**
				 * Fails the way a programming error or a broken dependency does.
				 *
				 * @throws \RuntimeException Always.
				 *
				 * @param array<string, mixed> $input The input.
				 * @return never
				 */
				public function adjust( array $input ): never {
					throw new \RuntimeException( 'SELECT secret FROM wp_users WHERE token = ' . (int) $input['delta'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the leak this test plants.
				}
			}
		);

		$outcomes = $surfaces->everywhere(
			FixtureStockOperation::definition(),
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => 42,
			)
		);

		foreach ( $outcomes as $surface => $outcome ) {
			$this->assertStringNotContainsString( 'SELECT', $outcome['text'], "{$surface}: the exception's message reached the client." );
			$this->assertStringContainsString( OperationInvoker::INTERNAL_ERROR, $outcome['text'], "{$surface}: the generic internal error is missing." );
		}

		$this->assertSame( 500, $outcomes['rest']['result']->get_status() );
		$this->assertSame( OperationInvoker::INTERNAL_ERROR, $outcomes['rest']['result']->get_data()['code'] );

		$ability = $outcomes['ability']['result'];

		$this->assertInstanceOf( WP_Error::class, $ability );
		$this->assertSame( OperationInvoker::INTERNAL_ERROR, $ability->get_error_code() );
		$this->assertSame( array( 'status' => 500 ), $ability->get_error_data() );

		$this->assertNull( $outcomes['cli']['result']['printed'] );
		$this->assertStringStartsWith( OperationInvoker::INTERNAL_ERROR . ': ', (string) $outcomes['cli']['result']['failure'] );

		$this->assertCount( 3, $surfaces->reported, 'Each surface reports the exception once.' );

		foreach ( $surfaces->reported as $failure ) {
			$this->assertInstanceOf( \RuntimeException::class, $failure );
			$this->assertSame( self::LEAK, $failure->getMessage() );
		}
	}
}
