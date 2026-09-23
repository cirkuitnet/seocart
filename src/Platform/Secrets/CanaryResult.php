<?php
/**
 * CanaryResult: whether the secrets canary opened
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of SecretsCanary::check().
 *
 * This class owns one fact: how the canary's outcome is told to the kernel, the status command and
 * Site Health. It names a key id and a reason, never a secret.
 *
 * @since 0.1.0
 */
final readonly class CanaryResult {

	/**
	 * Why the canary failed, or null when it opened.
	 *
	 * @since 0.1.0
	 *
	 * @var CanaryFailure|null
	 */
	public ?CanaryFailure $failure;

	/**
	 * The id of the active key, when there is one.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $keyId;

	/**
	 * Records the outcome. Use passed() or failed().
	 *
	 * @since 0.1.0
	 *
	 * @param CanaryFailure|null $failure Why it failed, or null.
	 * @param string|null        $keyId   The active key's id, or null.
	 */
	private function __construct( ?CanaryFailure $failure, ?string $keyId ) {
		$this->failure = $failure;
		$this->keyId   = $keyId;
	}

	/**
	 * Records that the canary opened to the text it was sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @param string $keyId The active key's id.
	 * @return self The outcome.
	 */
	public static function passed( string $keyId ): self {
		return new self( null, $keyId );
	}

	/**
	 * Records that the canary failed.
	 *
	 * @since 0.1.0
	 *
	 * @param CanaryFailure $failure Why.
	 * @param string|null   $keyId   The active key's id, when there is one.
	 * @return self The outcome.
	 */
	public static function failed( CanaryFailure $failure, ?string $keyId = null ): self {
		return new self( $failure, $keyId );
	}

	/**
	 * Tells whether the canary opened.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it opened to the text it was sealed with.
	 */
	public function ok(): bool {
		return null === $this->failure;
	}

	/**
	 * Names the likely cause, for an administrator.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The cause, translated, or null when the canary opened.
	 */
	public function cause(): ?string {
		return match ( $this->failure ) {
			null                                 => null,
			CanaryFailure::NoCipher              => __( 'This server provides neither PHP\'s sodium extension nor the sodium_compat library WordPress ships, so SEOCart cannot open the stored secrets.', 'seocart' ),
			CanaryFailure::NotInitialized        => __( 'SEOCart has no data key on this site, so it cannot open the stored secrets. The database may have been restored without it. Deactivate and activate SEOCart to create a key, then enter your payment credentials again.', 'seocart' ),
			CanaryFailure::EncryptionKeyInvalid  => __( 'SEOCART_ENCRYPTION_KEY in wp-config.php is not the base64 encoding of 32 bytes, so SEOCart cannot open the data key it protects.', 'seocart' ),
			CanaryFailure::EncryptionKeyAbsent   => __( 'The data key is protected by SEOCART_ENCRYPTION_KEY, which wp-config.php no longer defines. Restore the constant. If this database was copied from another site, enter your payment credentials again instead.', 'seocart' ),
			CanaryFailure::EncryptionKeyMismatch => __( 'SEOCART_ENCRYPTION_KEY changed, or this database was restored onto a different site: the constant in wp-config.php does not open the data key. Restore the original constant, or enter your payment credentials again.', 'seocart' ),
			CanaryFailure::KeysDamaged           => __( 'SEOCart\'s stored data keys are damaged or missing, although secrets were sealed with them, so SEOCart cannot open the stored secrets. Nothing was replaced: restore the seocart_data_keys option from a backup.', 'seocart' ),
			CanaryFailure::KeyReplaced           => __( 'The data key was replaced: the stored secrets were sealed with another key. If this database was restored or copied, enter your payment credentials again.', 'seocart' ),
			CanaryFailure::Unreadable            => __( 'The data key or the secrets canary was changed in the database, so SEOCart cannot trust the stored secrets. Enter your payment credentials again.', 'seocart' ),
			CanaryFailure::Mismatch              => __( 'The secrets canary does not hold the text SEOCart sealed, so SEOCart cannot trust the stored secrets. Enter your payment credentials again.', 'seocart' ),
		};
	}

	/**
	 * Returns the outcome as data, for the status command and the support report.
	 *
	 * @since 0.1.0
	 *
	 * @return array{ok: bool, failure: string|null, key_id: string|null} The outcome.
	 */
	public function toArray(): array {
		return array(
			'ok'      => $this->ok(),
			'failure' => $this->failure?->value,
			'key_id'  => $this->keyId,
		);
	}
}
