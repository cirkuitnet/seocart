<?php
/**
 * Tests the ability an operation is registered as
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Interfaces\Operations\AbilitiesAdapter;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\OperationSurfaceWalker;
use WP_Ability;
use WP_UnitTestCase;

/**
 * The fixture's ability as the Abilities API holds it, its category, and agent exposure on and off.
 *
 * @since 0.1.0
 */
final class AbilitiesAdapterTest extends WP_UnitTestCase {

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
	 * Tests that the ability carries the declaration: label, summary, category, compiled schemas and annotations.
	 *
	 * @since 0.1.0
	 */
	public function test_the_ability_is_registered_from_the_declaration(): void {
		$this->register( array( FixtureStockOperation::class, 'definition' ) );

		$ability  = wp_get_ability( FixtureStockOperation::ABILITY );
		$compiled = new CompiledOperation( FixtureStockOperation::definition() );

		$this->assertNotNull( $ability );
		$this->assertSame( 'Adjust fixture stock', $ability->get_label() );
		$this->assertSame( FixtureStockOperation::definition()->summary(), $ability->get_description() );
		$this->assertSame( AbilitiesAdapter::CATEGORY, $ability->get_category() );
		$this->assertSame( $compiled->inputSchema(), $ability->get_input_schema() );
		$this->assertSame( $compiled->outputSchema(), $ability->get_output_schema() );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			$ability->get_meta_item( 'annotations' )
		);
		$this->assertTrue( wp_has_ability_category( AbilitiesAdapter::CATEGORY ) );
	}

	/**
	 * Tests that an operation that allows agents is public, and so shown in the REST API, and that
	 * its ability resolves to it in the walk over the abilities.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_that_allows_agents_is_public(): void {
		$registry = $this->register( array( self::class, 'exposedDefinition' ) );

		$ability = wp_get_ability( FixtureStockOperation::ABILITY );
		$names   = array_values( array_map( static fn( WP_Ability $registered ): string => $registered->get_name(), wp_get_abilities() ) );

		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta_item( 'public' ) );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertContains( FixtureStockOperation::ABILITY, $names );
		$this->assertSame( array(), OperationSurfaceWalker::abilityViolations( $names, $registry ) );
	}

	/**
	 * Tests that agent exposure is off unless the declaration allows it: the fixture, which carries
	 * personal data and a secret, does not.
	 *
	 * @since 0.1.0
	 */
	public function test_agent_exposure_is_off_by_default(): void {
		$this->register( array( FixtureStockOperation::class, 'definition' ) );

		$ability = wp_get_ability( FixtureStockOperation::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_meta_item( 'public' ) );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/**
	 * Tests that no category is registered when no operation has an ability.
	 *
	 * @since 0.1.0
	 */
	public function test_no_category_without_an_ability(): void {
		new OperationSurfaces( new OperationRegistry() );

		$this->assertSame( array(), wp_get_abilities( array( 'namespace' => 'seocart' ) ) );
		$this->assertFalse( wp_has_ability_category( AbilitiesAdapter::CATEGORY ) );
	}

	/**
	 * Declares the fixture without its personal-data and secret fields, exposed to agents.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function exposedDefinition(): OperationDefinition {
		$fixture   = FixtureStockOperation::definition();
		$shareable = static fn( FieldSpec $field ): bool => ! in_array( $field->privacy(), array( Privacy::Pii, Privacy::Secret ), true );

		return new OperationDefinition(
			id: $fixture->id(),
			label: $fixture->label(),
			summary: $fixture->summary(),
			input: array_values( array_filter( $fixture->input(), $shareable ) ),
			output: new ResourceSchema( $fixture->output()->name(), array_values( array_filter( $fixture->output()->fields(), $shareable ) ) ),
			capability: $fixture->capability(),
			resource_field: null,
			errors: $fixture->errors(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			ability: 'fixture-adjust-stock',
			agent_exposed: true
		);
	}

	/**
	 * Registers one operation on every surface.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $factory Builds the operation's definition.
	 * @return OperationRegistry The registry that holds it.
	 *
	 * @phpstan-param callable(): OperationDefinition $factory
	 */
	private function register( callable $factory ): OperationRegistry {
		$registry = new OperationRegistry();
		$registry->add( FixtureStockOperation::ID, $factory );

		new OperationSurfaces( $registry );

		return $registry;
	}
}
