/**
 * The payment section's two Store API writes: the placement and the resume of its payment.
 *
 * They are the only requests the host sends. Each carries the Store API's request header, the
 * browser's own cookies, the cart's among them, and for a logged-in shopper the nonce the page was
 * given (`X-WP-Nonce`), without which WordPress does not run the request as that shopper. The
 * checkout's details, the payment method chosen among them, are written by the checkout itself.
 */

/**
 * The Store API's namespace under the REST root.
 *
 * @type {string}
 */
const NAMESPACE = 'seocart/store/v1';

/**
 * Creates the client.
 *
 * @param {string}      restRoot The REST root, ending in a slash.
 * @param {Function}    fetch    The browser's `fetch`.
 * @param {string|null} nonce    The logged-in shopper's REST nonce; null for a guest.
 * @return {{place: function(Object): Promise<Object>, resume: function(string): Promise<Object>}} The two writes;
 *         each resolves to `{ status, body, retryAfter }`, or `{ network: true }` when no answer arrived.
 */
export function createStoreApi( restRoot, fetch, nonce ) {
	const session = nonce ? { 'X-WP-Nonce': nonce } : {};

	/**
	 * Sends one write.
	 *
	 * @param {string} route   The route under the namespace.
	 * @param {Object} body    The request's body.
	 * @param {Object} headers Its own headers.
	 * @return {Promise<Object>} What it came to.
	 */
	const post = async ( route, body, headers = {} ) => {
		let response;

		try {
			response = await fetch( `${ restRoot }${ NAMESPACE }${ route }`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-SEOCart-Store': '1',
					...session,
					...headers,
				},
				body: JSON.stringify( body ),
			} );
		} catch {
			return { network: true };
		}

		let parsed = null;

		try {
			parsed = await response.json();
		} catch {
			// Not JSON, such as a proxy's error page: the status alone says what happened.
		}

		return {
			status: response.status,
			body: parsed,
			retryAfter: Number( response.headers.get( 'Retry-After' ) ) || 0,
		};
	};

	return {
		// A placement read back after a reload has no payment data: it is asked again with none.
		place: ( placement ) =>
			post(
				'/checkout',
				{
					cart_version: placement.cartVersion,
					grand_total_minor: placement.amountMinor,
					currency: placement.currency,
					payment_data: placement.paymentData ?? {},
				},
				{ 'Idempotency-Key': placement.key }
			),
		resume: ( intentUuid ) =>
			post( '/checkout/resume', { intent_uuid: intentUuid } ),
	};
}
