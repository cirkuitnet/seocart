<?php
/**
 * CheckoutSettings: the settings of the `checkout` group, the store's presence choices, its order limits and whether it takes guests' orders
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\AccountsAtCheckout;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a programming error to the developer; they are never HTML.

/**
 * Declares the `checkout` group: which presence the store chose for each checkout field, the smallest and largest order it takes in each currency, and whether a shopper must be signed in to place an order.
 *
 * Owns one fact: how the three choices are stored. Each is an option of its own, and the
 * checkout's rules read all three in one statement with the settings the calculation reads next.
 * The first two hold a JSON object, internal to the checkout: its staff operations write them, by
 * compare-and-swap. The third is a word, exposed to the settings operations, which write it.
 *
 * - `field_presence` maps a field's key to `hidden`, `optional` or `required`, for the fields whose
 *   presence differs from their default only. A key no declaration names is ignored when read.
 * - `order_limits` maps a currency code to `{ "max": …, "min": … }`, each a whole number of the
 *   currency's minor units from 1, or null for no bound; a currency without an entry has no limit,
 *   and an entry without either bound is left out. The minimum is never above the maximum.
 * - `accounts` is `optional`, `required` or `guest_only` (AccountsAtCheckout), `optional` until the
 *   store chooses: only `required` changes what a placement accepts.
 *
 * Each JSON value's check decodes it, refuses any other shape, and stores the canonical form: the
 * keys sorted, the encoding fixed. A shape no client can send is a programming error
 * (\InvalidArgumentException), and a stored value of another shape reads as
 * `settings.stored_value_invalid`, as for any setting. Nothing here holds personal data.
 *
 * Declarations are data: building them performs no I/O, calls no WordPress function and
 * translates nothing.
 *
 * @since 0.2.0
 */
final class CheckoutSettings {

	/**
	 * The group.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GROUP = 'checkout';

	/**
	 * The name of the setting of the store's presence choices.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIELD_PRESENCE = 'field_presence';

	/**
	 * The name of the setting of the order limits.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_LIMITS = 'order_limits';

	/**
	 * The name of the setting of whether a shopper must be signed in to place an order.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACCOUNTS = 'accounts';

	/**
	 * What the presence choices or the order limits hold when nothing was chosen: an empty object.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NONE = '{}';

	/**
	 * The two bounds of an entry of the order limits, in their canonical order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const BOUNDS = array( 'max', 'min' );

	/**
	 * How the canonical form is encoded.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

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
					name: self::FIELD_PRESENCE,
					type: FieldType::String,
					description: 'The presence the store chose for each checkout field whose presence differs from its default, as a JSON object of field key to hidden, optional or required.',
					label: static fn(): string => __( 'Checkout fields', 'seocart' ),
					example: '{"company":"optional"}',
					default_value: self::NONE,
					max_length: 8192
				),
				exposed: false,
				check: static fn( int|string $value ): string => self::encode( self::presences( (string) $value ) )
			),
			Setting::scalar(
				group: self::GROUP,
				field: new FieldSpec(
					name: self::ORDER_LIMITS,
					type: FieldType::String,
					description: 'The smallest and the largest order the store takes in each currency, as a JSON object of currency code to max and min, each in the currency\'s minor units or null for no bound.',
					label: static fn(): string => __( 'Order limits', 'seocart' ),
					example: '{"USD":{"max":null,"min":2500}}',
					default_value: self::NONE,
					max_length: 16384
				),
				exposed: false,
				check: static fn( int|string $value ): string => self::encode( self::limits( (string) $value ) )
			),
			Setting::scalar(
				group: self::GROUP,
				field: self::accountsField(),
				exposed: true
			),
		);
	}

	/**
	 * Declares the store's choice of accounts at checkout, the one field the setting stores and the checkout form answers.
	 *
	 * The setting's field is optional and `optional` until the store chooses; an answer's member is
	 * the same field, always given, with no default.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $answered Optional. True for an answer's member; false for the setting's field. Default false.
	 * @return FieldSpec The field.
	 */
	public static function accountsField( bool $answered = false ): FieldSpec {
		return new FieldSpec(
			name: self::ACCOUNTS,
			type: FieldType::String,
			description: 'Whether a shopper must be signed in to place an order: optional takes orders from guests and signed-in customers alike, required from signed-in customers only, and guest_only offers no sign-in, though a signed-in customer\'s order is still theirs.',
			label: static fn(): string => __( 'Accounts at checkout', 'seocart' ),
			example: AccountsAtCheckout::Required->value,
			required: $answered,
			default_value: $answered ? null : AccountsAtCheckout::Optional->value,
			allowed: array_column( AccountsAtCheckout::cases(), 'value' )
		);
	}

