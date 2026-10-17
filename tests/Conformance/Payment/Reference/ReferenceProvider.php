<?php
/**
 * ReferenceProvider: the fictional payment provider the reference gateway speaks to, in memory
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference;

/**
 * A small payment provider in memory, answering the reference gateway's requests through WordPress's `pre_http_request` filter.
 *
 * Owns one fact: the provider's state in a test: its payment intents, charges, captures and
 * refunds. Its API is JSON over form-encoded requests at https://api.reference.example:
 *
 *     POST /v1/intents                  create and confirm (capture=manual); the token decides
 *     POST /v1/intents/{id}/capture     capture, all or part
 *     POST /v1/intents/{id}/cancel      cancel an authorization or a wait
 *     GET  /v1/intents/{id}             retrieve
 *     GET  /v1/intents?intent_uuid=…    search by the store's uuid
 *     POST /v1/refunds                  refund part or all of a capture
 *     GET  /v1/refunds?intent={id}      list an intent's refunds
 *     GET  /v1/account                  the account
 *
 * A POST with an `Idempotency-Key` it has seen is answered with its first answer again. The key
 * is a bearer secret starting `ref_test_` or `ref_live_`; the two modes keep separate state. The
 * tokens: `ref_approve`, `ref_decline`, `ref_action`, `ref_action_done` (the customer acts,
 * so a cancel finds it approved), `ref_expiring` (waits, and is found cancelled afterwards) and
 * `ref_pending` (waits, and is still processing when asked).
 * Ids are counted from 1, so a scenario's answers are the same in every run.
 *
 * @since 0.2.0
 */
final class ReferenceProvider {

	/**
	 * The provider's host.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HOST = 'api.reference.example';

	/**
	 * The tokens and what they do.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const TOKENS = array(
		'approve'                   => 'ref_approve',
		'decline'                   => 'ref_decline',
		'requires_action'           => 'ref_action',
		'requires_action_completed' => 'ref_action_done',
		'expires'                   => 'ref_expiring',
		'pending'                   => 'ref_pending',
	);

	/**
	 * The intents, by mode and id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $intents = array();

	/**
	 * The refunds, by mode and id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $refunds = array();

	/**
	 * The first answer to each idempotency key, by mode and key.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, array{0: int, 1: array<string, mixed>}>>
	 */
	private array $answered = array();

