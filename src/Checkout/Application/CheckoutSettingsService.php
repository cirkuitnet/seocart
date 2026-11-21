<?php
/**
 * CheckoutSettingsService: the store's choices for its checkout fields and its order limits, read and changed by staff
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\CompiledFields;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Platform\Settings\SettingValues;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a programming error to the developer; it is never HTML. Coded errors go through CodedException.

/**
 * Performs `checkout.get_fields`, `checkout.update_fields`, `checkout.get_order_limits` and `checkout.update_order_limits`.
 *
 * Owns one fact: how a staff change reaches the `checkout` settings group without losing another
 * one made at the same moment. A read is the checkout's rules' (CheckoutRules), one statement
 * cold. A change is checked in full first, then made by compare-and-swap: the option's text is read
 * past the cache, the change applied to it, and SettingsStore::swapScalar() replaces it only if it
 * still holds exactly that text; a writer that lost the race reads again, at most ATTEMPTS times
 * in all, then is told `settings.version_conflict`. Two staff changing two fields at once keep
 * both changes. A change that changes nothing writes nothing.
 *
 * An option never written has no row to compare with, so a first change creates it
 * (SettingsStore::createScalar()), with an insert that the option name's unique key lets only one
 * writer win and that never replaces a row. A first change that lost to another writer reads the
 * winner's row and swaps, within the same attempts, so it never overwrites what landed meanwhile.
 *
 * Presence choices are kept only where they differ from a field's default; a choice for a key no
 * declaration names is dropped by the next change. Limits are replaced whole.
 *
 * @since 0.2.0
 */
final class CheckoutSettingsService {

	/**
	 * How many times a change reads and swaps before it gives up.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const ATTEMPTS = 3;

	/**
	 * The reason of a limit whose currency SEOCart does not know.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNKNOWN_CURRENCY = 'unknown_currency';

	/**
	 * The reason of a currency given twice.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * The reason of a limit whose minimum is above its maximum.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MIN_ABOVE_MAX = 'min_above_max';

	/**
	 * Creates the service. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutRules $rules The checkout's rules: the declared fields and the group's one reader.
	 * @param SettingsStore $store The store's settings, which the changes are written through.
	 */
	public function __construct(
		private CheckoutRules $rules,
		private SettingsStore $store
	) {
	}

	/**
	 * Performs `checkout.get_fields`: every declared field, hidden ones too, with its presence in this store.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The prepared input: the operation declares none.
	 * @param Actor                $actor Who reads.
	 * @return array{fields: list<array<string, mixed>>} The fields, by wire name, in form order.
	 */
	public function getFields( array $input, Actor $actor ): array {
		unset( $input, $actor );

		return self::fieldList( $this->rules->current() );
	}

	/**
	 * Performs `checkout.update_fields`: sets the presence of the fields named, keeps every other.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.field_unknown` for a key no declaration names, and
	 *                        `checkout.presence_not_allowed` for a presence the field does not allow,
	 *                        before anything is written; `settings.version_conflict` when another
	 *                        change kept winning.
	 *
	 * @param array<string, mixed> $input The prepared input: `fields`, a list of `{ key, presence }`.
	 * @param Actor                $actor Who changes them.
	 * @return array{fields: list<array<string, mixed>>} Every field, as getFields() answers it.
	 */
	public function updateFields( array $input, Actor $actor ): array {
		unset( $actor );

		$declared = new CompiledFields( $this->rules->fields(), array() );
		$chosen   = array();

		foreach ( (array) $input['fields'] as $change ) {
			$key      = (string) $change['key'];
			$field    = $declared->field( $key ) ?? CodedException::raise( CheckoutError::FieldUnknown, array( 'field' => $key ) );
			$presence = Presence::from( (string) $change['presence'] );

			if ( ! $field->allows( $presence ) ) {
				CodedException::raise(
					CheckoutError::PresenceNotAllowed,
					array(
						'field'    => $key,
						'presence' => $presence->value,
					)
				);
			}

			$chosen[ $key ] = $presence;
		}

		$written = $this->swap(
			CheckoutSettings::FIELD_PRESENCE,
			static fn( string $stored ): string => CheckoutSettings::encode( ( new CompiledFields( $declared->all(), $chosen + CheckoutSettings::presences( $stored ) ) )->choices() )
		);

		return self::fieldList( new CompiledFields( $declared->all(), CheckoutSettings::presences( $written ) ) );
	}

	/**
	 * Performs `checkout.get_order_limits`: the smallest and largest order the store takes, per currency.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The prepared input: the operation declares none.
	 * @param Actor                $actor Who reads.
	 * @return array{limits: list<array{currency: string, min_subtotal_minor: int|null, max_subtotal_minor: int|null}>} The limits, by currency code.
	 */
	public function getOrderLimits( array $input, Actor $actor ): array {
		unset( $input, $actor );

		return self::limitList( $this->rules->orderLimits() );
	}

