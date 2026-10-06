<?php
/**
 * CredentialState: whether a gateway's settings for a mode can be used, judged without opening a credential
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

/**
 * The state of a gateway's settings for one mode, as the stored document shows it.
 *
 * Owns one fact: the three words the status of a gateway, doctor and Site Health use for its
 * credentials. Judged from the stored text alone (GatewayModeSettings::credentials()): no
 * credential is opened to say it.
 *
 * @since 0.2.0
 */
enum CredentialState: string {

	/**
	 * Every setting of the mode is saved, and each credential is sealed with a data key the site holds.
	 *
	 * @since 0.2.0
	 */
	case Configured = 'configured';

	/**
	 * A setting of the mode without a default was never saved.
	 *
	 * @since 0.2.0
	 */
	case Missing = 'missing';

	/**
	 * A credential is not a sealed value naming a data key the site holds, or the document cannot be read.
	 *
	 * @since 0.2.0
	 */
	case Unreadable = 'unreadable';
}
