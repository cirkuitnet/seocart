<?php
/**
 * Tests the stock adjustment on its REST route, its ability and its command, against real stock tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Rest\CachePolicy;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `inventory.adjust_stock` through the three surfaces its one declaration is compiled into.
 *
 * The adapters are wired as the kernel wires them, over the stock service on real tables, so each
 * success is a real ledger entry. The plugin's roles are installed as activation installs them,
 * and the roles option is put back afterwards. Every user is created for the test and deleted
 * again, and is never a super admin.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class AdjustStockOperationTest extends StockTestCase {

	use CreatesUsers;
	use ReloadsRoles;

	/**
	 * The keys of the stock level every surface answers with, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const STOCK_LEVEL = array( 'variant_id', 'on_hand', 'allocated', 'held', 'available', 'ledger_entry_id' );

	/**
	 * The three surfaces, wired for the adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * The roles option as it was before the test installed the plugin's roles.
	 *
	 * @since 0.1.0
	 *
	 * @var mixed
	 */
	private mixed $roles = null;

	/**
	 * Installs the plugin's roles and wires the adjustment on every surface.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->roles = get_option( self::rolesOption() );

		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		$registry = new OperationRegistry();

		$registry->add( InventoryOperations::ADJUST_STOCK, array( InventoryOperations::class, 'adjustStock' ) );

		$this->surfaces = new OperationSurfaces( $registry, $this->service );
	}

	/**
	 * Deletes the users, puts the roles back and discards the surfaces.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		$this->deleteCreatedUsers();

		update_option( self::rolesOption(), $this->roles );

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that one input on the route, the ability and the command each adjusts once and answers with the same stock level.
	 *
	 * @since 0.1.0
	 */
	public function test_one_declaration_adjusts_the_same_way_on_every_surface(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$admin   = $this->createUser( 'administrator' );

		$this->stockItem( $variant, 10 );
		wp_set_current_user( $admin );

		$outcomes = $this->surfaces->everywhere(
			InventoryOperations::adjustStock(),
			array(
				'variant_id' => $variant,
				'delta'      => 5,
				'reason'     => 'received',
			)
		);

		$rest    = $outcomes['rest']['result'];
		$ability = $outcomes['ability']['result'];
		$command = $outcomes['cli']['result'];

		$this->assertInstanceOf( WP_REST_Response::class, $rest );
		$this->assertSame( 200, $rest->get_status(), $outcomes['rest']['text'] );
		$this->assertIsArray( $ability, $outcomes['ability']['text'] );
		$this->assertNull( $command['failure'] );

		$answers = array( $rest->get_data(), $ability, $command['printed']['item'] ?? array() );

		foreach ( $answers as $answer ) {
			$this->assertSame( self::STOCK_LEVEL, array_keys( $answer ), 'Every surface answers with the same stock level shape.' );
		}

		$this->assertSame( array( 15, 20, 25 ), array_column( $answers, 'on_hand' ), 'Each surface adjusted once, in turn.' );
		$this->assertSame( array( 15, 20, 25 ), array_column( $answers, 'available' ) );

		$ledger = $this->committedLedger( $b, $variant );

		$this->assertCount( 4, $ledger, 'The entry that stocked the item, then exactly one per surface.' );
		$this->assertSame( array( '5', '5', '5' ), array_column( array_slice( $ledger, 1 ), 'delta' ) );
		$this->assertSame( array_map( 'strval', array_column( $answers, 'ledger_entry_id' ) ), array_column( array_slice( $ledger, 1 ), 'id' ) );
		$this->assertSame( array( 'user', 'user', 'system' ), array_column( array_slice( $ledger, 1 ), 'actor_type' ), 'The command acts as a system actor on the user\'s authority.' );
		$this->assertSame( array( (string) $admin, (string) $admin, (string) $admin ), array_column( array_slice( $ledger, 1 ), 'actor_id' ) );
		$this->assertSame( array(), $this->surfaces->reported, 'An expected outcome was reported as an unexpected failure.' );
	}

	/**
	 * Tests that the variant comes from the URL only: a variant id in the query or the body is refused, and nothing is adjusted.
	 *
	 * Planted violations: in RestAdapter::arguments(), drop the URL-only validation callback (the
	 * route then answers 200 and adjusts the variant in the URL); and, together with that, have
	 * RestAdapter::respond() read route parameters with WP_REST_Request::get_param(), whose query
	 * and body values win over the URL (the route then adjusts the variant in the query).
	 *
	 * @since 0.1.0
	 */
	public function test_the_variant_comes_from_the_url_only(): void {
		$in_url   = self::variant();
		$in_query = self::variant();
		$b        = $this->secondConnection();

		$this->stockItem( $in_url, 10 );
		$this->stockItem( $in_query, 10 );
		wp_set_current_user( $this->createUser( 'administrator' ) );

		$route   = '/stock-items/' . $in_url . '/adjustments';
		$answers = array(
			'query'      => $this->surfaces->rest( 'POST', $route, self::input( 5 ), array( 'variant_id' => $in_query ) ),
			'body'       => $this->surfaces->rest( 'POST', $route, array( 'variant_id' => $in_query ) + self::input( 5 ) ),
			'body, same' => $this->surfaces->rest( 'POST', $route, array( 'variant_id' => $in_url ) + self::input( 5 ) ),
		);

		$this->assertSame( 10, $this->committedItem( $b, $in_query )['on_hand'] ?? null, 'The variant named in the query or the body was adjusted.' );
		$this->assertSame( 10, $this->committedItem( $b, $in_url )['on_hand'] ?? null, 'The variant in the URL was adjusted by a request that also named a variant elsewhere.' );

		foreach ( array( $in_url, $in_query ) as $variant ) {
			$this->assertCount( 1, $this->committedLedger( $b, $variant ) );
		}

		foreach ( $answers as $where => $response ) {
			$this->assertSame( 400, $response->get_status(), "A variant id in the {$where} was not refused." );
			$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		}

		$this->assertSame( 200, $this->surfaces->rest( 'POST', $route, self::input( 5 ) )->get_status(), 'The URL alone is accepted.' );
		$this->assertSame( 15, $this->committedItem( $b, $in_url )['on_hand'] ?? null );
	}

	/**
	 * Tests that a user without the capability is refused on every surface, and that the roles holding it may adjust.
	 *
	 * The route answers 403, the command fails naming the capability, and the ability answers with
	 * the refusal the Abilities API gives for every ability whose permission check says no.
	 *
	 * Planted violation: in Authorizer::allows(), return true for a primitive, the one check the
	 * route, the ability, the command and the service all make. Every refusal then goes through.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_without_the_capability_is_refused_everywhere(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 10 );

		foreach ( array( 'seocart_order_agent', 'subscriber' ) as $role ) {
			wp_set_current_user( $this->createUser( $role ) );

			$outcomes = $this->surfaces->everywhere( InventoryOperations::adjustStock(), array( 'variant_id' => $variant ) + self::input( -5 ) );

			$this->assertSame( 403, $outcomes['rest']['result']->get_status(), "{$role}: " . $outcomes['rest']['text'] );
			$this->assertSame( 'rest_forbidden', $outcomes['rest']['result']->as_error()->get_error_code() );
			$this->assertInstanceOf( WP_Error::class, $outcomes['ability']['result'] );
			$this->assertSame( 'ability_invalid_permissions', $outcomes['ability']['result']->get_error_code() );
			$this->assertStringStartsWith( 'rest_forbidden: ', (string) $outcomes['cli']['result']['failure'] );
			$this->assertStringContainsString( InventoryOperations::CAPABILITY, (string) $outcomes['cli']['result']['failure'] );
		}

		$this->assertSame( 10, $this->committedItem( $b, $variant )['on_hand'] ?? null, 'A refused user adjusted stock.' );
		$this->assertCount( 1, $this->committedLedger( $b, $variant ) );

		wp_set_current_user( $this->createUser( 'seocart_manager' ) );

		$this->assertSame( 200, $this->surfaces->rest( 'POST', '/stock-items/' . $variant . '/adjustments', self::input( -5 ) )->get_status(), 'The store manager may adjust stock.' );

		wp_set_current_user( $this->createUser( 'seocart_catalog_editor' ) );

		$this->assertNull( $this->surfaces->cli( 'seocart stock adjust', array( (string) $variant ), array_map( 'strval', self::input( -2 ) ) )['failure'], 'The catalog editor may adjust stock.' );
		$this->assertSame( 3, $this->committedItem( $b, $variant )['on_hand'] ?? null );
	}

	/**
	 * Tests that the service checks the actor itself, whoever calls it.
	 *
	 * Planted violation: in StockService::adjustStock(), remove the authorize() call. A caller that
	 * skipped the surface's permission check would then adjust stock as anyone.
	 *
	 * @since 0.1.0
	 */
	public function test_the_service_authorizes_the_actor_itself(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 10 );

		try {
			$this->service->adjustStock( array( 'variant_id' => $variant ) + self::input( -5 ), Actor::user( $this->createUser( 'subscriber' ) ) );
			$this->fail( 'The service adjusted stock for a user without the capability.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( AuthorizationError::Denied, $refused->errorCode() );
		}

		$this->assertSame( 10, $this->committedItem( $b, $variant )['on_hand'] ?? null );
	}

	/**
	 * Tests that each refusal of the service is the same code on every surface, with its status and details on REST, and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_every_refusal_is_the_same_code_everywhere(): void {
		$variant = self::variant();
		$missing = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 5 );
		wp_set_current_user( $this->createUser( 'administrator' ) );

		$cases = array(
			'stock.on_hand_conflict'      => array( 409, array( 'variant_id' => $variant ) + array( 'expected_on_hand' => 4 ) + self::input( 1 ) ),
			'stock.adjustment_below_zero' => array( 409, array( 'variant_id' => $variant ) + self::input( -6 ) ),
			'stock.item_missing'          => array( 404, array( 'variant_id' => $missing ) + self::input( 1 ) ),
			'stock.zero_delta'            => array( 400, array( 'variant_id' => $variant ) + self::input( 0 ) ),
		);

		foreach ( $cases as $code => $case ) {
			$outcomes = $this->surfaces->everywhere( InventoryOperations::adjustStock(), $case[1] );

			$this->assertSame( $case[0], $outcomes['rest']['result']->get_status(), "{$code}: " . $outcomes['rest']['text'] );
			$this->assertSame( $code, $outcomes['rest']['result']->as_error()->get_error_code() );
			$this->assertInstanceOf( WP_Error::class, $outcomes['ability']['result'], $outcomes['ability']['text'] );
			$this->assertSame( $code, $outcomes['ability']['result']->get_error_code() );
			$this->assertStringStartsWith( $code . ': ', (string) $outcomes['cli']['result']['failure'] );
		}

		$conflict = $this->surfaces->rest( 'POST', '/stock-items/' . $variant . '/adjustments', array( 'expected_on_hand' => 4 ) + self::input( 1 ) )->get_data();

		$this->assertSame( 5, $conflict['data']['details']->on_hand ?? null, 'The conflict tells the client the units on hand now.' );
		$this->assertSame( 4, $conflict['data']['details']->expected ?? null );
		$this->assertSame( 5, $this->committedItem( $b, $variant )['on_hand'] ?? null, 'A refusal adjusted stock.' );
		$this->assertCount( 1, $this->committedLedger( $b, $variant ) );
		$this->assertSame( array(), $this->surfaces->reported, 'A refusal the client caused was reported as an unexpected failure.' );
	}

	/**
	 * Tests that the reasons a client may give are exactly the merchant's, and a system reason is refused by the schema.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_merchant_reasons_are_accepted(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 5 );
		wp_set_current_user( $this->createUser( 'administrator' ) );

		$response = $this->surfaces->rest( 'POST', '/stock-items/' . $variant . '/adjustments', array( 'reason' => 'variant_deleted' ) + self::input( -5 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );

		$options = rest_do_request( new WP_REST_Request( 'OPTIONS', '/seocart/v1/stock-items/' . $variant . '/adjustments' ) )->get_data();

		$this->assertSame( array( 'received', 'recount', 'damaged', 'returned', 'correction' ), $options['endpoints'][0]['args']['reason']['enum'] ?? null );
		$this->assertSame( InventoryOperations::RESOURCE, $options['schema']['title'] ?? null );
		$this->assertSame( self::STOCK_LEVEL, array_keys( $options['schema']['properties'] ?? array() ) );
	}

	/**
	 * Tests that the answer is never cached: `Cache-Control: no-store, private`, on a success and on a refusal.
	 *
	 * @since 0.1.0
	 */
	public function test_the_answer_is_never_cached(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 5 );
		wp_set_current_user( $this->createUser( 'administrator' ) );

		foreach ( array( self::input( 1 ), self::input( -99 ) ) as $input ) {
			$response = $this->surfaces->rest( 'POST', '/stock-items/' . $variant . '/adjustments', $input );

			$this->assertSame( CachePolicy::CACHE_CONTROL, $response->get_headers()['Cache-Control'] ?? null );
		}
	}

	/**
	 * Returns a change and a reason, as a client sends them.
	 *
	 * @since 0.1.0
	 *
	 * @param int $delta The change.
	 * @return array{delta: int, reason: string} The input.
	 */
	private static function input( int $delta ): array {
		return array(
			'delta'  => $delta,
			'reason' => $delta < 0 ? 'damaged' : 'received',
		);
	}

	/**
	 * Returns the name of the current site's roles option.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `wptests_user_roles`.
	 */
	private static function rolesOption(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}
