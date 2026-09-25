<?php
/**
 * TranslationWatcher: hears that a post's language or translation group changed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Localization;

defined( 'ABSPATH' ) || exit;

/**
 * Receives the changes a multilingual plugin makes to a post's language or translation group, which the post lifecycle turns into catalog commands.
 *
 * Owns one fact: the one event an adapter reports. What changed is read again through
 * PostLocales, so a watcher never trusts a plugin's own account of it.
 *
 * @since 0.1.0
 */
interface TranslationWatcher {

	/**
	 * Hears that a post's language or translation group changed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 */
	public function translationsChanged( int $postId ): void;
}
