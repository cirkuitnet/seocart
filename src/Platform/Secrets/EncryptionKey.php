<?php
/**
 * EncryptionKey: the key-encrypting key that wp-config.php may define
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The key that wraps every data key, when the site defines one outside the database.
 *
 * This class owns one fact: where the key-encrypting key comes from. It is the constant named in
 * CONSTANT, the base64 encoding of 32 random bytes, defined in wp-config.php; fromEnvironment() is
 * the one place in the plugin that reads it. Nothing is derived from WordPress's salts, which a
 * plugin can filter and a host can rotate, and which are stored in the database when they are not
 * defined.
 *
 * The key never leaves this object except to the cipher: it has no string form, it is left out of
 * var_dump() and print_r(), and it cannot be serialized.
 *
 * @since 0.1.0
 */
final class EncryptionKey {

	/**
	 * The name of the constant that holds the key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONSTANT = 'SEOCART_ENCRYPTION_KEY';

	/**
	 * What the constant amounts to.
	 *
	 * @since 0.1.0
	 *
	 * @var EncryptionKeyState
	 */
	private EncryptionKeyState $state;

	/**
	 * The key, when the state is present.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $bytes;

	/**
	 * Creates the key. Use fromEnvironment() or fromValue().
	 *
	 * @since 0.1.0
	 *
	 * @param EncryptionKeyState $state The state.
	 * @param string|null        $bytes The key, when present.
	 */
	private function __construct( EncryptionKeyState $state, #[\SensitiveParameter] ?string $bytes ) {
		$this->state = $state;
		$this->bytes = $bytes;
	}

	/**
	 * Returns the key wp-config.php defines, or its absence. The one place the constant is read.
	 *
	 * @since 0.1.0
	 *
	 * @return self The key.
	 */
	public static function fromEnvironment(): self {
		return self::fromValue( defined( self::CONSTANT ) ? constant( self::CONSTANT ) : null );
	}

	/**
	 * Returns the key a value encodes.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The base64 encoding of 32 bytes; null for no key. Anything else is invalid.
	 * @return self The key.
	 */
	public static function fromValue( #[\SensitiveParameter] mixed $value ): self {
		if ( null === $value ) {
			return new self( EncryptionKeyState::Absent, null );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the key an operator writes in wp-config.php, in the encoding documented for it; nothing decoded is ever run.
		$bytes = is_string( $value ) ? base64_decode( trim( $value ), true ) : false;

		if ( ! is_string( $bytes ) || Cipher::KEY_BYTES !== strlen( $bytes ) ) {
			return new self( EncryptionKeyState::Invalid, null );
		}

		return new self( EncryptionKeyState::Present, $bytes );
	}

	/**
	 * Returns what the constant amounts to.
	 *
	 * @since 0.1.0
	 *
	 * @return EncryptionKeyState The state.
	 */
	public function state(): EncryptionKeyState {
		return $this->state;
	}

	/**
	 * Returns the key.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The 32 bytes, or null when the state is not present.
	 */
	public function bytes(): ?string {
		return $this->bytes;
	}

	/**
	 * Describes the key for var_dump() and print_r() without the key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The state.
	 */
	public function __debugInfo(): array {
		return array( 'state' => $this->state->value );
	}

	/**
	 * Refuses to be serialized.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @return never
	 */
	public function __serialize(): array {
		throw new \LogicException( 'An encryption key is never serialized.' );
	}
}
