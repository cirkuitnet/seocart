<?php
/**
 * Tests the declaration of public operations: the Store API's reads and writes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Cart\Interfaces\StoreApi\StoreApiError;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Platform\RateLimiter\RateLimit;
use SEOCart\Support\Schema\SchemaException;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;

/**
 * A public operation requires no capability, so its declaration carries the rules that replace one.
 *
 * Every variant starts from a public write of the fixture — its route in the Store API's namespace,
 * no capability, no ability or command, and a PublicWrite built by the Store API's policy — and
 * changes one thing.
 *
 * @since 0.1.0
 */
final class PublicOperationsTest extends TestCase {

	/**
	 * Tests that a public write is carried with its PublicWrite, and its policy's refusals among its codes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_public_write_carries_its_policy_and_its_refusals(): void {
		$definition = self::publicVariant( array() );

		$this->assertTrue( $definition->isPublic() );
		$this->assertNull( $definition->capability() );
		$this->assertSame( 'POST', $definition->httpMethod() );
		$this->assertSame( RestBinding::STORE_NAMESPACE, $definition->rest()?->restNamespace() );
		$this->assertSame( 'fixture.write', $definition->publicWrite()?->rateLimit()->bucket() );
		$this->assertTrue( $definition->publicWrite()->requiresCart() );
		$this->assertSame(
			array( FixtureStockError::Insufficient, StoreApiError::ReadMethod, StoreApiError::HeaderMissing, StoreApiError::NonceMissing, StoreApiError::CartTokenMissing, StoreApiError::RateLimited ),
			$definition->errors(),
			'The declared codes come first, then the refusals of the Store API\'s policy.'
		);
	}

	/**
	 * Tests that a write needing no existing cart cannot be refused for a missing token.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_that_may_create_a_cart_has_no_token_refusal(): void {
		$this->assertSame(
			array( StoreApiError::ReadMethod, StoreApiError::HeaderMissing, StoreApiError::NonceMissing, StoreApiError::RateLimited ),
			StoreRequestPolicy::write( 'cart.write', 60, 60, false )->refusals()
		);
	}

	/**
	 * Tests that a public read is carried without a PublicWrite, served by GET in the Store API.
	 *
	 * @since 0.1.0
	 */
	public function test_a_public_read_has_no_write_policy(): void {
		$definition = self::publicVariant(
			array(
				'annotations'  => new Annotations( read_only: true, destructive: false, idempotent: true ),
				'rest'         => new RestBinding( FixtureStockOperation::ROUTE, null, store: true ),
				'public_write' => null,
			)
		);

		$this->assertTrue( $definition->isPublic() );
		$this->assertNull( $definition->publicWrite() );
		$this->assertSame( 'GET', $definition->httpMethod() );
		$this->assertSame( array( FixtureStockError::Insufficient ), $definition->errors() );
	}

	/**
	 * Tests that the fixture, which requires a capability, is not public and is served in the admin namespace.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_with_a_capability_is_not_public(): void {
		$definition = FixtureStockOperation::definition();

		$this->assertFalse( $definition->isPublic() );
		$this->assertNull( $definition->publicWrite() );
		$this->assertSame( RestBinding::NAMESPACE, $definition->rest()?->restNamespace() );
		$this->assertFalse( $definition->rest()->isStore() );
	}

	/**
	 * Provides one refused public variant per rule, with the part of the message that names the rule.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, string}> Overrides of the public write, and the expected message part.
	 */
	public static function refusedVariants(): array {
		$guarded = array( 'capability' => 'seocart_manage_inventory' );

		return array(
			'public write without its PublicWrite' => array( array( 'public_write' => null ), 'is a public write, which cannot be declared without its PublicWrite' ),
			'public operation on an admin route'   => array( array( 'rest' => new RestBinding( FixtureStockOperation::ROUTE, WriteMethod::Post ) ), 'requires no capability, so it must be a Store API route' ),
			'public operation without a route'     => array(
				array(
					'rest'    => null,
					'ability' => 'fixture-adjust-stock',
				),
				'requires no capability, so it must be a Store API route',
			),
			'public operation naming a resource'   => array( array( 'resource_field' => 'item_id' ), 'checks nothing on a resource' ),
			'public operation with an ability'     => array( array( 'ability' => 'fixture-adjust-stock' ), 'is never an ability, a command or exposed to agents' ),
			'public operation with a command'      => array( array( 'cli' => new CliBinding( array( 'fixture-stock', 'adjust' ), array( 'item_id' ) ) ), 'is never an ability, a command or exposed to agents' ),
			'public operation exposed to agents'   => array( array( 'agent_exposed' => true ), 'is never an ability, a command or exposed to agents' ),
			'public read with a PublicWrite'       => array(
				array(
					'annotations' => new Annotations( read_only: true, destructive: false, idempotent: true ),
					'rest'        => new RestBinding( FixtureStockOperation::ROUTE, null, store: true ),
				),
				'is a public read: it has no write policy to declare',
			),
			'capability with a PublicWrite'        => array( $guarded + array( 'rest' => new RestBinding( FixtureStockOperation::ROUTE, WriteMethod::Post ) ), 'so it is not a public write: declare no PublicWrite' ),
			'capability on a Store API route'      => array(
				$guarded + array( 'public_write' => null ),
				'but the Store API (seocart/store/v1) serves public operations only',
			),
			'refusal also declared as an error'    => array( array( 'errors' => array( StoreApiError::RateLimited ) ), 'declares the error code store_api.rate_limited twice' ),
			'refusal that is not a catalog case'   => array(
				// @phpstan-ignore argument.type (The refusal written as a string is the declaration being refused.)
				array( 'public_write' => new PublicWrite( new RateLimit( 'fixture.write', 5, 60 ), false, array( 'store_api.rate_limited' ) ) ),
				'must be cases of an error catalog',
			),
		);
	}

