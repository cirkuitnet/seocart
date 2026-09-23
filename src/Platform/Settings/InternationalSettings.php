<?php
/**
 * InternationalSettings: the store-wide defaults of the international group
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\SupportError;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the settings of the `international` group.
 *
 * This class owns one fact: which international settings are options. Only the store's base
 * currency is one so far. Presentment currencies, markets and exchange rates have uniqueness
 * rules, versions and lookups per request, so they are table rows, never options; the defaults a
 * market may override join this group as the code that reads them arrives.
 *
 * @since 0.1.0
 */
final class InternationalSettings {

	/**
	 * The group.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP = 'international';

	/**
	 * The name of the base-currency setting.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BASE_CURRENCY = 'base_currency';

	/**
	 * Returns the group's settings.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function settings(): array {
		return array(
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::BASE_CURRENCY,
					type: FieldType::String,
					description: 'ISO 4217 code of the currency the store keeps its accounts in, in upper case.',
					label: static fn(): string => __( 'Base currency', 'seocart' ),
					example: 'EUR',
					default_value: 'USD'
				),
				exposed: true,
				check: static fn( int|string $code ): string => Currency::of( (string) $code )->code(),
				errors: array( SupportError::UnknownCurrency )
			),
		);
	}
}
