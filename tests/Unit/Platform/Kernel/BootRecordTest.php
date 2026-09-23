<?php
/**
 * Tests the boot record's shape: its encoding, what counts as corrupt, and its caps
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Kernel;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\SafeModeStatus;
use SEOCart\Platform\Kernel\SiteAddress;

/**
 * The record is one JSON object that begins with its revision, keeps what a newer version wrote,
 * refuses anything it cannot read, and cannot outgrow its byte budget.
 *
 * @since 0.1.0
 */
final class BootRecordTest extends TestCase {

	/**
	 * Stands WordPress's JSON encoder and address helpers in with PHP's.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $value ): string => rtrim( $value, '/\\' ) );
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a full record round-trips, and that its text begins with the shape version and revision.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_round_trips_and_begins_with_its_revision(): void {
		$record = self::full()->withRev( 7 );
		$json   = $record->toJson();

		$this->assertStringStartsWith( '{"v":1,"rev":7,"plugin_version":', $json );

		$read = BootRecord::fromJson( $json );

		$this->assertFalse( $read->isAbsent() );
		$this->assertSame( 7, $read->rev() );
		$this->assertSame( '0.1.0', $read->pluginVersion() );
		$this->assertSame( '20260922_0001_platform_bootstrap', $read->schemaHead() );
		$this->assertSame( LockMode::Table, $read->lockMode() );
		$this->assertSame( '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b', $read->installUuid() );
		$this->assertSame( 'https://shop.example.org', $read->homeUrl() );
		$this->assertSame( hash( 'sha256', 'shop.example.org' ), $read->homeHash() );
		$this->assertStringNotContainsString( 'shop.example.org', $json, 'The record holds the address as text, which a search-replace would rewrite.' );
		$this->assertSame( SafeModeStatus::Copy, $read->safeModeReason() );
		$this->assertSame( '2026-09-23T10:00:00Z', $read->safeModeSince() );
		$this->assertSame( '2026-09-23T11:00:00Z', $read->adoptedAt() );
		$this->assertSame( array( 'gateway.stripe' => true ), $read->killSwitches() );
		$this->assertSame( '2026-09-22T09:00:00Z', $read->installedAt() );
		$this->assertSame( $json, $read->toJson() );
	}

	/**
	 * Tests the absent record: no revision, never encoded, and what null reads as.
	 *
	 * @since 0.1.0
	 */
	public function test_the_absent_record_has_no_revision_and_is_never_encoded(): void {
		$this->assertTrue( BootRecord::fromJson( null )->isAbsent() );
		$this->assertSame( 0, BootRecord::absent()->rev() );
		$this->assertFalse( BootRecord::absent()->withPluginVersion( '0.1.0' )->isAbsent(), 'A change makes a record that exists.' );

		$this->expectException( \LogicException::class );

		BootRecord::absent()->toJson();
	}

	/**
	 * Returns stored texts that are not records of this shape.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> The texts.
	 */
	public static function corrupt(): array {
		return array(
			'not JSON'                      => array( '{"v":1,"rev":' ),
			'a list'                        => array( '[1,2]' ),
			'no shape version'              => array( '{"rev":1}' ),
			'a shape version as text'       => array( '{"v":"1","rev":1,"lock_mode":"get_lock"}' ),
			'a shape version below 1'       => array( '{"v":0,"rev":1,"lock_mode":"get_lock"}' ),
			'a newer shape, no revision'    => array( '{"v":2,"lock_mode":"get_lock"}' ),
			'no revision'                   => array( '{"v":1}' ),
			'a revision below 1'            => array( '{"v":1,"rev":0}' ),
			'a revision as text'            => array( '{"v":1,"rev":"1"}' ),
			'no lock mode'                  => array( '{"v":1,"rev":1,"install_uuid":"0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b"}' ),
			'an unknown lock mode'          => array( '{"v":1,"rev":1,"lock_mode":"flock"}' ),
			'a worked-out Safe Mode reason' => array( '{"v":1,"rev":1,"lock_mode":"get_lock","safe_mode":{"reason":"url_changed","since":"2026-09-23T10:00:00Z"}}' ),
			'a Safe Mode reason alone'      => array( '{"v":1,"rev":1,"lock_mode":"get_lock","safe_mode":"manual"}' ),
			'a malformed kill switch'       => array( '{"v":1,"rev":1,"lock_mode":"get_lock","kill":{"Gateway Stripe":true}}' ),
			'a kill switch set to false'    => array( '{"v":1,"rev":1,"lock_mode":"get_lock","kill":{"gateway.stripe":false}}' ),
			'an address that is not text'   => array( '{"v":1,"rev":1,"lock_mode":"get_lock","home_hash":"' . str_repeat( 'a', 64 ) . '","home_shown":["https://shop.example.org"]}' ),
			'an address not in base64url'   => array( '{"v":1,"rev":1,"lock_mode":"get_lock","home_hash":"' . str_repeat( 'a', 64 ) . '","home_shown":"https://shop.example.org"}' ),
			'a hash that is not a hash'     => array( '{"v":1,"rev":1,"lock_mode":"get_lock","home_hash":"shop.example.org","home_shown":"' . SiteAddress::encode( 'https://shop.example.org' ) . '"}' ),
			'a hash without its address'    => array( '{"v":1,"rev":1,"lock_mode":"get_lock","home_hash":"' . str_repeat( 'a', 64 ) . '"}' ),
			'an empty version'              => array( '{"v":1,"rev":1,"lock_mode":"get_lock","plugin_version":""}' ),
		);
	}

