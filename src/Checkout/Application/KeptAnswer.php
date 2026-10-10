<?php
/**
 * KeptAnswer: the answer a placement's idempotency key keeps, its order key sealed with what only the client holds
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Cart\Domain\CartToken;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Platform\Secrets\Cipher;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a broken row to the developer; it is never HTML.

/**
 * Writes a placement's answer for its key to keep, and reads it back for a retry, with the order's access key and the shopper's next action sealed.
 *
 * Owns one fact: how the order key, and what the shopper must do for the provider, are kept. A
 * retry is given the answer again, order key included, because the client that retries is the one
 * that lost it; while the payment waits for the shopper, the next action too, so the client can
 * still take it. The order itself keeps only the key's
 * hash, so the kept answer must not hold the key readable either: it is sealed with the plugin's
 * cipher, under a key derived from the two secrets every retry presents and the store never keeps,
 * the cart's token and the request's idempotency key (the tables hold a hash of each, never the
 * value). The order's uuid is the seal's associated data, so a box copied into another answer
 * does not open. A reader of the tables cannot open the box; a retry, which presents the same
 * token and key, can. A box that does not open, because it was damaged, is left out: the retry is
 * answered without the key rather than refused.
 *
 * The next action carries the provider's handle to the payment, a secret of the shopper's browser,
 * so it is sealed the same way, with its own associated data, so a box of one field never opens as
 * the other. Only the placement's own request holds the two secrets, so only it seals the next
 * action, and the settlement writes it in (IdempotencyKeys::settleAnswer()) while the payment waits
 * for the shopper, and clears it once it does not.
 *
 * Each field stays where it was in the answer, so an answer read back keeps its fields' order.
 *
 * @since 0.1.0
 */
final class KeptAnswer {

	/**
	 * The field the kept answer holds the sealed key in, in place of the key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SEALED = 'order_key_sealed';

	/**
	 * The field of the answer that holds the order key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ORDER_KEY = 'order_key';

	/**
	 * The field of the answer that holds what the shopper must do for the provider, or null.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEXT_ACTION = 'next_action';

	/**
	 * The field the kept answer holds the sealed next action in, in place of the next action; null when there is none.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEXT_ACTION_SEALED = 'next_action_sealed';

	/**
	 * What the next action's box is bound to, before the order's uuid, so it never opens as the order key's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NEXT_ACTION_DATA = 'next_action|';

	/**
	 * What the sealing key is derived for, so it is never another key derived from the same secrets.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PURPOSE = 'seocart-order-key|';

	/**
	 * Returns the answer as its key keeps it: the order key and the next action sealed, every other field as it is.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Seals the next action too.
	 *
	 * @param array<string, mixed> $answer         The answer sent: `order_uuid` and `order_key` among its fields, and
	 *                                             `next_action`, null while the shopper has nothing to do.
	 * @param CartToken            $token          The cart token the request presented.
	 * @param string               $idempotencyKey The idempotency key the request sent.
	 * @return string The answer to keep, as JSON.
	 */
	public static function seal( array $answer, CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): string {
		$orderUuid = (string) ( $answer['order_uuid'] ?? '' );
		$kept      = array();

		foreach ( $answer as $field => $value ) {
			if ( self::ORDER_KEY === $field ) {
				$kept[ self::SEALED ] = self::box( (string) $value, $orderUuid, $token, $idempotencyKey );
			} elseif ( self::NEXT_ACTION === $field ) {
				$kept[ self::NEXT_ACTION_SEALED ] = null === $value ? null : self::box( (string) wp_json_encode( $value ), self::NEXT_ACTION_DATA . $orderUuid, $token, $idempotencyKey );
			} else {
				$kept[ $field ] = $value;
			}
		}

		return (string) wp_json_encode( $kept );
	}

	/**
	 * Returns a next action sealed as the kept answer holds it, for the settlement to write in.
	 *
	 * @since 0.2.0
	 *
	 * @param NextAction $action         What the shopper must do.
	 * @param string     $orderUuid      The order's uuid.
	 * @param CartToken  $token          The cart token the request presented.
	 * @param string     $idempotencyKey The idempotency key the request sent.
	 * @return string The box, as JSON: an object with its nonce and its ciphertext.
	 */
	public static function sealAction( NextAction $action, string $orderUuid, CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): string {
		return (string) wp_json_encode( self::box( (string) wp_json_encode( self::actionOf( $action ) ), self::NEXT_ACTION_DATA . $orderUuid, $token, $idempotencyKey ) );
	}

