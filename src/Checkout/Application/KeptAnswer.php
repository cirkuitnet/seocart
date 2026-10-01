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
use SEOCart\Platform\Secrets\Cipher;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a broken row to the developer; it is never HTML.

/**
 * Writes a placement's answer for its key to keep, and reads it back for a retry, with the order's access key sealed.
 *
 * Owns one fact: how the order key is kept. A retry is given the answer again, order key included,
 * because the client that retries is the one that lost it. The order itself keeps only the key's
 * hash, so the kept answer must not hold the key readable either: it is sealed with the plugin's
 * cipher, under a key derived from the two secrets every retry presents and the store never keeps,
 * the cart's token and the request's idempotency key (the tables hold a hash of each, never the
 * value). The order's uuid is the seal's associated data, so a box copied into another answer
 * does not open. A reader of the tables cannot open the box; a retry, which presents the same
 * token and key, can. A box that does not open, because it was damaged, is left out: the retry is
 * answered without the key rather than refused.
 *
 * The key stays where it was in the answer, so an answer read back keeps its fields' order.
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
	 * What the sealing key is derived for, so it is never another key derived from the same secrets.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PURPOSE = 'seocart-order-key|';

	/**
	 * Returns the answer as its key keeps it: the order key sealed, every other field as it is.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $answer         The answer sent: `order_uuid` and `order_key` among its fields.
	 * @param CartToken            $token          The cart token the request presented.
	 * @param string               $idempotencyKey The idempotency key the request sent.
	 * @return string The answer to keep, as JSON.
	 */
	public static function seal( array $answer, CartToken $token, #[\SensitiveParameter] string $idempotencyKey ): string {
		$cipher = Cipher::detect();
		$kept   = array();

		foreach ( $answer as $field => $value ) {
			if ( self::ORDER_KEY !== $field ) {
				$kept[ $field ] = $value;

				continue;
			}

			$nonce = $cipher->randomNonce();

			$kept[ self::SEALED ] = array(
				'nonce' => $cipher->encode( $nonce ),
				'box'   => $cipher->encode( $cipher->encrypt( (string) $value, (string) ( $answer['order_uuid'] ?? '' ), $nonce, self::key( $token, $idempotencyKey ) ) ),
			);
		}

		return (string) wp_json_encode( $kept );
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

		foreach ( $stored as $field => $value ) {
			if ( self::SEALED !== $field ) {
				$answer[ $field ] = $value;

				continue;
			}

			$key = self::unseal( $value, (string) ( $stored['order_uuid'] ?? '' ), $token, $idempotencyKey );

			if ( null !== $key ) {
				$answer[ self::ORDER_KEY ] = $key;
			}
		}

		return $answer;
	}

	/**
	 * Opens a sealed order key.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed     $sealed         What the kept answer holds: an object with the nonce and the box, each in base64url.
	 * @param string    $orderUuid      The order's uuid, the seal's associated data.
	 * @param CartToken $token          The cart token the retry presents.
	 * @param string    $idempotencyKey The idempotency key the retry sends.
	 * @return string|null The order key; null when the box is damaged, or was sealed for other secrets or another order.
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
