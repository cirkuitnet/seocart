<?php
/**
 * Tests Safe Mode: when it turns on, what ends it, and the command that controls it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\DrainReport;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Cli\SafeModeCommand;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Notices;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Kernel\SafeModeStatus;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Platform\Kernel\SiteAddress;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * A copy of a store must not act as the store: Safe Mode turns on when the address changes, and
 * only the merchant, an operator or the constant ends it — never for a credentials failure.
 *
 * Its effect is tested on the outbox drainer the kernel wires, which Safe Mode pauses; the job
 * runner takes the same switch when the jobs module is wired.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In SiteAddress::key(), prefix the result with the scheme (`wp_parse_url( $url, PHP_URL_SCHEME )`):
 *   test_the_scheme_case_and_trailing_slash_are_not_the_address fails on http to https.
 * - In SiteAddress::key(), leave the port out: test_another_port_is_another_site sees a copy on
 *   another port take itself for the store.
 * - Store the address as text and compare it as text, as the record once did: make
 *   SiteAddress::encode() and decode() return the text they are given, and in SafeMode::decide()
 *   compare `SiteAddress::key( (string) $record->homeUrl() )` with the current key:
 *   test_a_search_replace_cannot_make_a_copy_look_like_the_store sees the copy take itself for the store.
 * - Make SiteAddress::encode() and decode() alone return the text they are given:
 *   test_a_replace_of_part_of_the_address_cannot_rewrite_the_recorded_one sees `www.` replaced in the
 *   address the notice names as the recorded one.
 * - In BootRecord::fromJson(), leave out the manual switch for a newer shape whose address cannot be
 *   read: test_a_newer_record_whose_address_cannot_be_read_keeps_safe_mode_on sees an older node act
 *   as the live store.
 * - In SafeMode::isActive(), plant `return false;`: every test that expects Safe Mode on fails, the
 *   drainer's among them.
 * - In Modules::eventsRegister(), leave the drainer's pause switch out: the copy delivers its events.
 * - In SafeMode::decide(), move the `false === $this->forced` check above the one for a copy or a
 *   rebuilt record: test_the_constant_cannot_clear_a_copy_or_a_rebuilt_record fails.
 * - In SafeMode::decide(), return a recorded manual switch before comparing the address, as it once
 *   did: test_a_copy_of_a_store_switched_on_by_hand_is_asked_whether_it_is_a_copy sees the manual
 *   notice, which tells the copy to switch Safe Mode off, and fails.
 *
 * @since 0.1.0
 */
final class SafeModeTest extends KernelTestCase {

	/**
	 * The time the frozen clock tells.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-23T15:30:00Z';

	/**
	 * Tests that a site at its recorded address is not in Safe Mode.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_at_its_recorded_address_is_not_in_safe_mode(): void {
		$this->plantRecord( self::installedRecord() );

		$safeMode = $this->safeMode();

		$this->assertSame( SafeModeStatus::Off, $safeMode->status() );
		$this->assertFalse( $safeMode->isActive() );
	}

	/**
	 * Tests that a site without a record is not in Safe Mode, and that entering it records nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_without_a_record_is_not_in_safe_mode_and_gets_no_record(): void {
		$safeMode = $this->safeMode();

		$this->assertSame( SafeModeStatus::Off, $safeMode->status() );

		$safeMode->enter( SafeModeStatus::Manual );

		$this->assertNull( $this->storedRecord(), 'A record is only ever created with an identity.' );
	}

	/**
	 * Tests that a changed address turns Safe Mode on.
	 *
	 * @since 0.1.0
	 */
	public function test_a_changed_address_turns_safe_mode_on(): void {
		$recorded = home_url();

		$this->plantRecord( self::installedRecord() );
		$this->moveSiteTo( 'https://copy.example.net' );

		$safeMode = $this->safeMode();

		$this->assertSame( SafeModeStatus::UrlChanged, $safeMode->status() );
		$this->assertTrue( $safeMode->isActive() );
		$this->assertSame( $recorded, $safeMode->recordedUrl() );
	}

