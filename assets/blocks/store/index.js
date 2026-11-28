/**
 * `@seocart/store`: the storefront's session and cart, as the Interactivity store `seocart/store`.
 *
 * It owns every request the storefront blocks send to the Store API (client.js, queue.js), the
 * cart's version and the nonce, and the one function that pushes onto the data layer
 * (data-layer.js). Its configuration is printed by the page, the same for every
 * visitor: `restRoot`, `nonceUrl`, `locale`, `currency`, `priceVersion` and `strings`, every
 * sentence the storefront shows, which a module names by its key (notice.js).
 *
 * State: `cart`, the last cart answer taken, or null before one is known; `cartStatus`, `unknown`,
 * `reading`, `ready` or `failed`; `pending`, whether a write is out or waiting; `lastAdded`,
 * `{ variantId, quantity }` of the last add the store accepted; `notice`, what the last write that
 * failed has to say. Actions: `addLines( lines )` and `readCart()`, each resolving to
 * `{ ok: true, cart }` or `{ ok: false, notice }`. Nothing is sent when a page loads: the first
 * request is the shopper's first add.
 */

/**
 * WordPress dependencies
 *
 * WordPress's own module: the build leaves it out and lists it in the .asset.json file, and
 * WordPress puts it in the page's import map, so it is not installed or listed as a package.
 */
/* eslint-disable import/no-unresolved */
import { store, getConfig } from '@wordpress/interactivity';
/* eslint-enable import/no-unresolved */

/**
 * Internal dependencies
 */
import { createClient } from './client';
import { createQueue } from './queue';

export { text, noticeText } from './notice';
export { pushEvent, item, whenShown } from './data-layer';

/**
 * The store's name.
 *
 * @type {string}
 */
const STORE = 'seocart/store';

/**
 * The one queue of the page, created on the first request.
 *
 * @type {Object|null}
 */
let queue = null;

/**
 * How many writes are out or waiting.
 *
 * @type {number}
 */
let writing = 0;

/**
 * Returns the page's queue, created on first use from the store's configuration.
 *
 * @return {Object} The queue.
 */
function cartQueue() {
	if ( null === queue ) {
		const { restRoot, nonceUrl } = getConfig( STORE );

		queue = createQueue( {
			client: createClient( {
				restRoot,
				nonceUrl,
				fetch: ( ...request ) => window.fetch( ...request ),
			} ),
			held: () => state.cart,
			adopt: ( cart ) => {
				state.cart = cart;
				state.cartStatus = 'ready';
			},
		} );
	}

	return queue;
}

export const { state, actions } = store( STORE, {
	state: {
		cart: null,
		cartStatus: 'unknown',
		pending: false,
		lastAdded: null,
		notice: null,
	},
	actions: {
		*addLines( lines ) {
			writing++;
			state.pending = true;
			state.notice = null;

			const outcome = yield cartQueue().add( lines );

			writing--;
			state.pending = writing > 0;

			if ( outcome.ok ) {
				state.lastAdded = {
					variantId: lines[ 0 ].variant_id,
					quantity: lines[ 0 ].quantity,
				};
			} else {
				state.notice = outcome.notice;
			}

			return outcome;
		},
		*readCart() {
			state.cartStatus = 'reading';

			const outcome = yield cartQueue().read();

			state.cartStatus = outcome.ok ? 'ready' : 'failed';

			return outcome;
		},
	},
} );
