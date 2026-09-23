<?php
/**
 * SecretSealer: turns the plain text of a secret setting into the sealed form the store keeps
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which the settings service seals a secret before the store writes it.
 *
 * This interface owns one fact: that a secret leaves the settings module sealed. The settings
 * service hands the plain text of a secret here and writes only what comes back; the store
 * refuses a secret that is not sealed. The secrets module implements it.
 *
 * @since 0.1.0
 */
interface SecretSealer {

	/**
	 * Checks the plain text of a secret against its setting, and seals it.
	 *
	 * Call it inside the transaction that writes the sealed value: the key it seals with stays
	 * the active key until that transaction ends, so no rotation can retire it before the write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the setting is not a secret, or the text does not fit it.
	 * @throws \LogicException           When no transaction is open.
	 * @throws CodedException            When the text cannot be sealed: no key, or no cipher.
	 *
	 * @param Setting $setting   The secret setting.
	 * @param string  $plaintext The plain text.
	 * @return string The sealed form, which the store keeps in place of the text.
	 */
	public function seal( Setting $setting, #[\SensitiveParameter] string $plaintext ): string;
}