	/**
	 * Tests that each corrupt text is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider corrupt
	 *
	 * @param string $json The stored text.
	 */
	public function test_a_corrupt_text_is_refused( string $json ): void {
		$this->expectException( \UnexpectedValueException::class );

		BootRecord::fromJson( $json );
	}

	/**
	 * Tests a record a newer version wrote in a newer shape: what this version can read is read, a
	 * field it cannot read counts as missing, a Safe Mode entry it cannot read keeps Safe Mode on,
	 * and the record is never encoded, so this version cannot write over it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_newer_shape_is_read_as_far_as_it_is_understood_and_never_encoded(): void {
		$read = BootRecord::fromJson(
			'{"v":' . ( BootRecord::VERSION + 1 ) . ',"rev":3,"plugin_version":{"major":2},"install_uuid":"0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b",'
			. '"lock_mode":"quantum","safe_mode":{"reason":"suspended","since":"2026-09-23T10:00:00Z"},"regions":["eu"]}'
		);

		$this->assertTrue( $read->isNewerShape() );
		$this->assertSame( 3, $read->rev() );
		$this->assertSame( '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b', $read->installUuid(), 'A field this version understands was not read.' );
		$this->assertNull( $read->pluginVersion(), 'A field this version cannot read must count as missing.' );
		$this->assertNull( $read->lockMode() );
		$this->assertSame( SafeModeStatus::Manual, $read->safeModeReason(), 'A Safe Mode entry this version cannot read must keep Safe Mode on.' );
		$this->assertFalse( BootRecord::fromJson( '{"v":1,"rev":3,"lock_mode":"get_lock"}' )->isNewerShape() );

		$newer          = '{"v":' . ( BootRecord::VERSION + 1 ) . ',"rev":3,"lock_mode":"get_lock",';
		$shown          = SiteAddress::encode( 'https://shop.example.org' );
		$readable       = BootRecord::fromJson( $newer . '"home_hash":"' . str_repeat( 'a', 64 ) . '","home_shown":"' . $shown . '"}' );
		$unreadable     = BootRecord::fromJson( $newer . '"home":{"sha3":"' . str_repeat( 'a', 64 ) . '"}}' );
		$unreadableHash = BootRecord::fromJson( $newer . '"home_hash":{"sha256":"' . str_repeat( 'a', 64 ) . '"},"home_shown":"' . $shown . '"}' );

		$this->assertNull( $readable->safeModeReason(), 'A newer shape whose address this version reads is not in Safe Mode for that.' );
		$this->assertSame( SafeModeStatus::Manual, $unreadable->safeModeReason(), 'A newer shape without an address this version can read must keep Safe Mode on.' );
		$this->assertSame( SafeModeStatus::Manual, $unreadableHash->safeModeReason(), 'A newer shape with an address this version cannot read must keep Safe Mode on.' );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'newer shape' );

		$read->withPluginVersion( '0.1.0' )->withRev( 4 )->toJson();
	}

	/**
	 * Tests that a record without a lock mode is never encoded, so no writer can store one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_without_a_lock_mode_is_never_encoded(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'lock mode' );

		BootRecord::absent()->withInstallUuid( '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b' )->withRev( 1 )->toJson();
	}

	/**
	 * Tests that keys a newer version wrote are kept and written back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_newer_versions_keys_survive_a_write(): void {
		$read = BootRecord::fromJson( '{"v":1,"rev":4,"schema_head":"20260922_0001_platform_bootstrap","lock_mode":"table","rates_version":12}' );

		$this->assertStringContainsString( '"rates_version":12', $read->withLockMode( LockMode::GetLock )->withRev( 5 )->toJson() );
	}

	/**
	 * Tests the caps: the largest record the caps allow fits the budget, and each cap refuses one more.
	 *
	 * @since 0.1.0
	 */
	public function test_the_caps_keep_every_record_within_its_budget(): void {
		$long    = str_repeat( 'x', 191 );
		$kill    = array();
		$largest = BootRecord::absent()
			->withPluginVersion( $long )
			->withSchemaHead( $long )
			->withLockMode( LockMode::GetLock )
			->withInstallUuid( $long )
			->withHomeUrl( str_repeat( 'u', BootRecord::MAX_URL_BYTES ) )
			->withSafeMode( SafeModeStatus::Rebuilt, $long )
			->withAdoptedAt( $long )
			->withInstalledAt( $long );

		for ( $i = 0; $i < BootRecord::MAX_KILL_SWITCHES; ++$i ) {
			$kill[ 'k' . str_pad( (string) $i, 63, '0', STR_PAD_LEFT ) ] = true;
		}

		$json = $largest->withKillSwitches( $kill )->withRev( PHP_INT_MAX )->toJson();

		$this->assertLessThanOrEqual( BootRecord::MAX_BYTES, strlen( $json ), 'The caps allow a record over the budget.' );

		$kill['one-more'] = true;

		$this->expectException( \InvalidArgumentException::class );

		$largest->withKillSwitches( $kill );
	}

