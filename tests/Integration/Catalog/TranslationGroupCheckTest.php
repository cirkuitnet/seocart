<?php
/**
 * Tests doctor's translation-group check and its --repair, against a multilingual setup kept in memory
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Doctor\TranslationGroupCheck;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductRestTestCase;
use SEOCart\Tests\Support\Catalog\VariantSets;
use SEOCart\Tests\Support\Doubles\SeveralLocales;

/**
 * Doctor's eleventh check, over a locale port that keeps several languages in memory (SeveralLocales), so the rule is tested without a multilingual plugin; the conformance suite runs it against Polylang.
 *
 * A post's binding disagrees with its translation group when the multilingual setup puts it in
 * another product's group, or gives it another locale, and nothing in the request that changed
 * it heard: SeveralLocales::assign() changes the group without telling a watcher, exactly as a
 * multilingual plugin's own request can, when the watcher was never armed in it. The check finds
 * the disagreement; --repair runs TranslationGroups::reconcile() for the post, under its
 * product's lock, the one rule a first save in another language and this repair both go through:
 * a post whose product holds commerce data is never moved onto another, and stays reported.
 *
 * The repair re-reads a binding under its product's lock before reconciling it, so a change a
 * second connection makes between the check and the repair is never overwritten, and a scanned
 * product or binding that vanished never sends reconcile() down its unbound branch to bind the
 * post elsewhere.
 *
 * Planted violation, confirmed to fail a test here: in TranslationGroups::disagreement(), skip a
 * post whose own group has fewer than two members — the check's old, wrong way of deciding there
 * was nothing to check — and the lone-translation test fails, nothing reported for a post that
 * plainly left its group.
 *
 * @since 0.1.0
 */
final class TranslationGroupCheckTest extends ProductRestTestCase {

	/**
	 * The languages the services see.
	 *
	 * @since 0.1.0
	 *
	 * @var SeveralLocales
	 */
	private SeveralLocales $locales;

	/**
	 * Returns the languages the services are built over.
	 *
	 * @since 0.1.0
	 *
	 * @return PostLocales The languages.
	 */
	protected function locales(): PostLocales {
		$this->locales = new SeveralLocales();

		return $this->locales;
	}

	/**
	 * Tests that the check passes when every bound post's binding still agrees with its group.
	 *
	 * @since 0.1.0
	 */
	public function test_it_passes_when_every_binding_agrees_with_its_group(): void {
		$this->savedProduct();

		$result = $this->check()->run();

		$this->assertTrue( $result->passed );
		$this->assertSame( array(), $result->findings );
	}

	/**
	 * Tests that a binding a Polylang-style change left behind, with nothing in that request to hear it, is reported and repaired, and that a second pass is clean.
	 *
	 * @since 0.1.0
	 */
	public function test_a_binding_a_change_the_watcher_never_heard_left_behind_is_reported_and_repaired(): void {
		$owner  = $this->savedProduct( 'SKU-1', 'Shirt' );
		$german = $this->post();

		// The lifecycle reconciles the plain post to an incomplete product of its own, since it
		// has no group yet; the multilingual setup then puts it in the owner's group on its own,
		// as SeveralLocales::assign() does, without telling the watcher — the gap doctor's check
		// is the safety net for.
		$this->assertNotNull( $this->products->findByPost( $german ) );
		$this->assertNotSame( $owner->productId, $this->products->findByPost( $german )->id() );

		$this->locales->assign( $german, Locale::of( 'de_DE' ), $owner->postId );

		$check = $this->check();
		$first = $check->run();

		$this->assertFalse( $first->passed );
		$this->assertStringContainsString( (string) $german, implode( ' ', $first->findings ) );

		$repaired = $check->repair();

		$this->assertNotSame( array(), $repaired->changes );
		$this->assertSame( $owner->productId, $this->products->findByPost( $german )?->id(), 'The repair did not join the post to its group\'s product.' );
		$this->assertSame( 'de_DE', $this->products->findByPost( $german )->bindingOf( $german )?->locale()->toString() );

		$this->assertTrue( $this->check()->run()->passed, 'The second pass is not clean.' );
	}

