/**
 * A scripted Store API for the store module's tests: a `fetch` that keeps one cart, refuses a write
 * based on a version the cart has left, asks a logged-in shopper's write for its nonce, and can lose
 * the answer of a write it applied, as a network can.
 */

const NONCE = '0123456789';

/**
 * Returns a response as `fetch` resolves it.
 *
 * @param {number} status  The status.
 * @param {*}      body    The body.
 * @param {Object} headers The headers, by name.
 * @return {Object} The response.
 */
function response( status, body, headers = {} ) {
	return {
		status,
		ok: status >= 200 && status < 300,
		headers: { get: ( name ) => headers[ name ] ?? null },
		json: async () => {
			if ( 'string' === typeof body ) {
				throw new SyntaxError( body );
			}

			return body;
		},
		text: async () => ( 'string' === typeof body ? body : '' ),
	};
}

/**
 * Creates the store.
 *
 * @param {Object}  options          The store's starting point.
 * @param {number=} options.version  The cart's version: 0 for no cart.
 * @param {Array=}  options.lines    The cart's lines.
 * @param {boolean} options.loggedIn Whether the shopper is logged in, so a write needs a nonce.
 * @return {Object} The store: `fetch`, what it was sent (`sent`), the cart, and settings a test changes.
 */
export function createFakeStore( {
	version = 0,
	lines = [],
	loggedIn = false,
} = {} ) {
	const store = {
		version,
		lines: lines.map( ( line ) => ( { ...line } ) ),
		sent: [],
		inFlight: 0,
		mostInFlight: 0,
		lose: 0,
		staleAlways: false,
		nonceAnswer: loggedIn ? NONCE : null,
		refuse: null,
		unavailable: [],
		unpriced: [],
	};

	const cart = () => ( {
		version: store.version,
		lines: store.lines.map( ( line ) => ( { ...line } ) ),
		promotion_codes: [],
		totals: {},
		unpriced_lines: store.unpriced,
		unavailable_lines: store.unavailable,
	} );

	const write = ( init ) => {
		const nonce = init.headers[ 'X-WP-Nonce' ];

		if ( loggedIn && NONCE !== nonce ) {
			return response( 403, {
				code:
					undefined === nonce
						? 'store_api.nonce_missing'
						: 'rest_cookie_invalid_nonce',
				message: 'refused',
				data: { status: 403 },
			} );
		}

		if ( store.refuse ) {
			return response( store.refuse.status, store.refuse.body );
		}

		const body = JSON.parse( init.body );

		if ( store.staleAlways || body.cart_version !== store.version ) {
			return response( 409, {
				code: 'cart.version_stale',
				message: 'stale',
				data: {
					status: 409,
					details: { current_version: store.version, totals: {} },
				},
			} );
		}

		body.lines.forEach( ( { variant_id: variantId, quantity } ) => {
			const held = store.lines.find(
				( line ) => line.variant_id === variantId
			);

			if ( held ) {
				held.quantity += quantity;
			} else {
				store.lines.push( {
					line_identity: `line-${ variantId }`,
					variant_id: variantId,
					quantity,
				} );
			}
		} );
		store.version++;

		if ( store.lose > 0 ) {
			store.lose--;
			throw new TypeError( 'network' );
		}

		return response( 200, cart(), loggedIn ? { 'X-WP-Nonce': NONCE } : {} );
	};

	store.fetch = async ( url, init = {} ) => {
		store.sent.push( { url, ...init } );
		store.inFlight++;
		store.mostInFlight = Math.max( store.mostInFlight, store.inFlight );

		try {
			// Let another request start meanwhile, as a browser would.
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

			if ( url.includes( 'action=rest-nonce' ) ) {
				return null === store.nonceAnswer
					? response( 400, '0' )
					: response( 200, store.nonceAnswer );
			}

			if ( 'GET' === init.method ) {
				return response( 200, cart() );
			}

			return write( init );
		} finally {
			store.inFlight--;
		}
	};

	return store;
}

export const CONFIG = {
	restRoot: 'https://shop.example/wp-json/',
	nonceUrl: 'https://shop.example/wp-admin/admin-ajax.php?action=rest-nonce',
};