	/**
	 * Tests the comparison: the scheme, the case of the host and a trailing slash do not change the
	 * address; another path does.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scheme_case_and_trailing_slash_are_not_the_address(): void {
		$this->assertSame( SiteAddress::hash( 'http://shop.example.org' ), SiteAddress::hash( 'https://shop.example.org' ), 'Moving to https is not a copy.' );
		$this->assertSame( SiteAddress::hash( 'https://Shop.Example.org/' ), SiteAddress::hash( 'https://shop.example.org' ) );
		$this->assertSame( SiteAddress::hash( 'https://example.org/store/' ), SiteAddress::hash( 'https://example.org/store' ) );
		$this->assertNotSame( SiteAddress::hash( 'https://example.org/store' ), SiteAddress::hash( 'https://example.org/staging' ) );
		$this->assertNotSame( SiteAddress::hash( 'https://example.org' ), SiteAddress::hash( 'https://staging.example.org' ) );

		$this->plantRecord( self::installedRecord()->withHomeUrl( set_url_scheme( home_url(), 'http' ) ) );
		$this->moveSiteTo( set_url_scheme( home_url(), 'https' ) );

		$this->assertFalse( $this->safeMode()->isActive(), 'A site moved from http to https entered Safe Mode.' );
	}

	/**
	 * Tests the port: another port is another site, and the default ports, 80 and 443, count as no
	 * port, since the scheme does not count either.
	 *
	 * @since 0.1.0
	 */
	public function test_another_port_is_another_site(): void {
		$this->assertNotSame( SiteAddress::hash( 'https://shop.example.org' ), SiteAddress::hash( 'https://shop.example.org:8443' ) );
		$this->assertSame( SiteAddress::hash( 'https://shop.example.org' ), SiteAddress::hash( 'https://shop.example.org:443' ), 'The default port of https counted as a port.' );
		$this->assertSame( SiteAddress::hash( 'http://shop.example.org' ), SiteAddress::hash( 'http://shop.example.org:80' ), 'The default port of http counted as a port.' );
		$this->assertSame( SiteAddress::hash( 'https://shop.example.org' ), SiteAddress::hash( 'http://shop.example.org:80' ), 'Moving to https on the default ports is not a copy.' );

		$this->plantRecord( self::installedRecord()->withHomeUrl( 'https://shop.example.org' ) );
		$this->moveSiteTo( 'https://shop.example.org:8443' );

		$this->assertSame( SafeModeStatus::UrlChanged, $this->safeMode()->status(), 'A copy on another port took itself for the store.' );

		$this->moveSiteTo( 'https://shop.example.org:443' );

		$this->assertSame( SafeModeStatus::Off, $this->safeMode()->status(), 'The default port was taken for another site.' );
	}

	/**
	 * Tests the standard way of copying a site: copy the database, then replace the old address with
	 * the new one everywhere, as `wp search-replace` does — the whole address, the address with its
	 * slashes escaped as JSON writes them, and the bare host. The record must still tell the copy
	 * that it moved, and still name the old address.
	 *
	 * @since 0.1.0
	 */
	public function test_a_search_replace_cannot_make_a_copy_look_like_the_store(): void {
		$old  = home_url();
		$new  = 'https://copy.example.net';
		$host = (string) wp_parse_url( $old, PHP_URL_HOST );

		$this->plantRecord( self::installedRecord() );

		$this->storeRecordText(
			str_replace(
				array( $old, str_replace( '/', '\/', $old ), $host ),
				array( $new, str_replace( '/', '\/', $new ), (string) wp_parse_url( $new, PHP_URL_HOST ) ),
				(string) $this->storedRecord()
			)
		);
		$this->moveSiteTo( $new );

		$safeMode = $this->safeMode();

		$this->assertSame( SafeModeStatus::UrlChanged, $safeMode->status(), 'After a search-replace, the copy took itself for the store.' );
		$this->assertSame( $old, $safeMode->recordedUrl(), 'The search-replace rewrote the address the notice names as the recorded one.' );
		$this->assertSame( array(), $this->reports, 'The replaced record did not read as a record.' );
	}

