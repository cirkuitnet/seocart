<?php
/**
 * RefusedRegistration: a gateway the registry did not register, why, and the plugin that asked
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
 * One registration the registry refused in this request, kept so a screen or a command can say SEOCart did not load a gateway, and why.
 *
 * Owns one fact: what is known of a refused registration. The gateway is named by its id when
 * its descriptor could be read, and by its class otherwise; the plugin is the one the gateway's
 * class, or the failing listener, was loaded from. The reason is one word, the detail one
 * sentence in English for an operator, the registry's own: it names an exception a plugin threw
 * by its class, never by its message. Neither ever holds a setting's value.
 *
 * @since 0.2.0
 */
final readonly class RefusedRegistration {

	/**
	 * Records the refusal.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $gateway The gateway's id, or its class when it has none yet.
	 * @param string|null $plugin  The slug of the plugin it was loaded from; null when it comes from none.
	 * @param string      $reason  Why: `late`, `invalid_descriptor`, `incompatible`, `duplicate`,
	 *                             `settings_clash`, `registration_failed` or `webhooks_undeclared`.
	 * @param string      $detail  What the registry found, in English.
	 */
	public function __construct(
		public string $gateway,
		public ?string $plugin,
		public string $reason,
		public string $detail
	) {
	}

	/**
	 * Returns the refusal as data, for a command's JSON.
	 *
	 * @since 0.2.0
	 *
	 * @return array{gateway: string, plugin: string|null, reason: string, detail: string} The refusal.
	 */
	public function toArray(): array {
		return array(
			'gateway' => $this->gateway,
			'plugin'  => $this->plugin,
			'reason'  => $this->reason,
			'detail'  => $this->detail,
		);
	}
}
