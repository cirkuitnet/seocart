<?php
/**
 * StubWebhooks: builds the stand-in gateway's webhook deliveries, signed as it signs them, with the knobs a test turns
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use WP_REST_Request;

/**
 * One delivery of the stand-in gateway's webhook: an event, signed with StubGateway::WEBHOOK_SECRET at a time, as a provider sends it.
 *
 * Owns one fact: how a test makes a delivery the stand-in reads, or one it must reject. A
 * delivery is immutable; each knob returns a new one. The event of a result is named by a
 * deterministic id, so the same result built twice is the same event, as a provider's retry is;
 * withEventId() makes it another event about the same object. The body is the JSON of the event,
 * byte for byte what is signed, unless withBody() replaces it.
 *
 * @since 0.2.0
 */
final class StubWebhooks {

	/**
	 * Builds a delivery. Use of(), dispute() or event().
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>  $event        The event.
	 * @param string|null           $body         The body, when it is not the event's JSON.
	 * @param int                   $skewSeconds  How far the signed time is from the delivery's arrival.
	 * @param bool                  $badSignature Whether the signature is of another body.
	 * @param array<string, string> $headers      Further headers, by lower-case name.
	 */
	private function __construct(
		private array $event,
		private ?string $body = null,
		private int $skewSeconds = 0,
		private bool $badSignature = false,
		private array $headers = array()
	) {
	}

	/**
	 * Builds the delivery of a result, as the stand-in reports it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException For a result the stand-in reports no event of.
	 *
	 * @param GatewayResult $result     The result: a type of StubGateway::WEBHOOK_EVENTS.
	 * @param string|null   $refundUuid Optional. The refund uuid the provider echoes, for a refund. Default null.
	 * @return self The delivery.
	 */
	public static function of( GatewayResult $result, ?string $refundUuid = null ): self {
		$type = array_search( array( $result->operation->value, $result->outcome->value ), StubGateway::WEBHOOK_EVENTS, true );

		if ( false === $type ) {
			throw new \InvalidArgumentException( sprintf( 'The stand-in reports no %1$s %2$s by webhook.', $result->operation->value, $result->outcome->value ) );
		}

		$data = array_filter(
			array(
				'intent_uuid'        => $result->intentUuid,
				'amount_minor'       => $result->amount->minorUnits(),
				'currency'           => $result->amount->currency()->code(),
				'provider_object_id' => $result->providerObjectId,
				'provider_intent_id' => $result->providerIntentId,
				'error_code'         => $result->errorCode,
				'refund_uuid'        => $refundUuid,
			),
			static fn( $value ): bool => null !== $value && '' !== $value
		);

		return self::event( self::idOf( (string) $type, $data ), (string) $type, $data );
	}

	/**
	 * Builds the delivery of a dispute, naming the payment it is about.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $intentUuid       The plugin's intent, when the provider echoes it.
	 * @param string|null $providerIntentId The provider's reference to the intent.
	 * @return self The delivery.
	 */
	public static function dispute( ?string $intentUuid, ?string $providerIntentId = null ): self {
		$data = array_filter(
			array(
				'intent_uuid'        => $intentUuid,
				'provider_intent_id' => $providerIntentId,
			)
		);

		return self::event( self::idOf( StubGateway::DISPUTE_EVENT, $data ), StubGateway::DISPUTE_EVENT, $data );
	}

	/**
	 * Builds the delivery of any event.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $id   The event's id.
	 * @param string               $type The event's type.
	 * @param array<string, mixed> $data What it reports.
	 * @return self The delivery.
	 */
	public static function event( string $id, string $type, array $data ): self {
		return new self(
			array(
				'id'      => $id,
				'type'    => $type,
				'created' => 1760000000,
				'data'    => $data,
			)
		);
	}

	/**
	 * Returns the same event under another id, as a provider sends a second event about one object.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id The other id.
	 * @return self The delivery.
	 */
	public function withEventId( string $id ): self {
		$copy              = clone $this;
		$copy->event['id'] = $id;

		return $copy;
	}

	/**
	 * Returns this event under the id of another delivery's, as a forgery replaying a known id would carry it.
	 *
	 * @since 0.2.0
	 *
	 * @param self $other The other delivery.
	 * @return self The delivery.
	 */
	public function withEventIdOf( self $other ): self {
		return $this->withEventId( $other->eventId() );
	}

	/**
	 * Returns the delivery with a signature of another body, which the stand-in rejects.
	 *
	 * @since 0.2.0
	 *
	 * @return self The delivery.
	 */
	public function withBadSignature(): self {
		$copy               = clone $this;
		$copy->badSignature = true;

		return $copy;
	}

