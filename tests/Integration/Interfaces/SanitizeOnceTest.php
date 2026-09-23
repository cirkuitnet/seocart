<?php
/**
 * Tests that the permission check and the service see the same, sanitized input on every surface
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
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\MetaCapabilityResolver;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * WordPress's validator accepts some values its sanitizer then changes: a uuid followed by a line
 * break passes the uuid check and is trimmed, and an integer written as `1e3` passes the integer check
 * and becomes 1000. Were the permission check to see the raw value and the service the sanitized
 * one, they would disagree about which resource the request is for.
 *
 * Two variants of the fixture check the meta capability `seocart_view_order` on their resource —
 * one a uuid, one an integer — through the one map_meta_cap callback and a resolver that records
 * every resource it is asked about and allows only the uuid OWNED and the number 1000. On every
 * surface the resolver must be asked about the sanitized value, and the service must act on the
 * same value.
 *
 * @since 0.1.0
 */
final class SanitizeOnceTest extends WP_UnitTestCase {

	/**
	 * The uuid the resolver allows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OWNED = 'ffffffff-0000-4000-8000-000000000001';

	/**
	 * The number the resolver allows.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const OWNED_NUMBER = 1000;

	/**
	 * The resources the resolver was asked about, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<mixed>
	 */
	private static array $asked = array();

	/**
	 * The input of every service call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $served = array();

	/**
	 * Hooks the mapper with the recording resolver and logs in a user who may view orders.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::$asked  = array();
		self::$served = array();

		$mapper = new CapabilityMapper( new CapabilityDeclaration() );
		$mapper->registerMetaCapability( 'seocart_view_order', array( self::class, 'resolver' ) );

		add_filter( 'map_meta_cap', array( $mapper, 'map' ), 10, 4 );

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_view_orders' );

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
	 * Tests that a uuid followed by a line break is checked as the trimmed uuid the service acts on.
	 *
	 * @since 0.1.0
	 */
	public function test_a_uuid_with_a_line_break_is_checked_as_it_is_acted_on(): void {
		$outcomes = $this->everywhere( 'uuidDefinition', 'item_id', self::OWNED . "\n" );

		$this->assertSame( array( self::OWNED, self::OWNED, self::OWNED ), self::$asked, 'The permission check saw another value than the service acts on.' );
		$this->assertSame( array( self::OWNED, self::OWNED, self::OWNED ), array_column( self::$served, 'item_id' ) );
		$this->assertSucceeded( $outcomes );
	}

	/**
	 * Tests that an integer written as `1e3` is checked as the 1000 the service acts on.
	 *
	 * @since 0.1.0
	 */
	public function test_an_integer_in_exponent_form_is_checked_as_it_is_acted_on(): void {
		$outcomes = $this->everywhere( 'numberDefinition', 'item_number', '1e3' );

		$this->assertSame( array( self::OWNED_NUMBER, self::OWNED_NUMBER, self::OWNED_NUMBER ), self::$asked, 'The permission check saw another value than the service acts on.' );
		$this->assertSame( array( self::OWNED_NUMBER, self::OWNED_NUMBER, self::OWNED_NUMBER ), array_column( self::$served, 'item_number' ) );
		$this->assertSucceeded( $outcomes );
	}