	/**
	 * Tests that each public variant breaking one rule is refused when it is declared.
	 *
	 * The first variant is the construction gate: a write that checks no capability cannot exist
	 * without the declaration of the policy that decides for it.
	 *
	 * Planted violation: in OperationDefinition::checkPublic(), delete the check that raises "is a
	 * public write, which cannot be declared without its PublicWrite". The first data set fails.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedVariants
	 *
	 * @param array<string, mixed> $overrides        The one change to the public write.
	 * @param string               $expected_message Part of the message.
	 */
	public function test_a_public_declaration_breaking_a_rule_is_refused( array $overrides, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		self::publicVariant( $overrides );
	}

	/**
	 * Tests that the same route and method in the two namespaces are two addresses, which the registry accepts.
	 *
	 * Planted violation: in OperationRegistry::addresses(), leave the namespace out of a REST
	 * address. The registry then refuses the pair as sharing an address.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_route_in_the_two_namespaces_is_two_addresses(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		$registry->add( 'fixture_stock.adjust_public_stock', static fn(): OperationDefinition => self::publicVariant( array( 'id' => 'fixture_stock.adjust_public_stock' ) ) );

		$this->assertCount( 2, $registry->all() );
	}

	/**
	 * Tests that a Store API route is in the Store API's namespace, and any other route in the admin namespace.
	 *
	 * @since 0.1.0
	 */
	public function test_a_route_names_its_namespace(): void {
		$this->assertSame( 'seocart/store/v1', ( new RestBinding( '/session', null, store: true ) )->restNamespace() );
		$this->assertSame( 'seocart/v1', ( new RestBinding( '/settings' ) )->restNamespace() );
	}

	/**
	 * Tests that a rate limit refuses what a counter row could not hold.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rate_limit_refuses_what_a_counter_cannot_hold(): void {
		$refused = array(
			array( 'Cart.Write', 1, 60 ),
			array( 'cart write', 1, 60 ),
			array( str_repeat( 'a', 65 ), 1, 60 ),
			array( 'cart.write', 0, 60 ),
			array( 'cart.write', 1, 0 ),
			array( 'cart.write', 1, RateLimit::LONGEST_WINDOW_SECONDS + 1 ),
		);

		foreach ( $refused as list( $bucket, $limit, $window ) ) {
			try {
				new RateLimit( $bucket, $limit, $window );
				$this->fail( "The rate limit {$bucket}, {$limit} per {$window} s was accepted." );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}

		$accepted = new RateLimit( str_repeat( 'a', 64 ), 1, RateLimit::LONGEST_WINDOW_SECONDS );

		$this->assertSame( RateLimit::LONGEST_WINDOW_SECONDS, $accepted->windowSeconds() );
	}

	/**
	 * Builds a public write of the fixture, with some named arguments replaced.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $overrides Named constructor arguments to replace.
	 * @return OperationDefinition The definition.
	 */
	private static function publicVariant( array $overrides ): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			...array_merge(
				array(
					'id'             => $fixture->id(),
					'label'          => $fixture->label(),
					'summary'        => $fixture->summary(),
					'input'          => $fixture->input(),
					'output'         => $fixture->output(),
					'capability'     => null,
					'resource_field' => null,
					'errors'         => array( FixtureStockError::Insufficient ),
					'annotations'    => $fixture->annotations(),
					'service'        => $fixture->service(),
					'rest'           => new RestBinding( FixtureStockOperation::ROUTE, WriteMethod::Post, store: true ),
					'ability'        => null,
					'cli'            => null,
					'agent_exposed'  => false,
					'public_write'   => StoreRequestPolicy::write( 'fixture.write', 5, 60, true ),
				),
				$overrides
			)
		);
	}
}
