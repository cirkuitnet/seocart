<?php
/**
 * Tests that the REST route, the ability and the command of one operation behave as one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_Error;
use WP_UnitTestCase;

/**
 * The fixture operation driven through all three surfaces with the same input, for the same user.
 *
 * Each surface receives the input in its own form — a JSON body and a URL segment, an ability
 * input object, command-line text — and each must come to the same outcome: the same validation
 * failure for the same bad input, the same permission decision for the same user, the same error
 * code for the same failure, and the same output for the same success. Each surface adjusts its
 * own item, so the three start from the same stock level.
 *
 * @since 0.1.0
 */
final class SurfaceParityTest extends WP_UnitTestCase {

	/**
	 * The item each surface adjusts, keyed by surface.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ITEMS = array(
		'rest'    => 'aaaaaaaa-0000-4000-8000-000000000001',
		'ability' => 'aaaaaaaa-0000-4000-8000-000000000002',
		'cli'     => 'aaaaaaaa-0000-4000-8000-000000000003',
	);

	/**
	 * The three surfaces, wired for the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Registers the fixture on every surface.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$this->surfaces = new OperationSurfaces( $registry );
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
	 * Tests that a success has the same output on every surface, without personal data or secrets
	 * for a user who may not see personal data.
	 *
	 * @since 0.1.0
	 */
	public function test_a_success_has_the_same_output_everywhere(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_inventory' ) ) );

		$outcomes = $this->everywhere(
			array(
				'delta' => 2,
				'note'  => 'Found two.',
			)
		);

		foreach ( $outcomes as $surface => $outcome ) {
			$this->assertSame(
				array(
					'item_id' => self::ITEMS[ $surface ],
					'on_hand' => FixtureStockService::INITIAL_LEVEL + 2,
					'reason'  => 'correction',
				),
				$outcome,
				"{$surface}: the output is not the declared public fields, with the default reason."
			);
		}
	}

	/**
	 * Tests that a user who may see personal data gets the note on every surface, and never the secret.
	 *
	 * @since 0.1.0
	 */
	public function test_personal_data_is_returned_everywhere_to_a_user_who_may_see_it(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_inventory', 'seocart_view_customer_pii' ) ) );

		foreach ( $this->everywhere( array( 'delta' => 2 ) + array( 'note' => 'Found two.' ) ) as $surface => $outcome ) {
			$this->assertIsArray( $outcome, $surface );
			$this->assertSame( array( 'item_id', 'on_hand', 'reason', 'note' ), array_keys( $outcome ), $surface );
			$this->assertSame( 'Found two.', $outcome['note'], $surface );
		}
	}

	/**
	 * Tests that every surface gives the service the same typed input: integers as integers, defaults filled in.
	 *
	 * @since 0.1.0
	 */
	public function test_every_surface_gives_the_service_the_same_input(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_inventory' ) ) );

		$this->everywhere( array( 'delta' => '2' ) );

		$this->assertCount( 3, $this->surfaces->service->calls );

		foreach ( $this->surfaces->service->calls as $index => $input ) {
			$this->assertSame( array_values( self::ITEMS )[ $index ], $input['item_id'] );
			$this->assertSame( 2, $input['delta'], 'A number sent as text reaches the service as an integer.' );
			$this->assertSame( 'correction', $input['reason'], 'The declared default is filled in.' );
			$this->assertArrayNotHasKey( 'note', $input, 'An absent optional input without a default stays absent.' );
		}
	}

	/**
	 * Provides bad input, the field it is wrong in, and the validator's sentence about it, or null
	 * when the REST API words that failure differently from the other two.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, string, string|null}> Input, field, sentence.
	 */
	public static function badInput(): array {
		return array(
			'delta is not a number' => array( array( 'delta' => 'abc' ), 'delta', 'delta is not of type integer.' ),
			'delta is out of range' => array( array( 'delta' => 2000000 ), 'delta', 'delta must be between -1000000 (inclusive) and 1000000 (inclusive)' ),
			'reason is not allowed' => array(
				array(
					'delta'  => 1,
					'reason' => 'theft',
				),
				'reason',
				'reason is not one of recount, damage, return, and correction.',
			),
			'note is too long'      => array(
				array(
					'delta' => 1,
					'note'  => str_repeat( 'x', 501 ),
				),
				'note',
				'note must be at most 500 characters long.',
			),
			'item id is not a uuid' => array(
				array(
					'delta'   => 1,
					'item_id' => 'item-42',
				),
				'item_id',
				'item_id is not a valid UUID.',
			),
			'delta is missing'      => array( array(), 'delta', null ),
		);
	}

	/**
	 * Tests that the same bad input is refused on every surface for the same reason, before the service runs.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider badInput
	 *
	 * @param array<string, mixed> $input    The input.
	 * @param string               $field    The field it is wrong in.
	 * @param string|null          $sentence What WordPress's validator says about it, on every surface.
	 */
	public function test_the_same_bad_input_is_refused_everywhere( array $input, string $field, ?string $sentence ): void {
		wp_set_current_user( self::user( array( 'seocart_manage_inventory' ) ) );

		$outcomes = $this->everywhere( $input );

		$this->assertSame( array(), $this->surfaces->service->calls, 'A surface called the service with bad input.' );
		$this->assertSame( 400, $outcomes['rest']['status'] );
		$this->assertContains( $outcomes['rest']['code'], array( 'rest_invalid_param', 'rest_missing_callback_param' ) );
		$this->assertSame( 'ability_invalid_input', $outcomes['ability']['code'] );
		$this->assertStringStartsWith( 'rest_', $outcomes['cli']['code'] );

		foreach ( $outcomes as $surface => $outcome ) {
			$this->assertStringContainsString( $field, $outcome['message'], "{$surface}: the refusal does not name the field." );

			if ( null !== $sentence ) {
				$this->assertStringContainsString( $sentence, str_replace( array( 'input[' . $field . ']' ), $field, $outcome['message'] ), "{$surface}: the refusal is not the validator's sentence." );
			}
		}
	}

