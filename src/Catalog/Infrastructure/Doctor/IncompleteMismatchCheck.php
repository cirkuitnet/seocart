<?php
/**
 * IncompleteMismatchCheck: products marked complete that are not whole
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
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Reports and repairs complete products with zero enabled variants at their active generation, or
 * whose default variant has no price in the base currency (doctor check 5).
 *
 * Owns one fact: which complete products are not actually whole. Scoped to `generation_state =
 * complete`: an incomplete product missing a price is the ordinary state of one still being set
 * up, not a finding. The repair is ProductSettler's one shape — reload under the product's lock,
 * recompute Product::settle(), write only if it differs, as a compare-and-set — so a product a
 * concurrent save already fixed between this check's read and the repair is left exactly as that
 * save left it.
 *
 * @since 0.1.0
 */
final class IncompleteMismatchCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'incomplete_mismatch';

	/**
	 * The most ids the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Re-settles one product, safely.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductSettler
	 */
	private ProductSettler $settler;

	/**
	 * The product ids the last run() found, for repair() to act on.
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
	 * @param ProductRepository $products     The products.
	 * @param callable          $baseCurrency Returns the store's base currency.
	 * @param ProductSettler    $settler      Re-settles one product, safely.
	 *
	 * @phpstan-param callable(): Currency $baseCurrency
	 */
	public function __construct( ProductRepository $products, callable $baseCurrency, ProductSettler $settler ) {
		$this->products     = $products;
		$this->baseCurrency = $baseCurrency;
		$this->settler      = $settler;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.incomplete_mismatch`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists complete products that are not whole.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every complete product is whole.
	 */
	public function run(): CheckResult {
		$ids         = $this->products->incompleteMismatchIds( ( $this->baseCurrency )()->code(), 0, self::LIMIT + 1 );
		$more        = count( $ids ) > self::LIMIT;
		$ids         = array_slice( $ids, 0, self::LIMIT );
		$this->found = $ids;

		if ( array() === $ids ) {
			return CheckResult::pass( self::NAME, 'Every complete product is whole.' );
		}

		$findings = array(
			sprintf(
				'Reported: product%1$s %2$s%3$s marked complete but %4$s zero enabled variants or no price in the base currency. --repair marks each incomplete when Product::settle() agrees it is not whole; settle() does not weigh zero enabled variants on its own, so a product wrong only that way stays reported, unrepaired, for a person to re-enable a variant.',
				1 === count( $ids ) ? '' : 's',
				implode( ', ', $ids ),
				$more ? ' and more' : '',
				1 === count( $ids ) ? 'has' : 'have'
			),
		);

		return CheckResult::fail( self::NAME, sprintf( '%d product%s marked complete that %s not whole.', count( $ids ), 1 === count( $ids ) ? '' : 's', 1 === count( $ids ) ? 'is' : 'are' ), $findings );
	}

	/**
	 * Marks incomplete each product run() found, unless it is no longer wrong.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->found as $productId ) {
			$change = $this->settler->settle( $productId );

			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		return new RepairResult( self::NAME, $changes );
	}
}
