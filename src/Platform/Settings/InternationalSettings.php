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
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the settings of the `international` group.
 *
 * This class owns one fact: which international settings are options: the store's base
 * currency, and the two tax rules a calculation follows, its cross-zone policy and its tax
 * rounding mode. Presentment currencies, markets and exchange rates have uniqueness rules,
 * versions and lookups per request, so they are table rows, never options; the defaults a market
 * may override join this group as the code that reads them arrives.
 *
 * The three are read together by every calculation, and one group is primed in one query, so
 * they are one group. The two tax rules accept the values their enums declare, and nothing else.
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
	 * The name of the cross-zone policy setting: what stays fixed when a gross price is sold into another tax zone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CROSS_ZONE_POLICY = 'cross_zone_policy';

	/**
	 * The name of the tax rounding mode setting: where tax is rounded.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TAX_ROUNDING_MODE = 'tax_rounding_mode';

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
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::CROSS_ZONE_POLICY,
					type: FieldType::String,
					description: 'What stays fixed when a price entered including tax is sold where the tax rate differs from the store\'s own: fixed_net keeps the price before tax, fixed_gross keeps the price paid.',
					label: static fn(): string => __( 'Cross-zone pricing policy', 'seocart' ),
					example: CrossZonePolicy::FixedGross->value,
					default_value: CrossZonePolicy::FixedNet->value,
					allowed: array_column( CrossZonePolicy::cases(), 'value' )
				),
				exposed: true
			),
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::TAX_ROUNDING_MODE,
					type: FieldType::String,
					description: 'Where tax is rounded: per_line rounds each line\'s tax, per_subtotal rounds the tax of the lines of one tax class once and shares it out to them.',
					label: static fn(): string => __( 'Tax rounding', 'seocart' ),
					example: TaxRoundingMode::PerSubtotal->value,
					default_value: TaxRoundingMode::PerLine->value,
					allowed: array_column( TaxRoundingMode::cases(), 'value' )
				),
				exposed: true
			),
		);
	}
}
