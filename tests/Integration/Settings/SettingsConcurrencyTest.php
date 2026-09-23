<?php
/**
 * Tests the settings store against a concurrent writer on a second connection
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Settings;

use SEOCart\Platform\Authorization\OptionGrantLedger;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SettingsFixtures;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The test plays another request on its own connection, with the store's own statements, and cleans the options table by hand.

/**
 * Two writers, two real connections, committed data, and interleavings the test controls.
 *
 * Connection A is the store over wpdb. Connection B stands for another request running the same
 * code: it sends the store's own statements, prepared from the store's own constants, so a change
 * to the store's statement changes B's too. The interleavings are set by barriers, never by
 * pauses: B is shown blocked by the server's process list (awaitWaiting()), or B's statement is
 * sent from WordPress's `query` filter at the moment A's statement is about to leave.
 *
 * The object cache stands in for a persistent one: a value this process cached earlier is what a
 * persistent cache would still hold for the next request.
 *
 * The harness transaction is off, so every test removes the options it wrote.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class SettingsConcurrencyTest extends DatabaseTestCase {

	/**
	 * The store over the fixture settings, on connection A.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * How many of the ledger's writes B has beaten, in the tests where B wins every race.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $racesLost = 0;

	/**
	 * Builds the store and makes sure no option of an earlier, broken run is left.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::removeOptions();

		$this->store = new SettingsStore( SettingsFixtures::registry(), $this->db );
	}

	/**
	 * Removes every option the test wrote.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb;

		// Whatever a failed test left open ends before the cleanup, so the cleanup is committed.
		$wpdb->query( 'ROLLBACK' );

		self::removeOptions();

		parent::tear_down();
	}

	/**
	 * Tests that two writers of one document version leave exactly one winner, and the loser changes nothing.
	 *
	 * A replaces version 1 inside a transaction and holds the row. B, which read version 1 too,
	 * sends the store's conditional statement and is shown blocked on the row by the server. A
	 * commits; B's statement then finds the row no longer holds what B read, and changes nothing.
	 *
	 * Planted violation: in SettingsStore::COMPARE_AND_SWAP, replace
	 * `AND option_value = CAST( %s AS BINARY )` with `AND LENGTH( %s ) > 0`, which keeps the
	 * placeholders and drops the condition. B then overwrites A's version.
	 *
	 * @since 0.1.0
	 */
	public function test_two_writers_of_one_version_leave_exactly_one_winner(): void {
		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'account' => 'seed' ) );

		$b        = $this->secondConnection();
		$read     = self::committedDocument( $b );
		$b_writes = self::compareAndSwap( $read, self::documentJson( 2, array( 'account' => 'from_b' ) ) );

		$this->db->transaction(
			function () use ( $b, $b_writes ): void {
				$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 1, array( 'account' => 'from_a' ) );

				$b->queryAsync( $b_writes );
				$this->awaitWaiting( $b, $b_writes, 'updating' );
			}
		);

		$this->assertSame( 0, $b->reap(), 'B changed the row after A had replaced the version B read.' );
		$this->assertSame( self::documentJson( 2, array( 'account' => 'from_a' ) ), self::committedDocument( $b ), 'The winner\'s document is not what is committed.' );
	}

	/**
	 * Tests that the store, when it loses, reports the conflict, changes nothing, and then reads the winner's value.
	 *
	 * B commits its replacement of version 1 at the moment A's conditional statement is about to
	 * leave: after A has read the row and found version 1, so only the statement itself can stop A.
	 * Before A's write, A's object cache is made to hold version 1, as a persistent cache would.
	 *
	 * Planted violations: the one above (A then overwrites B and nothing is reported); and, in
	 * SettingsStore::conflict(), removing `$this->forget( $option );` — the next read is then served
	 * version 1 from the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_the_losing_store_reports_the_conflict_and_then_reads_the_winner(): void {
		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'account' => 'seed' ) );

		$b        = $this->secondConnection();
		$read     = self::committedDocument( $b );
		$winner   = self::documentJson( 2, array( 'account' => 'from_b' ) );
		$b_writes = self::compareAndSwap( $read, $winner );
		$sent     = self::sendBefore( '/^UPDATE /', $b, $b_writes );

		$this->assertSame( 1, $this->store->document( SettingsFixtures::DOCUMENT )->version(), 'A did not read version 1.' );
		$this->assertSame( $read, wp_cache_get( 'seocart_fixture_document', 'options' ), 'The cache does not hold version 1, so the test could not see a stale read.' );

		try {
			$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 1, array( 'account' => 'from_a' ) );
			$this->fail( 'A replaced the document B had already replaced.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( SettingsError::VersionConflict, $lost->errorCode() );
			$this->assertSame( array( 'group' => SettingsFixtures::DOCUMENT ), $lost->context() );
		}

		$this->assertTrue( $sent->fired, 'B\'s statement was never sent, so nothing raced A.' );
		$this->assertSame( $winner, self::committedDocument( $b ), 'A changed the row although it lost.' );
		$this->assertSame( array( 'account' => 'from_b' ), $this->store->document( SettingsFixtures::DOCUMENT )->values(), 'The next read was not the winner\'s value.' );
		$this->assertSame( $winner, get_option( 'seocart_fixture_document' ), 'WordPress\'s own read was served the replaced value.' );
	}

	/**
	 * Tests that a write inside a transaction leaves no cached copy of the value it replaced, even one cached while it was open.
	 *
	 * Planted violation: in SettingsStore::settle(), remove the afterCommit() callback. The value
	 * another request cached while the transaction was open is then served after the commit.
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_cached_while_the_write_was_open_is_not_served_after_the_commit(): void {
		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'account' => 'old' ) );

		$old = (string) get_option( 'seocart_fixture_document' );

		$this->db->transaction(
			function () use ( $old ): void {
				$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 1, array( 'account' => 'new' ) );

				// Another request read the committed row, still the old one, and cached it.
				wp_cache_set( 'seocart_fixture_document', $old, 'options' );
			}
		);

		$this->assertSame( array( 'account' => 'new' ), $this->store->document( SettingsFixtures::DOCUMENT )->values() );
	}

	/**
	 * Tests that a document created after its absence was cached is read, not reported absent.
	 *
	 * Planted violation: in SettingsStore::CACHED_LISTS, remove `'notoptions'`. The next read is
	 * then told the option does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_a_created_document_is_not_served_as_absent(): void {
		$this->assertFalse( get_option( 'seocart_fixture_document' ) );
		$this->assertArrayHasKey( 'seocart_fixture_document', (array) wp_cache_get( 'notoptions', 'options' ), 'The absence was not cached, so the test proves nothing.' );

		$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'mode' => 'live' ) );

		$this->assertSame( self::documentJson( 1, array( 'mode' => 'live' ) ), get_option( 'seocart_fixture_document' ) );
	}

	/**
	 * Tests that two creators of one document leave one winner: the second gets the conflict.
	 *
	 * @since 0.1.0
	 */
	public function test_two_creators_of_one_document_leave_one_winner(): void {
		global $wpdb;

		$b      = $this->secondConnection();
		$winner = self::documentJson( 1, array( 'account' => 'from_b' ) );
		$sent   = self::sendBefore( '/^INSERT /', $b, (string) $wpdb->prepare( SettingsStore::CREATE_DOCUMENT, $wpdb->options, 'seocart_fixture_document', $winner ) );

		try {
			$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'account' => 'from_a' ) );
			$this->fail( 'A created a document B had already created.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( SettingsError::VersionConflict, $lost->errorCode() );
		}

		$this->assertTrue( $sent->fired );
		$this->assertSame( $winner, self::committedDocument( $b ) );
	}

	/**
	 * Tests that writers of two independent settings neither wait for nor overwrite each other.
	 *
	 * A writes one setting inside a transaction and holds its row. B writes the other and must be
	 * answered while A's transaction is still open. Both values are committed.
	 *
	 * Planted violation: in Setting::optionName(), return `self::OPTION_PREFIX . $this->group` for a
	 * scalar too. The two settings then share one row: B waits for A, and the test fails when the
	 * deadline passes.
	 *
	 * @since 0.1.0
	 */
	public function test_writers_of_two_independent_settings_never_meet(): void {
		global $wpdb;

		$this->store->writeScalars(
			array(
				'hold_minutes' => 15,
				'weight_unit'  => 'kg',
			)
		);

		$weight   = SettingsFixtures::registry()->setting( 'weight_unit' )->optionName();
		$b        = $this->secondConnection();
		$b_writes = (string) $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, 'lb', $weight );

		$this->db->transaction(
			function () use ( $b, $b_writes ): void {
				$this->store->writeScalars( array( 'hold_minutes' => 45 ) );

				$b->queryAsync( $b_writes );

				$this->assertTrue( $b->isReady( 2000 ), 'B waited for A: the two settings share a row.' );
				$this->assertSame( 1, $b->reap() );
			}
		);

		wp_cache_flush();

		$this->assertSame( 45, $this->store->value( 'hold_minutes' ) );
		$this->assertSame( 'lb', $this->store->value( 'weight_unit' ) );
	}

	/**
	 * Tests that two installer runs recording at once both keep their pairs: the loser retries on the new version.
	 *
	 * Planted violation: in OptionGrantLedger, set ATTEMPTS to 1. The run that loses the race then
	 * fails with the conflict instead of merging.
	 *
	 * @since 0.1.0
	 */
	public function test_concurrent_grant_records_are_merged_not_lost(): void {
		global $wpdb;

		$store  = new SettingsStore( Settings::registry(), $this->db );
		$ledger = new OptionGrantLedger( $store, $this->db );

		$ledger->record( array( 'seocart_manager' => array( 'seocart_view_orders' ) ) );

		$b        = $this->secondConnection();
		$read     = (string) $b->fetchValue( sprintf( "SELECT option_value FROM `%s` WHERE option_name = 'seocart_capability_grants'", $wpdb->options ) );
		$b_record = (string) wp_json_encode(
			array(
				'version' => 2,
				'values'  => array(
					'seocart_manager'  => 'seocart_view_orders',
					'seocart_reporter' => 'seocart_view_reports',
				),
			)
		);
		$sent     = self::sendBefore( '/^UPDATE /', $b, (string) $wpdb->prepare( SettingsStore::COMPARE_AND_SWAP, $wpdb->options, $b_record, 'seocart_capability_grants', $read ) );

		$ledger->record( array( 'seocart_manager' => array( 'seocart_edit_orders' ) ) );

		$this->assertTrue( $sent->fired, 'B\'s record was never sent, so nothing raced the ledger.' );
		$this->assertSame(
			array(
				'seocart_manager'  => array( 'seocart_edit_orders', 'seocart_view_orders' ),
				'seocart_reporter' => array( 'seocart_view_reports' ),
			),
			( new OptionGrantLedger( new SettingsStore( Settings::registry(), $this->db ), $this->db ) )->granted()
		);
		$this->assertSame( 3, $store->document( OptionGrantLedger::GROUP )->version(), 'The retry did not write the version after B\'s.' );
	}

	/**
	 * Tests that when every attempt loses, the run succeeds if the winner has recorded its pairs.
	 *
	 * B writes a new version of the record just before each of the ledger's writes. The first two
	 * lack the ledger's pair; the third holds it, as another installer run computing the same pairs
	 * would. The ledger loses all three races and then finds its pair recorded.
	 *
	 * Planted violation: in OptionGrantLedger::record(), after the last attempt, throw at once
	 * instead of reading the record again. The run then fails although its pair is recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_losing_every_attempt_succeeds_when_the_winner_recorded_the_pairs(): void {
		$ledger = $this->ledgerLosingEveryRace( 'seocart_edit_orders seocart_view_orders' );

		$ledger->record( array( 'seocart_manager' => array( 'seocart_edit_orders' ) ) );

		$this->assertSame( 3, $this->racesLost, 'B did not win every attempt, so the last-attempt read was not reached.' );
		$this->assertSame( array( 'seocart_edit_orders', 'seocart_view_orders' ), $ledger->granted()['seocart_manager'] );
	}

	/**
	 * Tests that when every attempt loses and the winner did not record the pairs, the conflict reaches the caller.
	 *
	 * Planted violation: in OptionGrantLedger::holds(), return `true`. The run then returns as if
	 * its pair were recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_losing_every_attempt_raises_the_conflict_when_the_pairs_are_missing(): void {
		$ledger = $this->ledgerLosingEveryRace( 'seocart_view_orders' );

		try {
			$ledger->record( array( 'seocart_manager' => array( 'seocart_edit_orders' ) ) );
			$this->fail( 'The ledger reported success, but its pair is not recorded.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( SettingsError::VersionConflict, $lost->errorCode() );
		}

		$this->assertSame( 3, $this->racesLost, 'The ledger did not try three times.' );
		$this->assertNotContains( 'seocart_edit_orders', $ledger->granted()['seocart_manager'] );
	}

	/**
	 * Tests that the check after the last lost race follows the row in the database, not what the options API answers.
	 *
	 * The row B committed last lacks the ledger's pair. From the moment B wins the third race, the
	 * options API answers for the record with a stale document that holds the pair, as a cache
	 * that missed an invalidation would. The test cannot reach the object cache between the
	 * store dropping it and the ledger's check, so the stale answer comes from the
	 * `pre_option_seocart_capability_grants` filter, where get_option() answers before it looks at
	 * the cache or the table. The ledger must still report the conflict.
	 *
	 * Planted violation: in OptionGrantLedger::record(), check `$this->granted()` after the last
	 * attempt instead of the document as stored. The run then trusts the stale answer and returns.
	 *
	 * @since 0.1.0
	 */
	public function test_the_last_check_follows_the_row_in_the_database(): void {
		$ledger = $this->ledgerLosingEveryRace( 'seocart_view_orders', 'seocart_edit_orders seocart_view_orders' );

		try {
			$ledger->record( array( 'seocart_manager' => array( 'seocart_edit_orders' ) ) );
			$this->fail( 'The ledger trusted a stale answer that its pair is recorded.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( SettingsError::VersionConflict, $lost->errorCode() );
		}

		$this->assertSame( 3, $this->racesLost, 'The ledger did not try three times.' );
		$this->assertContains( 'seocart_edit_orders', explode( ' ', (string) json_decode( (string) get_option( 'seocart_capability_grants' ), true )['values']['seocart_manager'] ), 'The stale answer was not in place, so the test proves nothing.' );
	}

	/**
	 * Tests that recording inside a transaction is refused with the database layer's refusal, and writes nothing.
	 *
	 * At REPEATABLE READ a retry inside a transaction reads the transaction's snapshot again and
	 * can never succeed, so the ledger does not start.
	 *
	 * Planted violation: in OptionGrantLedger::record(), remove the depth check. The record is then
	 * written inside the transaction.
	 *
	 * @since 0.1.0
	 */
	public function test_recording_inside_a_transaction_is_refused(): void {
		$ledger = new OptionGrantLedger( new SettingsStore( Settings::registry(), $this->db ), $this->db );

		try {
			$this->db->transaction(
				static function () use ( $ledger ): void {
					$ledger->record( array( 'seocart_manager' => array( 'seocart_view_orders' ) ) );
				}
			);
			$this->fail( 'The ledger recorded inside a transaction.' );
		} catch ( ForbiddenInsideTransaction $refused ) {
			$this->assertSame( OptionGrantLedger::FORBIDDEN_KIND, $refused->kind() );
		}

		$this->assertSame( array(), $ledger->granted(), 'The refused record wrote something.' );
	}

	/**
	 * Tests that a value written inside a transaction is read back by the store, but never cached before the commit.
	 *
	 * With a persistent object cache, a cached uncommitted row would be served to other requests,
	 * although it may never commit. After the commit, reads cache as usual.
	 *
	 * Planted violation: in SettingsStore::storedValues(), always take the priming path. The read
	 * then caches the uncommitted row.
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_written_inside_a_transaction_is_not_cached_before_the_commit(): void {
		$options = array( 'seocart_fixture_scalars_weight_unit', 'seocart_fixture_document' );

		$this->db->transaction(
			function () use ( $options ): void {
				$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );
				$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'mode' => 'live' ) );

				$this->assertSame( 'lb', $this->store->value( 'weight_unit' ), 'The store does not read its own write.' );
				$this->assertSame( 'live', $this->store->value( 'mode' ) );
				$this->assertSame( 1, $this->store->document( SettingsFixtures::DOCUMENT )->version() );

				foreach ( $options as $option ) {
					$this->assertFalse( wp_cache_get( $option, 'options' ), "{$option} was cached before its transaction committed." );

					foreach ( array( 'alloptions', 'notoptions' ) as $list ) {
						$this->assertArrayNotHasKey( $option, (array) wp_cache_get( $list, 'options' ), "The {$list} list names {$option} before its transaction committed." );
					}
				}
			}
		);

		$this->assertSame( 'lb', $this->store->value( 'weight_unit' ) );
		$this->assertSame( 1, $this->store->document( SettingsFixtures::DOCUMENT )->version() );
		$this->assertSame( 'lb', wp_cache_get( 'seocart_fixture_scalars_weight_unit', 'options' ), 'After the commit, a read does not cache as usual.' );
		$this->assertNotFalse( wp_cache_get( 'seocart_fixture_document', 'options' ), 'After the commit, a read does not cache as usual.' );
	}

	/**
	 * Tests that while writes are pending, a read of any setting leaves the lists core keeps uncached.
	 *
	 * Priming and get_option() load `alloptions`; on a site with no autoloaded option at all, core's
	 * fallback caches every option there, uncommitted ones included. So with a write pending, the
	 * store reads every option from the database, and the lists stay as they were: deleted here.
	 *
	 * Planted violation: in SettingsStore::storedValues(), take the priming path even with writes
	 * pending. The read of the other settings then loads `alloptions`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_with_writes_pending_caches_no_option_list(): void {
		$this->db->transaction(
			function (): void {
				$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );

				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );

				$values = $this->store->values( SettingsFixtures::registry()->all() );

				$this->assertSame( 'lb', $values['weight_unit'] );
				$this->assertSame( 15, $values['hold_minutes'] );
				$this->assertSame( 0, $this->store->document( SettingsFixtures::DOCUMENT )->version() );
				$this->assertFalse( wp_cache_get( 'alloptions', 'options' ), 'A read with a write pending loaded the autoloaded options, a list that can hold the uncommitted row.' );
				$this->assertFalse( wp_cache_get( 'notoptions', 'options' ), 'A read with a write pending cached the absence list.' );
			}
		);
	}

	/**
	 * Tests that once a transaction has ended, the next one reads what it wrote through the cache again.
	 *
	 * The list of pending writes is emptied when the outermost transaction commits or rolls back,
	 * not when the store happens to read outside a transaction.
	 *
	 * Planted violation: in SettingsStore::forgetPendingWhenTheTransactionEnds(), do not empty the
	 * list. The next transaction then still reads the committed option past the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_the_next_transaction_caches_what_the_last_one_wrote(): void {
		$this->db->transaction(
			function (): void {
				$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );
			}
		);

		try {
			$this->db->transaction(
				function (): void {
					$this->store->writeScalars( array( 'hold_minutes' => 30 ) );

					throw new \RuntimeException( 'roll back' );
				}
			);
		} catch ( \RuntimeException $expected ) {
			$this->assertSame( 'roll back', $expected->getMessage() );
		}

		$this->db->transaction(
			function (): void {
				$this->assertSame( 'lb', $this->store->value( 'weight_unit' ) );
				$this->assertSame( 15, $this->store->value( 'hold_minutes' ) );
				$this->assertSame( 'lb', wp_cache_get( 'seocart_fixture_scalars_weight_unit', 'options' ), 'The option a committed transaction wrote is still read past the cache.' );
				$this->assertArrayHasKey( 'seocart_fixture_scalars_hold_minutes', (array) wp_cache_get( 'notoptions', 'options' ), 'The option a rolled-back transaction wrote is still read past the cache.' );
			}
		);
	}

	/**
	 * Tests that a nested rollback leaves the outer transaction's writes pending, and that they end with it.
	 *
	 * The nested level writes first, so the callback that empties the list sits on it. When it
	 * rolls back, the outer level is still open: the entries stay, and the callback moves to the
	 * outer level, so the outer commit still empties the list.
	 *
	 * Planted violation: in SettingsStore::forgetPendingWhenTheTransactionEnds(), after a nested
	 * rollback, keep the entries but do not register the callback again. Nothing then empties the
	 * list, and the next transaction reads past the cache.
	 *
	 * @since 0.1.0
	 */
	public function test_a_nested_rollback_leaves_the_outer_writes_pending_until_the_outer_commit(): void {
		$this->db->transaction(
			function (): void {
				try {
					$this->db->transaction(
						function (): void {
							$this->store->writeScalars( array( 'hold_minutes' => 30 ) );

							throw new \RuntimeException( 'roll back the nested level' );
						}
					);
				} catch ( \RuntimeException $expected ) {
					$this->assertSame( 'roll back the nested level', $expected->getMessage() );
				}

				$this->store->writeScalars( array( 'weight_unit' => 'lb' ) );

				$this->assertSame( 'lb', $this->store->value( 'weight_unit' ) );
				$this->assertFalse( wp_cache_get( 'seocart_fixture_scalars_weight_unit', 'options' ), 'The outer level\'s uncommitted write was cached.' );
			}
		);

		$this->db->transaction(
			function (): void {
				$this->assertSame( 'lb', $this->store->value( 'weight_unit' ) );
				$this->assertSame( 'lb', wp_cache_get( 'seocart_fixture_scalars_weight_unit', 'options' ), 'After the outer commit, the next transaction still reads past the cache.' );
			}
		);
	}

	/**
	 * Tests that a write inside a transaction that rolls back leaves no option list cached from inside it.
	 *
	 * Planted violation: in SettingsStore::settle(), touch only the option's own key, not the lists.
	 * The absence list cached while the transaction was open is then still served after the rollback.
	 *
	 * @since 0.1.0
	 */
	public function test_the_option_lists_are_dropped_again_after_a_rollback(): void {
		try {
			$this->db->transaction(
				function (): void {
					$this->store->replaceDocument( SettingsFixtures::DOCUMENT, 0, array( 'mode' => 'live' ) );

					// A read inside the transaction caches the absence list again.
					get_option( 'seocart_fixture_never_written' );
					$this->assertIsArray( wp_cache_get( 'notoptions', 'options' ), 'The absence list was not cached inside the transaction, so the test proves nothing.' );

					throw new \RuntimeException( 'roll back' );
				}
			);
		} catch ( \RuntimeException $expected ) {
			$this->assertSame( 'roll back', $expected->getMessage() );
		}

		$this->assertFalse( wp_cache_get( 'notoptions', 'options' ), 'An option list cached inside the rolled-back transaction is still cached.' );
		$this->assertFalse( get_option( 'seocart_fixture_document' ), 'The rolled-back document is read.' );
	}

	/**
	 * Returns a ledger whose record B replaces just before each of the ledger's writes.
	 *
	 * The record starts with `seocart_manager` holding `seocart_view_orders`. Each of B's
	 * versions adds a reporter capability; B's third gives the manager the list given here.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $third_manager The manager's list in B's third version.
	 * @param string|null $stale_manager Optional. From B's third version on, the options API answers
	 *                                   for the record with a stale document giving the manager this
	 *                                   list. Default none.
	 * @return OptionGrantLedger The ledger, on connection A.
	 */
	private function ledgerLosingEveryRace( string $third_manager, ?string $stale_manager = null ): OptionGrantLedger {
		global $wpdb;

		$ledger = new OptionGrantLedger( new SettingsStore( Settings::registry(), $this->db ), $this->db );

		$ledger->record( array( 'seocart_manager' => array( 'seocart_view_orders' ) ) );

		$b        = $this->secondConnection();
		$managers = array( 'seocart_view_orders', 'seocart_view_orders', $third_manager );

		$this->racesLost = 0;

		add_filter(
			'query',
			function ( string $query ) use ( $b, $managers, $stale_manager, $wpdb ): string {
				if ( 1 !== preg_match( "/^UPDATE .*option_name = 'seocart_capability_grants'/s", $query ) || $this->racesLost >= count( $managers ) ) {
					return $query;
				}

				$read    = (string) $b->fetchValue( sprintf( "SELECT option_value FROM `%s` WHERE option_name = 'seocart_capability_grants'", $wpdb->options ) );
				$version = (int) json_decode( $read, true )['version'];
				$record  = (string) wp_json_encode(
					array(
						'version' => $version + 1,
						'values'  => array(
							'seocart_manager'  => $managers[ $this->racesLost ],
							'seocart_reporter' => 'seocart_view_reports',
						),
					)
				);

				++$this->racesLost;

				$b->query( (string) $wpdb->prepare( SettingsStore::COMPARE_AND_SWAP, $wpdb->options, $record, 'seocart_capability_grants', $read ) );

				if ( null !== $stale_manager && count( $managers ) === $this->racesLost ) {
					$stale = (string) wp_json_encode(
						array(
							'version' => $version + 1,
							'values'  => array( 'seocart_manager' => $stale_manager ),
						)
					);

					add_filter( 'pre_option_seocart_capability_grants', static fn(): string => $stale );
				}

				return $query;
			}
		);

		return $ledger;
	}

	/**
	 * Returns the fixture document as connection B sees it: what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return string The row's value.
	 */
	private static function committedDocument( SecondConnection $b ): string {
		global $wpdb;

		return (string) $b->fetchValue( sprintf( "SELECT option_value FROM `%s` WHERE option_name = 'seocart_fixture_document'", $wpdb->options ) );
	}

	/**
	 * Returns the store's conditional statement replacing the fixture document, as B sends it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $read    The value B read.
	 * @param string $written The value B writes.
	 * @return string The statement.
	 */
	private static function compareAndSwap( string $read, string $written ): string {
		global $wpdb;

		return (string) $wpdb->prepare( SettingsStore::COMPARE_AND_SWAP, $wpdb->options, $written, 'seocart_fixture_document', $read );
	}

	/**
	 * Returns a fixture document as the store encodes it.
	 *
	 * @since 0.1.0
	 *
	 * @param int                       $version The version.
	 * @param array<string, int|string> $values  The values, in declaration order.
	 * @return string The JSON.
	 */
	private static function documentJson( int $version, array $values ): string {
		return (string) wp_json_encode(
			array(
				'version' => $version,
				'values'  => (object) $values,
			)
		);
	}

	/**
	 * Has B send and commit a statement just before connection A sends its first statement of a kind.
	 *
	 * @since 0.1.0
	 *
	 * @param string           $pattern The kind of A's statement, as a regular expression.
	 * @param SecondConnection $b       Connection B.
	 * @param string           $sql     B's statement.
	 * @return object{fired: bool} Tells whether B's statement was sent.
	 */
	private static function sendBefore( string $pattern, SecondConnection $b, string $sql ): object {
		$sent = new class() {

			/**
			 * Whether B's statement was sent.
			 *
			 * @var bool
			 */
			public bool $fired = false;
		};

		add_filter(
			'query',
			static function ( string $query ) use ( $pattern, $b, $sql, $sent ): string {
				if ( ! $sent->fired && 1 === preg_match( $pattern, $query ) ) {
					$sent->fired = true;

					$b->query( $sql );
				}

				return $query;
			}
		);

		return $sent;
	}

	/**
	 * Removes every option the fixtures and the grant record can leave behind, and their cached copies.
	 *
	 * @since 0.1.0
	 */
	private static function removeOptions(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name = %s', $wpdb->options, $wpdb->esc_like( SettingsFixtures::OPTION_PREFIX ) . '%', 'seocart_capability_grants' ) );

		wp_cache_flush();
	}
}
