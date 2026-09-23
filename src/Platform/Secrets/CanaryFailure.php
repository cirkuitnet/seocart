<?php
/**
 * CanaryFailure: why the secrets canary did not open
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The reasons the secrets canary can fail, each with the likely cause an administrator is told.
 *
 * This enum owns one fact: what a failed canary means. The kernel enters Safe Mode on any of
 * them, and names the likely cause in its notice with CanaryResult::cause().
 *
 * @since 0.1.0
 */
enum CanaryFailure: string {

	/**
	 * Neither PHP's sodium extension nor WordPress's sodium_compat provides the cipher.
	 *
	 * @since 0.1.0
	 */
	case NoCipher = 'no_cipher';

	/**
	 * There is no data key or no canary: the plugin was never activated on this site, or the
	 * option that holds them was lost.
	 *
	 * @since 0.1.0
	 */
	case NotInitialized = 'not_initialized';

	/**
	 * The data key is wrapped, and SEOCART_ENCRYPTION_KEY is defined but is not a key.
	 *
	 * @since 0.1.0
	 */
	case EncryptionKeyInvalid = 'encryption_key_invalid';

	/**
	 * The data key is wrapped, and SEOCART_ENCRYPTION_KEY is not defined.
	 *
	 * @since 0.1.0
	 */
	case EncryptionKeyAbsent = 'encryption_key_absent';

	/**
	 * The data key is wrapped, and SEOCART_ENCRYPTION_KEY does not unwrap it: the constant changed,
	 * or the database was restored onto another site.
	 *
	 * @since 0.1.0
	 */
	case EncryptionKeyMismatch = 'encryption_key_mismatch';

	/**
	 * The stored data keys are damaged or missing although the site has had keys: the data keys
	 * option is not a document, holds no usable active key or no canary, or is gone while the key
	 * registry lists keys.
	 *
	 * @since 0.1.0
	 */
	case KeysDamaged = 'keys_damaged';

	/**
	 * The canary was sealed with another key than the active one: the data key was replaced.
	 *
	 * @since 0.1.0
	 */
	case KeyReplaced = 'key_replaced';

	/**
	 * The canary does not open with the active key: the key or the canary was changed.
	 *
	 * @since 0.1.0
	 */
	case Unreadable = 'unreadable';

	/**
	 * The canary opens, but not to the text the plugin sealed.
	 *
	 * @since 0.1.0
	 */
	case Mismatch = 'mismatch';
}