	/**
	 * Tests a replace of part of the address, `www.` to `stage.` as a staging copy gets: the address
	 * the notice names as the recorded one is still the store's, and the copy still sees that it moved.
	 *
	 * @since 0.1.0
	 */
	public function test_a_replace_of_part_of_the_address_cannot_rewrite_the_recorded_one(): void {
		$this->plantRecord( self::installedRecord()->withHomeUrl( 'https://www.example.org' ) );

		$this->storeRecordText( str_replace( array( '//www.', 'www.' ), array( '//stage.', 'stage.' ), (string) $this->storedRecord() ) );
		$this->moveSiteTo( 'https://stage.example.org' );

		$safeMode = $this->safeMode();

		$this->assertSame( 'https://www.example.org', $safeMode->recordedUrl(), 'The replace rewrote the address the notice names as the recorded one.' );
		$this->assertSame( SafeModeStatus::UrlChanged, $safeMode->status() );
	}

	/**
	 * Tests a record a newer version wrote in a newer shape, with a schema head this version reads, no
	 * Safe Mode reason, and the address in a form this version cannot read. The gate lets the store
	 * work, and without the address this version cannot tell a copy from the store, so Safe Mode
	 * stays on: an older node never acts as the live store on a record it cannot fully read.
	 *
	 * @since 0.1.0
	 */
	public function test_a_newer_record_whose_address_cannot_be_read_keeps_safe_mode_on(): void {
		$this->container()->get( Lifecycle::class )->activate();

		$installed = BootRecord::fromJson( $this->storedRecord() );

		$this->storeRecordText(
			sprintf(
				'{"v":%1$d,"rev":%2$d,"plugin_version":"999.0.0","schema_head":"%3$s","lock_mode":"get_lock","install_uuid":"%4$s","home":{"sha3":"%5$s"},"installed_at":"%6$s"}',
				BootRecord::VERSION + 1,
				$installed->rev() + 1,
				self::codeHead(),
				(string) $installed->installUuid(),
				str_repeat( 'a', 64 ),
				(string) $installed->installedAt()
			)
		);
		wp_load_alloptions();

		$container = $this->container();

		$this->assertSame( GateState::Ready, $container->get( SchemaGate::class )->state(), 'The gate is closed, so the store would not act anyway and the test would prove nothing.' );
		$this->assertTrue( $container->get( SafeMode::class )->isActive(), 'An older node that cannot read the address acted as the live store.' );
	}

	/**
	 * Tests that adopting the address records it, with the time, and ends Safe Mode.
	 *
	 * @since 0.1.0
	 */
	public function test_adopting_the_address_records_it_and_ends_safe_mode(): void {
		$this->plantRecord( self::installedRecord() );
		$this->moveSiteTo( 'https://moved.example.net' );

		$this->safeMode()->adopt();

		$safeMode = $this->safeMode();
		$stored   = BootRecord::fromJson( $this->storedRecord() );

		$this->assertSame( SafeModeStatus::Off, $safeMode->status() );
		$this->assertSame( 'https://moved.example.net', $stored->homeUrl() );
		$this->assertSame( self::NOW, $stored->adoptedAt() );
	}

	/**
	 * Tests that marking the site as a copy keeps Safe Mode on, whatever the address, until the address is adopted.
	 *
	 * @since 0.1.0
	 */
	public function test_marking_a_copy_keeps_safe_mode_on(): void {
		$this->plantRecord( self::installedRecord() );
		$this->moveSiteTo( 'https://copy.example.net' );

		$this->safeMode()->markCopy();

		$this->assertSame( SafeModeStatus::Copy, $this->safeMode()->status() );

		remove_all_filters( 'home_url' );

		$this->assertSame( SafeModeStatus::Copy, $this->safeMode()->status(), 'A copy stays a copy at the recorded address too.' );

		$this->safeMode()->adopt();

		$this->assertSame( SafeModeStatus::Off, $this->safeMode()->status() );
	}

