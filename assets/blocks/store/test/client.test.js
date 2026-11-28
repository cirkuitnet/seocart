/**
 * The Store API client: what every request carries, how an answer reads, and the nonce it holds.
 */

/**
 * Internal dependencies
 */
import { createClient } from '../client';
import { CONFIG } from './fixtures/fake-store';

/**
 * Returns a `fetch` that answers each request with the next of some responses, and what it was sent.
 *
 * @param {...Object} answers The responses, in order: `{ status, body, nonce }`, or an Error to throw.
 * @return {Object} The `fetch`, with `sent`.
 */
function scripted( ...answers ) {
	const fetch = async ( url, init = {} ) => {
		fetch.sent.push( { url, ...init } );

		const next = answers.shift();

		if ( next instanceof Error ) {
			throw next;
		}

		return {
			status: next.status,
			ok: next.status < 300,
			headers: {
				get: ( name ) =>
					'X-WP-Nonce' === name ? ( next.nonce ?? null ) : null,
			},
			json: async () => {
				if ( 'string' === typeof next.body ) {
					throw new SyntaxError( next.body );
				}

				return next.body;
			},
			text: async () => next.body,
		};
	};

	fetch.sent = [];

	return fetch;
}

test( "a write carries the store's header, the browser's cookies and its body as JSON", async () => {
	const fetch = scripted( { status: 200, body: { version: 1 } } );
	const answer = await createClient( { ...CONFIG, fetch } ).send(
		'POST',
		'/cart/lines',
		{ cart_version: 0 }
	);

	expect( answer ).toEqual( { status: 200, body: { version: 1 } } );
	expect( fetch.sent[ 0 ] ).toEqual( {
		url: 'https://shop.example/wp-json/seocart/store/v1/cart/lines',
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'X-SEOCart-Store': '1',
			'Content-Type': 'application/json',
		},
		body: '{"cart_version":0}',
	} );
} );

test( 'no answer, an answer of 500 or more, and a body that is not JSON are each no answer', async () => {
	const fetch = scripted(
		new TypeError( 'network' ),
		{ status: 502, body: { code: 'bad_gateway' } },
		{ status: 200, body: '<html>' }
	);
	const client = createClient( { ...CONFIG, fetch } );

	expect( await client.send( 'GET', '/cart' ) ).toEqual( { network: true } );
	expect( await client.send( 'GET', '/cart' ) ).toEqual( { network: true } );
	expect( await client.send( 'GET', '/cart' ) ).toEqual( { network: true } );
} );

test( 'the nonce of each answer replaces the one held, and is sent with the next request', async () => {
	const fetch = scripted(
		{ status: 200, body: {}, nonce: 'aaaaaaaaaa' },
		{ status: 200, body: {}, nonce: 'bbbbbbbbbb' },
		{ status: 200, body: {} }
	);
	const client = createClient( { ...CONFIG, fetch } );

	await client.send( 'GET', '/cart' );
	await client.send( 'GET', '/cart' );
	await client.send( 'GET', '/cart' );

	expect(
		fetch.sent.map( ( request ) => request.headers[ 'X-WP-Nonce' ] )
	).toEqual( [ undefined, 'aaaaaaaaaa', 'bbbbbbbbbb' ] );
} );

test( "an expired nonce is replaced once, from WordPress's action, and the write sent once more", async () => {
	const fetch = scripted(
		{ status: 200, body: {}, nonce: 'aaaaaaaaaa' },
		{ status: 403, body: { code: 'rest_cookie_invalid_nonce' } },
		{ status: 200, body: 'cccccccccc\n' },
		{ status: 200, body: { version: 2 } }
	);
	const client = createClient( { ...CONFIG, fetch } );

	await client.send( 'GET', '/cart' );

	expect(
		await client.send( 'POST', '/cart/lines', { cart_version: 1 } )
	).toEqual( { status: 200, body: { version: 2 } } );
	expect( fetch.sent[ 2 ] ).toEqual( {
		url: CONFIG.nonceUrl,
		credentials: 'same-origin',
	} );
	expect( fetch.sent[ 3 ].headers[ 'X-WP-Nonce' ] ).toBe( 'cccccccccc' );
} );

test( 'a second refusal for the nonce is not answered again', async () => {
	const fetch = scripted(
		{ status: 403, body: { code: 'store_api.nonce_missing' } },
		{ status: 200, body: 'dddddddddd' },
		{ status: 403, body: { code: 'rest_cookie_invalid_nonce' } }
	);
	const answer = await createClient( { ...CONFIG, fetch } ).send(
		'POST',
		'/cart/lines',
		{ cart_version: 0 }
	);

	expect( answer.sessionExpired ).toBe( true );
	expect( fetch.sent ).toHaveLength( 3 );
} );

test( "the nonce is held in memory only, never in the browser's storage", async () => {
	const fetch = scripted(
		{ status: 403, body: { code: 'store_api.nonce_missing' } },
		{ status: 200, body: 'eeeeeeeeee' },
		{ status: 200, body: {}, nonce: 'ffffffffff' }
	);

	window.localStorage.clear();
	window.sessionStorage.clear();

	await createClient( { ...CONFIG, fetch } ).send( 'POST', '/cart/lines', {
		cart_version: 0,
	} );

	expect( window.localStorage ).toHaveLength( 0 );
	expect( window.sessionStorage ).toHaveLength( 0 );
	expect( document.cookie ).toBe( '' );
} );
