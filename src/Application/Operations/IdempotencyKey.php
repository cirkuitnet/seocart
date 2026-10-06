<?php
/**
 * IdempotencyKey: the idempotency key a client sends with a request it may retry
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * The idempotency key on the wire: its header, its input field, and the two hashes it is stored and checked by.
 *
 * Owns one fact: what an idempotency key is, for every operation that takes one. A client sends a
 * new key, such as a UUID, with each request it may have to retry, and the same key when it
 * retries that request; the operation answers the retry as it answered the first. A key is 1 to
 * MAX_LENGTH bytes long, and an operation refuses any other with its own error before it hashes
 * it, as nothing on the way checks a header against the field's schema. Each operation
 * keeps its keys where its own record lives, scoped by a salt of its own, so no two operations
 * and no two scopes share a key, and only the hash of a key is ever stored. Nothing here reads or
 * writes anything.
 *
 * @since 0.2.0
 */
final class IdempotencyKey {

	/**
	 * The header the key is sent in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HEADER = 'Idempotency-Key';

	/**
	 * The input field the header is read into.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIELD = 'idempotency_key';

	/**
	 * The longest key a client may send, in bytes: a UUID fits, and so does any token of its own of up to this many ASCII characters; a character outside ASCII takes more than one byte.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 64;

	/**
	 * Cannot be called: the declaration is used through its constants and static functions.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the key's input field, which an operation binds to the header.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The field: optional on the wire, since the service refuses a request without the header with its own error.
	 */
	public static function field(): FieldSpec {
		return new FieldSpec(
			name: self::FIELD,
			type: FieldType::String,
			description: sprintf( 'The request\'s idempotency key, sent in the Idempotency-Key header and nowhere else, of at most %d bytes: a new key, such as a UUID, for each new request, and the same key when that request is retried.', self::MAX_LENGTH ),
			label: static fn(): string => __( 'Idempotency key', 'seocart' ),
			example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
			max_length: self::MAX_LENGTH
		);
	}

	/**
	 * Returns whether a key is one an operation takes: 1 to MAX_LENGTH bytes long.
	 *
	 * The field's schema counts characters, and a header is not checked against it on the way in,
	 * so an operation asks this of the key before it hashes it, and refuses a key it does not take
	 * with its own error.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key the client sent.
	 * @return bool True when the key is 1 to MAX_LENGTH bytes long.
	 */
	public static function accepts( #[\SensitiveParameter] string $key ): bool {
		return '' !== $key && strlen( $key ) <= self::MAX_LENGTH;
	}

	/**
	 * Returns the hash a key is stored and found by: the SHA-256 of its scope's salt and the key, joined by a bar.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When accepts() does not take the key: it is empty or longer than MAX_LENGTH bytes.
	 *
	 * @param string $salt What scopes the key, such as the hash of the cart a placement is made from.
	 * @param string $key  The key the client sent.
	 * @return string The hash, 64 lower-case hexadecimal characters.
	 */
	public static function hash( string $salt, #[\SensitiveParameter] string $key ): string {
		if ( ! self::accepts( $key ) ) {
			throw new \InvalidArgumentException( sprintf( 'An idempotency key is 1 to %d bytes long.', self::MAX_LENGTH ) );
		}

		return hash( 'sha256', $salt . '|' . $key );
	}

	/**
	 * Returns the fingerprint of a request: what a retry under the same key must send again.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $canonical The request in its canonical form, in an order its caller fixes.
	 * @return string The SHA-256 of its JSON, in lower-case hexadecimal.
	 */
	public static function fingerprint( array $canonical ): string {
		return hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}
}