	/**
	 * Tests that the repair never moves a post whose product holds commerce data onto another product, however often doctor runs: it stays reported, a conflict for a person.
	 *
	 * @since 0.1.0
	 */
	public function test_the_repair_never_moves_a_post_whose_product_holds_commerce_data(): void {
		$owner = $this->savedProduct( 'SKU-1', 'Shirt' );
		$other = $this->savedProduct( 'SKU-2', 'Trousers' );

		// The multilingual setup puts the second product's post in the first's group; both
		// products hold commerce data of their own, so the post must stay where it is.
		$this->locales->assign( $other->postId, Locale::of( 'de_DE' ), $owner->postId );

		$check = $this->check();
		$first = $check->run();

		$this->assertFalse( $first->passed );

		$repaired = $check->repair();

		$this->assertSame( $other->productId, $this->products->findByPost( $other->postId )?->id(), 'The post was moved off a product that holds commerce data.' );
		$this->assertNotSame( array(), $repaired->changes );
		$this->assertSame( array( ReportCode::TranslationConflict->value ), array_values( array_unique( array_column( $this->reports, 'code' ) ) ) );

		$this->assertFalse( $this->check()->run()->passed, 'The conflict is no longer reported.' );
	}

	/**
	 * Tests that a translation code took out of its group in Polylang, in a request that wrote no
	 * product post, is reported though it now sits alone in its own group, and that --repair
	 * unlinks it from the product it still presents.
	 *
	 * Reconciling the owner's own post, scanned first by id, unlinks the stray binding as its own
	 * stale-binding side effect before the translation's own turn comes up; the translation's own
	 * repair then correctly finds its binding already gone and skips it, rather than act on what
	 * the scan saw. Giving the now-unbound post a product of its own is doctor's unbound-post
	 * check's job, on a later pass: this check's own job is only to stop it presenting a product
	 * it left the group of, which it has done.
	 *
	 * @since 0.1.0
	 */
	public function test_a_lone_unlinked_translation_is_reported_and_repair_unlinks_it(): void {
		$owner  = $this->savedProduct( 'SKU-1', 'Shirt' );
		$german = $this->post();

		$this->link( $owner, $german, 'de_DE' );

		$this->assertSame( $owner->productId, $this->products->findByPost( $german )?->id() );

		// Code unlinks the post in Polylang: SeveralLocales::assign() with no post to join takes
		// it out of its group, exactly as pll_save_post_translations() does, without telling the
		// watcher. Nothing else changes: the post's own binding still presents $owner's product.
		$this->locales->assign( $german, Locale::of( 'de_DE' ), null );

		$check = $this->check();
		$first = $check->run();

		$this->assertFalse( $first->passed, 'A lone, unlinked translation must still be reported.' );
		$this->assertStringContainsString( (string) $german, implode( ' ', $first->findings ) );
		$this->assertStringContainsString( (string) $owner->postId, implode( ' ', $first->findings ), 'Reconciling the owner would also unlink the stray binding; that must be reported too, not left a silent side effect.' );

		$repaired = $check->repair();

		$this->assertNotSame( array(), $repaired->changes );
		$this->assertNull( $this->products->findByPost( $german ), 'The repair left the post bound to a product it no longer belongs to.' );

		$this->assertTrue( $this->check()->run()->passed, 'The second pass is not clean.' );
	}

	/**
	 * Tests that a translation the multilingual setup currently gives no language keeps its
	 * binding when the source post is reconciled: the check never reports the translation for
	 * this on its own, and reconciling the source, which would otherwise unlink any binding not
	 * in its group, leaves this one alone.
	 *
	 * @since 0.1.0
	 */
	public function test_a_translation_with_no_language_keeps_its_binding_when_the_source_is_reconciled(): void {
		$owner  = $this->savedProduct( 'SKU-1', 'Shirt' );
		$german = $this->post();

		$this->link( $owner, $german, 'de_DE' );

		// Polylang can leave a translation's post with no language, as it does when the language
		// itself is deleted; the post's own binding is untouched.
		$this->locales->forgetLanguage( $german );

		$this->assertNull( $this->services->groups->disagreement( $german ), 'A post with no language must never be reported on its own.' );
		$this->assertTrue( $this->check()->run()->passed, 'A post with no language must never be reported on its own.' );

		$this->db->transaction( fn (): bool => $this->services->groups->reconcile( $owner->postId ) );

		$this->assertSame( $owner->productId, $this->products->findByPost( $german )?->id(), 'Reconciling the source unlinked a translation the setup gives no language.' );
	}

