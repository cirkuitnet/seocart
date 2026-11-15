<?php
/**
 * Tests the shapes the `checkout` settings group stores, and the canonical form it stores them in
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingValues;
use SEOCart\Platform\Settings\Storage;

/**
 * Both settings are internal JSON texts, `{}` when nothing was chosen; each check decodes the text, refuses any other shape, and answers the canonical form with its keys sorted.
 *
 * Planted violation: in CheckoutSettings::limits(), drop the `$min > $max` refusal: the reversed
 * limit's row is stored.
 *
 * @since 0.2.0
 */
final class CheckoutSettingsTest extends TestCase {

	/**
	 * Tests the group: two internal scalars, each `{}` by default.
	 *
	 * @since 0.2.0
	 */
	public function test_the_group_holds_two_internal_scalars(): void {
		$this->assertSame(
			array(
				CheckoutSettings::FIELD_PRESENCE => array( Storage::Scalar, false, '{}', 'seocart_checkout_field_presence' ),
				CheckoutSettings::ORDER_LIMITS   => array( Storage::Scalar, false, '{}', 'seocart_checkout_order_limits' ),
			),
			array_combine(
				array_map( static fn( Setting $setting ): string => $setting->name(), CheckoutSettings::settings() ),
				array_map( static fn( Setting $setting ): array => array( $setting->storage(), $setting->isExposed(), $setting->field()->defaultValue(), $setting->optionName() ), CheckoutSettings::settings() )
			)
		);
	}

	/**
	 * Provides stored texts and what each setting's check answers: its canonical form, or null when it refuses it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string|null}> The setting, the text, the canonical form.
	 */
	public static function texts(): array {
		return array(
			'presences, sorted'           => array( CheckoutSettings::FIELD_PRESENCE, '{"phone":"required","company":"optional"}', '{"company":"optional","phone":"required"}' ),
			'presences, empty'            => array( CheckoutSettings::FIELD_PRESENCE, '{}', '{}' ),
			'a presence that is not one'  => array( CheckoutSettings::FIELD_PRESENCE, '{"phone":"mandatory"}', null ),
			'a key that is not one'       => array( CheckoutSettings::FIELD_PRESENCE, '{"Phone":"required"}', null ),
			'presences as a list'         => array( CheckoutSettings::FIELD_PRESENCE, '["phone"]', null ),
			'presences, not JSON'         => array( CheckoutSettings::FIELD_PRESENCE, 'phone=required', null ),
			'limits, sorted'              => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"min":2500,"max":null},"EUR":{"max":90000,"min":null}}', '{"EUR":{"max":90000,"min":null},"USD":{"max":null,"min":2500}}' ),
			'a limit that bounds nothing' => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"max":null,"min":null}}', '{}' ),
			'a limit at its maximum'      => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"max":2500,"min":2500}}', '{"USD":{"max":2500,"min":2500}}' ),
			'a reversed limit'            => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"max":2499,"min":2500}}', null ),
			'a limit of 0'                => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"max":null,"min":0}}', null ),
			'a limit that is not whole'   => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"max":null,"min":25.5}}', null ),
			'a currency that is not one'  => array( CheckoutSettings::ORDER_LIMITS, '{"ZZZ":{"max":null,"min":1}}', null ),
			'a limit without its bounds'  => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"min":1}}', null ),
			'a limit with another member' => array( CheckoutSettings::ORDER_LIMITS, '{"USD":{"max":null,"min":1,"basis":"net"}}', null ),
		);
	}

	/**
	 * Tests what a setting's check stores for a text, or that it refuses it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider texts
	 *
	 * @param string      $name      The setting.
	 * @param string      $text      The text.
	 * @param string|null $canonical What is stored, or null when the text is refused.
	 */
	public function test_each_text_is_stored_canonical_or_refused( string $name, string $text, ?string $canonical ): void {
		$setting = self::setting( $name );

		if ( null === $canonical ) {
			$this->expectException( \InvalidArgumentException::class );
		}

		$this->assertSame( $canonical, SettingValues::check( $setting, $text ) );
	}

	/**
	 * Returns a setting of the group.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The name.
	 * @return Setting The setting.
	 */
	private static function setting( string $name ): Setting {
		foreach ( CheckoutSettings::settings() as $setting ) {
			if ( $name === $setting->name() ) {
				return $setting;
			}
		}

		self::fail( "No setting {$name}." );
	}
}
