<?php
/**
 * SafeModeStatus: whether Safe Mode is on, and why
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * The answers SafeMode::status() gives.
 *
 * Owns one fact: the reasons Safe Mode can be on. Three of them are the reason an operator or the
 * installation records in the boot record (recordable()). A canary failure is recorded too, but
 * apart from that reason, by the secrets canary, so that neither ends the other. The other two are
 * worked out on each request, from the SEOCART_SAFE_MODE constant and from comparing the recorded
 * address with the current one.
 * The backing values are what the boot record stores and what `wp seocart safe-mode status` prints.
 *
 * @since 0.1.0
 */
enum SafeModeStatus: string {

	/**
	 * Safe Mode is off: the plugin may act on the outside world.
	 *
	 * @since 0.1.0
	 */
	case Off = 'off';

	/**
	 * SEOCART_SAFE_MODE is true in wp-config.php.
	 *
	 * @since 0.1.0
	 */
	case Constant = 'constant';

	/**
	 * An operator switched it on with `wp seocart safe-mode on`.
	 *
	 * @since 0.1.0
	 */
	case Manual = 'manual';

	/**
	 * The site's address differs from the one recorded at installation: this may be a copy.
	 *
	 * @since 0.1.0
	 */
	case UrlChanged = 'url_changed';

	/**
	 * The stored credentials cannot be decrypted, so none of them can be trusted.
	 *
	 * @since 0.1.0
	 */
	case Canary = 'canary';

	/**
	 * The merchant said this site is a copy of another store and should stay in Safe Mode.
	 *
	 * @since 0.1.0
	 */
	case Copy = 'copy';

	/**
	 * The installation record was lost and rebuilt, so the site's identity is unknown.
	 *
	 * @since 0.1.0
	 */
	case Rebuilt = 'rebuilt';

	/**
	 * Returns the reasons an operator or the installation records in the boot record.
	 *
	 * @since 0.1.0
	 *
	 * @return list<self> Manual, Copy and Rebuilt.
	 */
	public static function recordable(): array {
		return array( self::Manual, self::Copy, self::Rebuilt );
	}
}
