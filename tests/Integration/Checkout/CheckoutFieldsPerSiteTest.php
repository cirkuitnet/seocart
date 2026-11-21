<?php
/**
 * Tests that each site of a network has its own checkout field presences, its own extension fields and its own stored values
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\CheckoutRules;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Application\ExtensionFields;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Inventory\Application\InventorySettings;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;
use SEOCart\Tests\Support\DatabaseTestCase;

/**
 * On a network, a network-active extension registers its fields on each site the rules are needed on, each site keeps its own presences, and the values a site's shoppers give are kept in that site's own tables. Runs under WP_MULTISITE=1 and skips otherwise.
 *
 * Planted violation, shown red and removed: in CheckoutRules::fields(), keep the fields in a
 * static property shared by every instance: the second site's rules then answer the first site's
 * registrations, and the action fires once for the network.
 *
 * @since 0.2.0
 */
final class CheckoutFieldsPerSiteTest extends DatabaseTestCase {

	/**
	 * How many times the registration action fired.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $fired = 0;

	/**
	 * Skips on a single site; listens with the fixture extension and counts the action.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs on a network only: set WP_MULTISITE=1.' );
		}

		AcmeCheckoutFields::listen();
		add_action( CheckoutFieldRegistry::ACTION, array( $this, 'countFiring' ) );
	}

	/**
	 * Stops listening.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		remove_action( CheckoutFieldRegistry::ACTION, array( $this, 'countFiring' ) );
		AcmeCheckoutFields::forget();

		parent::tear_down();
	}

	/**
	 * Counts a firing of the action.
	 *
	 * @since 0.2.0
	 */
	public function countFiring(): void {
		++$this->fired;
	}

	/**
	 * Tests two sites: each its own presences of the extension's fields, the action fired for each site's rules, and each site's values in its own tables.
	 *
	 * @since 0.2.0
	 */
	public function test_each_site_has_its_own_presences_and_values(): void {
		$second = self::factory()->blog->create();

		$this->choose( array( AcmeCheckoutFields::PO_NUMBER => 'required' ) );

		$main = $this->presences();

		switch_to_blog( $second );

		try {
			$this->choose( array( AcmeCheckoutFields::GIFT_WRAP => 'hidden' ) );

			$other  = $this->presences();
			$tables = array( $this->db->table( CheckoutTables::SESSIONS ), $this->db->table( OrderTables::FIELD_VALUES ) );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( array( 'required', 'optional' ), array( $main[ AcmeCheckoutFields::PO_NUMBER ], $main[ AcmeCheckoutFields::GIFT_WRAP ] ), 'The first site\'s presences.' );
		$this->assertSame( array( 'optional', 'hidden' ), array( $other[ AcmeCheckoutFields::PO_NUMBER ], $other[ AcmeCheckoutFields::GIFT_WRAP ] ), 'The second site\'s presences.' );
		$this->assertSame( 2, $this->fired, 'The extension registers on each site its rules are needed on.' );
		$this->assertNotSame( array( $this->db->table( CheckoutTables::SESSIONS ), $this->db->table( OrderTables::FIELD_VALUES ) ), $tables, 'Two sites keep their shoppers\' values in one table.' );
	}

	/**
	 * Stores the current site's presence choices, as the staff's change keeps them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $presences Presence by field key.
	 */
	private function choose( array $presences ): void {
		( new SettingsStore( Settings::registry(), $this->db ) )->writeScalars( array( CheckoutSettings::FIELD_PRESENCE => (string) wp_json_encode( (object) $presences ) ) );
	}

	/**
	 * Returns the current site's presence of each declared field, by key, through rules of the site's own.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The presences.
	 */
	private function presences(): array {
		wp_cache_flush();

		$rules     = new CheckoutRules( new SettingsStore( Settings::registry(), $this->db ), InventorySettings::settings(), BuiltInFields::all(), new ExtensionFields( array(), $this->reporter() ), $this->reporter() );
		$fields    = $rules->current();
		$presences = array();

		foreach ( $fields->all() as $field ) {
			$presences[ $field->key ] = $fields->presenceOf( $field )->value;
		}

		$this->assertSame( Presence::Required->value, $presences['email'] );

		return $presences;
	}
}
