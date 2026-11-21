<?php
/**
 * Tests the statements of the checkout form's read and of the staff's reads and changes of the checkout's choices
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Checkout\Application\CheckoutForm;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Application\CheckoutSettingsService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\QueryLog;

/**
 * The form read sends one statement on a cold request, the one that reads the store's checkout choices with the settings the calculation reads next, and none once they are read; each staff read sends one; a staff change sends two, the read of the stored choice past the cache and its compare-and-swap, and no transaction; a store's first change sends two too, the read that finds no row and the insert that creates it, and takes no lock.
 *
 * Each measurement starts as a request does: a container of its own, and no object cache but the
 * options WordPress loads at boot. The statements are printed for the report.
 *
 * Planted violations: in CheckoutRules::current(), read the `checkout` group in a values() call of
 * its own, then the inventory settings in another: the cold form read sends two; in
 * CheckoutSettingsService::swap(), create the option with SettingsStore::writeScalars(): the first
 * change's insert replaces a row that exists.
 *
 * @group performance
 *
 * @since 0.2.0
 */
final class CheckoutSettingsBudgetTest extends PlacementTestCase {

	/**
	 * Removes the store's checkout choices.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		foreach ( CheckoutSettings::settings() as $setting ) {
			delete_option( $setting->optionName() );
		}

		parent::tear_down();
	}

	/**
	 * Tests that the form read sends one statement cold and none warm.
	 *
	 * @since 0.2.0
	 */
	public function test_the_form_read_reads_the_choices_once(): void {
		$kernel = $this->coldKernel();
		$form   = $kernel->get( CheckoutForm::class );
		$cold   = $this->measured( 'The form read, cold', static fn() => $form->read( array(), Actor::user( 0 ) ) );
		$warm   = $this->measured( 'The form read, warm', static fn() => $form->read( array(), Actor::user( 0 ) ) );

		$this->assertSame( 1, $cold->count(), 'The cold form read.' );
		$this->assertSame( 1, $cold->matching( '/seocart_checkout_field_presence.*seocart_inventory_multi_location_fulfilment|seocart_inventory_multi_location_fulfilment.*seocart_checkout_field_presence/s' )->count(), 'The checkout group is read with the inventory group\'s settings, in one statement.' );
		$this->assertSame( 0, $warm->count(), 'The warm form read.' );
	}

	/**
	 * Tests that each staff read sends one statement, cold.
	 *
	 * @since 0.2.0
	 */
	public function test_each_staff_read_sends_one_statement(): void {
		$fields = $this->coldKernel()->get( CheckoutSettingsService::class );

		$this->assertSame( 1, $this->measured( 'The fields read', static fn() => $fields->getFields( array(), Actor::user( 1 ) ) )->count() );

		$limits = $this->coldKernel()->get( CheckoutSettingsService::class );

		$this->assertSame( 1, $this->measured( 'The order limits read', static fn() => $limits->getOrderLimits( array(), Actor::user( 1 ) ) )->count() );
	}

	/**
	 * Tests that a staff change of a choice already saved sends two statements and opens no transaction.
	 *
	 * @since 0.2.0
	 */
	public function test_a_staff_change_sends_two_statements(): void {
		$this->coldKernel()->get( CheckoutSettingsService::class )->updateFields( self::change( 'phone', 'required' ), Actor::user( 1 ) );
		$this->coldKernel()->get( CheckoutSettingsService::class )->updateOrderLimits( array( 'limits' => array() ), Actor::user( 1 ) );

		$service = $this->coldKernel()->get( CheckoutSettingsService::class );
		$fields  = $this->measured( 'A change of the fields', static fn() => $service->updateFields( self::change( 'company', 'optional' ), Actor::user( 1 ) ) );
		$limits  = $this->measured(
			'A change of the order limits',
			static fn() => $service->updateOrderLimits(
				array(
					'limits' => array(
						array(
							'currency'           => 'USD',
							'min_subtotal_minor' => 100,
							'max_subtotal_minor' => null,
						),
					),
				),
				Actor::user( 1 )
			)
		);

		foreach ( array( $fields, $limits ) as $log ) {
			$this->assertSame( 2, $log->count() );
			$this->assertSame( array( 'SELECT', 'UPDATE' ), array_map( static fn( string $sql ): string => strtok( $sql, ' ' ), $log->sqls() ) );
			$this->assertSame( 0, $log->matching( '/^START TRANSACTION/' )->count() );
		}
	}

	/**
	 * Tests that a store's first change of its fields sends two statements, the read that finds no row and the insert that creates it, takes no lock and replaces no row.
	 *
	 * @since 0.2.0
	 */
	public function test_a_first_change_sends_two_statements(): void {
		$service = $this->coldKernel()->get( CheckoutSettingsService::class );
		$first   = $this->measured( 'The first change of the fields', static fn() => $service->updateFields( self::change( 'company', 'optional' ), Actor::user( 1 ) ) );

		$this->assertSame( array( 'SELECT', 'INSERT' ), array_map( static fn( string $sql ): string => (string) strtok( $sql, ' ' ), $first->sqls() ), 'The first change\'s statements.' );
		$this->assertSame( 0, $first->matching( '/GET_LOCK|RELEASE_LOCK|ON DUPLICATE KEY UPDATE/' )->count(), 'A first change takes no lock and replaces no row.' );
	}

	/**
	 * Returns the kernel's container of a new request: no object cache but the options WordPress loads at boot.
	 *
	 * @since 0.2.0
	 *
	 * @return Container The container.
	 */
	private function coldKernel(): Container {
		$kernel = $this->kernelOver( $this->db, $this->tokens );

		wp_cache_flush();
		wp_load_alloptions();

		return $kernel;
	}

	/**
	 * Runs work, and returns its statements after printing them.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name The measurement's name.
	 * @param \Closure $work The work.
	 * @return QueryLog The statements.
	 */
	private function measured( string $name, \Closure $work ): QueryLog {
		$log = $this->captureQueries( $work );

		fwrite( STDOUT, sprintf( "\n%s: %d statements.\n  %s\n", $name, $log->count(), implode( "\n  ", array_map( static fn( string $sql ): string => substr( (string) preg_replace( '/\s+/', ' ', $sql ), 0, 160 ), $log->sqls() ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.

		return $log;
	}

	/**
	 * Returns a change of one field's presence.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key      The field.
	 * @param string $presence Its presence.
	 * @return array{fields: list<array{key: string, presence: string}>} The input.
	 */
	private static function change( string $key, string $presence ): array {
		return array(
			'fields' => array(
				array(
					'key'      => $key,
					'presence' => $presence,
				),
			),
		);
	}
}
