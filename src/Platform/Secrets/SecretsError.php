<?php
/**
 * SecretsError: the error catalog of the secrets module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors sealing, opening and managing secrets can end in.
 *
 * This enum owns one fact: how a failure of the secrets machinery is reported. None of them is a
 * client's doing, and none carries a secret: their context names a data key's id, a record's
 * name or nothing. A secret that cannot be opened is always one of these errors, never an empty
 * string.
 *
 * @since 0.1.0
 */
enum SecretsError: string implements ErrorCode {

	/**
	 * Neither PHP's sodium extension nor WordPress's sodium_compat provides the cipher.
	 *
	 * @since 0.1.0
	 */
	case NoCipher = 'secrets.no_cipher';

	/**
	 * SEOCART_ENCRYPTION_KEY is defined, but is not the base64 encoding of 32 bytes.
	 *
	 * @since 0.1.0
	 */
	case EncryptionKeyInvalid = 'secrets.encryption_key_invalid';

	/**
	 * No data key has been created yet: the plugin's activation creates the first one.
	 *
	 * @since 0.1.0
	 */
	case NotInitialized = 'secrets.not_initialized';

	/**
	 * The stored data keys are damaged or missing although the site has had keys: the data keys
	 * option holds no usable active key, or is gone while the key registry lists keys. Nothing is
	 * replaced, so the option can still be restored.
	 *
	 * @since 0.1.0
	 */
	case KeysDamaged = 'secrets.keys_damaged';

	/**
	 * A data key cannot be used: it is wrapped, and the encryption key is absent or not the one
	 * that wrapped it, or its stored form is damaged.
	 *
	 * @since 0.1.0
	 */
	case KeyUnavailable = 'secrets.key_unavailable';

	/**
	 * A sealed value names a data key the site does not have.
	 *
	 * @since 0.1.0
	 */
	case UnknownKey = 'secrets.unknown_key';

	/**
	 * A sealed value does not open: it was altered, or it belongs to another record.
	 *
	 * @since 0.1.0
	 */
	case Unreadable = 'secrets.unreadable';

	/**
	 * A new data key cannot be created while the previous rotation still has records to re-seal.
	 *
	 * @since 0.1.0
	 */
	case RotationPending = 'secrets.rotation_pending';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::NoCipher,
				500,
				static fn(): string => __( 'Secrets cannot be sealed or opened: this server provides neither PHP\'s sodium extension nor the sodium_compat library WordPress ships.', 'seocart' )
			),
			new ErrorDefinition(
				self::EncryptionKeyInvalid,
				500,
				static fn(): string => __( 'SEOCART_ENCRYPTION_KEY is defined in wp-config.php, but it is not the base64 encoding of 32 bytes.', 'seocart' )
			),
			new ErrorDefinition(
				self::NotInitialized,
				500,
				static fn(): string => __( 'Secrets cannot be stored yet: no data key has been created. Deactivate and activate SEOCart to create one.', 'seocart' )
			),
			new ErrorDefinition(
				self::KeysDamaged,
				500,
				static fn(): string => __( 'SEOCart\'s stored data keys are damaged or missing, although secrets were sealed with them. Nothing was replaced: restore the seocart_data_keys option from a backup.', 'seocart' )
			),
			new ErrorDefinition(
				self::KeyUnavailable,
				500,
				static fn(): string =>
					/* translators: %1$s: The id of a data key, sixteen hexadecimal digits. */
					__( 'The data key %1$s cannot be used: SEOCART_ENCRYPTION_KEY is missing or is not the key that protected it, or its stored form is damaged.', 'seocart' ),
				array( 'key_id' )
			),
			new ErrorDefinition(
				self::UnknownKey,
				500,
				static fn(): string =>
					/* translators: %1$s: The id of a data key, sixteen hexadecimal digits. */
					__( 'A stored secret was sealed with the data key %1$s, which this site does not have.', 'seocart' ),
				array( 'key_id' )
			),
			new ErrorDefinition(
				self::Unreadable,
				500,
				static fn(): string =>
					/* translators: %1$s: The name of a stored secret, for example seocart_data_keys/secrets_canary. */
					__( 'The stored secret %1$s cannot be opened: it was changed, or copied from another record. Enter it again.', 'seocart' ),
				array( 'record' )
			),
			new ErrorDefinition(
				self::RotationPending,
				409,
				static fn(): string =>
					/* translators: %1$s: The id of a data key, sixteen hexadecimal digits. */
					__( 'The data key %1$s still seals stored secrets. Run wp seocart secrets rekey until it is retired, then rotate again.', 'seocart' ),
				array( 'key_id' )
			),
		);
	}
}
