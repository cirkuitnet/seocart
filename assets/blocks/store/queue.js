/**
 * The store's one queue of cart requests: one in flight at a time, the next waiting for its answer.
 *
 * A write carries the version of the cart held, or 0 when none is, and an answer replaces the cart
 * held only when its version is higher, so an older answer never shows over a newer one. The cart's
 * version is what makes a write safe to retry, and only a write whose base version the store
 * refused:
 *
 * - `cart.version_stale` as an add's first answer: the store changed nothing and names the cart's
 *   current version. "Add two of this" means the same whatever else the cart holds, so the add is
 *   sent once more at that version. A second stale answer is not resent: the cart is read and the
 *   shopper told it changed.
 * - No answer: the write may or may not have been applied, so it is never resent. The cart is read;
 *   when the read answers, the cart shown is the truth and the shopper is told the add could not be
 *   confirmed, and when it does not, that something went wrong.
 *
 * What the shopper is told is a notice, the name of a sentence of the store's configuration with
 * its values (notice.js), or a sentence the store's answer carries; the queue holds no words.
 */

/**
 * Internal dependencies
 */
import { notice } from './notice';

/**
 * The refusal of a write based on a version the cart has left.
 *
 * @type {string}
 */
export const STALE = 'cart.version_stale';

/**
 * Tells whether an answer is a refusal for the version.
 *
 * @param {Object} answer The answer.
 * @return {boolean} True for `cart.version_stale`.
 */
function stale( answer ) {
	return ! answer.network && STALE === answer.body?.code;
}

/**
 * Returns what to tell the shopper of a write the store refused.
 *
 * @param {Object} answer The refusal.
 * @return {Object} The notice: `{ key, values }`, or `{ message }` for a sentence the answer carries.
 */
export function refusal( answer ) {
	if ( answer.sessionExpired ) {
		return notice( 'session_expired' );
	}

	switch ( answer.body?.code ) {
		case 'store_api.rate_limited':
			return notice( 'rate_limited' );
		case 'cart.not_open':
			return notice( 'placing' );
		case 'cart.creation_limited':
		case 'cart.too_many_lines':
			return { message: String( answer.body.message ?? '' ) };
		default:
			return notice( 'failed' );
	}
}

/**
 * Creates the queue.
 *
 * @param {Object}   options        The queue's collaborators.
 * @param {Object}   options.client The Store API client (client.js).
 * @param {Function} options.held   Returns the cart held, or null when none is.
 * @param {Function} options.adopt  Receives a cart answer newer than the one held.
 * @return {{add: function(Array): Promise<Object>, read: function(): Promise<Object>}} The queue. Each
 *         call resolves to `{ ok: true, cart }` or `{ ok: false, notice }`.
 */
export function createQueue( { client, held, adopt } ) {
	let tail = Promise.resolve();

	/**
	 * Runs a request after the one before it has its answer.
	 *
	 * @param {Function} task The request.
	 * @return {Promise<Object>} What it came to.
	 */
	const queued = ( task ) => {
		const result = tail.then( task );

		tail = result.catch( () => undefined );

		return result;
	};

	/**
	 * Takes a cart answer, when it is newer than the cart held.
	 *
	 * @param {Object} cart The cart answered.
	 */
	const take = ( cart ) => {
		const current = held();

		if ( null === current || cart.version > current.version ) {
			adopt( cart );
		}
	};

	/**
	 * Reads the cart.
	 *
	 * @return {Promise<Object>} What it came to.
	 */
	const read = async () => {
		const answer = await client.send( 'GET', '/cart' );

		if ( answer.network || 200 !== answer.status ) {
			return { ok: false, notice: notice( 'failed' ) };
		}

		take( answer.body );

		return { ok: true, cart: answer.body };
	};

	/**
	 * Says what came of a write: its cart taken, or the notice for what went wrong.
	 *
	 * @param {Object} answer The write's last answer.
	 * @return {Promise<Object>} What it came to.
	 */
	const settle = async ( answer ) => {
		if ( answer.network ) {
			const now = await read();

			return {
				ok: false,
				notice: notice( now.ok ? 'unconfirmed' : 'failed' ),
			};
		}

		if ( 200 === answer.status ) {
			take( answer.body );

			return { ok: true, cart: answer.body };
		}

		if ( stale( answer ) ) {
			await read();

			return { ok: false, notice: notice( 'cart_changed' ) };
		}

		return { ok: false, notice: refusal( answer ) };
	};

	/**
	 * Adds lines, sent again once at the cart's current version after a first stale answer.
	 *
	 * @param {Array<Object>} lines The lines: `{ variant_id, quantity }`.
	 * @return {Promise<Object>} What it came to.
	 */
	const add = async ( lines ) => {
		const answer = await client.send( 'POST', '/cart/lines', {
			lines,
			cart_version: held()?.version ?? 0,
		} );

		if ( ! stale( answer ) ) {
			return settle( answer );
		}

		return settle(
			await client.send( 'POST', '/cart/lines', {
				lines,
				cart_version: answer.body.data.details.current_version,
			} )
		);
	};

	return {
		add: ( lines ) => queued( () => add( lines ) ),
		read: () => queued( read ),
	};
}
