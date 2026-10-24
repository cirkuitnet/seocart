<?php
/**
 * CreatesTerms: committed product terms for a test, and nothing of them left behind
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Infrastructure\ProductTaxonomies;

/**
 * Creates terms of the product taxonomies for a test that commits, and removes every trace of them afterwards.
 *
 * Owns one fact: what a test that writes product terms must clean up. Its terms are deleted, and
 * so is the option in which WordPress caches a hierarchical taxonomy's tree,
 * `<taxonomy>_children`: WordPress writes it on every change to the taxonomy's terms, even to
 * an empty tree, and a test that commits would leave it in the options table, where a later
 * test finds a `seocart_` option nobody declared.
 *
 * The test calls term() to create its terms and deleteCreatedTerms() from its tear_down().
 *
 * @since 0.2.0
 */
trait CreatesTerms {

	/**
	 * The terms the test created, as taxonomy => term ids.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<int>>
	 */
	private array $createdTerms = array();

	/**
	 * Creates a term, committed, and deletes it after the test.
	 *
	 * @since 0.2.0
	 *
	 * @param string $taxonomy The taxonomy.
	 * @param string $name     The term's name; its slug is WordPress's, from the name.
	 * @param int    $parentId Optional. Its parent, in the categories. Default none.
	 * @return int The term's id.
	 */
	protected function term( string $taxonomy, string $name, int $parentId = 0 ): int {
		$term = wp_insert_term( $name, $taxonomy, array( 'parent' => $parentId ) );

		if ( ! is_array( $term ) ) {
			self::fail( "The term {$name} could not be created: " . $term->get_error_message() );
		}

		$this->createdTerms[ $taxonomy ][] = (int) $term['term_id'];

		return (int) $term['term_id'];
	}

	/**
	 * Deletes the terms the test created, then each product taxonomy's cached tree.
	 *
	 * @since 0.2.0
	 */
	protected function deleteCreatedTerms(): void {
		foreach ( $this->createdTerms as $taxonomy => $ids ) {
			foreach ( $ids as $id ) {
				wp_delete_term( $id, $taxonomy );
			}
		}

		$this->createdTerms = array();

		// Deleting a term writes the tree again, so it is removed last.
		foreach ( ProductTaxonomies::names() as $taxonomy ) {
			delete_option( $taxonomy . '_children' );
		}
	}
}
