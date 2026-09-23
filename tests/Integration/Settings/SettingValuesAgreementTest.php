<?php
/**
 * Tests that the store's check of a value agrees with what the operation surfaces let through
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Settings;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Platform\Settings\SettingValues;
use SEOCart\Tests\Support\SettingsFixtures;
use WP_Error;
use WP_UnitTestCase;

/**
 * A field's constraints are enforced twice, by two readers of the same declaration: on the
 * surfaces, by WordPress's validator on the argument schema compiled from the field, and in the
 * store, by SettingValues::check(). This is the companion test that keeps the two one rule.
 *
 * For every fixture setting without a check of its own — one per kind of constraint — and every
 * candidate value:
 *
 * - a value the route's validator accepts, once the route has sanitized it as it does before the
 *   service runs, must be accepted by the store: otherwise a client's valid change would fail
 *   inside the service as an internal error;
 * - a value the store accepts must be accepted by the route's validator: otherwise the plugin's
 *   own code could store what no client may.
 *
 * Planted violation: in SettingValues::constraintBroken(), compare the length with `strlen()`
 * instead of `mb_strlen()`. Twenty two-byte letters then pass the route and are refused by the
 * store.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class SettingValuesAgreementTest extends WP_UnitTestCase {

	/**
	 * Tests the two directions over every candidate value of every setting.
	 *
	 * @since 0.1.0
	 */
	public function test_the_store_and_the_surfaces_accept_the_same_values(): void {
		$registry  = SettingsFixtures::registry();
		$arguments = ( new CompiledOperation( SettingsOperations::update( $registry ) ) )->restArguments();
		$checked   = 0;
		$problems  = array();

		foreach ( array( 'hold_minutes', 'weight_unit', 'default_market', 'store_name' ) as $name ) {
			$setting = $registry->setting( $name );

			foreach ( self::candidates() as $label => $value ) {
				$surface = rest_validate_value_from_schema( $value, $arguments[ $name ], $name );

				if ( true === $surface ) {
					$prepared = rest_sanitize_value_from_schema( $value, $arguments[ $name ], $name );

					if ( ! self::storeAccepts( $setting, $prepared ) ) {
						$problems[] = "{$name}: the route lets {$label} through as " . wp_json_encode( $prepared ) . ', and the store refuses it.';
					}
				}

				if ( self::storeAccepts( $setting, $value ) && $surface instanceof WP_Error ) {
					$problems[] = "{$name}: the store accepts {$label}, and the route refuses it: " . $surface->get_error_message();
				}

				++$checked;
			}
		}

		$this->assertGreaterThan( 100, $checked );
		$this->assertSame( array(), $problems, "The store and the surfaces disagree:\n  " . implode( "\n  ", $problems ) . "\n" );
	}

	/**
	 * Tests that the candidates reach both sides of every constraint, so an agreement is not an accident of easy values.
	 *
	 * @since 0.1.0
	 */
	public function test_the_candidates_reach_both_sides_of_every_constraint(): void {
		$registry = SettingsFixtures::registry();

		foreach ( array( 'hold_minutes', 'weight_unit', 'default_market', 'store_name' ) as $name ) {
			$accepted = array_filter( self::candidates(), static fn( mixed $value ): bool => self::storeAccepts( $registry->setting( $name ), $value ) );

			$this->assertNotEmpty( $accepted, "No candidate fits {$name}." );
			$this->assertLessThan( count( self::candidates() ), count( $accepted ), "Every candidate fits {$name}." );
		}
	}

	/**
	 * Tells whether the store accepts a value for a setting.
	 *
	 * @since 0.1.0
	 *
	 * @param Setting $setting The setting.
	 * @param mixed   $value   The value.
	 * @return bool True when check() returns it.
	 */
	private static function storeAccepts( Setting $setting, mixed $value ): bool {
		try {
			SettingValues::check( $setting, $value );

			return true;
		} catch ( \InvalidArgumentException ) {
			return false;
		}
	}

	/**
	 * Returns the candidate values: each constraint's edges, and the types a JSON body can carry.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The values, keyed by a description.
	 */
	private static function candidates(): array {
		return array(
			'the integer 0'                       => 0,
			'the integer 1'                       => 1,
			'the integer 1440'                    => 1440,
			'the integer 1441'                    => 1441,
			'the integer -1'                      => -1,
			'the largest integer'                 => PHP_INT_MAX,
			'the text 30'                         => '30',
			'the text 30.0'                       => '30.0',
			'the text " 30"'                      => ' 30',
			'the float 30.0'                      => 30.0,
			'the float 30.5'                      => 30.5,
			'true'                                => true,
			'null'                                => null,
			'a list'                              => array( 'kg' ),
			'an object'                           => array( 'unit' => 'kg' ),
			'the text kg'                         => 'kg',
			'the text KG'                         => 'KG',
			'the text " kg"'                      => ' kg',
			'the text oz'                         => 'oz',
			'the text stone'                      => 'stone',
			'empty text'                          => '',
			'a lower-case uuid'                   => 'aaaaaaaa-0000-4000-8000-000000000001',
			'an upper-case uuid'                  => 'AAAAAAAA-0000-4000-8000-000000000001',
			'a uuid and a new line'               => "aaaaaaaa-0000-4000-8000-000000000001\n",
			'a uuid without hyphens'              => 'aaaaaaaa000040008000000000000001',
			'twenty ASCII letters'                => str_repeat( 'a', 20 ),
			'twenty-one ASCII letters'            => str_repeat( 'a', 21 ),
			'twenty two-byte letters'             => str_repeat( 'ü', 20 ),
			'twenty-one two-byte letters'         => str_repeat( 'ü', 21 ),
			'twenty letters ending in a new line' => str_repeat( 'a', 19 ) . "\n",
			'text with markup'                    => '<b>Shop</b>',
		);
	}
}
