<?php
/**
 * ReportCode: the machine codes the storefront writes log lines under
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the log, never a shopper.
 *
 * This enum owns one fact: what the storefront reports without stopping the page. None is ever
 * thrown, none has a row in the error table, and none may spell a code the error table or another
 * module's reports use; a test composes them and checks it.
 *
 * @since 0.2.0
 */
enum ReportCode: string {

	/**
	 * A storefront file's list of dependencies and version, the `.asset.json` file the build writes
	 * beside it, is missing or unreadable, so the file was not loaded. A warning: the block that
	 * asked for it rendered its markup without it. The context names the file.
	 *
	 * @since 0.2.0
	 */
	case AssetUnreadable = 'storefront.asset_unreadable';
}
