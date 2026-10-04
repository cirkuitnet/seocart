<?php
/**
 * Tests that the extension generator makes the slug WordPress makes of a plugin name
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Extension;

use SEOCart\Tools\Extension\ExtensionType;
use SEOCart\Tools\Extension\Skeleton;
use WP_UnitTestCase;

/**
 * WordPress.org takes a plugin's slug from its name with sanitize_title() when the plugin is
 * submitted. bin/dev/new-extension.sh runs without WordPress, so it carries its own copy of
 * that rule for the names it can write (Skeleton::slugOf()); this test holds the copy equal to
 * WordPress's function, over every type's name and labels with each character a label may hold.
 *
 * @since 0.2.0
 */
final class ExtensionSlugTest extends WP_UnitTestCase {

	/**
	 * Tests that the generator's slug of each name is WordPress's.
	 *
	 * @since 0.2.0
	 */
	public function test_the_generator_makes_the_slug_wordpress_makes(): void {
		$labels = array( 'Stripe', 'PayPal', 'Authorize.Net', 'Example', '2Checkout', 'Pay - Later', 'A  B', 'Mollie.', 'X-Y.Z 9' );

		foreach ( ExtensionType::cases() as $type ) {
			foreach ( $labels as $label ) {
				$this->assertMatchesRegularExpression( Skeleton::LABEL_PATTERN, $label, "{$label} is not a label the generator takes, so it proves nothing here." );

				$name = $type->pluginName( $label );

				$this->assertSame( sanitize_title( $name ), Skeleton::slugOf( $name ), "The slug of \"{$name}\"." );
			}
		}
	}
}
