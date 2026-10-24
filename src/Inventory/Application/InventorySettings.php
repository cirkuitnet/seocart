<?php
/**
 * InventorySettings: the settings of orders no one location can ship whole
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the settings of the `inventory` group: what happens to an order no one location can ship whole, and the days a transfer is given.
 *
 * Owns one fact: which inventory choices are the merchant's. `multi_location_fulfilment` answers
 * one question, when no single location can ship the whole order: `split` ships it from several,
 * `transfer` accepts it and brings its units to one location first, `refuse` refuses it at
 * checkout. Routing and the availability shown to shoppers read it together, so the figure shown
 * is one the order can be fulfilled with. `transfer_lead_days` is how many days a shopper is told a
 * transfer adds; `transfer_warning_days` is how many days a requested transfer may wait before it
 * is flagged. Each is an option of its own, exposed to the settings operations.
 *
 * @since 0.2.0
 */
final class InventorySettings {

	/**
	 * The group.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GROUP = 'inventory';

	/**
	 * The name of the setting that says what happens to an order no one location can ship whole.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MULTI_LOCATION_FULFILMENT = 'multi_location_fulfilment';

	/**
	 * The name of the setting of the days a shopper is told a transfer adds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_LEAD_DAYS = 'transfer_lead_days';

	/**
	 * The name of the setting of the days a requested transfer may wait before it is flagged.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_WARNING_DAYS = 'transfer_warning_days';

	/**
	 * Returns the group's settings.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function settings(): array {
		return array(
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::MULTI_LOCATION_FULFILMENT,
					type: FieldType::String,
					description: 'What happens when no one location can ship a whole order: split ships it from several locations, transfer accepts it and brings its units to one location before it ships, refuse refuses it at checkout.',
					label: static fn(): string => __( 'Orders no one location can ship whole', 'seocart' ),
					example: FulfilmentMode::Transfer->value,
					default_value: FulfilmentMode::Split->value,
					allowed: array_column( FulfilmentMode::cases(), 'value' )
				),
				exposed: true
			),
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::TRANSFER_LEAD_DAYS,
					type: FieldType::Integer,
					description: 'The days a shopper is told an order adds when its units must first be brought to one location.',
					label: static fn(): string => __( 'Extra days for a transfer', 'seocart' ),
					example: 3,
					default_value: 3,
					minimum: 0,
					maximum: 365
				),
				exposed: true
			),
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::TRANSFER_WARNING_DAYS,
					type: FieldType::Integer,
					description: 'The days a transfer an order waits for may stay requested before it is flagged.',
					label: static fn(): string => __( 'Days before a waiting transfer is flagged', 'seocart' ),
					example: 3,
					default_value: 3,
					minimum: 1,
					maximum: 365
				),
				exposed: true
			),
		);
	}
}