	/**
	 * Tests that a translation moved into another product's group, off a product that holds
	 * commerce data, stays bound to that product as a reported conflict until the product's source
	 * post is reconciled, which unlinks it; the post's own next reconciliation, which its next write
	 * or doctor's unbound-post repair runs, then binds it to the product of the group it was moved
	 * into; and the product keeps every variant, price and unit it had, and is not deleted.
	 *
	 * Planted violation, confirmed to fail this test: skip the loop over the unlinks in
	 * TranslationGroups::apply(), and the post stays bound to the product whose group it left.
	 *
	 * @since 0.2.0
	 */
	public function test_a_translation_moved_off_a_product_with_commerce_data_is_unlinked_when_the_source_is_reconciled_then_joins_its_new_group(): void {
		$owner  = $this->productOfTwoVariants();
		$other  = $this->savedProduct( 'SKU-OTHER', 'Trousers' );
		$german = $this->post();

		$this->link( $owner, $german, 'de_DE' );

		$rows    = $this->commerceRows( $owner->productId );
		$deleted = $this->deletions();

		$this->assertCount( 2, $rows[ CatalogTables::VARIANTS ] );
		$this->assertCount( 2, $rows[ CatalogTables::VARIANT_PRICES ] );
		$this->assertCount( 2, $rows[ InventoryTables::ITEMS ] );

		// The merchant puts the German post in the other product's group, which the setup tells nobody.
		$this->locales->assign( $german, Locale::of( 'de_DE' ), $other->postId );
		$this->services->groups->reconcile( $german );

		$this->assertSame( $owner->productId, $this->products->findByPost( $german )?->id(), 'The post was moved off a product that holds commerce data.' );
		$this->assertSame( array( ReportCode::TranslationConflict->value ), array_values( array_unique( array_column( $this->reports, 'code' ) ) ) );

		$this->services->groups->reconcile( $owner->postId );

		$this->assertNull( $this->products->findByPost( $german ), 'Reconciling the source post left the post bound to the product whose group it left.' );
		$this->assertSame( $rows, $this->commerceRows( $owner->productId ), 'Unlinking the post changed a variant, a price or a unit.' );

		$this->services->groups->reconcile( $german );

		$this->assertSame( $other->productId, $this->products->findByPost( $german )?->id(), 'The post did not join the group it was moved into.' );
		$this->assertSame( 'de_DE', $this->products->findByPost( $german )->bindingOf( $german )?->locale()->toString() );
		$this->assertSame( $rows, $this->commerceRows( $owner->productId ), 'The product changed a variant, a price or a unit.' );
		$this->assertSame( $deleted, $this->deletions(), 'A product was deleted.' );
	}

