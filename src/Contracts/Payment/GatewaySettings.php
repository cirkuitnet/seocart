<?php
/**
 * GatewaySettings: a gateway's settings and credentials for one mode, read when asked
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * The settings a gateway declared, as the merchant configured them for one mode.
 *
 * Owns one fact: how a gateway reads its configuration. Each read goes to the gateway's settings
 * document when it is made, so a gateway keeps nothing: a credential is opened for the call that
 * needs it and held in memory only for that call. Settings are named as the gateway declared them
 * in its descriptor (GatewayDescriptor::$settings), without the mode.
 *
 * @since 0.2.0
 *
 * @api
 */
interface GatewaySettings {

	/**
	 * Returns a setting that is not a credential.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway declared no such setting, or declared it a credential.
	 *
	 * @param string $name The setting's name, as declared.
	 * @return int|string|null The value the merchant saved, or the setting's default; null when it has neither.
	 */
	public function value( string $name ): int|string|null;

	/**
	 * Opens a credential, for the call that needs it.
	 *
	 * Never log, store or return what it answers.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway declared no such setting, or declared it not a credential.
	 * @throws CredentialUnavailable     When the credential was never saved, or does not open: the gateway answers that
	 *                                   it is unavailable, before it sends anything.
	 *
	 * @param string $name The setting's name, as declared.
	 * @return string The credential.
	 */
	public function secret( string $name ): string;
}