	/**
	 * The last id counted, of every kind.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Starts answering. The test's hooks are restored after each test, which removes the filter.
	 *
	 * @since 0.2.0
	 */
	public function install(): void {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Answers a request to the provider's host that nothing answered before.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $pre  What an earlier filter answered; false when none did.
	 * @param mixed $args The request's arguments.
	 * @param mixed $url  The request's URL.
	 * @return mixed The answer, in the shape of WordPress's HTTP API, or the earlier answer.
	 */
	public function answer( mixed $pre, mixed $args, mixed $url ): mixed {
		if ( false !== $pre || ! is_string( $url ) || self::HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $pre;
		}

		$args = is_array( $args ) ? $args : array();

		list( $status, $body ) = $this->handle( (string) ( $args['method'] ?? 'GET' ), $url, (array) ( $args['headers'] ?? array() ), (string) ( $args['body'] ?? '' ) );

		return array(
			'headers'  => array(
				'content-type' => 'application/json',
				'request-id'   => 'req_' . ( ++$this->counter ),
			),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Builds a signed webhook delivery of an object, as the provider sends one.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $type   The event's type, such as `intent.captured`.
	 * @param array<mixed>       $payload The object the event is about.
	 * @param \DateTimeImmutable $at     When the provider signs it.
	 * @param string             $secret The endpoint's signing secret.
	 * @return array{headers: array<string, string>, body: string} The delivery.
	 */
	public function delivery( string $type, array $payload, \DateTimeImmutable $at, #[\SensitiveParameter] string $secret ): array {
		$body = (string) wp_json_encode(
			array(
				'id'      => 'evt_' . ( ++$this->counter ),
				'type'    => $type,
				'created' => $at->getTimestamp(),
				'data'    => array( 'object' => $payload ),
			)
		);

		return array(
			'headers' => ReferenceGateway::signed( array( 'content-type' => 'application/json' ), $body, $secret, $at ),
			'body'    => $body,
		);
	}

	/**
	 * Returns an intent as the provider holds it now, by the store's uuid, for a delivery about it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The store's uuid.
	 * @return array<string, mixed>|null The intent; null when the provider has none of that uuid.
	 */
	public function intentOf( string $intentUuid ): ?array {
		foreach ( $this->intents as $intents ) {
			foreach ( $intents as $intent ) {
				if ( $intent['intent_uuid'] === $intentUuid ) {
					return $intent;
				}
			}
		}

		return null;
	}

	/**
	 * Routes a request.
	 *
	 * @since 0.2.0
	 *
	 * @param string              $method  The method.
	 * @param string              $url     The URL.
	 * @param array<mixed, mixed> $headers The headers.
	 * @param string              $body    The form-encoded body.
	 * @return array{0: int, 1: array<string, mixed>} The status and the decoded body.
	 */
	private function handle( string $method, string $url, array $headers, string $body ): array {
		$headers = array_change_key_case( array_map( 'strval', $headers ) );
		$mode    = self::modeOf( $headers['authorization'] ?? '' );

		if ( null === $mode ) {
			return self::error( 401, 'unauthorized' );
		}

		$key = $headers['idempotency-key'] ?? null;

		if ( 'POST' === $method && null !== $key && isset( $this->answered[ $mode ][ $key ] ) ) {
			return $this->answered[ $mode ][ $key ];
		}

		$fields = array();
		parse_str( $body, $fields );
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$answer = $this->route( $mode, $method, (string) wp_parse_url( $url, PHP_URL_PATH ), $fields, $query );

		if ( 'POST' === $method && null !== $key ) {
			$this->answered[ $mode ][ $key ] = $answer;
		}

		return $answer;
	}

	/**
	 * Answers a request by its method and path.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $mode   `test` or `live`.
	 * @param string       $method The method.
	 * @param string       $path   The path.
	 * @param array<mixed> $fields The form fields.
	 * @param array<mixed> $query  The query's fields.
	 * @return array{0: int, 1: array<string, mixed>} The status and the decoded body.
	 */
	private function route( string $mode, string $method, string $path, array $fields, array $query ): array {
		if ( 1 === preg_match( '#^/v1/intents/([a-z0-9_]+)/(capture|cancel)$#D', $path, $match ) && 'POST' === $method ) {
			return 'capture' === $match[2] ? $this->capture( $mode, $match[1], $fields ) : $this->cancel( $mode, $match[1] );
		}

		if ( 1 === preg_match( '#^/v1/intents/([a-z0-9_]+)$#D', $path, $match ) && 'GET' === $method ) {
			return isset( $this->intents[ $mode ][ $match[1] ] ) ? array( 200, $this->seen( $mode, $match[1] ) ) : self::error( 404, 'no_such_intent' );
		}

		return match ( $method . ' ' . $path ) {
			'POST /v1/intents' => $this->create( $mode, $fields ),
			'GET /v1/intents'  => array( 200, array( 'data' => $this->search( $mode, (string) ( $query['intent_uuid'] ?? '' ) ) ) ),
			'POST /v1/refunds' => $this->refund( $mode, $fields ),
			'GET /v1/refunds'  => array( 200, array( 'data' => $this->refundsOf( $mode, (string) ( $query['intent'] ?? '' ) ) ) ),
			'GET /v1/account'  => array(
				200,
				array(
					'id'      => 'acct_1',
					'country' => 'US',
				),
			),
			default            => self::error( 404, 'no_such_route' ),
		};
	}

	/**
	 * Creates and confirms an intent; the token decides what becomes of it.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $mode   The mode.
	 * @param array<mixed> $fields The form fields.
	 * @return array{0: int, 1: array<string, mixed>} The answer.
	 */
	private function create( string $mode, array $fields ): array {
		$id     = 'ri_' . ( ++$this->counter );
		$token  = (string) ( $fields['token'] ?? '' );
		$intent = array(
			'id'                  => $id,
			'status'              => 'requires_capture',
			'amount'              => (int) ( $fields['amount'] ?? 0 ),
			'amount_captured'     => 0,
			'amount_refunded'     => 0,
			'currency'            => (string) ( $fields['currency'] ?? '' ),
			'intent_uuid'         => (string) ( $fields['intent_uuid'] ?? '' ),
			'order_number'        => (string) ( $fields['order_number'] ?? '' ),
			'charge'              => 'rch_' . ( ++$this->counter ),
			'capture'             => null,
			'decline_code'        => null,
			'decline_message'     => null,
			'next_action'         => null,
			'cancellation_reason' => null,
			'token'               => $token,
		);

		if ( self::TOKENS['decline'] === $token || ! in_array( $token, self::TOKENS, true ) ) {
			$intent['status']          = 'failed';
			$intent['decline_code']    = self::TOKENS['decline'] === $token ? 'card_declined' : 'invalid_token';
			$intent['decline_message'] = self::TOKENS['decline'] === $token ? 'The card was declined by its issuer.' : 'The payment token is not one this provider issued.';
		} elseif ( in_array( $token, array( self::TOKENS['requires_action'], self::TOKENS['requires_action_completed'] ), true ) ) {
			$intent['status']      = 'requires_action';
			$intent['charge']      = null;
			$intent['next_action'] = array(
				'type' => 'redirect',
				'url'  => 'https://pay.reference.example/act/' . $id,
			);
		} elseif ( in_array( $token, array( self::TOKENS['expires'], self::TOKENS['pending'] ), true ) ) {
			$intent['status'] = 'processing';
			$intent['charge'] = null;
		}

		$this->intents[ $mode ][ $id ] = $intent;

		return array( 200, self::shown( $intent ) );
	}

	/**
	 * Captures an authorized intent, all or part.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $mode   The mode.
	 * @param string       $id     The intent's id.
	 * @param array<mixed> $fields The form fields: `amount`, optional.
	 * @return array{0: int, 1: array<string, mixed>} The answer.
	 */
	private function capture( string $mode, string $id, array $fields ): array {
		$intent = $this->intents[ $mode ][ $id ] ?? null;

		if ( null === $intent ) {
			return self::error( 404, 'no_such_intent' );
		}

		if ( 'captured' === $intent['status'] ) {
			return self::error( 400, 'already_captured', self::shown( $intent ) );
		}

		if ( 'requires_capture' !== $intent['status'] ) {
			return self::error( 400, 'intent_unexpected_state', self::shown( $intent ) );
		}

		$amount = isset( $fields['amount'] ) ? (int) $fields['amount'] : (int) $intent['amount'];

		if ( $amount < 1 || $amount > $intent['amount'] ) {
			return self::error( 400, 'amount_too_large', self::shown( $intent ) );
		}

		$intent['status']              = 'captured';
		$intent['amount_captured']     = $amount;
		$intent['capture']             = 'rcp_' . ( ++$this->counter );
		$this->intents[ $mode ][ $id ] = $intent;

		return array( 200, self::shown( $intent ) );
	}

	/**
	 * Cancels an authorization or a wait; an intent whose customer acted is found approved instead.
	 *
	 * @since 0.2.0
	 *
	 * @param string $mode The mode.
	 * @param string $id   The intent's id.
	 * @return array{0: int, 1: array<string, mixed>} The answer.
	 */
	private function cancel( string $mode, string $id ): array {
		$intent = $this->intents[ $mode ][ $id ] ?? null;

		if ( null === $intent ) {
			return self::error( 404, 'no_such_intent' );
		}

		if ( self::TOKENS['requires_action_completed'] === $intent['token'] && 'requires_action' === $intent['status'] ) {
			$intent['status']              = 'requires_capture';
			$intent['next_action']         = null;
			$intent['charge']              = 'rch_' . ( ++$this->counter );
			$this->intents[ $mode ][ $id ] = $intent;

			return self::error( 400, 'already_succeeded', self::shown( $intent ) );
		}

		if ( in_array( $intent['status'], array( 'captured', 'failed' ), true ) ) {
			return self::error( 400, 'intent_unexpected_state', self::shown( $intent ) );
		}

		$intent['status']              = 'canceled';
		$intent['cancellation_reason'] = 'requested';
		$this->intents[ $mode ][ $id ] = $intent;

		return array( 200, self::shown( $intent ) );
	}

	/**
	 * Refunds part or all of an intent's capture.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $mode   The mode.
	 * @param array<mixed> $fields The form fields: `intent`, `amount`, `refund_uuid`.
	 * @return array{0: int, 1: array<string, mixed>} The answer.
	 */
	private function refund( string $mode, array $fields ): array {
		$id     = (string) ( $fields['intent'] ?? '' );
		$intent = $this->intents[ $mode ][ $id ] ?? null;

		if ( null === $intent || 'captured' !== $intent['status'] ) {
			return self::error( 400, 'intent_not_captured' );
		}

		$amount = (int) ( $fields['amount'] ?? 0 );

		if ( $amount < 1 || $amount > $intent['amount_captured'] - $intent['amount_refunded'] ) {
			return self::error( 400, 'amount_too_large' );
		}

		$refund = array(
			'id'          => 'rre_' . ( ++$this->counter ),
			'status'      => 'succeeded',
			'intent'      => $id,
			'intent_uuid' => $intent['intent_uuid'],
			'amount'      => $amount,
			'currency'    => $intent['currency'],
			'refund_uuid' => (string) ( $fields['refund_uuid'] ?? '' ),
		);

		$intent['amount_refunded']              += $amount;
		$this->intents[ $mode ][ $id ]           = $intent;
		$this->refunds[ $mode ][ $refund['id'] ] = $refund;

		return array( 200, $refund );
	}

	/**
	 * Returns an intent as retrieved: an expiring wait is found cancelled once it was confirmed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $mode The mode.
	 * @param string $id   The intent's id.
	 * @return array<string, mixed> The intent, as shown.
	 */
	private function seen( string $mode, string $id ): array {
		$intent = $this->intents[ $mode ][ $id ];

		if ( self::TOKENS['expires'] === $intent['token'] && 'processing' === $intent['status'] ) {
			$intent['status']              = 'canceled';
			$intent['cancellation_reason'] = 'expired';
			$this->intents[ $mode ][ $id ] = $intent;
		}

		return self::shown( $intent );
	}

	/**
	 * Finds the intents of a store's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $mode       The mode.
	 * @param string $intentUuid The store's uuid.
	 * @return list<array<string, mixed>> The intents found.
	 */
	private function search( string $mode, string $intentUuid ): array {
		$found = array();

		foreach ( $this->intents[ $mode ] ?? array() as $id => $intent ) {
			if ( '' !== $intentUuid && $intent['intent_uuid'] === $intentUuid ) {
				$found[] = $this->seen( $mode, (string) $id );
			}
		}

		return $found;
	}

	/**
	 * Lists an intent's refunds.
	 *
	 * @since 0.2.0
	 *
	 * @param string $mode The mode.
	 * @param string $id   The intent's id.
	 * @return list<array<string, mixed>> The refunds, in order.
	 */
	private function refundsOf( string $mode, string $id ): array {
		return array_values( array_filter( $this->refunds[ $mode ] ?? array(), static fn( array $refund ): bool => $refund['intent'] === $id ) );
	}

	/**
	 * Returns an intent without what the provider keeps to itself.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $intent The intent.
	 * @return array<string, mixed> The intent as answered.
	 */
	private static function shown( array $intent ): array {
		unset( $intent['token'] );

		return $intent;
	}

	/**
	 * Builds an error answer.
	 *
	 * @since 0.2.0
	 *
	 * @param int                       $status The status.
	 * @param string                    $code   The error's code.
	 * @param array<string, mixed>|null $intent The intent the error is about, when there is one.
	 * @return array{0: int, 1: array<string, mixed>} The answer.
	 */
	private static function error( int $status, string $code, ?array $intent = null ): array {
		$body = array( 'error' => array( 'code' => $code ) );

		if ( null !== $intent ) {
			$body['intent'] = $intent;
		}

		return array( $status, $body );
	}

	/**
	 * Tells which mode a bearer secret is of.
	 *
	 * @since 0.2.0
	 *
	 * @param string $authorization The Authorization header.
	 * @return string|null `test` or `live`; null for anything else.
	 */
	private static function modeOf( string $authorization ): ?string {
		return 1 === preg_match( '/^Bearer ref_(test|live)_[A-Za-z0-9_]+$/D', $authorization, $match ) ? $match[1] : null;
	}
}
