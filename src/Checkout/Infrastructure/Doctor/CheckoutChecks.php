<?php
/**
 * CheckoutChecks: doctor's check of the checkout's tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure\Doctor;

use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports idempotency keys stranded in `claimed`, and deletes them on repair.
 *
 * Owns one fact: when doctor calls a claimed key stranded. A key is claimed and completed in the
 * one transaction that places its order, so a committed key that is still claimed after
 * STRANDED_SECONDS was left by a lost connection: the server committed the claim on its own and
 * the rest of the placement rolled back. A retry with that key is answered "placement in
 * progress" for as long as the key lives. Nothing references such a key, so --repair deletes it,
 * after checking again in its statement that it is still claimed and still that old; the client's
 * next retry then places its order. Age is judged by the database clock.
 *
 * It prints key ids, scopes and ages, never a hash or an answer.
 *
 * @since 0.1.0
 */
final class CheckoutChecks implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'checkout';

	/**
	 * How long a key may stay claimed before it is reported: far longer than any placement takes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STRANDED_SECONDS = 3600;

	/**
	 * The most keys the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The key statements.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlIdempotencyKeys
	 */
	private MysqlIdempotencyKeys $keys;

	/**
	 * The keys the last run() found, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlIdempotencyKeys $keys The key statements.
	 */
	public function __construct( MysqlIdempotencyKeys $keys ) {
		$this->keys = $keys;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `checkout`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists the keys stranded in `claimed`.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when none is.
	 */
	public function run(): CheckResult {
		$stranded = $this->keys->stranded( self::STRANDED_SECONDS, self::LIMIT );

		$this->found = array_column( $stranded, 'id' );

		if ( array() === $stranded ) {
			return CheckResult::pass( self::NAME, sprintf( 'No idempotency key has been claimed for more than %d minutes without its order.', intdiv( self::STRANDED_SECONDS, 60 ) ) );
		}

		$findings = array();

		foreach ( $stranded as $key ) {
			$findings[] = sprintf(
				'Warning: idempotency key %1$d (%2$s) has been claimed for %3$d seconds without an order: a lost connection committed the claim alone. A retry with this key is told an order is being placed until --repair deletes it.',
				$key['id'],
				$key['scope'],
				$key['age_seconds']
			);
		}

		return CheckResult::fail( self::NAME, sprintf( '%d stranded idempotency %s.', count( $stranded ), 1 === count( $stranded ) ? 'key' : 'keys' ), $findings );
	}

	/**
	 * Deletes the keys run() found, only while each is still claimed and still that old.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed.
	 */
	public function repair(): RepairResult {
		if ( array() === $this->found ) {
			return new RepairResult( self::NAME );
		}

		$deleted = $this->keys->deleteStranded( $this->found, self::STRANDED_SECONDS );

		if ( 0 === $deleted ) {
			return new RepairResult( self::NAME );
		}

		return new RepairResult(
			self::NAME,
			array( sprintf( 'deleted %1$d of the stranded idempotency keys %2$s; a key completed or claimed again since was kept.', $deleted, implode( ', ', $this->found ) ) )
		);
	}
}
