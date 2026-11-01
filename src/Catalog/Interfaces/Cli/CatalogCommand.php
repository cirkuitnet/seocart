<?php
/**
 * CatalogCommand: `wp seocart catalog`, the catalog's maintenance from the command line
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Cli;

use SEOCart\Catalog\Application\Projection\CompleteProductPager;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Catalog\Application\Projection\SearchDocumentProjector;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Recomputes what the listings read of every complete product, a page at a time, resumably: its stock summary, its sort prices, or its search documents.
 *
 * Owns one fact: the command's contract with an operator. Each action walks the complete products
 * by id, PAGE at a time (CompleteProductPager), recomputes each page as the events that keep it do,
 * and prints after each page the id it reached, which `--after` resumes from. A value already right
 * is not written, so a second run writes nothing. It is how a store whose products were saved
 * before the summaries, the sort prices or the search documents existed catches up, and how a drift is put right.
 * - `summarize-stock` recomputes each product's stock summary (StockSummaryProjector). Every save
 *   and every stock movement but a checkout's hold and its release keeps the summaries right after
 *   that, and a run also puts right a summary that a hold given back left out of stock.
 * - `project-prices` recomputes each product's sort prices in every currency the store sells in
 *   (PriceProjector). Every save, and the repricing job after a new exchange-rate version, keep
 *   them right after that.
 * - `index-products` rebuilds each product's search documents, one per post that presents it
 *   (SearchDocumentProjector). Every save, a write to its post by any other path included, keeps
 *   them right after that.
 *
 * Each page runs while the command holds the outbox drainer's lock, so a page and a drain never
 * recompute a product at once (CompleteProductPager says how, and how long a page waits). It exits 0
 * when done, and 1 on a database failure, with its code and message: a drain that held the lock for
 * the whole wait, or the lock lost during a page, whose cursor is then not printed, so `--after`
 * from the last cursor printed does that page again.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin,
 * so it resolves to no operation definition by design. Output goes through a callable and run()
 * returns the exit code, so tests call run() without WP-CLI.
 *
 * @since 0.2.0
 */
final class CatalogCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: a database failure, or the command was used wrongly.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * How many products one page recomputes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const PAGE = 200;

	/**
	 * Each action, with what it recomputes, for the lines it prints.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{done: string, label: string}>
	 */
	private const ACTIONS = array(
		'summarize-stock' => array(
			'done'  => 'Summarized',
			'label' => 'summarized',
		),
		'project-prices'  => array(
			'done'  => 'Projected the prices of',
			'label' => 'projected',
		),
		'index-products'  => array(
			'done'  => 'Indexed the words of',
			'label' => 'indexed',
		),
	);

	/**
	 * Walks the complete products beside the drain.
	 *
	 * @since 0.2.0
	 *
	 * @var CompleteProductPager
	 */
	private CompleteProductPager $pager;

	/**
	 * Recomputes stock summaries as the stock events do.
	 *
	 * @since 0.2.0
	 *
	 * @var StockSummaryProjector
	 */
	private StockSummaryProjector $summaries;

	/**
	 * Recomputes sort prices as a save does.
	 *
	 * @since 0.2.0
	 *
	 * @var PriceProjector
	 */
	private PriceProjector $prices;

	/**
	 * Rebuilds search documents as a save does.
	 *
	 * @since 0.2.0
	 *
	 * @var SearchDocumentProjector
	 */
	private SearchDocumentProjector $documents;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Creates the command.
	 *
	 * @since 0.2.0
	 *
	 * @param CompleteProductPager    $pager     Walks the complete products beside the drain.
	 * @param StockSummaryProjector   $summaries Recomputes stock summaries as the stock events do.
	 * @param PriceProjector          $prices    Recomputes sort prices as a save does.
	 * @param SearchDocumentProjector $documents Rebuilds search documents as a save does.
	 * @param callable(string): void  $output    Prints one line, for example WP_CLI::log().
	 */
	public function __construct( CompleteProductPager $pager, StockSummaryProjector $summaries, PriceProjector $prices, SearchDocumentProjector $documents, callable $output ) {
		$this->pager     = $pager;
		$this->summaries = $summaries;
		$this->prices    = $prices;
		$this->documents = $documents;
		$this->output    = $output;
	}

	/**
	 * Recomputes what the listings read of every complete product: its stock summary, its sort prices, or its search documents.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do. `summarize-stock` recomputes every complete product's stock summary; `project-prices` its sort price in every currency the store sells in; `index-products` its search documents.
	 * ---
	 * options:
	 *   - summarize-stock
	 *   - project-prices
	 *   - index-products
	 * ---
	 *
	 * [--after=<id>]
	 * : Start after this product id: the id the last page of an interrupted run printed.
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when done, 1 on a database failure, such as a drain of the outbox holding its lock for longer than a page waits, or the lock lost during a page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart catalog summarize-stock
	 *     wp seocart catalog project-prices --after=4200
	 *     wp seocart catalog index-products
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                   $args      The action.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $args, $assocArgs ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                   $args      The action: summarize-stock, project-prices or index-products.
	 * @param array<string, string|bool> $assocArgs The options: after.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args, array $assocArgs ): int {
		$action = (string) ( $args[0] ?? '' );

		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			$this->say( 'Usage: wp seocart catalog ' . implode( '|', array_keys( self::ACTIONS ) ) . ' [--after=<id>]' );

			return self::EXIT_FAILED;
		}

		try {
			return $this->walk( $action, max( 0, (int) ( $assocArgs['after'] ?? 0 ) ) );
		} catch ( DatabaseException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			return self::EXIT_FAILED;
		}
	}

	/**
	 * Recomputes the complete products after a cursor, a page at a time, for one action.
	 *
	 * @since 0.2.0
	 *
	 * @param string $action One of ACTIONS' keys.
	 * @param int    $after  The last product id already done, or 0.
	 * @return int EXIT_OK.
	 */
	private function walk( string $action, int $after ): int {
		$words = self::ACTIONS[ $action ];
		$done  = $this->pager->walk(
			$after,
			self::PAGE,
			fn( array $productIds ): int => $this->work( $action, $productIds ),
			function ( int $products, int $cursor, int $written ) use ( $words ): void {
				$this->say( sprintf( '%1$s %2$d products up to id %3$d, %4$d written. To resume: --after=%3$d', $words['done'], $products, $cursor, $written ) );
			}
		);

		$this->say( sprintf( 'Done: %1$d complete products %2$s, %3$d written.', $done['products'], $words['label'], $done['written'] ) );

		return self::EXIT_OK;
	}

	/**
	 * Does one page's work for an action.
	 *
	 * @since 0.2.0
	 *
	 * @param string $action     One of ACTIONS' keys.
	 * @param int[]  $productIds The page's products.
	 * @return int How many rows the page wrote.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	private function work( string $action, array $productIds ): int {
		return match ( $action ) {
			'summarize-stock' => $this->summaries->project( $productIds ),
			'project-prices' => $this->prices->project( $productIds ),
			default => $this->documents->project( $productIds ),
		};
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}
}
