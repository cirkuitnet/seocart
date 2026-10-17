<?php
/**
 * Tests the stand-in gateway against the payment gateway conformance suite
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;
use SEOCart\Tests\Conformance\Payment\Scenario;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Runs every rule of the suite against the stand-in gateway the plugin registers on a site that is not in production.
 *
 * The stand-in calls no service, so it has no tape and any request it sent would fail. Its tokens
 * script each scenario; its pending payment is the one that expires once its wait has ended.
 *
 * @since 0.2.0
 */
final class StubGatewayConformanceTest extends GatewayConformanceCase {

	/**
	 * Returns the stand-in's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	protected function gatewayId(): string {
		return StubGateway::ID;
	}

	/**
	 * Returns no tape: the stand-in calls no service.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null Null.
	 */
	protected function tapes(): ?string {
		return null;
	}

	/**
	 * Returns the deliveries the stand-in would send: a payment's result, and an event it does not apply.
	 *
	 * The stand-in has no provider to record, so rule 10 reads these as they are, signed with the
	 * stand-in's own secret, as the webhook receiver's tests make them.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> The deliveries by name: mode, signed_at, headers, body.
	 */
	protected function freshDeliveries(): array {
		$at        = new \DateTimeImmutable( '2026-06-01T12:00:00+00:00' );
		$intent    = '019b76da-a800-7000-8000-00000000000a';
		$approved  = new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $intent, Money::of( 3000, Currency::of( 'USD' ) ), 'stub-ch-' . $intent, 'stub-pi-approve-' . $intent );
		$delivered = array();

		foreach (
			array(
				'authorized'      => StubWebhooks::of( $approved ),
				'account-updated' => StubWebhooks::event( 'evt_conformance_account_updated', 'account.updated', array( 'account' => 'stub' ) ),
			) as $name => $webhook
		) {
			$delivered[ $name ] = array(
				'mode'      => Mode::Test->value,
				'signed_at' => $at->format( \DateTimeInterface::ATOM ),
				'headers'   => $webhook->headers( $at->getTimestamp() ),
				'body'      => $webhook->body(),
			);
		}

		return $delivered;
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
