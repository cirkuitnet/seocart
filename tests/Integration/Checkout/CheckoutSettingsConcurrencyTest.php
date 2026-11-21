<?php
/**
 * Tests two staff changes of the checkout's fields at once, on two real connections
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\CheckoutRules;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Application\CheckoutSettingsService;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Inventory\Application\InventorySettings;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Connection B plays another request with the settings store's own statements, prepared from its constants, and the test cleans the option it wrote.

/**
 * Two staff save the checkout's fields at once: each change is kept. Connection A is the service; connection B stands for another request, committed at the moment A's statement is about to leave: the settings store's own statements, or the plugin's own code over a second database connection.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutSettingsService::swap(), write with SettingsStore::writeScalars() instead of
 *   swapScalar(): B's change is lost in the first case;
 * - in SettingsStore::createScalar(), send WRITE_SCALAR, the insert that updates a row that
 *   exists, instead of CREATE_ROW: in both first-save cases A's first save replaces B's value.
 *
 * @group concurrency
 *
 * @since 0.2.0
 */
final class CheckoutSettingsConcurrencyTest extends DatabaseTestCase {

	/**
	 * The option the presences are kept in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OPTION = 'seocart_checkout_field_presence';

	/**
	 * A's first write of the option, whichever statement it is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const FIRST_WRITE = '/^INSERT .*seocart_checkout_field_presence/s';

	/**
	 * How many times B has won.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $wins = 0;

	/**
	 * The second database connections the test opened.
	 *
	 * @since 0.2.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $seconds = array();

	/**
	 * Removes the option the test writes, before and after.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::removeOption();
	}

	/**
	 * Removes the option the test wrote.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );

		foreach ( $this->seconds as $second ) {
			$second->close();
		}

		self::removeOption();

		parent::tear_down();
	}

	/**
	 * Tests that a change losing the compare-and-swap to another reads again and keeps both changes.
	 *
	 * A reads the stored presences and is about to swap them for its own; just before its statement
	 * leaves, B commits its change of another field. A's swap finds the row changed and changes
	 * nothing; A reads again, applies its change to B's, and swaps.
	 *
	 * @since 0.2.0
	 */
	public function test_two_changes_at_once_keep_both(): void {
		$this->service( $this->db )->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$b = $this->secondConnection();

		$this->beforeEachSwap( $b, array( '{"company":"optional","phone":"required"}' ), 1 );

		$this->service( $this->db )->updateFields( self::fields( array( 'line2' => 'hidden' ) ), Actor::user( 1 ) );

		$this->assertSame( 1, $this->wins, 'B never raced A, so the test proves nothing.' );
		$this->assertSame( '{"company":"optional","line2":"hidden","phone":"required"}', self::committed( $b ), 'A change was lost.' );
	}

	/**
	 * Tests that two first saves at once, on a store that never saved its presences, keep both changes, and neither is stored over the other.
	 *
	 * A finds no row and is about to write the option. Just before A's statement leaves, B, a second
	 * staff save over a database connection of its own, saves its change from start to end and
	 * creates the option. A's write must not replace B's value: A finds B's row, reads it, and
	 * applies its change to it.
	 *
	 * @since 0.2.0
	 */
	public function test_two_first_saves_at_once_keep_both(): void {
		$b       = $this->service( $this->openSecondDatabase()->db() );
		$barrier = $this->beforeStatement( self::FIRST_WRITE, static fn() => $b->updateFields( self::fields( array( 'company' => 'optional' ) ), Actor::user( 2 ) ) );

		$this->service( $this->db )->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$this->assertTrue( $barrier->fired, 'A never wrote the option, so B never raced it and the test proves nothing.' );
		$this->assertSame( '{"company":"optional","phone":"required"}', self::committed( $this->secondConnection() ), 'One first save was stored over the other.' );
	}

