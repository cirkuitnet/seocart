<?php
/**
 * Settings: the list of every setting the plugin declares
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Platform\Authorization\OptionGrantLedger;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the registry of the plugin's settings.
 *
 * This class owns one fact: which settings the plugin declares. The store, the settings
 * operations, their generated documentation, the data registry and the tests all read this one
 * list. A module adds one line, naming the static method that returns its settings, and a module
 * whose settings form a document also names what the document holds:
 *
 *     InternationalSettings::settings(),
 *     OptionGrantLedger::GROUP => OptionGrantLedger::PURPOSE,
 *
 * @since 0.1.0
 */
final class Settings {

	/**
	 * Returns a new registry holding every setting. Reads and writes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return SettingsRegistry The registry.
	 */
	public static function registry(): SettingsRegistry {
		return new SettingsRegistry(
			array_merge(
				InternationalSettings::settings(),
				OptionGrantLedger::settings()
			),
			array(
				OptionGrantLedger::GROUP => OptionGrantLedger::PURPOSE,
			)
		);
	}
}
