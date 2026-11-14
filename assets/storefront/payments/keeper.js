/**
 * The placement's idempotency key, kept in the tab's `sessionStorage` until the placement settles.
 *
 * A placement is sent with a key the browser mints, and the store answers a request with a key it
 * has seen as it answered the first time, the order key included. The store knows a placement by
 * its key, its cart version, its total and its currency, never by the payment data, so those are
 * what the tab keeps: `{ key, cartVersion, amountMinor, currency, gatewayId, intentUuid }`, the
 * gateway's id telling which method it was placed with and, once the store names it, the uuid of
 * the payment the shopper acts for. The payment data the island confirmed, a payment token among
 * it, stays in the page's memory and is never written: a placement read back after a reload has
 * none, and is asked again with none. The order's key is never kept either.
 *
 * The key is sent again after a network failure, a server error, an unavailable gateway, a
 * placement still in progress or still pending, a session that needs a fresh nonce, too many
 * requests, while the shopper must act, after a reload, and on the shopper's return from a
 * provider's page; each gets the one order's answer back.
 *
 * A kept placement ends, and the next placement gets a fresh key, only when the store has
 * answered for it: a settled, declined or voided answer; a stale cart, which is how the store
 * answers a key it never placed with once the cart has moved on; another refusal. A placement for
 * another total, currency or gateway replaces it, when there is one to make: a cart with nothing to
 * place never replaces it (nothingToPlace()). Nothing else ends it, a reload included: a page
 * loaded while the cart is still at the kept version cannot tell a request that never arrived
 * from one still on its way, so the kept key is sent with the next placement, which then either
 * places for the first time or is answered the order the earlier request placed. The tab's
 * storage ends with the tab; when the browser keeps no storage, the key lives as long as the page.
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
const KEEPING = [
	'network',
	'server',
	'unavailable',
	'in_progress',
	'pending',
	'session',
	'rate_limited',
	'acting',
];

/**
 * The members of a kept placement that are written to storage, and only those.
 *
 * @type {string[]}
 */
const STORED = [
	'key',
	'cartVersion',
	'amountMinor',
	'currency',
	'gatewayId',
	'intentUuid',
];

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
 * The cart's version is not compared: a placement that committed moves it on, so a page reloaded
 * or returned to after one reads a newer version than the one kept. A kept placement sent again
 * is answered as the store holds it: the order it placed or, when it never reached the store, a
 * first placement at the version it was kept with and `cart.version_stale` at any other, which
 * ends its key.
 *
 * @param {Object|null} kept   The kept placement.
 * @param {Object}      inputs The placement that would be made now: amountMinor, currency, gatewayId.
 * @return {boolean} True when it is sent under the kept key.
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
 * Tells whether a cart has nothing to place in a kept placement's stead: a cart the store holds no
 * lines in, at version 0 (one never written, or one an order was placed from), or one at a zero
 * total while the kept placement had a total to pay. Such a cart never replaces the kept
 * placement, which may be an order the store placed: the kept one is sent again instead.
 *
 * @param {Object} kept The kept placement.
 * @param {Object} cart The cart: version, amountMinor.
 * @return {boolean} True when the kept placement is sent in its stead.
 */
export function nothingToPlace( kept, cart ) {
	return (
		0 === cart.version || ( 0 === cart.amountMinor && kept.amountMinor > 0 )
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
		( null === value.intentUuid || 'string' === typeof value.intentUuid )
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
		 * Returns the kept placement: the page's own, with the payment data it sent, or else the
		 * one the tab stored, with `paymentData` null; null when there is none.
		 *
		 * @return {Object|null} The placement.
		 */
		read() {
			if ( memory ) {
				return memory;
			}

			try {
				const raw = storage()?.getItem( STORAGE_KEY );
				const parsed = raw ? JSON.parse( raw ) : null;

				return isKept( parsed )
					? { ...parsed, paymentData: null }
					: null;
			} catch {
				// Unreadable or not JSON: nothing kept.
				return null;
			}
		},

		/**
		 * Keeps a placement: all of it in the page, its six members in the tab's storage.
		 *
		 * @param {Object} placement The placement.
		 */
		write( placement ) {
			memory = placement;

			try {
				storage()?.setItem(
					STORAGE_KEY,
					JSON.stringify(
						Object.fromEntries(
							STORED.map( ( name ) => [
								name,
								placement[ name ] ?? null,
							] )
						)
					)
				);
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
