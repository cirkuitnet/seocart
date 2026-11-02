<?php
/**
 * StockOutcome: what became of a stock cell
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one stock cell: applied, skipped or a conflict, with the item's on-hand count after the unit.
 *
 * Owns one fact: what a merchant learns of a cell. `applied` when the count was set, with the
 * ledger entry that records it; `skipped` when the count was already what the cell asked;
 * `conflict` when the item's count had changed since it was read, so nothing was set, and the
 * count is the one it has now, for the merchant to look at again.
 *
 * @since 0.2.0
 */
final readonly class StockOutcome {

	/**
	 * The count was set.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const APPLIED = 'applied';

	/**
	 * The count was already what the cell asked.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The count had changed since the cell was read; nothing was set.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONFLICT = 'conflict';

	/**
	 * Every outcome a cell may have.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const OUTCOMES = array( self::APPLIED, self::SKIPPED, self::CONFLICT );

	/**
	 * Holds an outcome.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $variantId     The variant.
	 * @param int      $locationId    The location.
	 * @param string   $outcome       APPLIED, SKIPPED or CONFLICT.
	 * @param int      $onHand        The item's units on hand after the unit.
	 * @param int|null $ledgerEntryId The ledger entry that records the change, or null for a cell not applied.
	 */
	public function __construct(
		public int $variantId,
		public int $locationId,
		public string $outcome,
		public int $onHand,
		public ?int $ledgerEntryId
	) {
	}
}
