<?php
/**
 * SecretsReport: the state of the site's secrets, without a secret
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * What SecretsStatus found: the cipher, the encryption key, the data keys and their record counts,
 * and the canary.
 *
 * This class owns one fact: the shape in which the secrets' state is told to the status command,
 * Site Health and the support report. It holds key ids, states, counts and times; never a key,
 * never a secret.
 *
 * @since 0.1.0
 */
final readonly class SecretsReport {

	/**
	 * The library that provides the cipher, Cipher::EXTENSION or Cipher::POLYFILL, or null when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $library;

	/**
	 * What SEOCART_ENCRYPTION_KEY amounts to.
	 *
	 * @since 0.1.0
	 *
	 * @var EncryptionKeyState
	 */
	public EncryptionKeyState $encryptionKey;

	/**
	 * Every key the registry lists, oldest first, with whether it is wrapped and how many records it seals.
	 *
	 * `wrapped` is null for a key whose material is gone: a retired one.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{key_id: string, state: string, algorithm: string, created_at: string, retired_at: string|null, wrapped: bool|null, records: int}>
	 */
	public array $keys;

	/**
	 * How many records name no key the site still has: a retired or unknown key, or none.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $orphaned;

	/**
	 * How many records the store cannot read as stored.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $damaged;

	/**
	 * The canary's outcome.
	 *
	 * @since 0.1.0
	 *
	 * @var CanaryResult
	 */
	public CanaryResult $canary;

	/**
	 * Records the state.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null        $library       The cipher's library, or null.
	 * @param EncryptionKeyState $encryptionKey The encryption key's state.
	 * @param array[]            $keys          The keys, oldest first.
	 * @param int                $orphaned      Records that name no key the site has.
	 * @param int                $damaged       Records the store cannot read as stored.
	 * @param CanaryResult       $canary        The canary's outcome.
	 *
	 * @phpstan-param list<array{key_id: string, state: string, algorithm: string, created_at: string, retired_at: string|null, wrapped: bool|null, records: int}> $keys
	 */
	public function __construct( ?string $library, EncryptionKeyState $encryptionKey, array $keys, int $orphaned, int $damaged, CanaryResult $canary ) {
		$this->library       = $library;
		$this->encryptionKey = $encryptionKey;
		$this->keys          = $keys;
		$this->orphaned      = $orphaned;
		$this->damaged       = $damaged;
		$this->canary        = $canary;
	}

	/**
	 * Returns the key in a state, if there is one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $state SecretKeysTable::ACTIVE or SecretKeysTable::RETIRING.
	 * @return array{key_id: string, state: string, algorithm: string, created_at: string, retired_at: string|null, wrapped: bool|null, records: int}|null The key, or null.
	 */
	public function key( string $state ): ?array {
		foreach ( $this->keys as $key ) {
			if ( $state === $key['state'] ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Returns the state as data, for the support report and the status command's JSON.
	 *
	 * @since 0.1.0
	 *
	 * @return array{library: string|null, encryption_key: string, keys: list<array{key_id: string, state: string, algorithm: string, created_at: string, retired_at: string|null, wrapped: bool|null, records: int}>, orphaned_records: int, damaged_records: int, canary: array{ok: bool, failure: string|null, key_id: string|null}} The state.
	 */
	public function toArray(): array {
		return array(
			'library'          => $this->library,
			'encryption_key'   => $this->encryptionKey->value,
			'keys'             => $this->keys,
			'orphaned_records' => $this->orphaned,
			'damaged_records'  => $this->damaged,
			'canary'           => $this->canary->toArray(),
		);
	}
}
