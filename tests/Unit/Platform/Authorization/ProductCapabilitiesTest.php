<?php
/**
 * Tests the product post type's capability map against the vocabulary
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\ProductCapabilities;

/**
 * Holds the capability map, the registration arguments and the vocabulary to one set of names.
 *
 * The integration test ProductCapabilityMapTest compares the map with what core's
 * get_post_type_capabilities() actually produces; this test pins the same key list without
 * WordPress, so a change to either side is caught by both suites.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class ProductCapabilitiesTest extends TestCase {

	/**
	 * The keys core's get_post_type_capabilities() produces for a post type with `map_meta_cap => true`.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CORE_KEYS = array(
		'edit_post',
		'read_post',
		'delete_post',
		'edit_posts',
		'edit_others_posts',
		'delete_posts',
		'publish_posts',
		'read_private_posts',
		'read',
		'delete_private_posts',
		'delete_published_posts',
		'delete_others_posts',
		'edit_private_posts',
		'edit_published_posts',
		'create_posts',
	);

	/**
	 * Tests that the registration arguments carry the complete map and switch meta mapping on.
	 *
	 * @since 0.1.0
	 */
	public function test_the_registration_arguments_carry_the_complete_map(): void {
		$arguments = ProductCapabilities::registrationArguments();

		$this->assertTrue( $arguments['map_meta_cap'], 'Without map_meta_cap, core collapses edit_post into one primitive with no owner or status distinction.' );
		$this->assertSame( array( 'seocart_product', 'seocart_products' ), $arguments['capability_type'] );
		$this->assertSame( ProductCapabilities::map(), $arguments['capabilities'] );
	}

	/**
	 * Tests that the map names every key core produces, and nothing else.
	 *
	 * Planted violation: delete the `'edit_private_posts'` line from ProductCapabilities::map().
	 * Core would then name that primitive itself, and no role would hold it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_map_names_every_key_core_produces(): void {
		$this->assertEqualsCanonicalizing( self::CORE_KEYS, array_keys( ProductCapabilities::map() ) );
	}

	/**
	 * Tests that every capability in the map is declared: a primitive, a meta capability, or core's `read`.
	 *
	 * The declaration takes its product primitives from the map, so the two agree by
	 * construction; this test is what keeps that construction in place.
	 *
	 * Planted violation: in the CapabilityDeclaration constructor, replace the whole assignment
	 * with `$this->primitives = self::PRIMITIVES;`, so the vocabulary keeps a list of its own.
	 * Every product primitive must then be reported as undeclared.
	 *
	 * @since 0.1.0
	 */
	public function test_every_capability_in_the_map_is_declared(): void {
		$declaration = new CapabilityDeclaration();

		foreach ( ProductCapabilities::map() as $key => $capability ) {
			if ( in_array( $key, array( 'edit_post', 'read_post', 'delete_post' ), true ) ) {
				$this->assertTrue( $declaration->isMetaCapability( $capability ), "{$key} maps to {$capability}, which is not a declared meta capability." );
				continue;
			}

			if ( 'read' === $key ) {
				$this->assertSame( ProductCapabilities::CORE_READ, $capability, 'A published product is read with core\'s read primitive.' );
				continue;
			}

			$this->assertTrue( $declaration->isPrimitive( $capability ), "{$key} maps to {$capability}, which the vocabulary does not declare." );
			$this->assertSame( CapabilityDeclaration::GROUP_CATALOG, $declaration->group( $capability ), $capability );
		}
	}

	/**
	 * Tests the primitives and meta capabilities the map yields.
	 *
	 * @since 0.1.0
	 */
	public function test_the_primitives_and_meta_capabilities_it_yields(): void {
		$this->assertSame(
			array(
				'edit_seocart_products',
				'edit_others_seocart_products',
				'edit_published_seocart_products',
				'edit_private_seocart_products',
				'publish_seocart_products',
				'read_private_seocart_products',
				'delete_seocart_products',
				'delete_others_seocart_products',
				'delete_published_seocart_products',
				'delete_private_seocart_products',
			),
			ProductCapabilities::primitives()
		);

		$this->assertSame( array( 'edit_seocart_product', 'read_seocart_product', 'delete_seocart_product' ), ProductCapabilities::metaCapabilities() );
		$this->assertSame( ProductCapabilities::map()['edit_posts'], ProductCapabilities::map()['create_posts'], 'Creating a product needs what editing one needs.' );
	}
}