	/**
	 * Tests SEOCART_SAFE_MODE: true forces Safe Mode on, false forces it off.
	 *
	 * @since 0.1.0
	 */
	public function test_the_constant_forces_safe_mode_either_way(): void {
		$this->plantRecord( self::installedRecord() );

		$this->assertSame( SafeModeStatus::Constant, $this->safeMode( true )->status() );

		$this->moveSiteTo( 'https://copy.example.net' );

		$this->assertSame( SafeModeStatus::Off, $this->safeMode( false )->status(), 'false overrides a changed address.' );

		remove_all_filters( 'home_url' );
		$this->safeMode()->enter( SafeModeStatus::Manual );

		$this->assertSame( SafeModeStatus::Manual, $this->safeMode()->status() );
		$this->assertSame( SafeModeStatus::Off, $this->safeMode( false )->status(), 'false overrides a manual switch.' );
	}

	/**
	 * Tests a store switched on by hand whose database is then copied to a new address: the copy is
	 * asked whether it is a copy, and is not told to switch Safe Mode off, which would adopt its
	 * address without asking.
	 *
	 * @since 0.1.0
	 */
	public function test_a_copy_of_a_store_switched_on_by_hand_is_asked_whether_it_is_a_copy(): void {
		$this->plantRecord( self::installedRecord() );
		$this->safeMode()->enter( SafeModeStatus::Manual );

		$this->assertSame( SafeModeStatus::Manual, $this->safeMode()->status() );

		$this->moveSiteTo( 'https://copy.example.net' );

		$this->assertSame( SafeModeStatus::UrlChanged, $this->safeMode()->status(), 'The manual switch hid that the address changed.' );

		wp_set_current_user( 1 );
		set_current_screen( 'dashboard' );

		ob_start();
		$this->container()->get( Notices::class )->render();
		$html = (string) ob_get_clean();

		set_current_screen( 'front' );

		$this->assertStringContainsString( 'choice=copy', $html, 'The copy is not asked whether it is a copy.' );
		$this->assertStringContainsString( 'choice=adopt', $html );
		$this->assertStringNotContainsString( 'safe-mode off', $html, 'The notice tells the copy to switch Safe Mode off, which adopts its address.' );
	}

	/**
	 * Tests that SEOCART_SAFE_MODE set to false clears neither a copy nor a rebuilt record: the
	 * constant is copied along with wp-config.php, so a cloned site with the original's configuration
	 * must not act as the store. Only an adoption ends them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_constant_cannot_clear_a_copy_or_a_rebuilt_record(): void {
		$this->plantRecord( self::installedRecord() );
		$this->safeMode()->markCopy();

		$this->assertSame( SafeModeStatus::Copy, $this->safeMode( false )->status(), 'SEOCART_SAFE_MODE set to false cleared a copy.' );

		$this->plantRecord( self::installedRecord()->withSafeMode( SafeModeStatus::Rebuilt, self::NOW ) );

		$this->assertSame( SafeModeStatus::Rebuilt, $this->safeMode( false )->status(), 'SEOCART_SAFE_MODE set to false cleared a rebuilt record.' );

		$this->safeMode()->adopt();

		$this->assertSame( SafeModeStatus::Off, $this->safeMode( false )->status() );
	}

	/**
	 * Tests that a canary failure survives the constant, a lesser reason and an adopted address; only its own end clears it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_canary_failure_survives_everything_but_its_own_end(): void {
		$this->plantRecord( self::installedRecord() );

		$this->safeMode()->enter( SafeModeStatus::Canary );

		$this->assertSame( SafeModeStatus::Canary, $this->safeMode()->status() );
		$this->assertTrue( $this->safeMode( false )->isActive(), 'SEOCART_SAFE_MODE set to false cleared a canary failure.' );

		$this->safeMode()->enter( SafeModeStatus::Manual );
		$this->safeMode()->adopt();

		$this->assertSame( SafeModeStatus::Canary, $this->safeMode()->status(), 'A lesser reason or an adoption replaced a canary failure.' );

		$this->safeMode()->exit();

		$this->assertSame( SafeModeStatus::Off, $this->safeMode()->status() );
	}

	/**
	 * Tests that entering Safe Mode for the same reason again writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_entering_twice_for_the_same_reason_writes_once(): void {
		$planted = $this->plantRecord( self::installedRecord() );

		$this->safeMode()->enter( SafeModeStatus::Manual );
		$this->safeMode()->enter( SafeModeStatus::Manual );

		$this->assertSame( $planted->rev() + 1, BootRecord::fromJson( $this->storedRecord() )->rev() );
	}

	/**
	 * Tests that the statuses Safe Mode works out cannot be recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_worked_out_status_cannot_be_recorded(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->safeMode()->enter( SafeModeStatus::UrlChanged );
	}

	/**
	 * Tests the effect: the outbox drainer the kernel wires delivers nothing while Safe Mode is on,
	 * and drains as usual once the site is adopted.
	 *
	 * @since 0.1.0
	 */
	public function test_the_wired_outbox_drainer_pauses_while_safe_mode_is_on(): void {
		$this->container()->get( Lifecycle::class )->activate();
		$this->moveSiteTo( 'https://copy.example.net' );

		$paused = $this->container()->get( OutboxDrainer::class )->drain( DrainOptions::command( 1 ) );

		$this->assertSame( DrainReport::SKIPPED_PAUSED, $paused->skipped, 'A copy of the store delivered its events.' );

		$this->safeMode()->adopt();

		$running = $this->container()->get( OutboxDrainer::class )->drain( DrainOptions::command( 1 ) );

		$this->assertNotSame( DrainReport::SKIPPED_PAUSED, $running->skipped, 'The drainer stayed paused after the site was adopted.' );
	}

