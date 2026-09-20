<?php
/**
 * Runs when SEOCart is deleted from the Plugins screen
 *
 * Uninstalling SEOCart preserves store data by default: orders, customers and payment
 * records are business and tax records, and deleting a plugin must never destroy them by
 * surprise. Erasing store data is a separate, explicit action inside the plugin — typed
 * confirmation, a grace period, and a verified, resumable job — not a side effect of this
 * file.
 *
 * The repository bootstrap creates no tables, options, roles or scheduled jobs, so there
 * is nothing to clean up yet. All destructive cleanup the plugin ever performs on
 * uninstall belongs in this file; `register_uninstall_hook()` is never used.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
