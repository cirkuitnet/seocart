<?php
/**
 * GatewayStatuses: reads the status of the store's gateways, and of the gateways its open payments name
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Support\Error\CodedException;

/**
 * The status of each registered gateway, the gateways open payments name that are not registered, and the registrations refused.
 *
 * Owns one fact: how a gateway's status is read. Its declaration and plugin come from the
 * registry, the switch and Safe Mode from the boot record the site has loaded, its credentials'
 * state from its settings document's stored text (no credential is opened), and the open
 * payments of every gateway from one statement, read once and shared by every status this
 * reader gives. Writes nothing.
 *
 * @since 0.2.0
 */
final class GatewayStatuses {

	/**
	 * The open intents per gateway and mode, once read.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{gateway_id: string, mode: Mode, open: int, waiting: int, waited_seconds: int|null}>|null
	 */
	private ?array $open = null;

	/**
	 * Creates the reader. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways          $gateways The registry.
	 * @param PaymentRepository $payments The payments, whose open intents are counted.
	 */
	public function __construct( private Gateways $gateways, private PaymentRepository $payments ) {
	}

	/**
	 * Returns the status of every registered gateway, in the order they registered.
	 *
	 * @since 0.2.0
	 *
	 * @return list<GatewayStatus> The statuses.
	 */
	public function all(): array {
		return array_map( fn( string $gatewayId ): GatewayStatus => $this->of( $gatewayId ), $this->gateways->ids() );
	}

	/**
	 * Returns the status of a registered gateway.
	 *
	 * A settings document the store cannot read leaves the modes unknown (null) and every mode's
	 * credentials unreadable, rather than failing the whole status.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return GatewayStatus The status.
	 */
	public function of( string $gatewayId ): GatewayStatus {
		$descriptor  = $this->gateways->descriptor( $gatewayId );
		$credentials = array();

		foreach ( $descriptor->modes as $mode ) {
			$credentials[ $mode->value ] = $this->gateways->credentials( $gatewayId, $mode );
		}

		try {
			$mode      = $this->gateways->mode( $gatewayId );
			$effective = $this->gateways->effectiveMode( $gatewayId );
		} catch ( CodedException $unread ) {
			if ( SettingsError::StoredValueInvalid !== $unread->errorCode() ) {
				throw $unread;
			}

			$mode      = null;
			$effective = null;
		}

		$open = 0;

		foreach ( $this->openIntents() as $row ) {
			if ( $gatewayId === $row['gateway_id'] ) {
				$open += $row['open'];
			}
		}

		return new GatewayStatus(
			$descriptor->id,
			$descriptor->label(),
			$descriptor->type,
			$descriptor->contract,
			$this->gateways->pluginOf( $gatewayId ),
			$mode,
			$effective,
			$this->gateways->isEnabled( $gatewayId ),
			$credentials,
			$descriptor->matrix->currencies(),
			$descriptor->hosts,
			$open,
			$this->gateways->webhookEvents( $gatewayId )
		);
	}

	/**
	 * Returns the gateways open payments name that are not registered, with how many of their payments are open.
	 *
	 * Such payments cannot be captured, voided, refunded or reconciled until the gateway is
	 * registered again: its plugin active, and its registration accepted (refusalReasons() says
	 * why SEOCart refused one).
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int> The open payments, by gateway id.
	 */
	public function unregistered(): array {
		$registered = array_flip( $this->gateways->ids() );
		$counts     = array();

		foreach ( $this->openIntents() as $row ) {
			if ( ! isset( $registered[ $row['gateway_id'] ] ) ) {
				$counts[ $row['gateway_id'] ] = ( $counts[ $row['gateway_id'] ] ?? 0 ) + $row['open'];
			}
		}

		return $counts;
	}

	/**
	 * Returns why the registry refused, in this request, each gateway id it read before refusing it.
	 *
	 * What "not registered" means for a gateway whose plugin is active: SEOCart refused it (an
	 * incompatible contract, settings it cannot keep). A registration refused before its id could
	 * be read is listed by refused() under its class alone.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The reason of each id's first refusal, such as `incompatible`, by gateway id.
	 */
	public function refusalReasons(): array {
		$reasons = array();

		foreach ( $this->gateways->refused() as $refusal ) {
			$reasons[ $refusal->gateway ] ??= $refusal->reason;
		}

		return $reasons;
	}

	/**
	 * Returns the registrations the registry refused in this request.
	 *
	 * @since 0.2.0
	 *
	 * @return list<RefusedRegistration> The refusals.
	 */
	public function refused(): array {
		return $this->gateways->refused();
	}

	/**
	 * Returns the open intents per gateway and mode, read once per reader.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{gateway_id: string, mode: Mode, open: int, waiting: int, waited_seconds: int|null}> The counts.
	 */
	public function openIntents(): array {
		return $this->open ??= $this->payments->openIntents();
	}
}
