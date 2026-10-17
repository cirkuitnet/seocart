<?php
/**
 * SecretInActionUrl: a gateway that puts its key in the customer's redirect
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Sends the customer to the provider's page with the gateway's API key in the URL.
 *
 * The plant of rule 11: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class SecretInActionUrl extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the next action\'s URL carries a credential of the gateway';

	/**
	 * Adds the key to a redirect's URL.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $intentUuid The store's uuid.
	 * @param array<mixed> $intent     The provider's intent.
	 * @return GatewayResult The authorization's answer.
	 */
	protected function authorizationOf( string $intentUuid, array $intent ): GatewayResult {
		$answer = parent::authorizationOf( $intentUuid, $intent );

		if ( null === $answer->nextAction || null === $answer->nextAction->url ) {
			return $answer;
		}

		return new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, $answer->amount, $answer->providerObjectId, $answer->providerIntentId, null, null, NextAction::redirect( $answer->nextAction->url . '?key=' . rawurlencode( $this->credential( Mode::Test ) ) ) );
	}
}