	/**
	 * Tests that a post whose group's owner is another product is reported even when the post
	 * keeps exactly the locale the plugin currently gives it: the owner mismatch alone is enough,
	 * with no separate locale disagreement to also catch it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_owner_mismatch_is_reported_even_when_the_posts_own_locale_agrees(): void {
		$owner = $this->savedProduct( 'SKU-1', 'Shirt' );
		$other = $this->savedProduct( 'SKU-2', 'Trousers' );

		// Both products hold commerce data of their own, and $other's post keeps the locale it is
		// already stored in (en_US, the default): the setup only changes which group it is in.
		$this->locales->assign( $other->postId, Locale::of( 'en_US' ), $owner->postId );

		$this->assertSame( 'en_US', $this->products->findByPost( $other->postId )?->bindingOf( $other->postId )?->locale()->toString() );

		$reason = $this->services->groups->disagreement( $other->postId );

		$this->assertNotNull( $reason, 'An owner mismatch must be reported on its own, without a locale change to also catch it.' );
		$this->assertStringNotContainsString( 'locale', $reason, 'The reason must name the owner mismatch, not a locale disagreement that is not there.' );

		$this->assertFalse( $this->check()->run()->passed );
	}

	/**
	 * Tests that a bound post whose own language changes, its group's product unchanged, is
	 * reported and repaired to the new locale.
	 *
	 * @since 0.1.0
	 */
	public function test_a_posts_own_locale_change_alone_is_reported_and_repaired(): void {
		$owner = $this->savedProduct( 'SKU-1', 'Shirt' );

		// The multilingual setup gives the post's own, single-post product's source post another
		// locale, on its own: no group, no owner mismatch, only the stored locale is now stale.
		$this->locales->assign( $owner->postId, Locale::of( 'de_DE' ), null );

		$reason = $this->services->groups->disagreement( $owner->postId );

		$this->assertNotNull( $reason, 'A post\'s own locale change must be reported on its own.' );

		$check = $this->check();
		$first = $check->run();

		$this->assertFalse( $first->passed );

		$check->repair();

		$this->assertSame( 'de_DE', $this->products->findByPost( $owner->postId )?->bindingOf( $owner->postId )?->locale()->toString() );
		$this->assertTrue( $this->check()->run()->passed, 'The second pass is not clean.' );
	}

	/**
	 * Tests that the repair does nothing when a second connection moves the binding onto another
	 * product between the check and the repair: the concurrent write is never overwritten.
	 *
	 * @since 0.1.0
	 */
	public function test_repair_does_nothing_when_the_binding_moved_since_the_check(): void {
		$owner  = $this->savedProduct( 'SKU-1', 'Shirt' );
		$german = $this->post();

		$this->link( $owner, $german, 'de_DE' );
		$this->locales->assign( $german, Locale::of( 'de_DE' ), null );

		$check = $this->check();
		$first = $check->run();

		$this->assertFalse( $first->passed );

		// A second connection rebinds the post onto a third product between the check and the
		// repair, as a concurrent write would.
		$b     = $this->secondConnection();
		$other = $this->savedProduct( 'SKU-3', 'Hat' );

		$b->query(
			sprintf(
				'UPDATE `%s` SET product_id = %d WHERE post_id = %d',
				$this->catalogTable( CatalogTables::PRODUCT_POSTS ),
				$other->productId,
				$german
			)
		);

		$repaired = $check->repair();

		$this->assertStringContainsString( 'changed since the check', implode( ' ', $repaired->changes ) );
		$this->assertSame( $other->productId, $this->products->findByPost( $german )?->id(), 'The repair overwrote a concurrent change.' );
	}

	/**
	 * Tests that the repair does nothing, and creates nothing, when the binding it found is gone
	 * by the time it runs: reconcile() never takes its unbound branch for it.
	 *
	 * @since 0.1.0
	 */
	public function test_repair_does_nothing_when_the_binding_is_gone(): void {
		$owner  = $this->savedProduct( 'SKU-1', 'Shirt' );
		$german = $this->post();

		$this->link( $owner, $german, 'de_DE' );
		$this->locales->assign( $german, Locale::of( 'de_DE' ), null );

		$check = $this->check();
		$first = $check->run();

		$this->assertFalse( $first->passed );

		// A second connection deletes the binding between the check and the repair, as another
		// repair, or a foreign write, might.
		$b = $this->secondConnection();

		$b->query( sprintf( 'DELETE FROM `%s` WHERE post_id = %d', $this->catalogTable( CatalogTables::PRODUCT_POSTS ), $german ) );

		$repaired = $check->repair();

		$this->assertStringContainsString( 'changed since the check', implode( ' ', $repaired->changes ) );
		$this->assertNull( $this->products->findByPost( $german ), 'The repair bound the post though its binding was gone.' );
	}

