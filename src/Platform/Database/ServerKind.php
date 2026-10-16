<?php
/**
 * ServerKind: the two database servers the plugin runs on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL or MariaDB.
 *
 * Owns one fact: the names the two servers go by wherever a choice depends on which one
 * answers, such as a query-plan allow-list entry that holds on one of them only. The backing
 * values are those names.
 *
 * @since 0.2.0
 */
enum ServerKind: string {

	/**
	 * MySQL, and every server whose version string does not name MariaDB.
	 *
	 * @since 0.2.0
	 */
	case MySQL = 'mysql';

	/**
	 * MariaDB.
	 *
	 * @since 0.2.0
	 */
	case MariaDB = 'mariadb';
}