	/**
	 * Tests that bad input is refused as bad input even for a user who may not run the operation:
	 * every surface validates before it checks the permission.
	 *
	 * @since 0.1.0
	 */
	public function test_every_surface_validates_before_it_checks_the_permission(): void {
		wp_set_current_user( self::user( array() ) );

		$outcomes = $this->everywhere( array( 'delta' => 'abc' ) );

		$this->assertSame( 'rest_invalid_param', $outcomes['rest']['code'] );
		$this->assertSame( 'ability_invalid_input', $outcomes['ability']['code'] );
		$this->assertSame( 'rest_invalid_type', $outcomes['cli']['code'] );
	}

	/**
	 * Provides users who may not run the operation.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{bool, int}> Whether the user is logged in, and the REST status.
	 */
	public static function refusedUsers(): array {
		return array(
			'a logged-in user without the capability' => array( true, 403 ),
			'a visitor who is not logged in'          => array( false, 401 ),
		);
	}

	/**
	 * Tests that the same user is refused on every surface, and the service never runs.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedUsers
	 *
	 * @param bool $logged_in   Whether the user is logged in.
	 * @param int  $rest_status The status the REST API answers with.
	 */
	public function test_the_same_user_is_refused_everywhere( bool $logged_in, int $rest_status ): void {
		wp_set_current_user( $logged_in ? self::user( array( 'seocart_view_customer_pii' ) ) : 0 );

		$outcomes = $this->everywhere( array( 'delta' => 1 ) );

		$this->assertSame( array(), $this->surfaces->service->calls );
		$this->assertSame( $rest_status, $outcomes['rest']['status'] );
		$this->assertSame( 'rest_forbidden', $outcomes['rest']['code'] );
		$this->assertSame( 'ability_invalid_permissions', $outcomes['ability']['code'] );
		$this->assertSame( 'rest_forbidden', $outcomes['cli']['code'] );
		$this->assertStringContainsString( 'seocart_manage_inventory', $outcomes['cli']['message'] );
	}

	/**
	 * Tests that the same failure is reported with the same code everywhere.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_failure_has_the_same_code_everywhere(): void {
		wp_set_current_user( self::user( array( 'seocart_manage_inventory' ) ) );

		$outcomes = $this->everywhere( array( 'delta' => -10 ) );
		$message  = 'You asked to remove 10, but only 5 are in stock.';

		$this->assertCount( 3, $this->surfaces->service->calls );
		$this->assertSame(
			array(
				'status'  => 409,
				'code'    => 'fixture_stock.insufficient',
				'message' => $message,
			),
			$outcomes['rest']
		);
		$this->assertSame(
			array(
				'status'  => 409,
				'code'    => 'fixture_stock.insufficient',
				'message' => $message,
			),
			$outcomes['ability']
		);
		$this->assertSame(
			array(
				'status'  => null,
				'code'    => 'fixture_stock.insufficient',
				'message' => $message,
			),
			$outcomes['cli']
		);
	}

	/**
	 * Runs one input on the three surfaces, each for its own item.
	 *
	 * The item id of the input, if given, replaces each surface's own item. A success is the output;
	 * a failure is its status, code and message.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The input, without the item id unless a test sets one.
	 * @return array<string, mixed> The outcome, keyed by surface.
	 */
	private function everywhere( array $input ): array {
		$outcomes = array();

		foreach ( self::ITEMS as $surface => $item ) {
			$item   = (string) ( $input['item_id'] ?? $item );
			$fields = array_diff_key( $input, array( 'item_id' => true ) );

			if ( 'rest' === $surface ) {
				$response = $this->surfaces->rest( 'POST', '/fixture-stock/' . rawurlencode( $item ) . '/adjustments', $fields );
				$data     = $response->get_data();

				$outcomes['rest'] = $response->is_error()
					? array(
						'status'  => $response->get_status(),
						'code'    => $data['code'],
						'message' => trim( $data['message'] . ' ' . implode( ' ', (array) ( $data['data']['params'] ?? array() ) ) ),
					)
					: $data;
			} elseif ( 'ability' === $surface ) {
				$result = $this->surfaces->ability( FixtureStockOperation::ABILITY, array( 'item_id' => $item ) + $fields );

				$outcomes['ability'] = $result instanceof WP_Error
					? array(
						'status'  => $result->get_error_data()['status'] ?? null,
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					)
					: $result;
			} else {
				$cli = $this->surfaces->cli( FixtureStockOperation::COMMAND, array( $item ), array_map( 'strval', $fields ) );

				if ( null === $cli['failure'] ) {
					$this->assertNotNull( $cli['printed'] );
					$this->assertSame( 'table', $cli['printed']['format'] );

					$outcomes['cli'] = $cli['printed']['item'];
				} else {
					list( $code, $message ) = explode( ': ', $cli['failure'], 2 );

					$outcomes['cli'] = array(
						'status'  => null,
						'code'    => $code,
						'message' => $message,
					);
				}
			}
		}

		return $outcomes;
	}

	/**
	 * Creates a user holding the given capabilities and nothing else of the plugin's.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $capabilities The capabilities.
	 * @return int The user id.
	 *
	 * @phpstan-param list<string> $capabilities
	 */
	private static function user( array $capabilities ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}

		return $user_id;
	}
}
