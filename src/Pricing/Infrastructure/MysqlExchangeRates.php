<?php
/**
 * MysqlExchangeRates: saves each version of the store's exchange rates, on MySQL
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Pricing\Application\ExchangeRates;
use SEOCart\Pricing\Application\ManualRate;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Appends a complete rate set as the next version, then makes it current, one save at a time.
 *
 * Owns one fact: how a version of the rates is written. The set is checked as a whole first: at
 * least one rate, each from the store's base currency, one per currency. Then the save takes the
 * rates lock, so saves run one after another: inside it, one transaction takes the next version
 * from the newest one stored and inserts the whole set at it in one statement, and once that has
 * committed the version is recorded as current. Two saves at the same moment therefore publish
 * two versions in turn, each a whole set, never one version made of two halves. Nothing is ever
 * updated or deleted, so every version an order was placed at stays as it was.
 *
 * A cart never prices at rates that are not stored: if the save fails, the previous version stays
 * current. The next version is taken from the stored rates, not from the version recorded as
 * current, so a save that committed but was never recorded is followed, not met; doctor reports
 * such a version, and its repair records it, under the same lock (underLock()).
 *
 * The lock is taken outside any transaction, so a save inside one is refused.
 *
 * @since 0.1.0
 */
final class MysqlExchangeRates implements ExchangeRates {

	/**
	 * The newest version stored, NULL before the first.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NEWEST_VERSION = 'SELECT MAX( version ) AS version FROM {exchange_rates}';

	/**
	 * A rate of a set: the pair, the rate and its scale, the version, the source and the user who saved it, 0 for none. ModuleStatements::forRows() repeats its row once per rate.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_RATES = 'INSERT INTO {exchange_rates} ( base_currency, quote_currency, rate, rate_scale, version, source, created_by, created_at ) VALUES ( %s, %s, %s, %d, %d, %s, NULLIF( %d, 0 ), UTC_TIMESTAMP(6) )';

	/**
	 * Where every rate saved here comes from.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SOURCE = 'manual';

	/**
	 * The lock a save, or a repair of the current version, holds.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK = 'pricing.exchange_rates';

	/**
	 * How long a table-mode lease on the lock lasts: far longer than a save takes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LOCK_TTL_SECONDS = 30;

	/**
	 * How long a save waits for another to finish before it gives up.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LOCK_WAIT_SECONDS = 10;

	/**
	 * Runs the save's transaction, and tells whether one is open already.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Sends the statements.
	 *
	 * @since 0.1.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Currency
	 */
	private \Closure $baseCurrency;

	/**
	 * Records a version as the current one.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(int): void
	 */
	private \Closure $recordVersion;

	/**
	 * Creates the writer. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database           $db            The connection.
	 * @param TransactionManager $transactions  Runs the save's transaction.
	 * @param callable           $withLock      Takes a named lock (string), for a TTL and a wait (ints, seconds), runs work
	 *                                          (callable) while holding it and releases it: LockService::withLock().
	 * @param \Closure           $baseCurrency  Returns the store's base currency (Currency).
	 * @param \Closure           $recordVersion Records a version (int) as the current one, once it is committed.
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 * @phpstan-param \Closure(): Currency                       $baseCurrency
	 * @phpstan-param \Closure(int): void                        $recordVersion
	 */
	public function __construct( Database $db, TransactionManager $transactions, callable $withLock, \Closure $baseCurrency, \Closure $recordVersion ) {
		$this->statements    = new ModuleStatements( $db, 'pricing', PricingTables::names() );
		$this->transactions  = $transactions;
		$this->withLock      = $withLock;
		$this->baseCurrency  = $baseCurrency;
		$this->recordVersion = $recordVersion;
	}

	/**
	 * Saves a complete set of rates as the next version, and makes it the current one once it is committed.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException           When a transaction is open: the rates lock is taken outside any.
	 * @throws \InvalidArgumentException When the set is empty, a rate is not from the base currency,
	 *                                   or two rates are for the same currency.
	 *
	 * @param ManualRate[] $rates The rates, one per currency.
	 * @param Actor        $actor Who saves them.
	 * @return int The new version.
	 *
	 * @phpstan-param list<ManualRate> $rates
	 */
	public function saveVersion( array $rates, Actor $actor ): int {
		$this->refuseInsideTransaction();
		$this->checkSet( $rates );

		return $this->underLock( fn(): int => $this->append( $rates, $actor ) );
	}

	/**
	 * Returns the newest version stored.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The version, or null when no rate is stored.
	 */
	public function newestVersion(): ?int {
		$newest = $this->statements->rows( self::NEWEST_VERSION )[0]['version'] ?? null;

		return null === $newest ? null : (int) $newest;
	}

	/**
	 * Runs work while no save can run: under the rates lock.
	 *
	 * @since 0.1.0
	 *
	 * @template T
	 *
	 * @param callable $work The work.
	 * @return mixed What the work returned.
	 *
	 * @phpstan-param callable(): T $work
	 * @phpstan-return T
	 */
	public function underLock( callable $work ): mixed {
		return ( $this->withLock )( self::LOCK, self::LOCK_TTL_SECONDS, self::LOCK_WAIT_SECONDS, static fn(): mixed => $work() );
	}

	/**
	 * Inserts the set at the next version in a transaction of its own, then records that version as current.
	 *
	 * @since 0.1.0
	 *
	 * @param ManualRate[] $rates The rates, checked.
	 * @param Actor        $actor Who saves them.
	 * @return int The new version.
	 *
	 * @phpstan-param list<ManualRate> $rates
	 */
	private function append( array $rates, Actor $actor ): int {
		$version = $this->transactions->transaction(
			function () use ( $rates, $actor ): int {
				$version = 1 + (int) $this->newestVersion();
				$values  = array();

				foreach ( $rates as $rate ) {
					array_push( $values, $rate->base->code(), $rate->quote->code(), $rate->rate->toString(), $rate->rate->scale(), $version, self::SOURCE, $actor->userId() );
				}

				$this->statements->execute( ModuleStatements::forRows( self::INSERT_RATES, count( $rates ) ), ...$values );

				return $version;
			}
		);

		( $this->recordVersion )( $version );

		return $version;
	}

	/**
	 * Refuses to save inside a transaction, before anything is sent: the rates lock is taken outside any.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open.
	 */
	private function refuseInsideTransaction(): void {
		if ( $this->transactions->depth() > 0 ) {
			throw new \LogicException( 'A rate set is saved under the rates lock, which is taken outside any transaction: save it before opening one.' );
		}
	}

	/**
	 * Refuses a set that cannot be a version: empty, with a rate from another currency than the base one, or two rates for one currency.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the set is one of those.
	 *
	 * @param ManualRate[] $rates The rates.
	 *
	 * @phpstan-param list<ManualRate> $rates
	 */
	private function checkSet( array $rates ): void {
		if ( array() === $rates ) {
			throw new \InvalidArgumentException( 'A rate set has at least one rate: a store with no other currency saves none.' );
		}

		$base   = ( $this->baseCurrency )();
		$quoted = array();

		foreach ( $rates as $rate ) {
			if ( ! $rate->base->equals( $base ) ) {
				throw new \InvalidArgumentException( sprintf( 'Every rate is from the base currency, %1$s; a rate from %2$s was given.', $base->code(), $rate->base->code() ) );
			}

			if ( isset( $quoted[ $rate->quote->code() ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'A rate set has one rate per currency; %s has two.', $rate->quote->code() ) );
			}

			$quoted[ $rate->quote->code() ] = true;
		}
	}
}
