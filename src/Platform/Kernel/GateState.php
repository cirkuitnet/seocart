<?php
/**
 * GateState: where the current site's schema stands against the code, as the schema gate sees it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * The four answers of the schema gate.
 *
 * Owns one fact: the vocabulary of the gate. Every state but Ready refuses commerce writes; the
 * states differ in what the merchant is told and in what the plugin tries by itself. The backing
 * values are the `reason` a refused write carries.
 *
 * @since 0.1.0
 */
enum GateState: string {

	/**
	 * The schema matches the code, or only migrations that allow trading while they run are outstanding.
	 *
	 * @since 0.1.0
	 */
	case Ready = 'ready';

	/**
	 * The site has no installation record yet, or it was lost; the next admin, command-line or cron request installs.
	 *
	 * @since 0.1.0
	 */
	case NotInstalled = 'not_installed';

	/**
	 * The code carries a migration the store cannot trade without, and it has not been applied.
	 *
	 * @since 0.1.0
	 */
	case CodeNewer = 'code_newer';

	/**
	 * A newer version of the plugin migrated this database; this code does not know its schema.
	 *
	 * @since 0.1.0
	 */
	case SchemaNewer = 'schema_newer';
}