	/**
	 * Declares the fixture's variant that checks `seocart_view_order` on its uuid.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function uuidDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.adjust_owned_stock',
			label: $fixture->label(),
			summary: 'Adjusts the stock level of one fixture item the user may act on.',
			input: $fixture->input(),
			output: $fixture->output(),
			capability: 'seocart_view_order',
			resource_field: 'item_id',
			errors: $fixture->errors(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-owned-stock/{item_id}/adjustments', WriteMethod::Post ),
			ability: 'fixture-adjust-owned-stock',
			cli: new CliBinding( array( 'fixture-owned-stock', 'adjust' ), array( 'item_id' ) )
		);
	}

	/**
	 * Declares the fixture's variant whose resource is a number, checked with `seocart_view_order`.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function numberDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();
		$number  = new FieldSpec(
			name: 'item_number',
			type: FieldType::Integer,
			description: 'Number of the stock item whose level is adjusted.',
			label: static fn(): string => 'Stock item number',
			example: 1000,
			required: true,
			minimum: 1
		);

		return new OperationDefinition(
			id: 'fixture_stock.adjust_numbered_stock',
			label: $fixture->label(),
			summary: 'Adjusts the stock level of one numbered fixture item the user may act on.',
			input: array( $number, $fixture->input()[1] ),
			output: new ResourceSchema( 'FixtureNumberedStock', array( $number ) ),
			capability: 'seocart_view_order',
			resource_field: 'item_number',
			errors: array(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-numbered-stock/{item_number}/adjustments', WriteMethod::Post ),
			ability: 'fixture-adjust-numbered-stock',
			cli: new CliBinding( array( 'fixture-numbered-stock', 'adjust' ), array( 'item_number' ) )
		);
	}

	/**
	 * Builds the recording resolver: OWNED and OWNED_NUMBER need `seocart_view_orders`, anything else cannot be resolved.
	 *
	 * @since 0.1.0
	 *
	 * @return MetaCapabilityResolver The resolver.
	 */
	public static function resolver(): MetaCapabilityResolver {
		return new class() implements MetaCapabilityResolver {

			/**
			 * Returns the primitives a user needs for a resource, after recording it.
			 *
			 * @param int               $userId The user.
			 * @param array<int, mixed> $args   The resource first.
			 * @return list<string>|null The primitives, or null for a resource that cannot be resolved.
			 */
			public function primitivesFor( int $userId, array $args ): ?array {
				$resource = $args[0] ?? null;

				SanitizeOnceTest::recordAsked( $resource );

				return in_array( $resource, array( SanitizeOnceTest::OWNED, SanitizeOnceTest::OWNED_NUMBER ), true ) ? array( 'seocart_view_orders' ) : null;
			}
		};
	}

	/**
	 * Records a resource the resolver was asked about.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The resource.
	 */
	public static function recordAsked( $value ): void {
		self::$asked[] = $value;
	}

	/**
	 * Records the input of a service call.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The input.
	 */
	public static function recordServed( array $input ): void {
		self::$served[] = $input;
	}

	/**
	 * Runs one variant on the three surfaces with one resource value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $factory The method that declares the variant.
	 * @param string $field   The resource field.
	 * @param string $value   The resource value, as a client sends it.
	 * @return array<string, array{result: mixed, text: string}> The outcomes.
	 */
	private function everywhere( string $factory, string $field, string $value ): array {
		$registry   = new OperationRegistry();
		$definition = self::$factory();

		$registry->add( $definition->id(), array( self::class, $factory ) );

		$surfaces = new OperationSurfaces(
			$registry,
			new class() {

				/**
				 * Records the input and answers with the resource and a level.
				 *
				 * @param array<string, mixed> $input The input.
				 * @return array<string, mixed> The result.
				 */
				public function adjust( array $input ): array {
					SanitizeOnceTest::recordServed( $input );

					return $input + array(
						'on_hand' => 5,
						'reason'  => 'correction',
					);
				}
			}
		);

		return $surfaces->everywhere(
			$definition,
			array(
				$field  => $value,
				'delta' => 1,
			)
		);
	}

	/**
	 * Asserts that every surface ran the operation.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{result: mixed, text: string}> $outcomes The outcomes.
	 */
	private function assertSucceeded( array $outcomes ): void {
		$this->assertSame( 200, $outcomes['rest']['result']->get_status(), $outcomes['rest']['text'] );
		$this->assertIsArray( $outcomes['ability']['result'], $outcomes['ability']['text'] );
		$this->assertNull( $outcomes['cli']['result']['failure'], $outcomes['cli']['text'] );
	}
}
