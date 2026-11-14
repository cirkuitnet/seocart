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
 * placement still in progress or still pending, a session that needs a fresh nonce, while the
 * shopper must act, after a reload, and on the shopper's return from a provider's page; each gets
 * the one order's answer back. A settled, declined or voided answer, a cart that changed and a
 * refusal end the key: the next placement gets a fresh one. When the browser keeps no storage,
 * the key lives as long as the page.
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
 * The cart's version is not compared: the placement itself moves it on, so a page reloaded or
 * returned to always reads a newer version than the one kept. A kept placement that never reached
 * the store is answered `cart.version_stale` when sent again, which ends its key.
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