	/**
	 * Tests that a field's cap is enforced when a caller sets it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_address_over_its_cap_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		BootRecord::absent()->withHomeUrl( str_repeat( 'u', BootRecord::MAX_URL_BYTES + 1 ) );
	}

	/**
	 * Tests that only a recordable reason, with its time, is recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_only_a_recordable_reason_with_its_time_is_recorded(): void {
		$this->assertNull( self::full()->withSafeMode( null, null )->safeModeReason() );

		foreach ( array( array( SafeModeStatus::UrlChanged, '2026-09-23T10:00:00Z' ), array( SafeModeStatus::Manual, null ) ) as $case ) {
			try {
				BootRecord::absent()->withSafeMode( $case[0], $case[1] );
				$this->fail( 'Safe Mode recorded ' . $case[0]->value . ( null === $case[1] ? ' without a time' : '' ) . '.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'recordable', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that two records the same but for their revision are the same.
	 *
	 * @since 0.1.0
	 */
	public function test_records_that_differ_only_in_revision_are_the_same(): void {
		$this->assertTrue( self::full()->withRev( 1 )->sameAs( self::full()->withRev( 9 ) ) );
		$this->assertFalse( self::full()->sameAs( self::full()->withLockMode( LockMode::GetLock ) ) );
		$this->assertTrue( BootRecord::absent()->sameAs( BootRecord::absent() ) );
		$this->assertFalse( BootRecord::absent()->sameAs( self::full() ) );
	}

	/**
	 * Tests that times are recorded in UTC.
	 *
	 * @since 0.1.0
	 */
	public function test_times_are_recorded_in_utc(): void {
		$this->assertSame( '2026-09-23T10:00:00Z', BootRecord::formatTime( new \DateTimeImmutable( '2026-09-23 12:00:00', new \DateTimeZone( 'Europe/Berlin' ) ) ) );
	}

	/**
	 * Returns a record with every field set.
	 *
	 * @since 0.1.0
	 *
	 * @return BootRecord The record, without a revision.
	 */
	private static function full(): BootRecord {
		return BootRecord::absent()
			->withPluginVersion( '0.1.0' )
			->withSchemaHead( '20260922_0001_platform_bootstrap' )
			->withLockMode( LockMode::Table )
			->withInstallUuid( '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b' )
			->withHomeUrl( 'https://shop.example.org' )
			->withSafeMode( SafeModeStatus::Copy, '2026-09-23T10:00:00Z' )
			->withAdoptedAt( '2026-09-23T11:00:00Z' )
			->withKillSwitches( array( 'gateway.stripe' => true ) )
			->withInstalledAt( '2026-09-22T09:00:00Z' );
	}
}
