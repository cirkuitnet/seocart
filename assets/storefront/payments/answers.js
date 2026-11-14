/**
 * What the Store API's answers to the payment section's two writes mean for the host: the
 * placement (`POST /checkout`) and the resume of its payment (`POST /checkout/resume`).
 *
 * Each answer is one of these kinds:
 *
 * - `settled`: an order was placed and the payment came to an outcome the shopper moves on with:
 *   approved, processing, or duplicate (the store had settled it already);
 * - `pending`: the placement is still with the gateway, or its request died there; the order
 *   waits as placed until the store learns the outcome;
 * - `acting`: the shopper must act for the payment provider (requires_action);
 * - `declined`: the payment was declined, the order failed and the cart is open again;
 * - `voided`: the payment's window ran out and it was voided; the cart is open again;
 * - `stale`: the cart changed since the shopper read it (cart.version_stale,
 *   checkout.totals_changed). Not a payment failure: the island is told nothing;
 * - `session`: the logged-in shopper's nonce is missing or expired; nothing was written;
 * - `in_progress`: another request with the same key is placing the order; wait, and send it again;
 * - `unavailable`: the gateway did not answer; the order may wait as placed;
 * - `server` and `network`: no answer the host can read;
 * - `refused`: anything else the Store API refused, by its code.
 */

/**
 * The outcomes after which the shopper moves on to the order.
 *
 * @type {string[]}
 */
const SETTLED = [ 'approved', 'processing', 'duplicate' ];

/**
 * The Store API codes that mean the cart changed under the shopper.
 *
 * @type {string[]}
 */
const STALE = [ 'cart.version_stale', 'checkout.totals_changed' ];

/**
 * The codes of a request whose logged-in session needs a fresh nonce: the Store API's, and
 * WordPress's own for a nonce that no longer verifies.
 *
 * @type {string[]}
 */
const SESSION = [ 'store_api.nonce_missing', 'rest_cookie_invalid_nonce' ];

/**
 * Returns the kind of an answer.
 *
 * @param {Object}  response         What the request came to.
 * @param {boolean} response.network True when no answer arrived.
 * @param {number}  response.status  The HTTP status.
 * @param {Object}  response.body    The answer, parsed; null when it was not JSON.
 * @return {string} The kind.
 */
export function classify( { network, status, body } ) {
	if ( network ) {
		return 'network';
	}

	if ( status >= 200 && status < 300 ) {
		const outcome = body?.outcome;

		if ( SETTLED.includes( outcome ) ) {
			return 'settled';
		}

		if ( 'requires_action' === outcome ) {
			return 'acting';
		}

		// A placement declined or voided earlier is answered so again to a retry with its key.
		return [ 'pending', 'declined', 'voided' ].includes( outcome )
			? outcome
			: 'server';
	}

	const code = body?.code;

	if ( 'checkout.payment_declined' === code ) {
		return 'declined';
	}

	if ( STALE.includes( code ) ) {
		return 'stale';
	}

	if ( SESSION.includes( code ) ) {
		return 'session';
	}

	if ( 'checkout.placement_in_progress' === code ) {
		return 'in_progress';
	}

	if ( 'checkout.gateway_unavailable' === code ) {
		return 'unavailable';
	}

	return status >= 500 ? 'server' : 'refused';
}
