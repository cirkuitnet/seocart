<?php
/**
 * StoreSession: answers the Store API's session read
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Platform\Authorization\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * The service behind `store_api.get_session`.
 *
 * Owns one fact: what the session read answers. The actor is the user WordPress authenticated
 * the request as, which the REST adapter names; the nonce is created for that same user. It reads
 * and writes nothing else, and sets no cookie.
 *
 * @since 0.1.0
 */
final class StoreSession {

	/**
	 * Returns a fresh nonce and the user it is for.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The prepared input: nothing.
	 * @param Actor                $actor The user WordPress authenticated the request as.
	 * @return array{nonce: string, user_id: int} The session, keyed by wire name.
	 */
	public function describe( array $input, Actor $actor ): array {
		unset( $input );

		return array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'user_id' => $actor->userId(),
		);
	}
}