	/**
	 * Tests `wp seocart safe-mode`: status prints the reason and both addresses; on, off and a wrong action.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_switches_safe_mode_and_prints_what_is_now_true(): void {
		$this->plantRecord( self::installedRecord() );
		$this->moveSiteTo( 'https://copy.example.net' );

		$this->assertSame(
			array( SafeModeCommand::EXIT_OK, array( 'Safe Mode: on (url_changed)', 'Recorded address: ' . self::recordedAddress(), 'Current address: https://copy.example.net' ) ),
			$this->command( 'status' )
		);

		list( $code, $lines ) = $this->command( 'off' );

		$this->assertSame( SafeModeCommand::EXIT_OK, $code );
		$this->assertSame( 'Safe Mode: off', $lines[0] );

		list( $code, $lines ) = $this->command( 'on' );

		$this->assertSame( SafeModeCommand::EXIT_OK, $code );
		$this->assertSame( 'Safe Mode: on (manual)', $lines[0] );

		list( $code, $lines ) = $this->command( 'sideways' );

		$this->assertSame( SafeModeCommand::EXIT_USAGE, $code );
		$this->assertStringContainsString( 'Use on, off or status', $lines[0] );
	}

	/**
	 * Runs `wp seocart safe-mode` as WP-CLI would, and collects what it printed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $action The action.
	 * @return array{0: int, 1: list<string>} The exit code and the printed lines.
	 */
	private function command( string $action ): array {
		$lines   = array();
		$command = new SafeModeCommand(
			$this->safeMode(),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);
		$code    = $command->run( array( $action ) );

		return array( $code, $lines );
	}

	/**
	 * Builds Safe Mode over the real boot option, with the frozen clock.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $forced Optional. The value of SEOCART_SAFE_MODE. Default null, not defined.
	 * @return SafeMode Safe Mode, reading the record afresh.
	 */
	private function safeMode( ?bool $forced = null ): SafeMode {
		return new SafeMode( new BootOption( $this->db, $this->reporter() ), FrozenClock::at( self::NOW ), $forced );
	}

	/**
	 * Stores the current site's boot record as text, the way a tool that edits the database would, and forgets every cached copy.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The option's new value.
	 */
	private function storeRecordText( string $text ): void {
		global $wpdb;

		$wpdb->update( $wpdb->options, array( 'option_value' => $text ), array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test edits the row as a search-replace tool would, past every cache.
		wp_cache_flush();
	}

	/**
	 * Makes the site answer with another address, as a copy or a moved site would.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url The address home_url() returns from now on.
	 */
	private function moveSiteTo( string $url ): void {
		remove_all_filters( 'home_url' );
		add_filter( 'home_url', static fn(): string => $url );
	}

	/**
	 * Returns the address the site had before any test moved it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The address.
	 */
	private static function recordedAddress(): string {
		return (string) get_option( 'home' );
	}
}
