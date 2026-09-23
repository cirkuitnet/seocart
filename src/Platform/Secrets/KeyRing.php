<?php
/**
 * KeyRing: the data keys a site holds at one moment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The active key and the retiring key, as one read of the data keys document found them.
 *
 * This class owns one fact: which keys may seal and open a secret while the caller holds them. It
 * is handed out by SecretKeys::ringForWrite() and SecretKeys::underLock(), which read the document
 * under a shared lock, so the ring stays true until the caller's transaction ends: no rotation and
 * no retirement can commit before then. The retiring key is unwrapped only when a record names it.
 *
 * @since 0.1.0
 */
final class KeyRing {

	/**
	 * The key new secrets are sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @var DataKey
	 */
	private DataKey $active;

	/**
	 * The retiring key's id, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $retiringId;

	/**
	 * Unwraps the retiring key, on first use.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): DataKey
	 */
	private \Closure $retiring;

	/**
	 * Holds the keys.
	 *
	 * @since 0.1.0
	 *
	 * @param DataKey     $active     The active key.
	 * @param string|null $retiringId The retiring key's id, or null when no key is retiring.
	 * @param \Closure    $retiring   Unwraps the retiring key; called only when a record names it.
	 *
	 * @phpstan-param \Closure(): DataKey $retiring
	 */
	public function __construct( DataKey $active, ?string $retiringId, \Closure $retiring ) {
		$this->active     = $active;
		$this->retiringId = $retiringId;
		$this->retiring   = $retiring;
	}

	/**
	 * Returns the key new secrets are sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @return DataKey The active key.
	 */
	public function active(): DataKey {
		return $this->active;
	}

	/**
	 * Returns the retiring key's id.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when no key is retiring.
	 */
	public function retiringId(): ?string {
		return $this->retiringId;
	}

	/**
	 * Returns the key a sealed value names, if it is the active or the retiring key.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::UnknownKey when it is neither, or
	 *                        SecretsError::KeyUnavailable when the retiring key cannot be unwrapped.
	 *
	 * @param string $keyId The key id.
	 * @return DataKey The key.
	 */
	public function key( string $keyId ): DataKey {
		if ( $this->active->id() === $keyId ) {
			return $this->active;
		}

		if ( $this->retiringId === $keyId ) {
			return ( $this->retiring )();
		}

		CodedException::raise( SecretsError::UnknownKey, array( 'key_id' => $keyId ) );
	}
}