	/**
	 * Reads stored presence choices.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the text is not a JSON object of field keys to presences.
	 *
	 * @param string $json The stored text.
	 * @return array<string, Presence> The choices, by field key, sorted by key.
	 */
	public static function presences( string $json ): array {
		$choices = array();

		foreach ( self::object( $json ) as $key => $presence ) {
			if ( ! CheckoutField::isKey( (string) $key ) || ! is_string( $presence ) || null === Presence::tryFrom( $presence ) ) {
				throw new \InvalidArgumentException( 'The checkout field presences are a JSON object of field keys to hidden, optional or required.' );
			}

			$choices[ (string) $key ] = Presence::from( $presence );
		}

		ksort( $choices, SORT_STRING );

		return $choices;
	}

	/**
	 * Reads stored order limits.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the text is not a JSON object of currency codes to a
	 *                                   max and a min, each null or a whole number from 1, the min
	 *                                   not above the max.
	 *
	 * @param string $json The stored text.
	 * @return array<string, array{max: int|null, min: int|null}> The limits, by currency code, sorted by
	 *                                                            code, without an entry that bounds nothing.
	 */
	public static function limits( string $json ): array {
		$limits = array();

		foreach ( self::object( $json ) as $code => $entry ) {
			if ( ! is_array( $entry ) || self::BOUNDS !== array_keys( self::sorted( $entry ) ) || ! self::isCurrency( (string) $code ) ) {
				throw new \InvalidArgumentException( 'The order limits are a JSON object of currency codes to a max and a min.' );
			}

			$max = $entry['max'];
			$min = $entry['min'];

			foreach ( array( $max, $min ) as $bound ) {
				if ( null !== $bound && ( ! is_int( $bound ) || $bound < 1 ) ) {
					throw new \InvalidArgumentException( 'An order limit is null or a whole number of minor units from 1.' );
				}
			}

			if ( null !== $max && null !== $min && $min > $max ) {
				throw new \InvalidArgumentException( 'An order limit\'s minimum is not above its maximum.' );
			}

			if ( null !== $max || null !== $min ) {
				$limits[ (string) $code ] = array(
					'max' => $max,
					'min' => $min,
				);
			}
		}

		ksort( $limits, SORT_STRING );

		return $limits;
	}

	/**
	 * Writes choices or limits in their canonical form.
	 *
	 * @since 0.2.0
	 *
	 * @param array $values The presences (presences()) or the limits (limits()), sorted by key.
	 * @return string The JSON text: an object, its keys in the order given.
	 *
	 * @phpstan-param array<string, Presence|array{max: int|null, min: int|null}> $values
	 */
	public static function encode( array $values ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The settings' check runs while they are declared, which calls no WordPress function.
		return json_encode( (object) $values, self::JSON_FLAGS );
	}

	/**
	 * Tells whether a text is a currency code SEOCart knows.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code The text.
	 * @return bool True when Currency::of() accepts it.
	 */
	public static function isCurrency( string $code ): bool {
		try {
			Currency::of( $code );
		} catch ( CodedException ) {
			return false;
		}

		return true;
	}

	/**
	 * Decodes a stored text that must be a JSON object.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When it is not.
	 *
	 * @param string $json The text.
	 * @return array<mixed> The object's members.
	 */
	private static function object( string $json ): array {
		try {
			$decoded = json_decode( $json, false, 4, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $broken ) {
			throw new \InvalidArgumentException( 'A checkout setting holds a JSON object.', 0, $broken );
		}

		if ( ! $decoded instanceof \stdClass ) {
			throw new \InvalidArgumentException( 'A checkout setting holds a JSON object.' );
		}

		return json_decode( $json, true, 4, JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns an array with its keys sorted.
	 *
	 * @since 0.2.0
	 *
	 * @param array $values The array.
	 * @return array<mixed> The array, sorted by key.
	 *
	 * @phpstan-param array<mixed> $values
	 */
	private static function sorted( array $values ): array {
		ksort( $values, SORT_STRING );

		return $values;
	}
}
