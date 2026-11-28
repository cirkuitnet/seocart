/**
 * The storefront's client of the Store API: every request the store `seocart/store` sends.
 *
 * Each request carries the Store API's header (`X-SEOCart-Store: 1`), the browser's own cookies
 * (`credentials: 'same-origin'`), the cart's among them, and the REST nonce once the client holds
 * one. An answer is `{ status, body }`. A request that got no answer, an answer of 500 or more,
 * and one whose body is not JSON are `{ network: true }`: a write may or may not have been applied.
 *
 * The nonce step. A logged-in shopper's write is refused before anything runs, with
 * `store_api.nonce_missing` when it carries no nonce and `rest_cookie_invalid_nonce` when its nonce
 * has expired. The client then fetches one nonce from WordPress's `rest-nonce` action and sends the
 * same write once more with it; a refused write was not applied, so sending it again is safe. A
 * refusal the client cannot answer with a nonce is marked `sessionExpired`. The nonce comes only
 * from that action and from the `X-WP-Nonce` header of each answer, and is held in memory only,
 * never in the browser's storage.
 */

/**
 * The Store API's namespace under the REST root.
 *
 * @type {string}
 */
const NAMESPACE = 'seocart/store/v1';

/**
 * The refusals of a write that a fresh nonce answers.
 *
 * @type {string[]}
 */
const NONCE_REFUSALS = [
	'store_api.nonce_missing',
	'rest_cookie_invalid_nonce',
];

/**
 * What a nonce from WordPress looks like.
 *
 * @type {RegExp}
 */
const NONCE = /^[0-9a-f]{10}$/;

/**
 * Tells whether an answer refuses a write for its nonce.
 *
 * @param {Object} answer The answer.
 * @return {boolean} True for a refusal a fresh nonce answers.
 */
export function refusedForNonce( answer ) {
	return ! answer.network && NONCE_REFUSALS.includes( answer.body?.code );
}

/**
 * Creates the client.
 *
 * @param {Object}   options          The client's settings.
 * @param {string}   options.restRoot The REST root, ending in a slash.
 * @param {string}   options.nonceUrl The address of WordPress's `rest-nonce` action.
 * @param {Function} options.fetch    The browser's `fetch`.
 * @return {{send: function(string, string, Object=): Promise<Object>}} The client: send( method, route, body ).
 */
export function createClient( { restRoot, nonceUrl, fetch } ) {
	let nonce = null;

	/**
	 * Sends one request, once.
	 *
	 * @param {string} method The HTTP method.
	 * @param {string} route  The route under the namespace.
	 * @param {Object} body   The body, or undefined for none.
	 * @return {Promise<Object>} The answer.
	 */
	const request = async ( method, route, body ) => {
		const headers = { 'X-SEOCart-Store': '1' };
		let response;

		if ( undefined !== body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}

		if ( null !== nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}

		try {
			response = await fetch( `${ restRoot }${ NAMESPACE }${ route }`, {
				method,
				credentials: 'same-origin',
				headers,
				body: undefined === body ? undefined : JSON.stringify( body ),
			} );
		} catch {
			return { network: true };
		}

		nonce = response.headers.get( 'X-WP-Nonce' ) || nonce;

		if ( response.status >= 500 ) {
			return { network: true };
		}

		try {
			return { status: response.status, body: await response.json() };
		} catch {
			// Not JSON, such as a proxy's error page: no answer from the store.
			return { network: true };
		}
	};

	/**
	 * Fetches a nonce from WordPress's `rest-nonce` action.
	 *
	 * @return {Promise<boolean>} Whether a nonce is now held.
	 */
	const refreshNonce = async () => {
		try {
			const response = await fetch( nonceUrl, {
				credentials: 'same-origin',
			} );
			const fresh = response.ok ? ( await response.text() ).trim() : '';

			if ( ! NONCE.test( fresh ) ) {
				return false;
			}

			nonce = fresh;

			return true;
		} catch {
			return false;
		}
	};

	return {
		async send( method, route, body ) {
			const answer = await request( method, route, body );

			if ( ! refusedForNonce( answer ) ) {
				return answer;
			}

			if ( ! ( await refreshNonce() ) ) {
				return { ...answer, sessionExpired: true };
			}

			const again = await request( method, route, body );

			return refusedForNonce( again )
				? { ...again, sessionExpired: true }
				: again;
		},
	};
}
