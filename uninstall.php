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
 * So this file removes nothing. The plugin's tables stay, and so do its options: among
 * them the boot record, which keeps the installation's identity, so a store that is
 * reinstalled is recognised as the same store rather than as a copy of it. The plugin's
 * roles and the capabilities it granted stay too, as merchants may have assigned them.
 * WordPress deletes only a deactivated plugin, and deactivation is where the plugin stops
 * any work it has scheduled, so nothing of it runs after this point.
 *
 * All destructive cleanup the plugin ever performs on uninstall belongs in this file;
 * `register_uninstall_hook()` is never used.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
