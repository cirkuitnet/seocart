<?php
/**
 * Tests the boot option: its reads, its compare-and-swap writes and its budget
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Tests\Support\KernelTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant and inspect the stored row directly, as another request would see it.

/**
 * The one autoloaded option: read lazily, written only by a conditional statement, and small.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In BootOption::write(), drop `AND option_value LIKE %s` and its argument from the UPDATE:
 *   test_a_rival_write_between_read_and_write_makes_the_change_apply_again loses the rival's
 *   change and fails.
 * - In BootRecord, raise MAX_KILL_SWITCHES to 128: test_the_record_cannot_outgrow_its_budget
 *   accepts a map of 9 KB and fails.
 * - In Lifecycle::installSite(), add `add_option( 'seocart_planted', 'x', '', true );`:
 *   test_an_installed_site_autoloads_exactly_one_small_plugin_option counts two and fails.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class BootOptionTest extends KernelTestCase {

	/**
	 * Tests that a record written once reads back the same, from a fresh reader, with its revision first.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_round_trips_and_starts_with_its_revision(): void {
		$written = $this->plantRecord( self::installedRecord( '20260922_0001_platform_bootstrap' )->withLockMode( LockMode::GetLock ) );

		$this->assertSame( 1, $written->rev() );
		$this->assertStringStartsWith( '{"v":1,"rev":1,', (string) $this->storedRecord() );

		wp_cache_flush();

		$read = ( new BootOption( $this->db, $this->reporter() ) )->read();

		$this->assertTrue( $read->sameAs( $written ) );
		$this->assertSame( 1, $read->rev() );
		$this->assertSame( LockMode::GetLock, $read->lockMode() );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a site without a record reads as absent, and reports nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_record_reads_as_absent_and_reports_nothing(): void {
		$this->assertTrue( ( new BootOption( $this->db, $this->reporter() ) )->read()->isAbsent() );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a corrupt record reads as absent, is reported once, and is replaced by the next write.
	 *
	 * @since 0.1.0
	 */
	public function test_a_corrupt_record_is_reported_once_and_replaced_by_the_next_write(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => BootOption::NAME,
				'option_value' => '{"v":1,"rev":"seven"}',
				'autoload'     => 'on',
			)
		);
		wp_cache_flush();

		$option = new BootOption( $this->db, $this->reporter() );

		$this->assertTrue( $option->read()->isAbsent() );
		$this->assertTrue( $option->read()->isAbsent() );
		$this->assertSame( array( BootOption::CORRUPT ), array_column( $this->reports, 'code' ), 'A corrupt record is reported once per request.' );

		$written = $option->mutate( static fn(): BootRecord => self::installedRecord() );

		$this->assertSame( 1, $written->rev() );
		$this->assertStringStartsWith( '{"v":1,"rev":1,', (string) $this->storedRecord() );
	}

	/**
	 * Tests that a value WordPress unserializes into something else is corrupt too, and is replaced.
	 *
	 * @since 0.1.0
	 */
	public function test_a_serialized_value_is_corrupt_and_is_replaced(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => BootOption::NAME,
				'option_value' => 'a:1:{s:1:"v";i:1;}',
				'autoload'     => 'on',
			)
		);
		wp_cache_flush();

		$option = new BootOption( $this->db, $this->reporter() );

		$this->assertTrue( $option->read()->isAbsent() );

		$option->mutate( static fn(): BootRecord => self::installedRecord() );

		$this->assertStringStartsWith( '{"v":1,"rev":1,', (string) $this->storedRecord() );
	}

	/**
	 * Tests the compare-and-swap: another writer commits between this writer's read and its write, so
	 * this writer's statement changes nothing, it reads the record again and applies its change to that.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rival_write_between_read_and_write_makes_the_change_apply_again(): void {
		global $wpdb;

		$this->plantRecord( self::installedRecord() );

		$option = new BootOption( $this->db, $this->reporter() );
		$rival  = $this->secondConnection();
		$calls  = 0;

		$result = $option->mutate(
			static function ( BootRecord $record ) use ( &$calls, $rival, $wpdb ): BootRecord {
				++$calls;

				if ( 1 === $calls ) {
					// Another request records a lock mode and commits, between this writer's read and its write.
					$theirs = $record->withLockMode( LockMode::Table )->withRev( $record->rev() + 1 )->toJson();

					$rival->query(
						sprintf(
							"UPDATE `%s` SET option_value = '%s' WHERE option_name = '%s'",
							$wpdb->options,
							esc_sql( $theirs ),
							BootOption::NAME
						)
					);
				}

				return $record->withSchemaHead( '20260922_0001_platform_bootstrap' );
			}
		);

		$this->assertSame( 2, $calls, 'The losing writer applies its change once more, to the record the winner wrote.' );
		$this->assertSame( 3, $result->rev() );

		wp_cache_flush();

		$stored = BootRecord::fromJson( $this->storedRecord() );

		$this->assertSame( LockMode::Table, $stored->lockMode(), 'The rival\'s change was lost.' );
		$this->assertSame( '20260922_0001_platform_bootstrap', $stored->schemaHead(), 'This writer\'s change was lost.' );
		$this->assertSame( 3, $stored->rev() );
	}

	/**
	 * Tests that two writers that both read no record cannot both create it: the second applies its change to the first's.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rival_creation_between_read_and_write_makes_the_change_apply_again(): void {
		global $wpdb;

		$option = new BootOption( $this->db, $this->reporter() );
		$rival  = $this->secondConnection();
		$calls  = 0;

		$result = $option->mutate(
			static function ( BootRecord $record ) use ( &$calls, $rival, $wpdb ): BootRecord {
				++$calls;

				if ( 1 === $calls ) {
					$theirs = self::installedRecord()->withLockMode( LockMode::Table )->withRev( 1 )->toJson();

					$rival->query(
						sprintf(
							"INSERT INTO `%s` ( option_name, option_value, autoload ) VALUES ( '%s', '%s', 'on' )",
							$wpdb->options,
							BootOption::NAME,
							esc_sql( $theirs )
						)
					);
				}

				return ( $record->isAbsent() ? self::installedRecord() : $record )->withSchemaHead( '20260922_0001_platform_bootstrap' );
			}
		);

		$this->assertSame( 2, $calls );
		$this->assertSame( 2, $result->rev() );

		$stored = BootRecord::fromJson( $this->storedRecord() );

		$this->assertSame( LockMode::Table, $stored->lockMode(), 'The first creator\'s record was overwritten.' );
		$this->assertSame( '20260922_0001_platform_bootstrap', $stored->schemaHead() );
	}

	/**
	 * Tests the conditional statement under a row lock: a rival that read the old revision waits for
	 * this writer's open transaction, and once it commits the rival's statement matches nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rival_blocked_behind_a_write_changes_nothing_once_it_commits(): void {
		global $wpdb;

		$read   = $this->plantRecord( self::installedRecord() );
		$option = new BootOption( $this->db, $this->reporter() );
		$rival  = $this->secondConnection();
		$sql    = sprintf(
			"UPDATE `%s` SET option_value = '%s' WHERE option_name = '%s' AND option_value LIKE '%s'",
			$wpdb->options,
			esc_sql( $read->withLockMode( LockMode::Table )->withRev( 2 )->toJson() ),
			BootOption::NAME,
			esc_sql( BootOption::revisionPattern( $read->rev() ) )
		);

		$this->db->transaction(
			function () use ( $option, $rival, $sql ): void {
				$option->mutate( static fn( BootRecord $record ): BootRecord => $record->withSchemaHead( '20260922_0001_platform_bootstrap' ) );

				$rival->queryAsync( $sql );
				$this->awaitWaiting( $rival, $sql, 'updating' );
			}
		);

		$this->assertTrue( $rival->isReady( 5000 ), 'The rival never got an answer after the commit.' );
		$this->assertSame( 0, $rival->reap(), 'The rival\'s statement matched a record whose revision it had not read.' );

		$stored = BootRecord::fromJson( $this->storedRecord() );

		$this->assertSame( 2, $stored->rev() );
		$this->assertSame( '20260922_0001_platform_bootstrap', $stored->schemaHead() );
		$this->assertSame( LockMode::GetLock, $stored->lockMode(), 'The rival\'s change was written over this writer\'s.' );
	}

	/**
	 * Tests that a change that changes nothing writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unchanged_record_is_not_written_again(): void {
		$written = $this->plantRecord( self::installedRecord() );
		$option  = new BootOption( $this->db, $this->reporter() );

		$log = $this->captureQueries(
			static function () use ( $option ): void {
				$option->mutate( static fn( BootRecord $record ): BootRecord => $record->withPluginVersion( SEOCART_VERSION ) );
			}
		);

		$this->assertQueryCount( 0, $log, 'An unchanged record' );
		$this->assertStringStartsWith( '{"v":1,"rev":' . $written->rev() . ',', (string) $this->storedRecord() );
	}

	/**
	 * Tests that the record cannot outgrow 8 KB: the largest one the caps allow fits, and one more switch is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_the_record_cannot_outgrow_its_budget(): void {
		global $wpdb;

		$largest = self::largestKillMap( BootRecord::MAX_KILL_SWITCHES );
		$written = $this->plantRecord(
			self::installedRecord( str_repeat( 'h', 191 ) )
				->withHomeUrl( 'https://example.org/' . str_repeat( 'p', BootRecord::MAX_URL_BYTES - 20 ) )
				->withKillSwitches( $largest )
		);

		$this->assertSame( BootRecord::MAX_KILL_SWITCHES, count( $written->killSwitches() ) );
		$this->assertLessThanOrEqual( BootRecord::MAX_BYTES, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT LENGTH( option_value ) FROM %i WHERE option_name = %s', $wpdb->options, BootOption::NAME ) ) );

		$nineKilobytes = self::largestKillMap( 128 );

		$this->assertGreaterThan( 9000, strlen( (string) wp_json_encode( $nineKilobytes ) ) );

		try {
			$this->changeRecord( static fn( BootRecord $record ): BootRecord => $record->withKillSwitches( $nineKilobytes ) );
			$this->fail( 'A kill-switch map of 9 KB was accepted.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertStringContainsString( 'at most', $refused->getMessage() );
		}

		$this->assertLessThanOrEqual( BootRecord::MAX_BYTES, strlen( (string) $this->storedRecord() ) );
	}

	/**
	 * Tests G2: after an installation, the site autoloads exactly one plugin option, and it fits its budget.
	 *
	 * @since 0.1.0
	 */
	public function test_an_installed_site_autoloads_exactly_one_small_plugin_option(): void {
		global $wpdb;

		$this->container()->get( Lifecycle::class )->installSite();

		$autoloaded = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s AND autoload IN ( ' . implode( ', ', array_fill( 0, count( wp_autoload_values_to_autoload() ), '%s' ) ) . ' )',
				array_merge( array( $wpdb->options, $wpdb->esc_like( 'seocart_' ) . '%' ), wp_autoload_values_to_autoload() )
			)
		);

		$this->assertSame( array( BootOption::NAME ), $autoloaded, 'G2: the plugin autoloads one option, its boot record.' );
		$this->assertLessThanOrEqual( BootRecord::MAX_BYTES, strlen( (string) $this->storedRecord() ) );
	}

	/**
	 * Builds a map of kill switches whose ids are as long as allowed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count How many.
	 * @return array<string, true> The map.
	 */
	private static function largestKillMap( int $count ): array {
		$map = array();

		for ( $i = 0; $i < $count; ++$i ) {
			$map[ 'k' . str_pad( (string) $i, 63, '0', STR_PAD_LEFT ) ] = true;
		}

		return $map;
	}
}
