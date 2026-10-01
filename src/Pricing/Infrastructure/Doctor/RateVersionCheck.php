<?php
/**
 * RateVersionCheck: compares the exchange-rate version recorded as current with the newest one stored
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure\Doctor;

use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Pricing\Infrastructure\MysqlExchangeRates;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a current exchange-rate version that is not the newest one stored, and records the newest on repair.
 *
 * Owns one fact: when doctor calls the current rate version wrong. Every save records the version
 * it stored as current once it has committed, so the two agree unless something came between:
 *
 * - behind: a newer version is stored than the one recorded, because a save committed and the
 *   request ended before it was recorded. Carts keep pricing at the older rates;
 * - ahead: the version recorded has no rates stored, because the rates were restored from an older
 *   copy or deleted. Carts in other currencies are refused, and the saves that follow are never
 *   made current, since a save never moves the current version back.
 *
 * --repair records the newest version stored, or that none is current when no rate is stored,
 * under the rates lock, so no save is half-way through, and after checking the two again. In the
 * ahead case that moves the current version back, which only a repair does: it records through the
 * kernel's recording with the rule that a version never goes back lifted on purpose.
 *
 * It prints version numbers only.
 *
 * @since 0.1.0
 */
final class RateVersionCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'rates';

	/**
	 * The rates.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlExchangeRates
	 */
	private MysqlExchangeRates $rates;

	/**
	 * Returns the version recorded as current, or null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): (int|null)
	 */
	private \Closure $recordedVersion;

	/**
	 * Records a version as current, even an older one, or null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(int|null): void
	 */
	private \Closure $setVersion;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlExchangeRates $rates           The rates.
	 * @param \Closure           $recordedVersion Returns the version recorded as current (int), or null for none.
	 * @param \Closure           $setVersion      Records a version (int) as current, even an older one, or none (null).
	 *
	 * @phpstan-param \Closure(): (int|null)   $recordedVersion
	 * @phpstan-param \Closure(int|null): void $setVersion
	 */
	public function __construct( MysqlExchangeRates $rates, \Closure $recordedVersion, \Closure $setVersion ) {
		$this->rates           = $rates;
		$this->recordedVersion = $recordedVersion;
		$this->setVersion      = $setVersion;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `rates`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Compares the version recorded as current with the newest one stored.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when they are the same, or when no rate is stored and none is current.
	 */
	public function run(): CheckResult {
		$recorded = ( $this->recordedVersion )();
		$stored   = $this->rates->newestVersion();

		if ( $recorded === $stored ) {
			return CheckResult::pass( self::NAME, null === $stored ? 'No exchange rate is stored, and none is current.' : sprintf( 'The current exchange-rate version, %d, is the newest stored.', $stored ) );
		}

		if ( (int) $recorded < (int) $stored ) {
			return CheckResult::fail(
				self::NAME,
				'The current exchange-rate version is behind the newest stored.',
				array( sprintf( 'Exchange-rate version %1$d is stored, but %2$s is current: a save committed and was never made current, so carts still price at the older rates. --repair makes version %1$d current.', (int) $stored, self::describe( $recorded ) ) )
			);
		}

		return CheckResult::fail(
			self::NAME,
			'The current exchange-rate version has no rates stored.',
			array( sprintf( 'Exchange-rate version %1$d is current, but %2$s: carts in other currencies are refused, and later saves are not made current. --repair makes %3$s current.', (int) $recorded, null === $stored ? 'no rate is stored' : 'the newest stored is version ' . $stored, self::describe( $stored ) ) )
		);
	}

	/**
	 * Records the newest version stored as current, under the rates lock, if the two still differ.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed.
	 */
	public function repair(): RepairResult {
		return $this->rates->underLock(
			function (): RepairResult {
				$recorded = ( $this->recordedVersion )();
				$stored   = $this->rates->newestVersion();

				if ( $recorded === $stored ) {
					return new RepairResult( self::NAME );
				}

				( $this->setVersion )( $stored );

				return new RepairResult( self::NAME, array( sprintf( 'made %1$s current in place of %2$s', self::describe( $stored ), self::describe( $recorded ) ) ) );
			}
		);
	}

	/**
	 * Names a version for a message.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $version The version, or null for none.
	 * @return string For example `version 3`, or `no version`.
	 */
	private static function describe( ?int $version ): string {
		return null === $version ? 'no version' : 'version ' . $version;
	}
}
