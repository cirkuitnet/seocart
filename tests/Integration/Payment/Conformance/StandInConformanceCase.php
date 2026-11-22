<?php
/**
 * StandInConformanceCase: the payment gateway conformance suite, run against a gateway that answers as the stand-in does
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;
use SEOCart\Tests\Conformance\Payment\Scenario;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Runs every rule of the suite against a gateway that answers as the stand-in does: the stand-in itself, or a gateway of the test's that gives the stand-in's answers under its own id.
 *
 * Such a gateway calls no service, so it has no tape and any request it sent would fail. The
 * stand-in's tokens script each scenario; its pending payment is the one that expires once its
 * wait has ended. Its webhook deliveries are made here, signed with the stand-in's own secret, as
 * the webhook receiver's tests make them. Each gateway has a test of its own, which names its id.
 *
 * @since 0.2.0
 */
abstract class StandInConformanceCase extends GatewayConformanceCase {

	/**
	 * Returns no tape: the gateway calls no service.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null Null.
	 */
	protected function tapes(): ?string {
		return null;
	}

	/**
	 * Returns the deliveries rule 10 reads, as the stand-in would send them.
	 *
	 * The stand-in has no provider to record, so rule 10 reads these as they are, signed with the
	 * stand-in's own secret, and sends them to the gateway in the suite's mode. With no recording,
	 * a delivery states no mode of its own.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> The deliveries by name: signed_at, headers, body.
	 */
	protected function freshDeliveries(): array {
		$at        = new \DateTimeImmutable( '2026-06-01T12:00:00+00:00' );
		$delivered = array();

		foreach ( $this->webhooks() as $name => $webhook ) {
			$delivered[ $name ] = array(
				'signed_at' => $at->format( \DateTimeInterface::ATOM ),
				'headers'   => $webhook->headers( $at->getTimestamp() ),
				'body'      => $webhook->body(),
			);
		}

		return $delivered;
	}

	/**
	 * Returns the stand-in's events the deliveries carry: a payment's result, and an event it does not apply.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, StubWebhooks> The events by the delivery's name.
	 */
	protected function webhooks(): array {
		return array(
			'authorized'      => StubWebhooks::of( self::approval( '019b76da-a800-7000-8000-00000000000a' ) ),
			'account-updated' => StubWebhooks::event( 'evt_conformance_account_updated', 'account.updated', array( 'account' => 'stub' ) ),
		);
	}

	/**
	 * Returns the stand-in's approval of an intent's authorization, as a delivery reports it: named by the store's uuid of the intent, with the charge and the provider's id the stand-in gives it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return GatewayResult The approval.
	 */
	protected static function approval( string $intentUuid ): GatewayResult {
		return new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $intentUuid, Money::of( 3000, Currency::of( 'USD' ) ), 'stub-ch-' . $intentUuid, 'stub-pi-approve-' . $intentUuid );
	}

	/**
	 * Signs a delivery as the stand-in does: an HMAC-SHA256 of `{t}.{body}` in its signature header.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The delivery's headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The secret to sign with.
	 * @param \DateTimeImmutable    $at      When it is signed.
	 * @return array<string, string> The headers, signed.
	 */
	protected function signDelivery( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		return array( StubGateway::SIGNATURE_HEADER => 't=' . $at->getTimestamp() . ',v1=' . hash_hmac( 'sha256', $at->getTimestamp() . '.' . $body, $secret ) ) + $headers;
	}

	/**
	 * Returns the stand-in's token of each scenario.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The tokens by scenario.
	 */
	protected function tokens(): array {
		return array(
			Scenario::Approve->value                 => StubGateway::APPROVE,
			Scenario::Decline->value                 => StubGateway::DECLINE,
			Scenario::RequiresAction->value          => StubGateway::REQUIRES_ACTION,
			Scenario::RequiresActionCompleted->value => StubGateway::REQUIRES_ACTION_COMPLETED,
			Scenario::Expires->value                 => StubGateway::PENDING,
			Scenario::Pending->value                 => StubGateway::PENDING,
		);
	}
}