	/**
	 * Performs `checkout.update_order_limits`: replaces the store's order limits with the ones given.
	 *
	 * A currency left out has no limit, and so has an entry that bounds nothing.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.invalid_order_limits`, naming the currency and why: a code
	 *                        SEOCart does not know, a currency given twice, or a minimum above the
	 *                        maximum, before anything is written; `settings.version_conflict` when
	 *                        another change kept winning.
	 *
	 * @param array<string, mixed> $input The prepared input: `limits`, a list of `{ currency, min_subtotal_minor, max_subtotal_minor }`.
	 * @param Actor                $actor Who changes them.
	 * @return array{limits: list<array{currency: string, min_subtotal_minor: int|null, max_subtotal_minor: int|null}>} The limits now, as getOrderLimits() answers them.
	 */
	public function updateOrderLimits( array $input, Actor $actor ): array {
		unset( $actor );

		$limits = array();

		foreach ( (array) $input['limits'] as $entry ) {
			$currency = (string) $entry['currency'];
			$min      = isset( $entry['min_subtotal_minor'] ) ? (int) $entry['min_subtotal_minor'] : null;
			$max      = isset( $entry['max_subtotal_minor'] ) ? (int) $entry['max_subtotal_minor'] : null;
			$reason   = match ( true ) {
				! CheckoutSettings::isCurrency( $currency )   => self::UNKNOWN_CURRENCY,
				isset( $limits[ $currency ] )                 => self::DUPLICATE,
				null !== $min && null !== $max && $min > $max => self::MIN_ABOVE_MAX,
				default                                       => null,
			};

			if ( null !== $reason ) {
				CodedException::raise(
					CheckoutError::InvalidOrderLimits,
					array(
						'currency' => $currency,
						'reason'   => $reason,
					)
				);
			}

			$limits[ $currency ] = array(
				'max' => $max,
				'min' => $min,
			);
		}

		$replacement = CheckoutSettings::encode( CheckoutSettings::limits( CheckoutSettings::encode( $limits ) ) );
		$written     = $this->swap( CheckoutSettings::ORDER_LIMITS, static fn(): string => $replacement );

		return self::limitList( CheckoutSettings::limits( $written ) );
	}

	/**
	 * Changes one setting of the group by compare-and-swap, creating it on its first change, and returns what it holds after.
	 *
	 * Each attempt reads the option past the cache. With no row, the change is applied to the
	 * setting's default and the option created (SettingsStore::createScalar()); a create that lost
	 * to another writer's is an attempt lost like a swap, and the next attempt reads the winner's row.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `settings.version_conflict` when ATTEMPTS creates and swaps all lost.
	 *
	 * @param string   $name   The setting: CheckoutSettings::FIELD_PRESENCE or ::ORDER_LIMITS.
	 * @param \Closure $change Given the canonical text read, answers the canonical text to store.
	 * @return string The canonical text the setting holds after the change.
	 *
	 * @phpstan-param \Closure(string): string $change
	 */
	private function swap( string $name, \Closure $change ): string {
		for ( $attempt = 1; $attempt <= self::ATTEMPTS; $attempt++ ) {
			$read = $this->store->storedText( $name );

			if ( null === $read ) {
				$next = $change( CheckoutSettings::NONE );

				if ( $this->store->createScalar( $name, $next ) ) {
					return $next;
				}

				continue;
			}

			$next = $change( self::canonical( $name, $read ) );

			if ( $next === $read || $this->store->swapScalar( $name, $read, $next ) ) {
				return $next;
			}
		}

		CodedException::raise( SettingsError::VersionConflict, array( 'group' => CheckoutSettings::GROUP ) );
	}

	/**
	 * Returns the canonical form of a setting's stored text, as any read of it would, refusing one of another shape with `settings.stored_value_invalid` (SettingValues::fromStorage()).
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the name is not a setting of the group: a programming error.
	 *
	 * @param string       $name   The setting.
	 * @param string|false $stored The stored text, or false for a value that is not text.
	 * @return string The canonical text.
	 */
	private static function canonical( string $name, string|false $stored ): string {
		foreach ( CheckoutSettings::settings() as $setting ) {
			if ( $name === $setting->name() ) {
				return (string) SettingValues::fromStorage( $setting, $stored );
			}
		}

		throw new \LogicException( 'The checkout settings group has no setting named ' . $name . '.' );
	}

	/**
	 * Returns the fields, by wire name, as the staff reads answer them.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields $fields The fields with their presences.
	 * @return array{fields: list<array<string, mixed>>} The fields, in form order.
	 */
	private static function fieldList( CompiledFields $fields ): array {
		return array(
			'fields' => array_map(
				static fn( CheckoutField $field ): array => CheckoutRules::identityOf( $field ) + array(
					'presence'  => $fields->presenceOf( $field )->value,
					'presences' => array_map( static fn( Presence $presence ): array => array( 'presence' => $presence->value ), $field->presences ),
					'source'    => BuiltInFields::SOURCE,
					'locked'    => $field->isLocked(),
				),
				$fields->all()
			),
		);
	}

	/**
	 * Returns the limits, by wire name, as the staff reads answer them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array{max: int|null, min: int|null}> $limits The limits, by currency code.
	 * @return array{limits: list<array{currency: string, min_subtotal_minor: int|null, max_subtotal_minor: int|null}>} The limits.
	 */
	private static function limitList( array $limits ): array {
		$list = array();

		foreach ( $limits as $currency => $limit ) {
			$list[] = array(
				'currency'           => (string) $currency,
				'min_subtotal_minor' => $limit['min'],
				'max_subtotal_minor' => $limit['max'],
			);
		}

		return array( 'limits' => $list );
	}
}
