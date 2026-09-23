<?php
/**
 * DataKey: one data key and its id
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * A key that seals secrets, and the id every sealed value names it by.
 *
 * This class owns one fact: which bytes a key id stands for. The id is sixteen lower-case
 * hexadecimal digits drawn at random, never derived from the key. The bytes never leave this
 * object except to the cipher: it has no string form, it is left out of var_dump() and print_r(),
 * and it cannot be serialized.
 *
 * @since 0.1.0
 */
final class DataKey {

	/**
	 * The shape of a key id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID_PATTERN = '/^[0-9a-f]{16}\z/';

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $bytes;

	/**
	 * Creates a key from its id and bytes.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the id is not sixteen hexadecimal digits or the key not 32 bytes.
	 *
	 * @param string $id    The id.
	 * @param string $bytes The key.
	 */
	public function __construct( string $id, #[\SensitiveParameter] string $bytes ) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || Cipher::KEY_BYTES !== strlen( $bytes ) ) {
			throw new \InvalidArgumentException( 'A data key has an id of sixteen lower-case hexadecimal digits and 32 bytes.' );
		}

		$this->id    = $id;
		$this->bytes = $bytes;
	}

	/**
	 * Creates a new random key with a new random id.
	 *
	 * @since 0.1.0
	 *
	 * @param Cipher $cipher The cipher, which draws the key.
	 * @return self The key.
	 */
	public static function generate( Cipher $cipher ): self {
		return new self( bin2hex( random_bytes( 8 ) ), $cipher->randomKey() );
	}

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string Sixteen lower-case hexadecimal digits.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the key.
	 *
	 * @since 0.1.0
	 *
	 * @return string 32 bytes.
	 */
	public function bytes(): string {
		return $this->bytes;
	}

	/**
	 * Describes the key for var_dump() and print_r() without the key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The id.
	 */
	public function __debugInfo(): array {
		return array( 'id' => $this->id );
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
		throw new \LogicException( 'A data key is never serialized.' );
	}
}
