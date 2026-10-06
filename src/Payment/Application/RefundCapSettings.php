<?php
/**
 * RefundCapSettings: the settings that cap what a shipped role may give back
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Platform\Settings\Setting;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the settings of the `refund_caps` group: for each shipped role whose refunds are capped, the most it may give back of one order, and in any 24 hours.
 *
 * Owns one fact: which roles are capped, by which settings. Each value is an amount of the base
 * currency in major units, such as `250.00`, applied at the currency's own exponent: 250 yen in a
 * JPY store, 250 euros in a EUR one. An empty value is no cap. A role that is not listed here, such
 * as the store manager, an administrator, or a role the merchant made, is never capped. The two
 * settings are read together by every capped refund, and one group is primed in one query.
 *
 * @since 0.2.0
 */
final class RefundCapSettings {

	/**
	 * The group.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GROUP = 'refund_caps';

	/**
	 * The most an order agent may give back of one order, all of its refunds together.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_AGENT_PER_ORDER = 'order_agent_per_order';

	/**
	 * The most an order agent may ask the gateway to give back in any 24 hours.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_AGENT_PER_DAY = 'order_agent_per_day';

	/**
	 * The shipped roles whose refunds are capped, each with its settings: per order, then per day.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const CAPPED_ROLES = array(
		'seocart_order_agent' => array( self::ORDER_AGENT_PER_ORDER, self::ORDER_AGENT_PER_DAY ),
	);

	/**
	 * An amount in major units: digits, then at most six decimals.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const AMOUNT = '/^\d{1,15}(\.\d{1,6})?\z/';

	/**
	 * Returns the group's settings.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function settings(): array {
		return array(
			self::cap( self::ORDER_AGENT_PER_ORDER, 'The most an order agent may give back of one order, all of its refunds together, as an amount of the base currency in major units, such as 250.00; empty for no cap.', static fn(): string => __( 'Order agent refund cap per order', 'seocart' ), '250.00' ),
			self::cap( self::ORDER_AGENT_PER_DAY, 'The most an order agent may ask the payment gateway to give back in any 24 hours, of any orders, as an amount of the base currency in major units, such as 1000.00; empty for no cap.', static fn(): string => __( 'Order agent refund cap per day', 'seocart' ), '1000.00' ),
		);
	}

	/**
	 * Checks a cap: empty, or an amount in major units that is not negative, with at most six decimals.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_cap_invalid` for anything else.
	 *
	 * @param int|string $value The value given.
	 * @return string The value, as given.
	 */
	public static function amount( int|string $value ): string {
		$value = (string) $value;

		if ( '' !== $value && 1 !== preg_match( self::AMOUNT, $value ) ) {
			CodedException::raise( PaymentError::RefundCapInvalid );
		}

		return $value;
	}

	/**
	 * Declares one cap.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The setting's name.
	 * @param string   $description What it caps.
	 * @param \Closure $label       Its label, translated when called.
	 * @param string   $amount      Its default amount.
	 * @return Setting The setting.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function cap( string $name, string $description, \Closure $label, string $amount ): Setting {
		return Setting::scalar(
			group: self::GROUP,
			field: new FieldSpec(
				name: $name,
				type: FieldType::String,
				description: $description,
				label: $label,
				example: $amount,
				default_value: $amount,
				max_length: 22
			),
			exposed: true,
			check: static fn( int|string $value ): string => self::amount( $value ),
			errors: array( PaymentError::RefundCapInvalid )
		);
	}
}
