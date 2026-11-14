/**
 * The placement's idempotency key, kept in the tab's `sessionStorage` until the placement settles.
 *
 * A placement is sent with a key the browser mints, and the store answers a request with a key it
 * has seen as it answered the first time, the order key included. So the key, and the request it
 * was sent with, are kept until an answer settles the placement: sent again after a network
 * failure, a server error, an unavailable gateway or a placement still in progress, after a reload
 * in the middle of a bank's challenge, and on the shopper's return from a provider's page, they
 * get the one order's answer back. A settled, declined or voided answer, a cart that changed and
 * a refusal end the key: the next placement gets a fresh one.
 *
 * The kept placement holds the key and what the request sent (the cart's version, the total, the
 * currency, the payment data) with the gateway and, once the store names it, the payment's uuid.
 * The order's key is never kept. When the browser keeps no storage, the key lives as long as the
 * page.
 */

/**
 * The `sessionStorage` item the kept placement is written to.
 *
 * @type {string}
 */
export const STORAGE_KEY = 'seocart:placement';

/**
 * The kinds of answer (see answers.js) after which the key is sent again.
 *
 * @type {string[]}
 */
const KEEPING = [ 'network', 'server', 'unavailable', 'in_progress', 'acting' ];

/**
 * Tells whether the key outlives an answer of a kind.
 *
 * @param {string} kind The answer's kind.
 * @return {boolean} True when the next attempt sends the same key.
 */
export function keepsKey( kind ) {
	return KEEPING.includes( kind );
}

/**
 * Tells whether a kept placement is still the one the shopper is placing: the same total in the
 * same currency, by the same gateway.
 *
 * The cart's version is not compared: the placement itself moves it on, so a page reloaded or
 * returned to always reads a newer version than the one kept. A kept placement that never reached
 * the store is answered `cart.version_stale` when sent again, which ends its key.
 *
 * @param {Object|null} kept   The kept placement.
 * @param {Object}      inputs The placement that would be made now: amountMinor, currency, gatewayId.
 * @return {boolean} True when the kept one is sent again instead.
 */
export function samePlacement( kept, inputs ) {
	return (
		!! kept &&
		kept.amountMinor === inputs.amountMinor &&
		kept.currency === inputs.currency &&
		kept.gatewayId === inputs.gatewayId
	);
}

/**
 * Tells whether a value read back is a kept placement.
 *
 * @param {*} value The value.
 * @return {boolean} True when it is.
 */
function isKept( value ) {
	return (
		!! value &&
		'string' === typeof value.key &&
		Number.isInteger( value.cartVersion ) &&
		Number.isInteger( value.amountMinor ) &&
		'string' === typeof value.currency &&
		'string' === typeof value.gatewayId &&
		!! value.paymentData &&
		'object' === typeof value.paymentData
	);
}

/**
 * Creates the keeper of one tab's placement.
 *
 * @param {Object}             scope The global object, whose `sessionStorage` may be missing or refuse access.
 * @param {function(): string} mint  Returns a fresh key.
 * @return {Object} The keeper: read(), begin(), write(), clear().
 */
export function createKeeper( scope, mint ) {
	let memory = null;

	const storage = () => {
		try {
			return scope.sessionStorage ?? null;
		} catch {
			return null;
		}
	};

	const keeper = {
		/**
		 * Returns the kept placement, or null.
		 *
		 * @return {Object|null} The placement.
		 */
		read() {
			try {
				const raw = storage()?.getItem( STORAGE_KEY );

				if ( raw ) {
					const parsed = JSON.parse( raw );

					return isKept( parsed ) ? parsed : null;
				}
			} catch {
				// Unreadable or not JSON: what the page itself kept is all there is.
			}

			return memory;
		},

		/**
		 * Keeps a placement.
		 *
		 * @param {Object} placement The placement.
		 */
		write( placement ) {
			memory = placement;

			try {
				storage()?.setItem( STORAGE_KEY, JSON.stringify( placement ) );
			} catch {
				// Storage refused (private browsing, a full quota): the page keeps it.
			}
		},

		/**
		 * Starts a placement with a fresh key, and keeps it.
		 *
		 * @param {Object} inputs What the placement sends: cartVersion, amountMinor, currency,
		 *                        gatewayId and paymentData.
		 * @return {Object} The placement.
		 */
		begin( inputs ) {
			const placement = { key: mint(), ...inputs, intentUuid: null };

			keeper.write( placement );

			return placement;
		},

		/**
		 * Ends the key.
		 */
		clear() {
			memory = null;

			try {
				storage()?.removeItem( STORAGE_KEY );
			} catch {
				// Nothing kept there.
			}
		},
	};

	return keeper;
}
