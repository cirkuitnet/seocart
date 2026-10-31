<?php
/**
 * Tests the seven transfer operations on their REST routes, abilities and commands, against real tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\TransferOperations;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\Inventory\StockServices;
use SEOCart\Tests\Support\Inventory\TransferTestCase;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_Error;
use WP_REST_Response;

/**
 * The seven transfer operations, and the deactivation's move, each through the three surfaces its one declaration is compiled into.
 *
 * The adapters are wired as the kernel wires them, over the transfer and locations services on
 * real tables. Lines reach the command as a JSON option, as a client writes `--lines=<json>`.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class TransferOperationSurfacesTest extends TransferTestCase {

	use GrantsCapabilities;

	/**
	 * The three surfaces, wired for the operations.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the transfer operations and the deactivation on every surface.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		foreach ( self::transferOperations() as $id => $factory ) {
			$registry->add( $id, array( TransferOperations::class, $factory ) );
		}

		$registry->add( LocationOperations::DEACTIVATE_LOCATION, array( LocationOperations::class, 'deactivateLocation' ) );

		$this->surfaces = new OperationSurfaces(
			$registry,
			null,
			array(
				Transfers::class => $this->transfers,
				Locations::class => StockServices::locations( $this->db, new SequentialIdGenerator( 420000 ), $this->transfers ),
			)
		);
	}

	/**
	 * Discards the surfaces.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that a creation, a change, a send, a receipt in parts, the list and the read answer the same on the route, the ability and the command, each acting once.
	 *
	 * @since 0.2.0
	 */
	public function test_each_transfer_operation_answers_the_same_on_every_surface(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$v      = self::variant();
		$w      = self::variant();

		$this->stockItem( $v, 30, $source );
		$this->stockItem( $w, 30, $source );

		wp_set_current_user( $this->createUser( 'seocart_manager' ) );

		$created = self::answers(
			$this->allThree(
				TransferOperations::createTransfer(),
				array(
					'source_location_uuid'      => $this->uuidOf( $b, $source ),
					'destination_location_uuid' => $this->uuidOf( $b, $this->defaultLocation ),
					'lines'                     => array(
						array(
							'variant_id' => $v,
							'quantity'   => 3,
						),
					),
					'note'                      => 'Restock the shop.',
				)
			)
		);
		$uuids   = array_map( static fn( array $answer ): string => (string) $answer['transfer_uuid'], $created );

		$this->assertCount( 3, array_unique( $uuids ), 'Each surface created a transfer of its own.' );
		$this->assertSame( array( 'draft', 'draft', 'draft' ), array_column( $created, 'state' ) );
		$this->assertSameDocuments( $created );

		$edited = self::answers(
			$this->eachOwn(
				TransferOperations::updateTransfer(),
				$uuids,
				array(
					'lines' => array(
						array(
							'variant_id' => $v,
							'quantity'   => 2,
						),
						array(
							'variant_id' => $w,
							'quantity'   => 1,
						),
					),
				)
			)
		);

		$this->assertSame( array( 'Restock the shop.', 'Restock the shop.', 'Restock the shop.' ), array_column( $edited, 'note' ), 'A change keeps what it is not given.' );
		$this->assertSameDocuments( $edited );

		$sent = self::answers( $this->eachOwn( TransferOperations::sendTransfer(), $uuids, array( 'note' => 'Courier 123' ) ) );

		$this->assertSame( array( 'in_transit', 'in_transit', 'in_transit' ), array_column( $sent, 'state' ) );
		$this->assertSame( array( 24, 27 ), array_map( fn( int $variant ): int => (int) $this->committedItem( $b, $variant, $source )['on_hand'], array( $v, $w ) ), 'Each surface sent its transfer once.' );
		$this->assertSameDocuments( $sent );

		$received = array();

		foreach ( $this->eachOwn( TransferOperations::receiveTransfer(), $uuids, array(), fn( string $uuid ): array => array( 'lines' => array( $this->receiptLine( $sent, $uuid, $v, 2, 0 ) ) ) ) as $surface => $outcome ) {
			$received[ $surface ] = self::answers( array( $surface => $outcome ) )[ $surface ];
		}

		$this->assertSame( array( 'in_transit', 'in_transit', 'in_transit' ), array_column( $received, 'state' ), 'One unit of the second variant is on the way.' );
		$this->assertSame( 6, (int) $this->committedItem( $b, $v )['on_hand'], 'Each surface received two units once.' );
		$this->assertSameDocuments( $received );

		$listed = self::answers( $this->allThree( TransferOperations::listTransfers(), array( 'location_uuid' => $this->uuidOf( $b, $source ) ) ) );

		$this->assertSame( $listed['rest'], $listed['ability'] );
		$this->assertSame( $listed['rest'], $listed['cli'] );
		$this->assertSame( array_reverse( array_values( $uuids ) ), array_column( $listed['rest']['transfers'], 'transfer_uuid' ), 'Newest first.' );
		$this->assertSame( array( 2, 3, 2, 0 ), array( $listed['rest']['transfers'][0]['lines'], $listed['rest']['transfers'][0]['sent'], $listed['rest']['transfers'][0]['received'], $listed['rest']['transfers'][0]['lost'] ) );
		$this->assertSame( array( 1, 20, false ), array( $listed['rest']['page'], $listed['rest']['per_page'], $listed['rest']['has_more'] ) );

		$read = self::answers( $this->allThree( TransferOperations::getTransfer(), array( 'transfer_uuid' => $uuids['cli'] ) ) );

		$this->assertSame( $read['rest'], $read['ability'] );
		$this->assertSame( $read['rest'], $read['cli'] );
		$this->assertSame( $received['cli'], $read['rest'], 'The read answers the document the receipt answered.' );
		$this->assertArrayNotHasKey( 'created_by', $read['rest'], 'Who created a transfer is never answered.' );
		$this->assertSame( array(), $this->surfaces->reported, 'An expected outcome was reported as an unexpected failure.' );
	}

	/**
	 * Tests that a draft naming more than its source has saves on every surface, and that its send is refused on each with the line's figures, nothing sent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_draft_over_the_sources_figure_saves_and_its_send_is_refused(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$v      = self::variant();

		$this->stockItem( $v, 2, $source );

		wp_set_current_user( $this->createUser( 'seocart_manager' ) );

		$uuids = array();

		foreach ( array( 'rest', 'ability', 'cli' ) as $surface ) {
			$uuids[ $surface ] = (string) $this->draft( $source, $this->defaultLocation, array( $v => 5 ) )['transfer_uuid'];
		}

		$outcomes = $this->eachOwn( TransferOperations::sendTransfer(), $uuids, array() );

		$this->assertSame( 409, $outcomes['rest']->get_status() );
		$this->assertSame( 'stock.transfer_refused', $outcomes['rest']->as_error()->get_error_code() );
		$this->assertSame( 'stock.transfer_refused', $outcomes['ability'] instanceof WP_Error ? $outcomes['ability']->get_error_code() : null );
		$this->assertStringStartsWith( 'stock.transfer_refused: ', (string) $outcomes['cli']['failure'] );
		$this->assertSame( 2, (int) $this->committedItem( $b, $v, $source )['on_hand'] );
	}

	/**
	 * Tests that a requested transfer's line carrying an order's units cannot be edited: lines given replace the others only, on every surface.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::DELETE_UNALLOCATED_LINES,
	 * drop `AND allocation_id IS NULL`: an edit removes the order's line, and the order's units are
	 * then promised by no transfer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_line_carrying_an_orders_units_cannot_be_edited(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$v      = self::variant();
		$spare  = self::variant();
		$uuids  = array();

		$this->stockItem( $v, 9, $source );
		$this->stockItem( $spare, 9, $source );

		wp_set_current_user( $this->createUser( 'seocart_manager' ) );

		foreach ( array( 'rest', 'ability', 'cli' ) as $surface ) {
			$order = self::order();

			$this->plantAllocation( $v, $source, $order, count( $uuids ) + 1, 2 );

			$uuids[ $surface ] = $this->requestFor( $order, $this->defaultLocation )[0]->uuid;
		}

		$edited = self::answers(
			$this->eachOwn(
				TransferOperations::updateTransfer(),
				$uuids,
				array(
					'lines' => array(
						array(
							'variant_id' => $spare,
							'quantity'   => 1,
						),
					),
				)
			)
		);

		foreach ( $edited as $surface => $answer ) {
			$this->assertSame(
				array(
					array( $v, 2, true ),
					array( $spare, 1, false ),
				),
				array_map( static fn( array $line ): array => array( $line['variant_id'], $line['quantity'], $line['allocated'] ), $answer['lines'] ),
				"{$surface} edited the order's line."
			);
		}

		$this->assertSame( 'stock.transfer_state', $this->surfaces->rest( 'POST', '/stock-transfers/' . $uuids['rest'] . '/cancellation' )->as_error()->get_error_code(), 'A requested transfer carrying an order\'s units is not cancelled by a merchant.' );
		$this->assertSame( 'carries_order', $this->surfaces->rest( 'POST', '/stock-transfers/' . $uuids['rest'] . '/cancellation' )->get_data()['data']['details']->reason ?? null );
		$this->assertCount( 2, $this->committedLines( $b, $uuids['cli'] ) );
	}

	/**
	 * Tests the capabilities on every surface: whoever manages inventory uses all seven; a user who may manage only the settings uses none; and a deactivation moving stock needs both, refused with nothing changed.
	 *
	 * Planted violation, shown red and removed: in TransferOperations::getTransfer(), require
	 * `seocart_manage_settings`: the catalog editor is refused the read, and the settings manager let through.
	 *
	 * @since 0.2.0
	 */
	public function test_each_surface_checks_the_capability_its_declaration_names(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$target = $this->plantLocation();
		$v      = self::variant();

		$this->stockItem( $v, 5, $source );

		$uuid = (string) $this->draft( $source, $this->defaultLocation, array( $v => 1 ) )['transfer_uuid'];

		wp_set_current_user( $this->createUser( 'seocart_catalog_editor' ) );

		foreach ( array( TransferOperations::listTransfers(), TransferOperations::getTransfer() ) as $definition ) {
			$outcomes = $this->allThree( $definition, TransferOperations::GET_TRANSFER === $definition->id() ? array( 'transfer_uuid' => $uuid ) : array() );

			$this->assertSame( 200, $outcomes['rest']->get_status(), $definition->id() );
			$this->assertIsArray( $outcomes['ability'], $definition->id() );
			$this->assertNull( $outcomes['cli']['failure'], $definition->id() );
		}

		wp_set_current_user( $this->userGranted( LocationOperations::CAPABILITY )->userId() );

		foreach ( self::transferOperations() as $factory ) {
			$definition = TransferOperations::$factory();
			$outcomes   = $this->allThree( $definition, $this->inputFor( $definition, $uuid, $b ) );

			$this->assertSame( 403, $outcomes['rest']->get_status(), $definition->id() );
			$this->assertSame( 'rest_forbidden', $outcomes['rest']->as_error()->get_error_code() );
			$this->assertSame( 'ability_invalid_permissions', $outcomes['ability'] instanceof WP_Error ? $outcomes['ability']->get_error_code() : null, $definition->id() );
			$this->assertStringContainsString( InventoryOperations::CAPABILITY, (string) $outcomes['cli']['failure'] );
		}

		$moved = $this->allThree(
			LocationOperations::deactivateLocation(),
			array(
				'location_uuid' => $this->uuidOf( $b, $source ),
				'move_stock_to' => $this->uuidOf( $b, $target ),
			)
		);

		$this->assertSame( 403, $moved['rest']->get_status() );
		$this->assertSame( 'authorization.denied', $moved['rest']->as_error()->get_error_code() );
		$this->assertSame( 'authorization.denied', $moved['ability'] instanceof WP_Error ? $moved['ability']->get_error_code() : null );
		$this->assertStringStartsWith( 'authorization.denied: ', (string) $moved['cli']['failure'] );
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT active FROM `%s` WHERE id = %d', $this->table( 'locations' ), $source ) ), 'The refused move changed nothing.' );
		$this->assertSame( 'draft', $this->committedTransfer( $b, $uuid )['state'] );
	}

	/**
	 * Tests that the transfer a change, a send, a receipt or a cancel acts on comes from the URL only: a uuid in the query or the body is refused, and nothing changes.
	 *
	 * @since 0.2.0
	 */
	public function test_the_transfer_comes_from_the_url_only(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$v      = self::variant();

		$this->stockItem( $v, 5, $source );

		$inUrl = (string) $this->draft( $source, $this->defaultLocation, array( $v => 1 ) )['transfer_uuid'];
		$other = (string) $this->draft( $source, $this->defaultLocation, array( $v => 1 ) )['transfer_uuid'];

		wp_set_current_user( $this->createUser( 'seocart_manager' ) );

		$routes = array(
			array( 'PATCH', '/stock-transfers/' . $inUrl, array( 'note' => 'Moved' ) ),
			array( 'POST', '/stock-transfers/' . $inUrl . '/dispatch', array() ),
			array( 'POST', '/stock-transfers/' . $inUrl . '/cancellation', array() ),
		);

		foreach ( $routes as list( $method, $route, $body ) ) {
			foreach ( array( 'query', 'body' ) as $where ) {
				$response = 'query' === $where
					? $this->surfaces->rest( $method, $route, $body, array( 'transfer_uuid' => $other ) )
					: $this->surfaces->rest( $method, $route, array( 'transfer_uuid' => $other ) + $body );

				$this->assertSame( 400, $response->get_status(), "{$method} {$route}: a transfer in the {$where} was not refused." );
				$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
			}
		}

		$this->assertSame( array( 'draft', 'draft' ), array( $this->committedTransfer( $b, $inUrl )['state'], $this->committedTransfer( $b, $other )['state'] ) );
		$this->assertNull( $this->committedTransfer( $b, $inUrl )['note'], 'A refused request changed the transfer.' );
	}

	/**
	 * Tests that each refusal of the service is the same code on every surface, with its status, and that a page past the last is refused as bad input everywhere.
	 *
	 * Planted violation, shown red and removed: in TransferOperations::listTransfers(), leave out
	 * the page's maximum: a page past the last is answered, as an empty page, rather than refused.
	 *
	 * @since 0.2.0
	 */
	public function test_every_refusal_is_the_same_code_everywhere(): void {
		$b       = $this->secondConnection();
		$source  = $this->plantLocation();
		$v       = self::variant();
		$missing = wp_generate_uuid4();

		$this->stockItem( $v, 5, $source );

		$sent = $this->draft( $source, $this->defaultLocation, array( $v => 1 ) );
		$uuid = (string) $sent['transfer_uuid'];

		$this->send( $uuid );

		wp_set_current_user( $this->createUser( 'seocart_manager' ) );

		$cases = array(
			array( 'stock.transfer_not_found', 404, TransferOperations::getTransfer(), array( 'transfer_uuid' => $missing ) ),
			array( 'stock.transfer_state', 409, TransferOperations::cancelTransfer(), array( 'transfer_uuid' => $uuid ) ),
			array(
				'stock.transfer_state',
				409,
				TransferOperations::updateTransfer(),
				array(
					'transfer_uuid' => $uuid,
					'note'          => 'Late',
				),
			),
			array(
				'stock.transfer_same_location',
				400,
				TransferOperations::createTransfer(),
				array(
					'source_location_uuid'      => $this->uuidOf( $b, $source ),
					'destination_location_uuid' => $this->uuidOf( $b, $source ),
					'lines'                     => array(
						array(
							'variant_id' => $v,
							'quantity'   => 1,
						),
					),
				),
			),
			array(
				'stock.location_missing',
				404,
				TransferOperations::createTransfer(),
				array(
					'source_location_uuid'      => $missing,
					'destination_location_uuid' => $this->uuidOf( $b, $source ),
					'lines'                     => array(
						array(
							'variant_id' => $v,
							'quantity'   => 1,
						),
					),
				),
			),
			array(
				'stock.item_missing',
				404,
				TransferOperations::createTransfer(),
				array(
					'source_location_uuid'      => $this->uuidOf( $b, $source ),
					'destination_location_uuid' => $this->uuidOf( $b, $this->defaultLocation ),
					'lines'                     => array(
						array(
							'variant_id' => 999999,
							'quantity'   => 1,
						),
					),
				),
			),
			array(
				'stock.transfer_invalid',
				400,
				TransferOperations::receiveTransfer(),
				array(
					'transfer_uuid' => $uuid,
					'lines'         => array( $this->receiptLine( array( 'x' => $sent ), $uuid, $v, 0, 0 ) ),
				),
			),
			array(
				'stock.transfer_receipt_conflict',
				409,
				TransferOperations::receiveTransfer(),
				array(
					'transfer_uuid' => $uuid,
					'lines'         => array(
						array(
							'line_id'                    => self::lineOf( $sent, $v ),
							'received'                   => 1,
							'expected_received_quantity' => 7,
							'expected_lost_quantity'     => 0,
						),
					),
				),
			),
		);

		foreach ( $cases as list( $code, $status, $definition, $input ) ) {
			$outcomes = $this->allThree( $definition, $input );

			$this->assertSame( $status, $outcomes['rest']->get_status(), "{$code}: " . wp_json_encode( $outcomes['rest']->get_data() ) );
			$this->assertSame( $code, $outcomes['rest']->as_error()->get_error_code() );
			$this->assertSame( $code, $outcomes['ability'] instanceof WP_Error ? $outcomes['ability']->get_error_code() : null, $code );
			$this->assertStringStartsWith( $code . ': ', (string) $outcomes['cli']['failure'] );
		}

		// A page past the last one the declaration allows is bad input on every surface, never a failure of the page's offset.
		foreach ( array( TransferOperations::MOST_PAGE + 1, PHP_INT_MAX ) as $page ) {
			$beyond = $this->allThree( TransferOperations::listTransfers(), array( 'page' => $page ) );

			$this->assertSame( array( 400, 'rest_invalid_param', 'ability_invalid_input' ), array( $beyond['rest']->get_status(), $beyond['rest']->as_error()?->get_error_code(), $beyond['ability'] instanceof WP_Error ? $beyond['ability']->get_error_code() : null ), "Page {$page}." );
			$this->assertMatchesRegularExpression( '/^rest_[a-z_]+: /', (string) $beyond['cli']['failure'], "Page {$page}." );
		}

		$this->assertSame( array(), $this->surfaces->reported, 'A refusal the client caused was reported as an unexpected failure.' );
	}

	/**
	 * Tests that, of the seven, only the two reads are exposed to agents, every one has an ability, and the production registry holds all seven.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_two_reads_are_exposed_to_agents(): void {
		$exposed = array();

		foreach ( self::transferOperations() as $id => $factory ) {
			$definition = TransferOperations::$factory();

			$this->assertNotNull( $definition->abilityName(), "{$id} has no ability." );

			$exposed[ $id ] = $definition->isAgentExposed();
		}

		$this->assertSame( array( TransferOperations::LIST_TRANSFERS, TransferOperations::GET_TRANSFER ), array_keys( array_filter( $exposed ) ) );
		$this->assertSame( array(), array_diff( array_keys( $exposed ), array_map( static fn( OperationDefinition $definition ): string => $definition->id(), Operations::registry()->all() ) ), 'The production registry lacks one of the seven.' );
	}

	/**
	 * Runs one input on the three surfaces of one operation: route parameters in the path, the rest in a JSON body; the input object; positional values, and options with lists as JSON.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition  $definition The operation.
	 * @param array<string, mixed> $input      The input, keyed by wire name.
	 * @return array{rest: WP_REST_Response, ability: mixed, cli: array{printed: array{item: array<string, mixed>, format: string}|null, failure: string|null}} Each surface's outcome.
	 */
	private function allThree( OperationDefinition $definition, array $input ): array {
		return array(
			'rest'    => $this->viaRest( $definition, $input ),
			'ability' => $this->surfaces->ability( (string) $definition->abilityName(), $input ),
			'cli'     => $this->viaCli( $definition, $input ),
		);
	}

	/**
	 * Runs an operation on each surface against the surface's own transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition   $definition The operation, on one transfer.
	 * @param array<string, string> $uuids      Each surface's transfer, by surface.
	 * @param array<string, mixed>  $input      The rest of the input.
	 * @param \Closure|null         $inputFor   Optional. Builds the rest of the input for a transfer instead. Default null.
	 * @return array{rest: WP_REST_Response, ability: mixed, cli: array{printed: array{item: array<string, mixed>, format: string}|null, failure: string|null}} Each surface's outcome.
	 *
	 * @phpstan-param (\Closure(string): array<string, mixed>)|null $inputFor
	 */
	private function eachOwn( OperationDefinition $definition, array $uuids, array $input, ?\Closure $inputFor = null ): array {
		$of = static fn( string $uuid ): array => array( 'transfer_uuid' => $uuid ) + ( null === $inputFor ? $input : $inputFor( $uuid ) );

		return array(
			'rest'    => $this->viaRest( $definition, $of( $uuids['rest'] ) ),
			'ability' => $this->surfaces->ability( (string) $definition->abilityName(), $of( $uuids['ability'] ) ),
			'cli'     => $this->viaCli( $definition, $of( $uuids['cli'] ) ),
		);
	}

	/**
	 * Sends an input to an operation's route.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition  $definition The operation.
	 * @param array<string, mixed> $input      The input.
	 * @return WP_REST_Response The response.
	 */
	private function viaRest( OperationDefinition $definition, array $input ): WP_REST_Response {
		$rest = $definition->rest();

		$this->assertNotNull( $rest, $definition->id() . ' has no route.' );

		$route = $rest->route();

		foreach ( $rest->pathParameters() as $name ) {
			$route = str_replace( '{' . $name . '}', (string) $input[ $name ], $route );
			unset( $input[ $name ] );
		}

		return null === $rest->writeMethod()
			? $this->surfaces->rest( 'GET', $route, array(), $input )
			: $this->surfaces->rest( (string) $definition->httpMethod(), $route, $input );
	}

	/**
	 * Runs an input as the operation's command: its positional values, and its options as text, lists as JSON.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition  $definition The operation.
	 * @param array<string, mixed> $input      The input.
	 * @return array{printed: array{item: array<string, mixed>, format: string}|null, failure: string|null} The outcome.
	 */
	private function viaCli( OperationDefinition $definition, array $input ): array {
		$cli  = $definition->cli();
		$args = array();

		$this->assertNotNull( $cli, $definition->id() . ' has no command.' );

		foreach ( $cli->positional() as $name ) {
			$args[] = (string) $input[ $name ];
			unset( $input[ $name ] );
		}

		return $this->surfaces->cli( $cli->command(), $args, array_map( static fn( mixed $value ): string => is_array( $value ) ? (string) wp_json_encode( $value ) : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ), $input ) );
	}

	/**
	 * Returns a receipt line of one variant of a surface's transfer, from what its send answered.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $answers  The answers, by surface.
	 * @param string                              $uuid     The transfer.
	 * @param int                                 $variant  The variant.
	 * @param int                                 $received The units received.
	 * @param int                                 $lost     The units lost.
	 * @return array{line_id: int, received: int, lost: int, expected_received_quantity: int, expected_lost_quantity: int} The line.
	 */
	private function receiptLine( array $answers, string $uuid, int $variant, int $received, int $lost ): array {
		foreach ( $answers as $answer ) {
			if ( $uuid === $answer['transfer_uuid'] ) {
				return array(
					'line_id'                    => self::lineOf( $answer, $variant ),
					'received'                   => $received,
					'lost'                       => $lost,
					'expected_received_quantity' => 0,
					'expected_lost_quantity'     => 0,
				);
			}
		}

		$this->fail( 'No answer names the transfer.' );
	}

	/**
	 * Returns an input each transfer operation accepts, for the capability walk.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition                     $definition The operation.
	 * @param string                                  $uuid       A draft.
	 * @param \SEOCart\Tests\Support\SecondConnection $b      Connection B.
	 * @return array<string, mixed> The input.
	 */
	private function inputFor( OperationDefinition $definition, string $uuid, \SEOCart\Tests\Support\SecondConnection $b ): array {
		$lines = array(
			array(
				'variant_id' => 1,
				'quantity'   => 1,
			),
		);

		return match ( $definition->id() ) {
			TransferOperations::LIST_TRANSFERS   => array(),
			TransferOperations::CREATE_TRANSFER  => array(
				'source_location_uuid'      => $this->uuidOf( $b, $this->defaultLocation ),
				'destination_location_uuid' => wp_generate_uuid4(),
				'lines'                     => $lines,
			),
			TransferOperations::RECEIVE_TRANSFER => array(
				'transfer_uuid' => $uuid,
				'lines'         => array(
					array(
						'line_id'                    => 1,
						'received'                   => 1,
						'expected_received_quantity' => 0,
						'expected_lost_quantity'     => 0,
					),
				),
			),
			default                              => array( 'transfer_uuid' => $uuid ),
		};
	}

	/**
	 * Asserts that the documents the surfaces answered differ only in their own identifiers and times.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $answers The answers, by surface.
	 */
	private function assertSameDocuments( array $answers ): void {
		$alike = array_map(
			static function ( array $answer ): array {
				unset( $answer['transfer_uuid'], $answer['created_at'], $answer['sent_at'] );

				$answer['lines'] = array_map(
					static function ( array $line ): array {
						unset( $line['line_id'] );

						return $line;
					},
					(array) $answer['lines']
				);

				return $answer;
			},
			$answers
		);

		$this->assertSame( $alike['rest'], $alike['ability'] );
		$this->assertSame( $alike['rest'], $alike['cli'] );
	}

	/**
	 * Returns what each surface answered, failing the test when one refused.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $outcomes Each surface's outcome.
	 * @return array<string, array<string, mixed>> The answers, keyed by surface.
	 */
	private static function answers( array $outcomes ): array {
		$answers = array();

		foreach ( $outcomes as $surface => $result ) {
			if ( $result instanceof WP_REST_Response ) {
				self::assertSame( 200, $result->get_status(), "{$surface}: " . wp_json_encode( $result->get_data() ) );

				$result = $result->get_data();
			} elseif ( is_array( $result ) && array_key_exists( 'printed', $result ) ) {
				self::assertNull( $result['failure'], "{$surface}: " . (string) $result['failure'] );

				$result = $result['printed']['item'] ?? null;
			}

			self::assertIsArray( $result, "{$surface}: " . ( $result instanceof WP_Error ? $result->get_error_message() : wp_json_encode( $result ) ) );

			$answers[ $surface ] = $result;
		}

		return $answers;
	}

	/**
	 * Returns the seven transfer operations: each id with the factory that declares it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The factories, by operation id.
	 */
	private static function transferOperations(): array {
		return array(
			TransferOperations::LIST_TRANSFERS   => 'listTransfers',
			TransferOperations::GET_TRANSFER     => 'getTransfer',
			TransferOperations::CREATE_TRANSFER  => 'createTransfer',
			TransferOperations::UPDATE_TRANSFER  => 'updateTransfer',
			TransferOperations::SEND_TRANSFER    => 'sendTransfer',
			TransferOperations::RECEIVE_TRANSFER => 'receiveTransfer',
			TransferOperations::CANCEL_TRANSFER  => 'cancelTransfer',
		);
	}
}
