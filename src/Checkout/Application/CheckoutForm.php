<?php
/**
 * CheckoutForm: the checkout form a storefront renders, as the store set its fields and limits
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Platform\Authorization\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * Performs `checkout.get_form`: the fields the checkout asks for, in form order, with their words and limits, and the order limits per currency.
 *
 * Owns one fact: what a storefront's checkout form shows, so a client mirrors the server and never
 * decides a field of its own. A hidden field is not listed. Each field says whether an order needs
 * it, its longest value, a select's options, its autocomplete token, and where its value is sent
 * (Carrier): in each address, in the billing address alone, or in the custom fields. The labels
 * are translated in the request's locale. The order limits are the store's, by currency; where the
 * cart stands against them is the session write's answer (OrderLimits::verdict()).
 *
 * The storefront's own render calls read() in PHP, so the block and the route show one form. The
 * answer holds no personal data and is the same for every visitor.
 *
 * @since 0.2.0
 */
final class CheckoutForm {

	/**
	 * Creates the service. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutRules $rules The checkout's rules: the fields in force and the order limits.
	 */
	public function __construct( private CheckoutRules $rules ) {
	}

	/**
	 * Performs `checkout.get_form`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The prepared input: the operation declares none.
	 * @param Actor                $actor Who reads: anyone.
	 * @return array{fields: list<array<string, mixed>>, order_limits: list<array{currency: string, minimum_minor: int|null, maximum_minor: int|null}>} The form, by wire name.
	 */
	public function read( array $input, Actor $actor ): array {
		unset( $input, $actor );

		$fields = $this->rules->current();
		$limits = array();

		foreach ( $this->rules->orderLimits() as $currency => $limit ) {
			$limits[] = array(
				'currency'      => (string) $currency,
				'minimum_minor' => $limit['min'],
				'maximum_minor' => $limit['max'],
			);
		}

		return array(
			'fields'       => array_map(
				static fn( CheckoutField $field ): array => array(
					'key'          => $field->key,
					'section'      => $field->section->value,
					'type'         => $field->type->value,
					'label'        => ( $field->label )(),
					'help'         => null === $field->help ? null : ( $field->help )(),
					'required'     => Presence::Required === $fields->presenceOf( $field ),
					'max_length'   => $field->maxLength,
					'options'      => self::options( $field ),
					'autocomplete' => $field->autocomplete,
					'carrier'      => Carrier::of( $field )->value,
				),
				$fields->inForce()
			),
			'order_limits' => $limits,
		);
	}

	/**
	 * Returns a select's options, each its value and its label, translated; none for any other type.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @return list<array{value: string, label: string}> The options, in declared order.
	 */
	private static function options( CheckoutField $field ): array {
		$options = array();

		foreach ( $field->options as $value => $label ) {
			$options[] = array(
				'value' => (string) $value,
				'label' => $label(),
			);
		}

		return $options;
	}
}
