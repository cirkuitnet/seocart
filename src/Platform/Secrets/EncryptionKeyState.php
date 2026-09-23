<?php
/**
 * EncryptionKeyState: whether wp-config.php defines a usable encryption key
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The three states of the key-encrypting key.
 *
 * This enum owns one fact: what the site's encryption key setting amounts to. Site Health, the
 * status command and the canary all name the state, never the key.
 *
 * @since 0.1.0
 */
enum EncryptionKeyState: string {

	/**
	 * The constant is not defined: data keys are stored unwrapped, in the database.
	 *
	 * @since 0.1.0
	 */
	case Absent = 'absent';

	/**
	 * The constant is defined but is not the base64 encoding of 32 bytes.
	 *
	 * @since 0.1.0
	 */
	case Invalid = 'invalid';

	/**
	 * The constant is defined and usable: data keys are stored wrapped by it.
	 *
	 * @since 0.1.0
	 */
	case Present = 'present';
}
