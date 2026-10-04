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
use SEOCart\Platform\Secrets\SecretKeys;

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
	 * The settings declared only once something registers them, such as the payment gateways',
	 * join it through the late declarations, the first time a read needs them: a registry the
	 * store builds for a cart, the settings screen or the canary never asks for them.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The late declarations were added.
	 *
	 * @param callable|null $late Optional. Returns the late declarations, as SettingsRegistry takes them. Default none.
	 * @return SettingsRegistry The registry.
	 *
	 * @phpstan-param (callable(): array{settings: list<Setting>, documents: array<string, string>})|null $late
	 */
	public static function registry( ?callable $late = null ): SettingsRegistry {
		return new SettingsRegistry(
			array_merge(
				InternationalSettings::settings(),
				OptionGrantLedger::settings(),
				SecretKeys::settings()
			),
			array(
				OptionGrantLedger::GROUP => OptionGrantLedger::PURPOSE,
				SecretKeys::GROUP        => SecretKeys::PURPOSE,
			),
			$late
		);
	}
}
