<?php
/**
 * Tests that a permission callback can be built only for a declared capability
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\PermissionCallback;

/**
 * Proves, without WordPress, what a PermissionCallback may be built for.
 *
 * The route walker accepts any PermissionCallback, so what the type refuses at construction is
 * what no plugin route can ever check. The integration test of the same name dispatches real
 * requests through the callbacks that can be built.
 *
 * @since 0.1.0
 */
final class PermissionCallbackTest extends TestCase {

	/**
	 * Tests that the class cannot be extended.
	 *
	 * The walker recognises the plugin's callbacks with `instanceof`. A subclass could override
	 * __invoke() to allow everything and still pass the walk.
	 *
	 * Planted violation: remove `final` from the PermissionCallback class declaration.
	 *
	 * @since 0.1.0
	 */
	public function test_the_class_is_final(): void {
		$this->assertTrue( ( new \ReflectionClass( PermissionCallback::class ) )->isFinal(), 'PermissionCallback must be final: the route walker trusts every instance of it.' );
	}

	/**
	 * Tests that requiring() refuses anything but a declared plugin primitive.
	 *
	 * `exist` is held by every visitor who is not logged in and `read` by every customer, so a
	 * route guarded by either would be open to them.
	 *
	 * Planted violation: in PermissionCallback::requiring(), delete the `isPrimitive()` check.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider notDeclaredPrimitives
	 *
	 * @param string $capability The capability to require.
	 */
	public function test_requiring_refuses_anything_but_a_declared_plugin_primitive( string $capability ): void {
		$this->expectException( \InvalidArgumentException::class );

		PermissionCallback::requiring( $capability );
	}

	/**
	 * Provides capabilities requiring() must refuse.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}>
	 */
	public function notDeclaredPrimitives(): array {
		return array(
			'core exist'                      => array( 'exist' ),
			'core read'                       => array( 'read' ),
			'core manage_options'             => array( 'manage_options' ),
			'another plugin\'s capability'    => array( 'manage_woocommerce' ),
			'an undeclared plugin capability' => array( 'seocart_frobnicate' ),
			'a plugin meta capability'        => array( 'seocart_view_order' ),
			'a product meta capability'       => array( 'edit_seocart_product' ),
			'a declared primitive, misspelt'  => array( 'SEOCart_view_orders' ),
			'empty'                           => array( '' ),
		);
	}

	/**
	 * Tests that requiringOn() refuses anything but a declared meta capability, and a missing parameter name.
	 *
	 * A primitive checked "on a resource" would ignore the resource and allow every holder of the
	 * primitive, whichever resource the request names.
	 *
	 * Planted violation: in PermissionCallback::requiringOn(), delete the `isMetaCapability()` check.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider notDeclaredMetaCapabilities
	 *
	 * @param string $capability         The capability to require on the resource.
	 * @param string $resource_parameter The request parameter that names the resource.
	 */
	public function test_requiring_on_refuses_anything_but_a_declared_meta_capability( string $capability, string $resource_parameter ): void {
		$this->expectException( \InvalidArgumentException::class );

		PermissionCallback::requiringOn( $capability, $resource_parameter );
	}

	/**
	 * Provides capabilities and parameters requiringOn() must refuse.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function notDeclaredMetaCapabilities(): array {
		return array(
			'a declared primitive'            => array( 'seocart_edit_orders', 'id' ),
			'core read'                       => array( 'read', 'id' ),
			'core exist'                      => array( 'exist', 'id' ),
			'core edit_post'                  => array( 'edit_post', 'id' ),
			'an undeclared plugin capability' => array( 'seocart_view_widget', 'id' ),
			'an empty parameter name'         => array( 'seocart_view_order', '' ),
			'a blank parameter name'          => array( 'seocart_view_order', ' ' ),
		);
	}

	/**
	 * Tests the callbacks that can be built, and what they report.
	 *
	 * @since 0.1.0
	 */
	public function test_declared_capabilities_and_the_public_read_marker_are_accepted(): void {
		$this->assertSame( 'seocart_view_orders', PermissionCallback::requiring( 'seocart_view_orders' )->capability() );
		$this->assertSame( 'edit_others_seocart_products', PermissionCallback::requiring( 'edit_others_seocart_products' )->capability() );
		$this->assertSame( 'edit_seocart_product', PermissionCallback::requiringOn( 'edit_seocart_product', 'id' )->capability() );
		$this->assertSame( 'seocart_view_order', PermissionCallback::requiringOn( 'seocart_view_order', 'uuid' )->capability() );

		$this->assertFalse( PermissionCallback::requiring( 'seocart_view_orders' )->isPublicRead() );
		$this->assertTrue( PermissionCallback::publicRead()->isPublicRead() );
		$this->assertNull( PermissionCallback::publicRead()->capability() );
	}
}
