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
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Connection B plays another request with the settings store's own statements, prepared from its constants, and the test cleans the option it wrote.

/**
 * Two staff save the checkout's fields at once: each change is kept. Connection A is the service; connection B stands for another request, sending the settings store's own statements, committed at the moment A's statement is about to leave.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutSettingsService::swap(), write with SettingsStore::writeScalars() instead of
 *   swapScalar(): B's change is lost in the first case;
 * - in CheckoutSettingsService::createdWith(), write without reading the option again inside the
 *   lock: B's first change is lost in the second case.
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
	 * How many times B has won.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $wins = 0;

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
		$this->service()->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$b = $this->secondConnection();

		$this->beforeEachSwap( $b, array( '{"company":"optional","phone":"required"}' ), 1 );

		$this->service()->updateFields( self::fields( array( 'line2' => 'hidden' ) ), Actor::user( 1 ) );

		$this->assertSame( 1, $this->wins, 'B never raced A, so the test proves nothing.' );
		$this->assertSame( '{"company":"optional","line2":"hidden","phone":"required"}', self::committed( $b ), 'A change was lost.' );
	}

	/**
	 * Tests that two first changes at once, on a store that never saved its presences, keep both: the second creator reads the first's row and swaps.
	 *
	 * B creates the option with its change just before A takes the create lock; A, inside the lock,
	 * finds the option and leaves it to the compare-and-swap.
	 *
	 * @since 0.2.0
	 */
	public function test_two_first_changes_keep_both(): void {
		global $wpdb;

		$b    = $this->secondConnection();
		$sent = false;

		add_filter(
			'query',
			static function ( string $query ) use ( $b, $wpdb, &$sent ): string {
				if ( ! $sent && str_starts_with( $query, 'SELECT GET_LOCK(' ) ) {
					$sent = true;

					$b->query( (string) $wpdb->prepare( SettingsStore::WRITE_SCALAR, $wpdb->options, self::OPTION, '{"company":"optional"}', '{"company":"optional"}' ) );
				}

				return $query;
			}
		);

		$this->service()->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$this->assertTrue( $sent, 'B never created the option, so the test proves nothing.' );
		$this->assertSame( '{"company":"optional","phone":"required"}', self::committed( $b ), 'The first change was lost.' );
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
		$this->service()->updateFields( self::fields( array( 'phone' => 'required' ) ), Actor::user( 1 ) );

		$b = $this->secondConnection();

		$this->beforeEachSwap( $b, array( '{"phone":"optional"}', '{"phone":"required"}', '{"phone":"hidden"}' ), 3 );

		try {
			$this->service()->updateFields( self::fields( array( 'line2' => 'hidden' ) ), Actor::user( 1 ) );
			$this->fail( 'A change that lost every attempt was answered as made.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( array( SettingsError::VersionConflict, array( 'group' => CheckoutSettings::GROUP ) ), array( $lost->errorCode(), $lost->context() ) );
		}

		$this->assertSame( 3, $this->wins, 'A change tries three times.' );
		$this->assertStringNotContainsString( 'line2', (string) self::committed( $b ) );
	}

	/**
	 * Has B commit a value of the option just before each of A's compare-and-swap statements, up to a number of times.
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
				if ( $this->wins >= $times || ! str_starts_with( $query, 'UPDATE ' ) || ! str_contains( $query, self::OPTION ) ) {
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
	 * Builds the service over connection A, as the kernel wires it.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckoutSettingsService The service.
	 */
	private function service(): CheckoutSettingsService {
		$store = new SettingsStore( Settings::registry(), $this->db );

		return new CheckoutSettingsService( new CheckoutRules( $store, InventorySettings::settings(), BuiltInFields::all() ), $store, new LockService( $this->db, LockMode::GetLock ) );
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
