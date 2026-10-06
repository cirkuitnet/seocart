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
 * Planted violation, shown red and removed: in BootRecord::readRateVersion(), accept any whole
 * number: a stored exchange-rate version of 0 is read as current, and the corrupt-text test fails
 * on it.
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
		$record = self::full()->withKillSwitch( 'gateway.stripe', true )->withRev( 7 );
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
		$this->assertSame( '2026-09-22T09:00:00Z', $read->installedAt() );
		$this->assertTrue( $read->canaryFailed() );
		$this->assertSame( '2026-09-23T12:00:00Z', $read->canaryFailedSince() );
		$this->assertSame( 4, $read->rateVersion() );
		$this->assertTrue( $read->isKilled( 'gateway.stripe' ) );
		$this->assertSame( $json, $read->toJson() );
		$this->assertFalse( BootRecord::fromJson( $read->withCanaryFailure( null )->toJson() )->canaryFailed(), 'The canary\'s end was not recorded.' );
		$this->assertSame( SafeModeStatus::Copy, $read->withCanaryFailure( null )->safeModeReason(), 'The canary\'s end changed the recorded reason.' );
	}

	/**
	 * Tests that a kill switch is turned on and read, and removed with the order of the others kept, and that an id that is none, or one beyond the cap, is refused.
	 *
	 * Planted violation, shown red and removed: in BootRecord::withKillSwitch(), leave the key in
	 * place when the switch is turned off: the cleared switch still reads as on.
	 *
	 * @since 0.2.0
	 */
	public function test_a_kill_switch_is_set_read_and_removed_keeping_the_others_order(): void {
		$record = self::full()->withKillSwitch( 'gateway.stripe', true )->withKillSwitch( 'gateway.paypal', true )->withKillSwitch( 'jobs', true );

		$this->assertTrue( $record->isKilled( 'gateway.paypal' ) );
		$this->assertFalse( $record->isKilled( 'gateway.square' ) );

		$cleared = $record->withKillSwitch( 'gateway.paypal', false );
		$read    = BootRecord::fromJson( $cleared->withRev( 3 )->toJson() );

		$this->assertFalse( $read->isKilled( 'gateway.paypal' ), 'A switch turned off is removed.' );
		$this->assertSame(
			array(
				'gateway.stripe' => true,
				'jobs'           => true,
			),
			(array) json_decode( $read->toJson(), true )['kill'],
			'The other switches keep their order.'
		);
		$this->assertTrue( $record->isKilled( 'gateway.paypal' ), 'The copy changed, not the record it came from.' );
		$this->assertTrue( $read->withKillSwitch( 'gateway.paypal', false )->sameAs( $read ), 'Removing a switch that is off changes nothing.' );
		$this->assertTrue( $read->withKillSwitch( 'gateway.stripe', true )->sameAs( $read ), 'Turning on a switch that is on changes nothing.' );

		$longest = 'gateway.' . str_repeat( 'a', 32 );

		$this->assertTrue( BootRecord::absent()->withKillSwitch( $longest, true )->isKilled( $longest ), 'The longest gateway id makes a switch.' );

		foreach ( array( 'Gateway.Stripe', 'gateway stripe', '', 'k' . str_repeat( 'x', 64 ) ) as $id ) {
			try {
				$record->withKillSwitch( $id, true );
				$this->fail( sprintf( 'The kill switch "%s" was accepted.', $id ) );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'subsystem id', $refused->getMessage() );
			}
		}

		$full = BootRecord::absent();

		for ( $i = 0; $i < BootRecord::MAX_KILL_SWITCHES; ++$i ) {
			$full = $full->withKillSwitch( 'k' . $i, true );
		}

		$this->assertTrue( $full->withKillSwitch( 'k0', false )->withKillSwitch( 'one_more', true )->isKilled( 'one_more' ), 'A switch can take the place of one removed.' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at most' );

		$full->withKillSwitch( 'one_more', true );
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
			'a canary entry without a time' => array( '{"v":1,"rev":1,"lock_mode":"get_lock","canary":true}' ),
			'the canary as a reason'        => array( '{"v":1,"rev":1,"lock_mode":"get_lock","safe_mode":{"reason":"canary","since":"2026-09-23T10:00:00Z"}}' ),
			'a rate version as text'        => array( '{"v":1,"rev":1,"lock_mode":"get_lock","rate_version":"4"}' ),
			'a rate version below 1'        => array( '{"v":1,"rev":1,"lock_mode":"get_lock","rate_version":0}' ),
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
		$this->assertFalse( $readable->canaryFailed(), 'A newer shape without a canary entry has no canary failure.' );
		$this->assertTrue( BootRecord::fromJson( $newer . '"canary":{"failed_at":1}}' )->canaryFailed(), 'A canary entry this version cannot read must count as a failure.' );
		$this->assertSame( SafeModeStatus::Manual, $unreadable->safeModeReason(), 'A newer shape without an address this version can read must keep Safe Mode on.' );
		$this->assertSame( SafeModeStatus::Manual, $unreadableHash->safeModeReason(), 'A newer shape with an address this version cannot read must keep Safe Mode on.' );
		$this->assertNull( BootRecord::fromJson( $newer . '"rate_version":{"major":4}}' )->rateVersion(), 'A rate version this version cannot read must count as none, so no rate is priced at.' );

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
			->withInstalledAt( $long )
			->withCanaryFailure( str_repeat( 't', 32 ) )
			->withRateVersion( PHP_INT_MAX )
			->withRev( PHP_INT_MAX );

		for ( $i = 0; $i < BootRecord::MAX_KILL_SWITCHES; ++$i ) {
			$kill[ 'k' . str_pad( (string) $i, 63, '0', STR_PAD_LEFT ) ] = true;
		}

		// The largest record is built by decoding, adding the kill switches to the budget's byte
		// count, and reading the record back, so the cap on reading is what is tested.
		$data         = json_decode( $largest->toJson(), true );
		$data['kill'] = $kill;
		$json         = (string) wp_json_encode( $data );

		$this->assertLessThanOrEqual( BootRecord::MAX_BYTES, strlen( $json ), 'The caps allow a record over the budget.' );
		$this->assertFalse( BootRecord::fromJson( $json )->isAbsent(), 'The largest record the caps allow is read back.' );

		$kill['one-more'] = true;
		$data['kill']     = $kill;

		$this->expectException( \UnexpectedValueException::class );

		BootRecord::fromJson( (string) wp_json_encode( $data ) );
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
	 * Tests that the canary entry holds a time and no more.
	 *
	 * @since 0.1.0
	 */
	public function test_a_canary_time_over_its_cap_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		BootRecord::absent()->withCanaryFailure( str_repeat( 't', 33 ) );
	}

	/**
	 * Tests that only a recordable reason, with its time, is recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_only_a_recordable_reason_with_its_time_is_recorded(): void {
		$this->assertNull( self::full()->withSafeMode( null, null )->safeModeReason() );

		foreach ( array( array( SafeModeStatus::UrlChanged, '2026-09-23T10:00:00Z' ), array( SafeModeStatus::Canary, '2026-09-23T10:00:00Z' ), array( SafeModeStatus::Manual, null ) ) as $case ) {
			try {
				BootRecord::absent()->withSafeMode( $case[0], $case[1] );
				$this->fail( 'Safe Mode recorded ' . $case[0]->value . ( null === $case[1] ? ' without a time' : '' ) . '.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'recordable', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that a record keeps no exchange-rate version until one is recorded, that one can be taken back to none, and that a version below 1 is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rate_version_is_recorded_from_1(): void {
		$installed = BootRecord::absent()->withLockMode( LockMode::GetLock );

		$this->assertNull( $installed->rateVersion() );
		$this->assertNull( BootRecord::fromJson( $installed->withRev( 1 )->toJson() )->rateVersion() );
		$this->assertSame( 1, BootRecord::fromJson( $installed->withRateVersion( 1 )->withRev( 1 )->toJson() )->rateVersion() );
		$this->assertNull( BootRecord::fromJson( $installed->withRateVersion( 1 )->withRateVersion( null )->withRev( 1 )->toJson() )->rateVersion() );

		$this->expectException( \InvalidArgumentException::class );

		$installed->withRateVersion( 0 );
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
			->withInstalledAt( '2026-09-22T09:00:00Z' )
			->withCanaryFailure( '2026-09-23T12:00:00Z' )
			->withRateVersion( 4 );
	}
}
