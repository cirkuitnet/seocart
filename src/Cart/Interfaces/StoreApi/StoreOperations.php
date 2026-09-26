<?php
/**
 * StoreOperations: the operations of the Store API
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the operations of the Store API, `seocart/store/v1`.
 *
 * Owns one fact: how the Store API's operations are offered to clients. Each is public, so each is
 * a route of the Store API only, never an ability or a command.
 *
 * `store_api.get_session`, `GET seocart/store/v1/session`, is how a storefront gets a REST nonce
 * without one being written into a cached page. It answers with a fresh `wp_rest` nonce for the
 * user WordPress authenticated the request as, and that user's id: 0 for a guest. A client that
 * believes it is logged in and reads 0 knows that its login was not honoured — a request that
 * carries the login cookie without a nonce is a guest's — and fetches a nonce for its login the way
 * WordPress's own screens do. The answer never mints a nonce from the login cookie alone: WordPress
 * lets a page on another origin read a REST answer, so such a nonce would leak. The response is
 * never cached and sets no cookie.
 *
 * Declarations are data: building a definition calls no WordPress function.
 *
 * @since 0.1.0
 */
final class StoreOperations {

	/**
	 * The id of the session read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GET_SESSION = 'store_api.get_session';

	/**
	 * The route of the session read, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SESSION_ROUTE = '/session';

	/**
	 * Builds the session read.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function getSession(): OperationDefinition {
		return new OperationDefinition(
			id: self::GET_SESSION,
			label: static fn(): string => __( 'Get the session', 'seocart' ),
			summary: 'Returns a fresh REST nonce for the user WordPress authenticated the request as, and that user\'s id, 0 for a guest, so a storefront can roll its nonce forward and notice a login that was not honoured.',
			input: array(),
			output: new ResourceSchema(
				'StoreSession',
				array(
					new FieldSpec(
						name: 'nonce',
						type: FieldType::String,
						description: 'A fresh wp_rest nonce for the user the request was authenticated as. Send it as the X-WP-Nonce header of the next request.',
						label: static fn(): string => __( 'Nonce', 'seocart' ),
						example: '2f1e4c9a7b',
						required: true
					),
					new FieldSpec(
						name: 'user_id',
						type: FieldType::Integer,
						description: 'The WordPress user the request was authenticated as; 0 for a guest, and for a request that carried a login cookie without a nonce.',
						label: static fn(): string => __( 'User', 'seocart' ),
						example: 0,
						required: true,
						minimum: 0
					),
				)
			),
			capability: null,
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( StoreSession::class, 'describe' ),
			rest: new RestBinding( self::SESSION_ROUTE, store: true )
		);
	}
}
