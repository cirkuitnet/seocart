<?php
/**
 * SettingsError: the error catalog of the settings module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors reading or writing a setting can end in.
 *
 * This enum owns one fact: how a failed settings read or write is reported. A value refused by a
 * setting's own check is reported with that check's code, and a value that does not fit the field
 * never reaches the store from a client: the operation's schema refuses it first.
 *
 * @since 0.1.0
 */
enum SettingsError: string implements ErrorCode {

	/**
	 * A document was written by someone else after the writer read it; the write changed nothing.
	 *
	 * @since 0.1.0
	 */
	case VersionConflict = 'settings.version_conflict';

	/**
	 * An option holds a value its setting cannot hold, so the setting cannot be read.
	 *
	 * @since 0.1.0
	 */
	case StoredValueInvalid = 'settings.stored_value_invalid';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::VersionConflict,
				409,
				static fn(): string =>
					/* translators: %1$s: The machine name of a settings group, for example capability_grants. */
					__( 'The %1$s settings were changed by someone else after you read them, so your change was not saved. Read them again, then repeat your change.', 'seocart' ),
				array( 'group' )
			),
			new ErrorDefinition(
				self::StoredValueInvalid,
				500,
				static fn(): string =>
					/* translators: %1$s: The name of a WordPress option, for example seocart_international_base_currency. */
					__( 'The option %1$s holds a value SEOCart cannot use. Save the setting again to replace it.', 'seocart' ),
				array( 'option' )
			),
		);
	}
}
