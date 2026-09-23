<?php
/**
 * Tests the WP-CLI command an operation is registered as
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * The fixture's command as WP-CLI would be given it, and how it prints and fails.
 *
 * WP-CLI is not loaded in the test suite; the adapter takes WP-CLI's functions as arguments, and
 * OperationSurfaces passes recorders in their place.
 *
 * @since 0.1.0
 */
final class CliAdapterTest extends WP_UnitTestCase {

	/**
	 * An item id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ITEM = 'cccccccc-0000-4000-8000-000000000001';

	/**
	 * The surfaces, wired for the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Registers the fixture and logs in a user who may adjust stock.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$this->surfaces = new OperationSurfaces( $registry );

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
	 * Tests that the command is registered under its name, with the summary and the compiled synopsis.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_is_registered_from_the_declaration(): void {
		$this->assertSame( array( FixtureStockOperation::COMMAND ), array_keys( $this->surfaces->commands ) );
		$this->assertSame(
			array(
				'shortdesc' => FixtureStockOperation::definition()->summary(),
				'synopsis'  => ( new CompiledOperation( FixtureStockOperation::definition() ) )->cliSynopsis(),
			),
			$this->surfaces->commands[ FixtureStockOperation::COMMAND ]['args']
		);
	}

	/**
	 * Tests that the result is printed in the format asked for, table by default.
	 *
	 * @since 0.1.0
	 */
	public function test_the_result_is_printed_in_the_format_asked_for(): void {
		$default = $this->surfaces->cli( FixtureStockOperation::COMMAND, array( self::ITEM ), array( 'delta' => '1' ) );
		$json    = $this->surfaces->cli(
			FixtureStockOperation::COMMAND,
			array( self::ITEM ),
			array(
				'delta'  => '1',
				'format' => 'json',
			)
		);

		$this->assertNull( $default['failure'] );
		$this->assertSame( 'table', $default['printed']['format'] ?? null );
		$this->assertSame( 'json', $json['printed']['format'] ?? null );
		$this->assertSame(
			array(
				'item_id' => self::ITEM,
				'on_hand' => FixtureStockService::INITIAL_LEVEL + 2,
				'reason'  => 'correction',
			),
			$json['printed']['item']
		);
	}

	/**
	 * Tests that an unknown format is refused before anything runs.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_format_is_refused(): void {
		$outcome = $this->surfaces->cli(
			FixtureStockOperation::COMMAND,
			array( self::ITEM ),
			array(
				'delta'  => '1',
				'format' => 'yaml',
			)
		);

		$this->assertSame( 'rest_invalid_param: --format must be one of table, json.', $outcome['failure'] );
		$this->assertNull( $outcome['printed'] );
		$this->assertSame( array(), $this->surfaces->service->calls );
	}

	/**
	 * Tests that a failure is reported as `<code>: <message>` with the correlation id, and nothing is printed.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failure_is_reported_with_its_code(): void {
		$outcome = $this->surfaces->cli( FixtureStockOperation::COMMAND, array( self::ITEM ), array( 'delta' => '-9' ) );

		$this->assertSame( 'fixture_stock.insufficient: You asked to remove 9, but only 5 are in stock. (correlation id: ' . OperationSurfaces::CORRELATION_ID . ')', $outcome['failure'] );
		$this->assertNull( $outcome['printed'] );
	}
}
