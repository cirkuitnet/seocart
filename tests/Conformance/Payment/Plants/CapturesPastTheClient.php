<?php
/**
 * CapturesPastTheClient: a planted gateway whose authorization goes through the plugin's client and whose capture does not
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Authorizes as the reference gateway does, through the plugin's client, and captures through a library of its own.
 *
 * It stands for a gateway that sends some calls through the client and others through a
 * provider's SDK with its own HTTP stack: the capture is answered approved, and none of its
 * requests reaches the client, so no tape records or replays it, while the scenario's other
 * requests do go through the client. The plant answers the capture itself, as that library would
 * from the intent the authorization returned. The plant of rule 12's run-time half: the suite must
 * fail an operation that sent no request of its own through the client.
 *
 * @since 0.2.0
 */
final class CapturesPastTheClient extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the capture sent no request of its own through the HTTP client';

	/**
	 * The intents the provider answered through the client, by the provider's id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<mixed>>
	 */
	private array $intents = array();

	/**
	 * Keeps each intent the provider answered, for the library's capture.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int, body: array<mixed>} $answer The answer.
	 */
	protected function answered( array $answer ): void {
		$intent = $answer['body'];

		if ( isset( $intent['id'], $intent['amount'], $intent['currency'] ) && is_string( $intent['id'] ) ) {
			$this->intents[ $intent['id'] ] = $intent;
		}
	}

	/**
	 * Captures through the library: approved, and nothing sent through the client.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The library's answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$intent = $this->intents[ (string) $request->providerIntentId ] ?? null;

		if ( null === $intent ) {
			return parent::capture( $request );
		}

		return new GatewayResult( self::ID, Operation::Capture, Outcome::Approved, $request->intentUuid, $request->amount, 'rcp_library_' . (string) $intent['id'], (string) $intent['id'] );
	}
}