	/**
	 * Returns a next action as an answer carries it.
	 *
	 * @since 0.2.0
	 *
	 * @param NextAction $action What the shopper must do.
	 * @return array{type: string, url: string|null, client_token: string|null} The step, by wire name.
	 */
	public static function actionOf( NextAction $action ): array {
		return array(
			'type'         => $action->type,
			'url'          => $action->url,
			'client_token' => $action->clientToken,
		);
	}

	/**
	 * Returns a kept answer as a retry is given it: the order key opened in its place, or left out when its box does not open.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the kept answer is not a JSON object.
	 *
	 * @param string    $kept           The kept answer, as JSON.
	 * @param CartToken $token          The cart token the retry presents.
	 * @param string    $idempotencyKey The idempotency key the retry sends.
	 * @return array<string, mixed> The answer.
	 */
	public static function open( string $kept, CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): array {
		$stored = json_decode( $kept, true );

		if ( ! is_array( $stored ) ) {
			throw new \UnexpectedValueException( 'An idempotency key keeps an answer that is not a JSON object.' );
		}

		$answer = array();

		$orderUuid = (string) ( $stored['order_uuid'] ?? '' );

		foreach ( $stored as $field => $value ) {
			if ( self::SEALED === $field ) {
				$key = self::unseal( $value, $orderUuid, $token, $idempotencyKey );

				if ( null !== $key ) {
					$answer[ self::ORDER_KEY ] = $key;
				}
			} elseif ( self::NEXT_ACTION_SEALED === $field ) {
				$action = null === $value ? null : self::unseal( $value, self::NEXT_ACTION_DATA . $orderUuid, $token, $idempotencyKey );

				$answer[ self::NEXT_ACTION ] = null === $action ? null : json_decode( $action, true );
			} else {
				$answer[ $field ] = $value;
			}
		}

		return $answer;
	}

	/**
	 * Seals a value with the plugin's cipher, under the key derived from the two secrets a retry presents.
	 *
	 * @since 0.2.0
	 *
	 * @param string    $value          The value.
	 * @param string    $data           What the box is bound to: the order's uuid, prefixed for each field but the order key.
	 * @param CartToken $token          The cart token.
	 * @param string    $idempotencyKey The idempotency key.
	 * @return array{nonce: string, box: string} The box, each part in base64url.
	 */
	private static function box( #[\SensitiveParameter] string $value, string $data, CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): array {
		$cipher = Cipher::detect();
		$nonce  = $cipher->randomNonce();

		return array(
			'nonce' => $cipher->encode( $nonce ),
			'box'   => $cipher->encode( $cipher->encrypt( $value, $data, $nonce, self::key( $token, $idempotencyKey ) ) ),
		);
	}

	/**
	 * Opens a sealed value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed     $sealed         What the kept answer holds: an object with the nonce and the box, each in base64url.
	 * @param string    $orderUuid      What the box is bound to: the order's uuid, prefixed for each field but the order key.
	 * @param CartToken $token          The cart token the retry presents.
	 * @param string    $idempotencyKey The idempotency key the retry sends.
	 * @return string|null The value; null when the box is damaged, or was sealed for other secrets, another order or another field.
	 */
	private static function unseal( mixed $sealed, string $orderUuid, CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): ?string {
		if ( ! is_array( $sealed ) || ! is_string( $sealed['nonce'] ?? null ) || ! is_string( $sealed['box'] ?? null ) ) {
			return null;
		}

		$cipher = Cipher::detect();
		$nonce  = $cipher->decode( $sealed['nonce'] );
		$box    = $cipher->decode( $sealed['box'] );

		if ( null === $nonce || null === $box || Cipher::NONCE_BYTES !== strlen( $nonce ) ) {
			return null;
		}

		return $cipher->decrypt( $box, $orderUuid, $nonce, self::key( $token, $idempotencyKey ) );
	}

	/**
	 * Derives the sealing key from the two secrets a retry presents.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken $token          The cart token.
	 * @param string    $idempotencyKey The idempotency key.
	 * @return string The key: Cipher::KEY_BYTES bytes.
	 */
	private static function key( CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): string {
		return hash( 'sha256', self::PURPOSE . $token->value() . '|' . $idempotencyKey, true );
	}
}
