<?php
/**
 * SettingsService: the application service behind the settings operations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes the exposed settings, for the settings operations.
 *
 * This class owns one fact: what the settings operations do. Reading returns every exposed
 * setting, its stored value or its default. Changing writes the exposed settings the input names
 * — an absent one is left as it is — and answers like a read, so the client sees the settings as
 * they are now. The operation's permission check has already required the settings capability;
 * the input has already been validated against the schema compiled from the same fields. Each
 * method receives the actor the surface names, as every operation's service does; no setting
 * exposed so far asks for more than the settings capability, so neither method reads it yet.
 *
 * @since 0.1.0
 */
final class SettingsService {

	/**
	 * The settings.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsRegistry
	 */
	private SettingsRegistry $registry;

	/**
	 * The store.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry $registry The settings.
	 * @param SettingsStore    $store    The store of the current site.
	 */
	public function __construct( SettingsRegistry $registry, SettingsStore $store ) {
		$this->registry = $registry;
		$this->store    = $store;
	}

	/**
	 * Returns every exposed setting.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when an option holds a value its
	 *                        setting cannot hold.
	 *
	 * @param array<string, mixed> $input The prepared input: the operation declares none.
	 * @param Actor                $actor Who reads.
	 * @return array<string, int|string|null> Each exposed setting's value, keyed by name.
	 */
	public function get( array $input, Actor $actor ): array {
		unset( $input, $actor );

		return $this->store->values( $this->registry->exposed() );
	}

	/**
	 * Changes the exposed settings the input names, then returns every exposed setting.
	 *
	 * Every value is checked before any is written, so a change refused for one value saves none.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With a setting's own code when its check refuses a value, or
	 *                        SettingsError::StoredValueInvalid when a setting cannot be read back.
	 *
	 * @param array<string, mixed> $input The prepared input: new values, keyed by setting name.
	 * @param Actor                $actor Who changes the settings.
	 * @return array<string, int|string|null> Each exposed setting's value, keyed by name.
	 */
	public function update( array $input, Actor $actor ): array {
		$changes = array();

		foreach ( $this->registry->exposed() as $setting ) {
			if ( array_key_exists( $setting->name(), $input ) ) {
				$changes[ $setting->name() ] = $input[ $setting->name() ];
			}
		}

		$this->store->writeScalars( $changes );

		return $this->get( array(), $actor );
	}
}
