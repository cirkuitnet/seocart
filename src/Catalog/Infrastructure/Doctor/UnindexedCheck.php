<?php
/**
 * UnindexedCheck: doctor's count of the posts of complete products without a search document
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Search\SearchDocuments;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the posts of complete products that a search ordered by relevance leaves out for want of a document, and fails while there is any.
 *
 * Owns one fact: whether the word index has caught up with the catalog. Every save writes the
 * documents of its product, so a post of a complete product without one was saved before the
 * index existed, or its document's write failed; a search ordered by relevance leaves it out,
 * while every other order lists it. A product whose every variant is disabled is not counted:
 * it is never listed. The failure names the command that indexes them. It never repairs: the
 * command does, a page at a time.
 *
 * @since 0.2.0
 */
final class UnindexedCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'unindexed';

	/**
	 * The command that indexes them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COMMAND = 'wp seocart catalog index-products';

	/**
	 * The search documents' sources.
	 *
	 * @since 0.2.0
	 *
	 * @var SearchDocuments
	 */
	private SearchDocuments $documents;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchDocuments $documents The search documents' sources.
	 */
	public function __construct( SearchDocuments $documents ) {
		$this->documents = $documents;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `unindexed`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Counts the posts of complete products without a search document.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Passed at none; failed with the count and the command otherwise.
	 */
	public function run(): CheckResult {
		$count = $this->documents->unindexedCount();

		if ( 0 === $count ) {
			return CheckResult::pass( self::NAME, 'Every post of a complete product has its search document.' );
		}

		return CheckResult::fail(
			self::NAME,
			sprintf( '%1$d %2$s of complete products %3$s no search document yet, so a search ordered by relevance leaves %4$s out. Run `%5$s`.', $count, 1 === $count ? 'post' : 'posts', 1 === $count ? 'has' : 'have', 1 === $count ? 'it' : 'them', self::COMMAND )
		);
	}
}
