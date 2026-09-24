<?php
/**
 * Tests the sellability verdict as a reader gets it: through the wired query, from the one fetch
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * Every verdict, planted by direct SQL on a whole product, is what the kernel's Query\Sellability
 * answers: the fetch reads each fact the rule needs from the rows, joins the source post only
 * when it is a product post, and counts only a price in the base currency. One query on the
 * catalog's tables answers any number of variants.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In MysqlProductRepository::sellabilityFacts(), drop `AND wp.post_type = %s` from the join
 *   (and its argument): the case whose source post is an ordinary post answers `sellable`.
 * - In the same query, drop `AND vp.currency = %s` (and its argument): the case priced only in
 *   another currency answers `sellable`.
 *
 * @since 0.1.0
 */
final class SellabilityFetchTest extends CatalogTestCase {

	/**
	 * Tests that each planted fact is the verdict the wired query gives.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_plants
	 *
	 * @param string[] $plants         Statements that plant the fact, with {table} and {id} markers.
	 * @param bool     $canReadPrivate Whether the reader may read private products.
	 * @param string   $expected       The verdict.
	 */
	public function test_each_planted_fact_is_the_verdict( array $plants, bool $canReadPrivate, string $expected ): void {
		$product   = $this->storedProduct();
		$variantId = (int) $product->defaultVariant()?->id();
		$names     = array(
			'{products}'       => $this->catalogTable( CatalogTables::PRODUCTS ),
			'{product_posts}'  => $this->catalogTable( CatalogTables::PRODUCT_POSTS ),
			'{variants}'       => $this->catalogTable( CatalogTables::VARIANTS ),
			'{variant_prices}' => $this->catalogTable( CatalogTables::VARIANT_PRICES ),
			'{posts}'          => $this->db->prefix() . 'posts',
			'{product}'        => (string) $product->id(),
			'{variant}'        => (string) $variantId,
			'{post}'           => (string) $product->sourcePostId(),
		);

		foreach ( $plants as $plant ) {
			$this->db->execute( strtr( $plant, $names ) );
		}

		clean_post_cache( (int) $product->sourcePostId() );

		$verdicts = $this->query()->of( array( $variantId ), $canReadPrivate );

		$this->assertSame( array( $variantId ), array_keys( $verdicts ) );
		$this->assertSame( $expected, $verdicts[ $variantId ]->value );
	}

	/**
	 * Provides one planted fact per verdict, and the facts that must not change it.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>, bool, string}> Test cases.
	 */
	public static function data_plants(): array {
		$status = static fn( string $status ): string => "UPDATE {posts} SET post_status = '{$status}' WHERE ID = {post}";

		return array(
			'nothing planted'                             => array( array(), false, 'sellable' ),
			'incomplete'                                  => array( array( "UPDATE {products} SET generation_state = 'incomplete' WHERE id = {product}" ), false, 'incomplete' ),
			'updating'                                    => array( array( "UPDATE {products} SET generation_state = 'updating' WHERE id = {product}" ), true, 'updating' ),
			'updating, even as a draft'                   => array( array( "UPDATE {products} SET generation_state = 'updating' WHERE id = {product}", $status( 'draft' ) ), true, 'updating' ),
			'a marker this code does not know'            => array( array( "UPDATE {products} SET generation_state = 'unheard' WHERE id = {product}" ), false, 'incomplete' ),
			'no source post'                              => array( array( 'UPDATE {products} SET source_post_id = NULL WHERE id = {product}' ), false, 'no_binding' ),
			'no binding row for the source post'          => array( array( 'DELETE FROM {product_posts} WHERE post_id = {post}' ), false, 'no_binding' ),
			'the source post is an ordinary post'         => array( array( "UPDATE {posts} SET post_type = 'post' WHERE ID = {post}" ), false, 'no_binding' ),
			'the source post is gone'                     => array( array( 'DELETE FROM {posts} WHERE ID = {post}' ), false, 'no_binding' ),
			'no price'                                    => array( array( 'DELETE FROM {variant_prices} WHERE variant_id = {variant}' ), false, 'no_base_price' ),
			'a price in another currency only'            => array( array( "UPDATE {variant_prices} SET currency = 'EUR' WHERE variant_id = {variant}" ), false, 'no_base_price' ),
			'the variant is not in the active generation' => array( array( 'UPDATE {products} SET active_variant_generation = 2 WHERE id = {product}' ), false, 'not_active_generation' ),
			'no generation published'                     => array( array( 'UPDATE {products} SET active_variant_generation = 0 WHERE id = {product}' ), false, 'not_active_generation' ),
			'disabled'                                    => array( array( 'UPDATE {variants} SET is_enabled = 0 WHERE id = {variant}' ), false, 'variant_disabled' ),
			'a draft'                                     => array( array( $status( 'draft' ) ), false, 'not_published' ),
			'pending'                                     => array( array( $status( 'pending' ) ), false, 'not_published' ),
			'scheduled'                                   => array( array( $status( 'future' ) ), false, 'not_published' ),
			'in the trash'                                => array( array( $status( 'trash' ) ), false, 'not_published' ),
			'an auto-draft'                               => array( array( $status( 'auto-draft' ) ), false, 'not_published' ),
			'private, to a reader without access'         => array( array( $status( 'private' ) ), false, 'private' ),
			'private, to a reader with access'            => array( array( $status( 'private' ) ), true, 'sellable' ),
		);
	}

	/**
	 * Tests that an id no variant has is `unknown_variant`, and that many variants cost one query on the catalog's tables.
	 *
	 * @since 0.1.0
	 */
	public function test_many_variants_cost_one_fetch_and_an_unknown_one_is_named(): void {
		$first  = (int) $this->storedProduct( 'ONE' )->defaultVariant()?->id();
		$second = (int) $this->storedProduct( 'TWO', 'draft' )->defaultVariant()?->id();
		$query  = $this->query();

		// The store's base currency is read from the settings on first use; that read is not the fetch.
		$query->of( array( $first ), false );

		$verdicts = array();
		$log      = $this->captureQueries(
			static function () use ( $query, $first, $second, &$verdicts ): void {
				$verdicts = $query->of( array( $second, $first, $second + 1000 ), false );
			}
		);

		$this->assertSame(
			array(
				$second        => SellabilityReason::NotPublished,
				$first         => SellabilityReason::Sellable,
				$second + 1000 => SellabilityReason::UnknownVariant,
			),
			$verdicts
		);
		$this->assertQueryCount( 1, $log->forTable( $this->catalogTable( CatalogTables::VARIANTS ) ), 'Queries on the variants table for three verdicts' );
	}

	/**
	 * Returns the query as the kernel wires it, over this test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return Sellability The query.
	 */
	private function query(): Sellability {
		$query = KernelContainer::build( $this->db, $this->reporter() )->get( Sellability::class );

		$this->assertInstanceOf( Sellability::class, $query );

		return $query;
	}
}
