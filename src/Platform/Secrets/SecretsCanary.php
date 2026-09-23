<?php
/**
 * SecretsCanary: proves the stored secrets still open
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
 * Opens the canary, a known text sealed with the active data key, and says why when it does not.
 *
 * This class owns one fact: whether the site can open its secrets now. The canary is sealed when
 * the first key is created and sealed again at every rotation, so it always proves the active key.
 * The kernel calls check() on `admin_init` and before a gateway call, and enters Safe Mode on a
 * failure, so a key that no longer opens is reported before a charge is attempted rather than
 * failing inside it.
 *
 * check() never throws for a failure of the secrets themselves; each is a CanaryFailure. It
 * writes nothing, and opening the canary costs one read of the data keys option, which the object
 * cache keeps.
 *
 * @since 0.1.0
 */
final class SecretsCanary {

	/**
	 * The data keys.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretKeys
	 */
	private SecretKeys $keys;

	/**
	 * Creates the canary check.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretKeys $keys The data keys.
	 */
	public function __construct( SecretKeys $keys ) {
		$this->keys = $keys;
	}

	/**
	 * Opens the canary.
	 *
	 * @since 0.1.0
	 *
	 * @return CanaryResult Passed, with the active key's id, or failed, with the reason.
	 */
	public function check(): CanaryResult {
		try {
			$cipher = $this->keys->cipher();
		} catch ( CodedException ) {
			return CanaryResult::failed( CanaryFailure::NoCipher );
		}

		try {
			$key    = $this->keys->active();
			$sealed = $this->keys->sealedCanary();
		} catch ( CodedException $failure ) {
			return $this->unavailable( $failure );
		}

		if ( null === $sealed ) {
			return CanaryResult::failed( CanaryFailure::KeysDamaged, $key->id() );
		}

		if ( Envelope::keyId( $sealed ) !== $key->id() ) {
			return CanaryResult::failed( CanaryFailure::KeyReplaced, $key->id() );
		}

		try {
			$text = Envelope::open( $sealed, $key, $this->keys->canaryRecord(), $cipher );
		} catch ( CodedException ) {
			return CanaryResult::failed( CanaryFailure::Unreadable, $key->id() );
		}

		return hash_equals( SecretKeys::CANARY_TEXT, $text ) ? CanaryResult::passed( $key->id() ) : CanaryResult::failed( CanaryFailure::Mismatch, $key->id() );
	}

	/**
	 * Tells why the active key cannot be read.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $failure What reading it ended in.
	 * @return CanaryResult The failure: no key on a site that never had one; damaged keys on a site
	 *                      that had; the encryption key's state when the key is wrapped.
	 */
	private function unavailable( CodedException $failure ): CanaryResult {
		if ( SecretsError::NotInitialized === $failure->errorCode() ) {
			return CanaryResult::failed( $this->keys->hasHistory() ? CanaryFailure::KeysDamaged : CanaryFailure::NotInitialized );
		}

		try {
			$key_id  = $this->keys->activeKeyId();
			$wrapped = null !== $key_id && true === ( $this->keys->wrapped()[ $key_id ] ?? false );
		} catch ( CodedException ) {
			return CanaryResult::failed( CanaryFailure::KeysDamaged );
		}

		return CanaryResult::failed( $wrapped ? $this->encryptionKeyFailure() : CanaryFailure::KeysDamaged, $key_id );
	}

	/**
	 * Tells what is wrong with the encryption key, when it does not unwrap the active key.
	 *
	 * @since 0.1.0
	 *
	 * @return CanaryFailure The reason, from the encryption key's state.
	 */
	private function encryptionKeyFailure(): CanaryFailure {

		return match ( $this->keys->encryptionKey()->state() ) {
			EncryptionKeyState::Absent  => CanaryFailure::EncryptionKeyAbsent,
			EncryptionKeyState::Invalid => CanaryFailure::EncryptionKeyInvalid,
			EncryptionKeyState::Present => CanaryFailure::EncryptionKeyMismatch,
		};
	}
}
