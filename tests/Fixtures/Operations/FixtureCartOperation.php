<?php
/**
 * FixtureCartOperation: Store API operations for the tests of the request policy and the cart token
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

/**
 * Three public operations of the Store API, declared as a cart's would be.
 *
 * - `fixture_cart.add_line`, POST: a write that creates the cart when the request carries no token,
 *   as the first line of a cart does. At most LIMIT per client in a day.
 * - `fixture_cart.change_line`, PATCH: a write to a cart that must already exist.
 * - `fixture_cart.read_cart`, GET: a public read whose service, wrongly, issues a token, so the
 *   tests can show that a read never sends one.
 *
 * FixtureCartService serves all three.
 *
 * @since 0.1.0
 */
final class FixtureCartOperation {

	/**
	 * The id of the write that may create a cart.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADD_LINE = 'fixture_cart.add_line';

	/**
	 * The id of the write to an existing cart.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CHANGE_LINE = 'fixture_cart.change_line';

	/**
	 * The id of the read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const READ_CART = 'fixture_cart.read_cart';

	/**
	 * The route of the two writes, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINES_ROUTE = '/fixture-cart/lines';

	/**
	 * The route of the read, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CART_ROUTE = '/fixture-cart';

	/**
	 * The requests one client may send to the two writes in a window.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 3;

	/**
	 * The window of the limit: a day.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const WINDOW = 86400;

	/**
	 * The bucket the two writes are counted in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BUCKET = 'fixture_cart.write';

	/**
	 * Adds the three operations to a registry.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The registry.
	 */
	public static function register( OperationRegistry $registry ): void {
		$registry->add( self::ADD_LINE, array( self::class, 'addLine' ) );
		$registry->add( self::CHANGE_LINE, array( self::class, 'changeLine' ) );
		$registry->add( self::READ_CART, array( self::class, 'readCart' ) );
	}

	/**
	 * Declares the write that may create a cart.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function addLine(): OperationDefinition {
		return self::definition( self::ADD_LINE, 'addLine', new RestBinding( self::LINES_ROUTE, WriteMethod::Post, store: true ), false );
	}

	/**
	 * Declares the write to an existing cart.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function changeLine(): OperationDefinition {
		return self::definition( self::CHANGE_LINE, 'changeLine', new RestBinding( self::LINES_ROUTE, WriteMethod::Patch, store: true ), true );
	}

	/**
	 * Declares the read.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function readCart(): OperationDefinition {
		return self::definition( self::READ_CART, 'readCart', new RestBinding( self::CART_ROUTE, null, store: true ), null );
	}

	/**
	 * Declares one of the three.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $id            The id.
	 * @param string      $method        The service method.
	 * @param RestBinding $rest          The route.
	 * @param bool|null   $requires_cart For a write, whether its cart must exist; null for the read.
	 * @return OperationDefinition The definition.
	 */
	private static function definition( string $id, string $method, RestBinding $rest, ?bool $requires_cart ): OperationDefinition {
		$write = null !== $requires_cart;

		return new OperationDefinition(
			id: $id,
			label: static fn(): string => 'Fixture cart',
			summary: 'Records a call to the fixture cart and says whether the request carried a cart token.',
			input: $write ? array(
				new FieldSpec(
					name: 'fail',
					type: FieldType::String,
					description: 'Makes the write fail after it has issued a token.',
					label: static fn(): string => 'Fail',
					example: 'no',
					allowed: array( 'no', 'yes' )
				),
			) : array(),
			output: new ResourceSchema(
				'FixtureCart',
				array(
					new FieldSpec( name: 'calls', type: FieldType::Integer, description: 'The calls the service has received.', label: static fn(): string => 'Calls', example: 1, required: true ),
					new FieldSpec( name: 'user_id', type: FieldType::Integer, description: 'The user the call ran for.', label: static fn(): string => 'User', example: 0, required: true ),
					new FieldSpec( name: 'token', type: FieldType::String, description: 'presented, issued or none.', label: static fn(): string => 'Token', example: 'none', required: true ),
				)
			),
			capability: null,
			resource_field: null,
			errors: $write ? array( FixtureStockError::Insufficient ) : array(),
			annotations: new Annotations( read_only: ! $write, destructive: false, idempotent: false ),
			service: array( FixtureCartService::class, $method ),
			rest: $rest,
			public_write: $write ? StoreRequestPolicy::write( self::BUCKET, self::LIMIT, self::WINDOW, (bool) $requires_cart ) : null
		);
	}
}
