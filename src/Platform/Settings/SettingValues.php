<?php
/**
 * SettingValues: whether a value may be stored in a setting, and what a stored value means
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception below names a setting for the developer whose code wrote it; it is never rendered as HTML.

/**
 * Checks a value against its setting before it is written, and reads a stored value back.
 *
 * This class owns one fact: which values a setting can hold. The store runs forStorage() on every
 * write, whoever the writer is, and fromStorage() on every read, so an option edited by hand, or
 * left behind by a declaration that has since changed, is reported rather than used.
 *
 * check() enforces the field's declared constraints, as the operation surfaces already have with
 * WordPress's validator on the schema compiled from the same field, and then the setting's own
 * check. The two enforcements of the constraints are one rule written for two readers: a value a
 * surface lets through is never refused here, and nothing accepted here is refused by a surface.
 * An integration test holds them to that over every constraint a field can declare.
 *
 * A value that breaks the field's constraints can only come from the plugin's own code — a client's
 * would have been refused by the surface — so check() refuses it with an \InvalidArgumentException.
 * The setting's own check may raise one of its declared codes, which reach the client.
 *
 * A secret has two forms. check() judges the plain text, before it is sealed. What the store
 * writes and reads is the sealed form, which forStorage() and fromStorage() accept only in the
 * shape a sealed value has — a version tag, such as `v1`, and colon-separated base64url parts —
 * so a secret cannot reach the options table as plain text by mistake. Which sealed forms exist,
 * and how they are opened, is the secrets module's business, not this class's.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class SettingValues {

	/**
	 * The shape of a uuid: lower-case, as FieldType::Uuid declares it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * The shape of an integer stored as text.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INTEGER_PATTERN = '/^-?(?:0|[1-9][0-9]*)\z/';

	/**
	 * The shape of a sealed secret: a version tag, then colon-separated base64url parts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SEALED_PATTERN = '/^[a-z][0-9]+(?::[A-Za-z0-9_-]+)+\z/';

	/**
	 * Cannot be called: the class is used through its static functions only.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Checks a value about to be written.
	 *
	 * The setting's own check may also refuse the value, with one of its declared codes or, for a
	 * value no client can send, an \InvalidArgumentException of its own.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the value does not fit the field.
	 * @phpstan-throws \InvalidArgumentException|CodedException
	 *
	 * @param Setting $setting The setting.
	 * @param mixed   $value   The value.
	 * @return int|string The value to store.
	 */
	public static function check( Setting $setting, mixed $value ): int|string {
		$reason = self::constraintBroken( $setting->field(), $value );

		if ( null !== $reason || ( ! is_int( $value ) && ! is_string( $value ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'The value given for the setting %1$s %2$s.', $setting->name(), $reason ?? 'is neither an integer nor text' ) );
		}

		return $setting->applyCheck( $value );
	}

	/**
	 * Checks a value about to be stored: the plain value of a setting, or the sealed form of a secret.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the value does not fit the field, or a secret is not sealed.
	 * @phpstan-throws \InvalidArgumentException|CodedException
	 *
	 * @param Setting $setting The setting.
	 * @param mixed   $value   The value, sealed when the setting is a secret.
	 * @return int|string The value to store.
	 */
	public static function forStorage( Setting $setting, mixed $value ): int|string {
		if ( ! $setting->isSecret() ) {
			return self::check( $setting, $value );
		}

		if ( ! is_string( $value ) || 1 !== preg_match( self::SEALED_PATTERN, $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'The value given for the secret %1$s is not sealed; a secret is stored sealed, never as plain text.', $setting->name() ) );
		}

		return $value;
	}

	/**
	 * Reads a value as the options table or a document holds it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when the value is not one the
	 *                        setting can hold.
	 *
	 * @param Setting $setting The setting.
	 * @param mixed   $stored  The stored value: text from the options table, or a JSON scalar
	 *                         from a document.
	 * @return int|string The value.
	 */
	public static function fromStorage( Setting $setting, mixed $stored ): int|string {
		if ( FieldType::Integer === $setting->field()->type() && is_string( $stored ) && 1 === preg_match( self::INTEGER_PATTERN, $stored ) && (string) (int) $stored === $stored ) {
			$stored = (int) $stored;
		}

		try {
			return self::forStorage( $setting, $stored );
		} catch ( \InvalidArgumentException | CodedException ) {
			// Why the value is refused is not the reader's to know; which option holds it is.
			CodedException::raise( SettingsError::StoredValueInvalid, array( 'option' => $setting->optionName() ) );
		}
	}

	/**
	 * Returns the text an option holds for a checked value.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $value A value check() accepted.
	 * @return string The text: an integer in decimal, text as it is.
	 */
	public static function toOption( int|string $value ): string {
		return (string) $value;
	}

	/**
	 * Tells which declared constraint of a field a value breaks.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec $field The field.
	 * @param mixed     $value The value.
	 * @return string|null What is wrong, completing "The value given for the setting X …", or null
	 *                     when the value fits.
	 */
	private static function constraintBroken( FieldSpec $field, mixed $value ): ?string {
		if ( FieldType::Integer === $field->type() ) {
			if ( ! is_int( $value ) ) {
				return 'is not an integer';
			}

			if ( ( null !== $field->minimum() && $value < $field->minimum() ) || ( null !== $field->maximum() && $value > $field->maximum() ) ) {
				return 'is outside the range the field allows';
			}

			return null;
		}

		if ( ! is_string( $value ) ) {
			return 'is not text';
		}

		if ( FieldType::Uuid === $field->type() && 1 !== preg_match( self::UUID_PATTERN, $value ) ) {
			return 'is not a lower-case uuid';
		}

		if ( array() !== $field->allowedValues() && ! in_array( $value, $field->allowedValues(), true ) ) {
			return 'is not one of the values the field allows';
		}

		if ( null !== $field->maxLength() && mb_strlen( $value ) > $field->maxLength() ) {
			return 'is longer than the field allows';
		}

		return null;
	}
}
