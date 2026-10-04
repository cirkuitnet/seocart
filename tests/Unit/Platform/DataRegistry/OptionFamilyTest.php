<?php
/**
 * Tests an option family: one declaration of every option whose name begins with a prefix
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\DataRegistry;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\DataRegistry\Contribution;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Platform\DataRegistry\OwnedData;

/**
 * A family declares every option its prefix begins, never autoloads, and may not cover another registration; the production registry declares the payment gateways' documents as one.
 *
 * Planted violation, shown red and removed: in OptionDefinition::covers(), drop the length
 * check: the prefix alone then counts as one of the family.
 *
 * @since 0.2.0
 */
final class OptionFamilyTest extends TestCase {

	/**
	 * Tests what a family covers, and that the registry answers for an option by its name or its family.
	 *
	 * @since 0.2.0
	 */
	public function test_a_family_covers_the_options_its_prefix_begins(): void {
		$family   = OptionDefinition::prefixed( 'seocart_example_', 'Example', 'The example documents.', Classification::Secret );
		$registry = new DataRegistry( new CapabilityDeclaration(), new Contribution( options: array( $family, new OptionDefinition( 'seocart_other', 'Example', 'Another option.', false, Classification::Public ) ) ) );

		$this->assertSame( array( 'seocart_example_%', true, false ), array( $family->name(), $family->isFamily(), $family->autoloads() ) );
		$this->assertTrue( $registry->declaresOption( 'seocart_example_one' ) );
		$this->assertTrue( $registry->declaresOption( 'seocart_other' ) );
		$this->assertFalse( $registry->declaresOption( 'seocart_example_' ), 'The prefix alone is no member.' );
		$this->assertFalse( $registry->declaresOption( 'seocart_examples' ) );
		$this->assertFalse( $registry->declaresOption( 'seocart_other_two' ), 'An option is not a family.' );
	}

	/**
	 * Tests that an option, or another family, a family covers is refused: it would be declared twice.
	 *
	 * @since 0.2.0
	 */
	public function test_a_family_may_not_cover_another_registration(): void {
		$family = OptionDefinition::prefixed( 'seocart_example_', 'Example', 'The example documents.', Classification::Secret );

		foreach ( array( new OptionDefinition( 'seocart_example_one', 'Example', 'One.', false, Classification::Public ), OptionDefinition::prefixed( 'seocart_example_sub_', 'Example', 'Sub documents.', Classification::Secret ) ) as $covered ) {
			try {
				new DataRegistry( new CapabilityDeclaration(), new Contribution( options: array( $family ) ), new Contribution( options: array( $covered ) ) );
				$this->fail( $covered->name() . ' was registered beside the family that covers it.' );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsString( 'covered by the family', $refused->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );

		new OptionDefinition( 'seocart_example_%', 'Example', 'Autoloaded.', true, Classification::Public, true );
	}

	/**
	 * Tests that the production registry declares the payment gateways' documents as one secret family.
	 *
	 * @since 0.2.0
	 */
	public function test_the_production_registry_declares_the_gateway_documents(): void {
		$registry = OwnedData::registry();
		$families = array_values( array_filter( $registry->options(), static fn( OptionDefinition $option ): bool => $option->isFamily() ) );

		$this->assertCount( 1, $families );
		$this->assertSame( array( 'seocart_gateway_%', 'Settings', Classification::Secret ), array( $families[0]->name(), $families[0]->module(), $families[0]->classification() ) );
		$this->assertTrue( $registry->declaresOption( 'seocart_gateway_stripe' ) );
	}
}
