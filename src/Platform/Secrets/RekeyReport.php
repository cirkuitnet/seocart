<?php
/**
 * RekeyReport: the outcome of one batch of re-sealing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one call to SecretVault::rekey().
 *
 * This class owns one fact: how a batch of re-sealing is told to its caller. It names records and
 * key ids, never a secret.
 *
 * @since 0.1.0
 */
final readonly class RekeyReport {

	/**
	 * The id of the active key once the batch was done.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $activeKeyId;

	/**
	 * How many records the batch re-sealed with the active key.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $resealed;

	/**
	 * How many records changed between being read and being replaced, and were left for the next batch.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $changed;

	/**
	 * The records the batch could not open, by name: each must be entered again.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $unreadable;

	/**
	 * The records the store cannot read as stored, by name: their option, or a neighbour in their
	 * document, must be repaired. While there is one, no key is retired.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $damaged;

	/**
	 * The records each key seals after the batch, keyed by key id or SecretVault::NO_KEY.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	public array $counts;

	/**
	 * How many records the next batch would try to re-seal: sealed with another key than the active
	 * one, readable as stored, and not found unreadable by this batch.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $pending;

	/**
	 * The id of the key the batch retired, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $retired;

	/**
	 * Records the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $activeKeyId The active key once the batch was done.
	 * @param int                $resealed    Records re-sealed.
	 * @param int                $changed     Records left because they changed meanwhile.
	 * @param string[]           $unreadable  Records that could not be opened.
	 * @param string[]           $damaged     Records the store cannot read as stored.
	 * @param array<string, int> $counts      Records per key after the batch.
	 * @param int                $pending     Records the next batch would try.
	 * @param string|null        $retired     The key retired, or null.
	 *
	 * @phpstan-param list<string> $unreadable
	 * @phpstan-param list<string> $damaged
	 */
	public function __construct( string $activeKeyId, int $resealed, int $changed, array $unreadable, array $damaged, array $counts, int $pending, ?string $retired ) {
		$this->activeKeyId = $activeKeyId;
		$this->resealed    = $resealed;
		$this->changed     = $changed;
		$this->unreadable  = $unreadable;
		$this->damaged     = $damaged;
		$this->counts      = $counts;
		$this->pending     = $pending;
		$this->retired     = $retired;
	}

	/**
	 * Counts the records still sealed with a key other than the active one, the unreadable ones included.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of records.
	 */
	public function remaining(): int {
		$remaining = 0;

		foreach ( $this->counts as $key_id => $count ) {
			if ( $this->activeKeyId !== (string) $key_id ) {
				$remaining += $count;
			}
		}

		return $remaining;
	}

	/**
	 * Tells whether every stored secret is sealed with the active key and readable as stored.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when nothing is left to do.
	 */
	public function settled(): bool {
		return 0 === $this->remaining() && array() === $this->damaged;
	}
}
