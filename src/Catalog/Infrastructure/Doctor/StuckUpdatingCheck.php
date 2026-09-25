<?php
/**
 * StuckUpdatingCheck: products left `updating` longer than a crash could explain
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Doctor\ProductSettler;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and repairs products left `updating` for more than STALE_MINUTES (doctor check 7).
 *
 * Owns one fact: which products a crash, or a listener that ended the transaction, left mid-write.
 * The repair is ProductSettler's compare-and-set, with the extra guard check 7 needs: the
 * statement also requires the exact `updated_at` this check's read saw, so a save that re-marked
 * the product between the read and the repair — starting a real window — always wins; the repair
 * then changes nothing, which is correct, not an error.
 *
 * @since 0.1.0
 */
final class StuckUpdatingCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'stuck_updating';

	/**
	 * The most products the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * How long `updating` may last before it is reported: no legitimate window runs this long.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STALE_MINUTES = 15;

	/**
	 * The products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Tells the time.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Re-settles one product, safely.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductSettler
	 */
	private ProductSettler $settler;

	/**
	 * The products the last run() found, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{product_id: int, updated_at: string}>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products The products.
	 * @param Clock             $clock    Tells the time.
	 * @param ProductSettler    $settler  Re-settles one product, safely.
	 */
	public function __construct( ProductRepository $products, Clock $clock, ProductSettler $settler ) {
		$this->products = $products;
		$this->clock    = $clock;
		$this->settler  = $settler;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.stuck_updating`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists products left `updating` too long.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when none is stuck.
	 */
	public function run(): CheckResult {
		$before = $this->clock->now()->modify( '-' . self::STALE_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s.u' );
		$rows   = $this->products->stuckUpdating( $before, self::LIMIT + 1 );
		$more   = count( $rows ) > self::LIMIT;
		$rows   = array_slice( $rows, 0, self::LIMIT );

		$this->found = $rows;

		if ( array() === $rows ) {
			return CheckResult::pass( self::NAME, sprintf( 'No product has been left updating for more than %d minutes.', self::STALE_MINUTES ) );
		}

		$findings = array(
			sprintf(
				'Reported: product%1$s %2$s%3$s left updating for more than %4$d minutes: a crash, or a listener that ended the transaction. --repair settles each to complete or incomplete, whichever Product::settle() says.',
				1 === count( $rows ) ? '' : 's',
				implode( ', ', array_map( static fn( array $row ): int => $row['product_id'], $rows ) ),
				$more ? ' and more' : '',
				self::STALE_MINUTES
			),
		);

		return CheckResult::fail( self::NAME, sprintf( '%d product%s stuck updating.', count( $rows ), 1 === count( $rows ) ? '' : 's' ), $findings );
	}

	/**
	 * Settles each product run() found, only while it still has the exact marker read.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->found as $row ) {
			$change = $this->settler->settle( $row['product_id'], $row['updated_at'] );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		return new RepairResult( self::NAME, $changes );
	}
}
