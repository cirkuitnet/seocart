<?php
/**
 * GatewayStatus: what an operator, a command or a screen is shown of one registered gateway
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\Mode;

/**
 * The state of one registered gateway, as GatewayStatuses reads it: words and counts, never a setting's value.
 *
 * Owns one fact: what the status of a gateway says. Its declaration (id, label, type, contract,
 * currencies, hosts), the plugin it was loaded from, the mode it is set to and the mode new
 * payments are created in now, whether an operator switched it off, the state of its settings for
 * each mode it declares (judged without opening a credential), and how many of its payments are
 * open. The command prints it, and an admin screen can show it; nothing in it is a secret.
 *
 * @since 0.2.0
 */
final readonly class GatewayStatus {

	/**
	 * Records the status.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $id            The gateway's id.
	 * @param string      $label         Its name for people, translated.
	 * @param string      $type          The kind of integration, such as `payments`.
	 * @param string      $contract      The payment contract version it was written against.
	 * @param string|null $plugin        The slug of the plugin it was loaded from; null for none.
	 * @param Mode|null   $mode          The mode it is set to take new payments in; null when its settings cannot be read.
	 * @param Mode|null   $effectiveMode The mode new payments are created in now, test while Safe Mode is on; null likewise.
	 * @param bool        $enabled       False while an operator has switched it off.
	 * @param array       $credentials   The state of its settings, by mode, for each mode it declares.
	 * @param array       $currencies    The currencies its capability matrix declares.
	 * @param array       $hosts         The external services it calls.
	 * @param int         $openIntents   How many of its payments are open: waiting for an answer, or authorized.
	 * @param array       $webhookEvents The provider's event types its webhook endpoints subscribe to, when it sets them up itself.
	 *
	 * @phpstan-param array<string, CredentialState> $credentials
	 * @phpstan-param list<string>                   $currencies
	 * @phpstan-param list<OutboundHost>             $hosts
	 * @phpstan-param list<string>                   $webhookEvents
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $type,
		public string $contract,
		public ?string $plugin,
		public ?Mode $mode,
		public ?Mode $effectiveMode,
		public bool $enabled,
		public array $credentials,
		public array $currencies,
		public array $hosts,
		public int $openIntents,
		public array $webhookEvents = array()
	) {
	}

	/**
	 * Returns the status as data, for a command's JSON or a screen.
	 *
	 * @since 0.2.0
	 *
	 * @return array{id: string, label: string, type: string, contract: string, plugin: string|null, mode: string|null, effective_mode: string|null, enabled: bool, credentials: array<string, string>, currencies: list<string>, hosts: list<array{id: string, host: string, service: string}>, open_intents: int, webhook_events: list<string>} The status.
	 */
	public function toArray(): array {
		return array(
			'id'             => $this->id,
			'label'          => $this->label,
			'type'           => $this->type,
			'contract'       => $this->contract,
			'plugin'         => $this->plugin,
			'mode'           => $this->mode?->value,
			'effective_mode' => $this->effectiveMode?->value,
			'enabled'        => $this->enabled,
			'credentials'    => array_map( static fn( CredentialState $state ): string => $state->value, $this->credentials ),
			'currencies'     => $this->currencies,
			'hosts'          => array_map(
				static fn( OutboundHost $host ): array => array(
					'id'      => $host->id,
					'host'    => $host->host,
					'service' => $host->service,
				),
				$this->hosts
			),
			'open_intents'   => $this->openIntents,
			'webhook_events' => $this->webhookEvents,
		);
	}
}