	/**
	 * Puts a post in the product's source post's group, in a locale, as the multilingual setup would, and links it to the product.
	 *
	 * @since 0.1.0
	 *
	 * @param SaveResult $saved  The product.
	 * @param int        $postId The post.
	 * @param string     $locale The locale.
	 */
	private function link( SaveResult $saved, int $postId, string $locale ): void {
		$this->locales->assign( $postId, Locale::of( $locale ), $saved->postId );
		$this->services->bindings->link( $saved->productId, $postId, Locale::of( $locale ) );
	}

	/**
	 * Saves a published product of one size in two grinds, each variant priced in the base currency and stocked at the default location, through the REST server.
	 *
	 * @since 0.2.0
	 *
	 * @return SaveResult The save that created its post.
	 */
	private function productOfTwoVariants(): SaveResult {
		$saved    = $this->save(
			null,
			array(
				'post_title'  => 'Coffee',
				'post_status' => 'publish',
			)
		);
		$location = (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE id = %d', $this->db->table( InventoryTables::LOCATIONS ), $this->defaultLocation );
		$grinds   = array( 'whole', 'filter' );
		$response = $this->request(
			'PUT',
			'/' . $saved->postId,
			array(
				ProductCommerceSchema::PROPERTY => array(
					'options'  => VariantSets::options( array( '250g' ), $grinds ),
					'variants' => VariantSets::withStock( VariantSets::variants( 'COF', array( '250g' ), $grinds, array(), false ), array( $location ) ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $saved;
	}

	/**
	 * Takes every row of a product's options and variants whole: its axes and their values, its variants, the values they select, their prices and their stock items.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return array<string, list<array<string, mixed>>> The rows, by table.
	 */
	private function commerceRows( int $productId ): array {
		$axes     = $this->db->table( CatalogTables::OPTION_AXES );
		$variants = $this->db->table( CatalogTables::VARIANTS );

		return array(
			CatalogTables::OPTION_AXES           => $this->db->fetchAll( 'SELECT * FROM %i WHERE product_id = %d ORDER BY id', $axes, $productId ),
			CatalogTables::OPTION_VALUES         => $this->db->fetchAll( 'SELECT o.* FROM %i o JOIN %i a ON a.id = o.axis_id WHERE a.product_id = %d ORDER BY o.id', $this->db->table( CatalogTables::OPTION_VALUES ), $axes, $productId ),
			CatalogTables::VARIANTS              => $this->db->fetchAll( 'SELECT * FROM %i WHERE product_id = %d ORDER BY id', $variants, $productId ),
			CatalogTables::VARIANT_OPTION_VALUES => $this->db->fetchAll( 'SELECT s.* FROM %i s JOIN %i v ON v.id = s.variant_id WHERE v.product_id = %d ORDER BY s.variant_id, s.option_value_id', $this->db->table( CatalogTables::VARIANT_OPTION_VALUES ), $variants, $productId ),
			CatalogTables::VARIANT_PRICES        => $this->db->fetchAll( 'SELECT p.* FROM %i p JOIN %i v ON v.id = p.variant_id WHERE v.product_id = %d ORDER BY p.variant_id, p.currency', $this->db->table( CatalogTables::VARIANT_PRICES ), $variants, $productId ),
			InventoryTables::ITEMS               => $this->db->fetchAll( 'SELECT i.* FROM %i i JOIN %i v ON v.id = i.variant_id WHERE v.product_id = %d ORDER BY i.variant_id, i.location_id', $this->db->table( InventoryTables::ITEMS ), $variants, $productId ),
		);
	}

	/**
	 * Counts the ProductDeleted events published so far.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	private function deletions(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE event_name = %s', $this->db->table( OutboxTable::NAME ), ProductDeleted::eventName() );
	}

	/**
	 * Builds the check as CatalogChecks builds it, over the test's services.
	 *
	 * @since 0.1.0
	 *
	 * @return TranslationGroupCheck The check.
	 */
	private function check(): TranslationGroupCheck {
		return new TranslationGroupCheck(
			$this->services->products,
			$this->services->groups,
			$this->db,
			array( new LockService( $this->db, LockMode::GetLock ), 'withLock' )
		);
	}
}
