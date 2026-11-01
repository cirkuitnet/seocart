<?php
/**
 * IndexKind: what kind of index a declared index is
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The three kinds of secondary index a plugin table may declare.
 *
 * Owns one fact: how an index orders and finds its rows. A unique key and a plain key are B-tree
 * indexes, the first refusing a second row with the same values; a fulltext key is an inverted
 * index of the words of its text columns, which `MATCH … AGAINST` reads and nothing else does.
 *
 * @since 0.2.0
 */
enum IndexKind: string {

	/**
	 * A B-tree index that refuses two rows with the same values.
	 *
	 * @since 0.2.0
	 */
	case Unique = 'unique';

	/**
	 * A plain B-tree index.
	 *
	 * @since 0.2.0
	 */
	case Key = 'key';

	/**
	 * An index of the words of text columns, for `MATCH … AGAINST`.
	 *
	 * @since 0.2.0
	 */
	case Fulltext = 'fulltext';

	/**
	 * Returns the index type the server reports for an index of this kind, in information_schema.STATISTICS.INDEX_TYPE.
	 *
	 * @since 0.2.0
	 *
	 * @return string `FULLTEXT` for a fulltext key; `BTREE` for the two others.
	 */
	public function indexType(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return self::Fulltext === $this ? 'FULLTEXT' : 'BTREE';
	}
}