	/**
	 * Tests that a value written straight through the settings store while a first save is under way is kept.
	 *
	 * A finds no row and is about to write the option. Just before A's statement leaves, B writes the
	 * option with SettingsStore::writeScalars() over a database connection of its own, as code that
	 * is no staff save may, waiting for nothing. A's write must not replace B's value: A reads it and
	 * applies its change to it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_direct_write_during_a_first_save_is_kept(): void {
		$b       = new SettingsStore( Settings::registry(), $this->openSecondDatabase()->db() );
		$barrier = $this->beforeStatement( self::FIRST_WRITE, static fn() => $b->writeScalars( array( CheckoutSettings::FIELD_PRESENCE => '{"company":"optional"}' ) ) );

		$this->service( $this->db )->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$this->assertTrue( $barrier->fired, 'A never wrote the option, so B never raced it and the test proves nothing.' );
		$this->assertSame( '{"company":"optional","phone":"required"}', self::committed( $this->secondConnection() ), 'The value written straight through the store was replaced.' );
	}

	/**
	 * Tests that a change that loses three attempts answers `settings.version_conflict` and writes nothing of its own.
	 *
	 * Planted violation: in CheckoutSettingsService::ATTEMPTS, allow a fourth attempt: the change
	 * then goes through on it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_change_losing_every_attempt_is_told_so(): void {
		$this->service( $this->db )->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$b = $this->secondConnection();

		$this->beforeEachSwap( $b, array( '{"phone":"optional"}', '{"phone":"required"}', '{"phone":"hidden"}' ), 3 );

		try {
			$this->service( $this->db )->updateFields( self::fields( array( 'line2' => 'hidden' ) ), Actor::user( 1 ) );
			$this->fail( 'A change that lost every attempt was answered as made.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( array( SettingsError::VersionConflict, array( 'group' => CheckoutSettings::GROUP ) ), array( $lost->errorCode(), $lost->context() ) );
		}

		$this->assertSame( 3, $this->wins, 'A change tries three times.' );
		$this->assertStringNotContainsString( 'line2', (string) self::committed( $b ) );
	}

	/**
	 * Has B commit a value of the option just before each of A's writes of it, up to a number of times.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param string[]         $values What B writes before A's first swap, second swap, and so on.
	 * @param int              $times  How many of A's swaps B races.
	 *
	 * @phpstan-param list<string> $values
	 */
	private function beforeEachSwap( SecondConnection $b, array $values, int $times ): void {
		global $wpdb;

		$this->wins = 0;

		add_filter(
			'query',
			function ( string $query ) use ( $b, $values, $times, $wpdb ): string {
				// A's write of the option, whichever statement it is: the compare-and-swap, or any other write.
				if ( $this->wins >= $times || 1 !== preg_match( '/^(UPDATE|INSERT) /', $query ) || ! str_contains( $query, self::OPTION ) ) {
					return $query;
				}

				$read = (string) self::committed( $b );

				$b->query( (string) $wpdb->prepare( SettingsStore::COMPARE_AND_SWAP, $wpdb->options, $values[ $this->wins ], self::OPTION, $read ) );

				++$this->wins;

				return $query;
			}
		);
	}

	/**
	 * Builds the service over a database connection, as the kernel wires it.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection: A's, or a second one.
	 * @return CheckoutSettingsService The service.
	 */
	private function service( Database $db ): CheckoutSettingsService {
		$store = new SettingsStore( Settings::registry(), $db );

		return new CheckoutSettingsService( new CheckoutRules( $store, InventorySettings::settings(), BuiltInFields::all() ), $store );
	}

	/**
	 * Opens a second database connection, for the plugin's own code to run as another request. It is closed in tear_down().
	 *
	 * @since 0.2.0
	 *
	 * @return SecondDatabase The connection.
	 */
	private function openSecondDatabase(): SecondDatabase {
		$second          = new SecondDatabase( $this->reporter() );
		$this->seconds[] = $second;

		return $second;
	}

	/**
	 * Returns a change's input.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $presences Presence by field key.
	 * @return array{fields: list<array{key: string, presence: string}>} The input.
	 */
	private static function fields( array $presences ): array {
		$fields = array();

		foreach ( $presences as $key => $presence ) {
			$fields[] = array(
				'key'      => $key,
				'presence' => $presence,
			);
		}

		return array( 'fields' => $fields );
	}

	/**
	 * Returns the option as connection B sees it: what is committed.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return string|null The option's text, or null when there is none.
	 */
	private static function committed( SecondConnection $b ): ?string {
		global $wpdb;

		$value = $b->fetchValue( sprintf( "SELECT option_value FROM `%s` WHERE option_name = '%s'", $wpdb->options, self::OPTION ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Removes the option and its cached copies.
	 *
	 * @since 0.2.0
	 */
	private static function removeOption(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		wp_cache_flush();
	}
}