	/**
	 * Returns the delivery signed a number of seconds before it arrives.
	 *
	 * @since 0.2.0
	 *
	 * @param int $seconds How long before; below 0, after.
	 * @return self The delivery.
	 */
	public function staleBy( int $seconds ): self {
		$copy              = clone $this;
		$copy->skewSeconds = $seconds;

		return $copy;
	}

	/**
	 * Returns the delivery with a body of the test's own, signed as it is.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The body.
	 * @return self The delivery.
	 */
	public function withBody( string $body ): self {
		$copy       = clone $this;
		$copy->body = $body;

		return $copy;
	}

	/**
	 * Returns the delivery with one more header, which the stand-in does not read.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name  The header's name.
	 * @param string $value Its value.
	 * @return self The delivery.
	 */
	public function withHeader( string $name, string $value ): self {
		$copy                                 = clone $this;
		$copy->headers[ strtolower( $name ) ] = $value;

		return $copy;
	}

	/**
	 * Returns the delivery twice, as a provider retries one.
	 *
	 * @since 0.2.0
	 *
	 * @return list<self> The two deliveries.
	 */
	public function twice(): array {
		return array( $this, $this );
	}

	/**
	 * Shuffles deliveries in an order a seed decides, so a red run can be repeated.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks[] $deliveries The deliveries.
	 * @param int            $seed       The seed.
	 * @return list<self> The deliveries, shuffled.
	 *
	 * @phpstan-param list<self> $deliveries
	 */
	public static function shuffled( array $deliveries, int $seed ): array {
		$random = new \Random\Randomizer( new \Random\Engine\Mt19937( $seed ) );

		return array_values( $random->shuffleArray( $deliveries ) );
	}

	/**
	 * Returns the event's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	public function eventId(): string {
		return (string) $this->event['id'];
	}

	/**
	 * Returns the body, byte for byte as it is sent.
	 *
	 * @since 0.2.0
	 *
	 * @return string The body.
	 */
	public function body(): string {
		return $this->body ?? (string) wp_json_encode( $this->event );
	}

	/**
	 * Returns the headers the delivery is sent with at a time, the signature among them, by lower-case name.
	 *
	 * @since 0.2.0
	 *
	 * @param int $arrivesAt When it arrives, as a Unix time.
	 * @return array<string, string> The headers.
	 */
	public function headers( int $arrivesAt ): array {
		$signedAt  = $arrivesAt - $this->skewSeconds;
		$signed    = $this->badSignature ? $this->body() . ' ' : $this->body();
		$signature = hash_hmac( 'sha256', $signedAt . '.' . $signed, StubGateway::WEBHOOK_SECRET );

		return array(
			'content-type'                => 'application/json',
			StubGateway::SIGNATURE_HEADER => 't=' . $signedAt . ',v1=' . $signature,
		) + $this->headers;
	}

	/**
	 * Returns the delivery as the plugin hands it to the gateway, arriving at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param \DateTimeImmutable $arrivesAt When it arrives.
	 * @param Mode               $mode      Optional. The mode of the address it is sent to. Default test.
	 * @param string             $gatewayId Optional. The gateway it is addressed to. Default the stand-in.
	 * @return WebhookEnvelope The envelope.
	 */
	public function envelope( \DateTimeImmutable $arrivesAt, Mode $mode = Mode::Test, string $gatewayId = StubGateway::ID ): WebhookEnvelope {
		return new WebhookEnvelope( $gatewayId, $mode, $this->headers( $arrivesAt->getTimestamp() ), $this->body(), $arrivesAt );
	}

	/**
	 * Returns the delivery as a REST request to the webhook route, signed now.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode   $mode      Optional. The mode of the address it is sent to. Default test.
	 * @param string $gatewayId Optional. The gateway it is addressed to. Default the stand-in.
	 * @param string $method    Optional. The method it is sent with. Default POST.
	 * @return WP_REST_Request The request.
	 */
	public function request( Mode $mode = Mode::Test, string $gatewayId = StubGateway::ID, string $method = 'POST' ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/' . WebhookAddress::NAMESPACE . WebhookAddress::path( $gatewayId, $mode ) );

		$request->set_body( $this->body() );

		foreach ( $this->headers( time() ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * Returns a deterministic event id for an event's type and data: the same result is the same event.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $type The event's type.
	 * @param array<string, mixed> $data What it reports.
	 * @return string The id, such as `evt_3f…`.
	 */
	private static function idOf( string $type, array $data ): string {
		return 'evt_' . substr( hash( 'sha256', $type . '|' . (string) wp_json_encode( $data ) ), 0, 24 );
	}
}
