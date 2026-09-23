<?php
/**
 * Tests that every surface hands the service the actor it acts for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * The REST route and the ability act for the current user, `Actor::user()`; the command acts for
 * the user WP-CLI runs as, `Actor::system( 'cli', … )`. Without `--user` WP-CLI runs as no one: a
 * command that changes the store then refuses to run and says how to fix it, and a read-only one
 * is left to the permission check.
 *
 * @since 0.1.0
 */
final class ActorTest extends WP_UnitTestCase {

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
	 * Tests the actor each surface hands the service.
	 *
	 * @since 0.1.0
	 */
	public function test_each_surface_hands_the_service_its_actor(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );

		wp_set_current_user( $user->ID );

		$surfaces = new OperationSurfaces( self::registry() );

		$surfaces->everywhere(
			FixtureStockOperation::definition(),
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => 1,
			)
		);

		$this->assertSame(
			array(
				array( $user->ID, null ),
				array( $user->ID, null ),
				array( $user->ID, 'cli' ),
			),
			array_map(
				static fn( $actor ): array => array( $actor->userId(), $actor->systemName() ),
				$surfaces->service->actors
			),
			'REST and the ability act for the current user in person; the command as the process `cli` on that user\'s authority.'
		);
	}

	/**
	 * Tests that the personal-data fields of an output follow the actor's capability, not the logged-in user's.
	 *
	 * @since 0.1.0
	 */
	public function test_personal_data_follows_the_actor(): void {
		$may_see = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$may_see->add_cap( 'seocart_manage_inventory' );
		$may_see->add_cap( 'seocart_view_customer_pii' );

		$may_not = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$may_not->add_cap( 'seocart_manage_inventory' );

		$surfaces  = new OperationSurfaces( self::registry() );
		$operation = new CompiledOperation( FixtureStockOperation::definition() );
		$input     = $surfaces->invoker()->prepare(
			$operation,
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => 1,
				'note'    => 'Call Ada Lovelace.',
			)
		);

		$this->assertIsArray( $input );

		wp_set_current_user( $may_not->ID );

		$for_actor_who_may = $surfaces->invoker()->invoke( $operation, $input, Actor::user( $may_see->ID ) );

		wp_set_current_user( $may_see->ID );

		$for_actor_who_may_not = $surfaces->invoker()->invoke( $operation, $input, Actor::user( $may_not->ID ) );

		$this->assertIsArray( $for_actor_who_may );
		$this->assertIsArray( $for_actor_who_may_not );
		$this->assertSame( 'Call Ada Lovelace.', $for_actor_who_may['note'] ?? null, 'The actor may see personal data; the logged-in user may not, and does not decide.' );
		$this->assertArrayNotHasKey( 'note', $for_actor_who_may_not, 'The actor may not see personal data; the logged-in user may, and does not decide.' );
	}

	/**
	 * Tests that a command that changes the store refuses to run without a user, and says how to run it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_changing_command_refuses_to_run_without_a_user(): void {
		wp_set_current_user( 0 );

		$surfaces = new OperationSurfaces( self::registry() );
		$outcome  = $surfaces->cli( FixtureStockOperation::COMMAND, array( FixtureStockOperation::EXAMPLE_ITEM ), array( 'delta' => '1' ) );

		$this->assertSame( 'rest_forbidden: This command changes the store, so it runs only as a user. Run it with --user=<id|login|email> for a user who holds the capability seocart_manage_inventory.', $outcome['failure'] );
		$this->assertNull( $outcome['printed'] );
		$this->assertSame( array(), $surfaces->service->calls );
	}

	/**
	 * Tests that a read-only command without a user is refused by the permission check, like a visitor.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_only_command_without_a_user_is_left_to_the_permission_check(): void {
		wp_set_current_user( 0 );

		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.count_stock', array( self::class, 'readOnlyDefinition' ) );

		$surfaces = new OperationSurfaces( $registry );
		$outcome  = $surfaces->cli( 'seocart fixture-stock count', array( FixtureStockOperation::EXAMPLE_ITEM ), array( 'delta' => '0' ) );

		$this->assertSame( 'rest_forbidden: The current user may not run this command, which requires the capability seocart_manage_inventory. Run it as a user who has it, with --user.', $outcome['failure'] );
		$this->assertSame( array(), $surfaces->service->calls );
	}

	/**
	 * Declares a read-only variant of the fixture with a command.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function readOnlyDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.count_stock',
			label: $fixture->label(),
			summary: 'Counts the stock of one fixture item.',
			input: $fixture->input(),
			output: $fixture->output(),
			capability: $fixture->capability(),
			resource_field: null,
			errors: $fixture->errors(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: $fixture->service(),
			cli: new CliBinding( array( 'fixture-stock', 'count' ), array( 'item_id' ) )
		);
	}

	/**
	 * Returns a registry holding the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationRegistry The registry.
	 */
	private static function registry(): OperationRegistry {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		return $registry;
	}
}
