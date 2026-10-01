/**
 * The Store API spec: what a visitor's browser receives from the storefront and the Store API
 * before it writes anything.
 *
 * A catalog page and a Store API read set no cookie: the cart cookie is issued only by a cart
 * write, the first of which creates the cart, so a page cache can serve every visitor the same
 * page. The session
 * read answers a guest with a nonce and user 0, and is never cached. An unknown path under the
 * Store API is answered in the plugin's one error shape, and is never cached either.
 *
 * Every request here is a guest's: a request context of its own, without the administrator's
 * session the other specs share. The page read is a post the spec publishes and deletes again.
 *
 * The cart: a write without the Store API's request header is refused and changes nothing; the
 * first write, which creates the cart, gets the cart cookie, for the guest cart's seven days; a
 * later write gets the same cookie again, for seven days from that write, so the cookie lives as
 * long as the cart; a read of the cart gets none. The cart's lines name a variant no product has,
 * which the cart keeps and reports unpriced, so the spec needs no catalog.
 *
 * The order-status read answers a guest who presents the order's access key, in its header or
 * in the emailed link's `order_key` parameter, with the order's figures and lines; a guest
 * without the key, or with a wrong one, gets the same `order.not_found` as for an order that
 * does not exist. The Store API cannot place an order yet, so the reads of a real order need one
 * planted on the site under test first, named in SEOCART_E2E_ORDER as `<uuid>:<key>`; without it
 * they are skipped, and the refusal of an order that does not exist still runs.
 *
 * The checkout: a write of the checkout's details moves the cart's version on and gets the cart
 * cookie again; its answer carries a guest's shipping address as an object without its fields,
 * which are personal data; a replay of it is refused as stale. With no line priced, the totals
 * charge no shipping.
 *
 * Promotion codes: a code no promotion has is refused with `promotion.code_invalid` and changes
 * nothing. A code is applied with a POST, as the customer typed it, and removed with a DELETE
 * that names it in the path and carries the cart version in the query string; each moves the
 * version on and gets the cart cookie again, and a replayed removal is refused as stale. Nothing
 * creates a promotion through the Store API, so the code to apply needs one planted on the site
 * under test first, named in SEOCART_E2E_CODE: an active code with no usage limit and no fixed
 * amount, so it applies to any cart. Without it that test is skipped, and the refusal still runs.
 */

import type { APIRequestContext, APIResponse } from '@playwright/test';
import { test, expect } from '../fixtures';
import { ignoreHTTPSErrors, siteBaseURL } from '../support/environment';

/** The Store API's namespace. */
const STORE_NAMESPACE = 'seocart/store/v1';

/** Every Store API answer carries this, a guest's included. */
const NO_STORE = 'no-store, private';

/** The header every Store API write carries. */
const STORE_HEADER = { 'X-SEOCart-Store': '1' };

/** The cookie that carries a browser's cart token. */
const CART_COOKIE = 'seocart_cart_token';

/** A variant no product has: the cart keeps its line and reports it unpriced. */
const UNKNOWN_VARIANT = 990001;

/** How long a guest's cart lives after a write, and so its cookie: seven days, in seconds. */
const GUEST_CART_SECONDS = 7 * 24 * 60 * 60;

/**
 * Returns a Set-Cookie header's `name=value` pair.
 *
 * @param cookie The header's value.
 */
function cookiePair( cookie: string ): string {
	return cookie.split( ';' )[ 0 ];
}

/**
 * Returns a Set-Cookie header's Max-Age, in seconds, or NaN when it has none.
 *
 * @param cookie The header's value.
 */
function maxAge( cookie: string ): number {
	return Number( /;\s*max-age=(\d+)/i.exec( cookie )?.[ 1 ] );
}

/**
 * Expects a cart cookie to live a guest cart's seven days, give or take the minute a request may take.
 *
 * @param cookie The header's value.
 */
function expectGuestCartLife( cookie: string ): void {
	expect( maxAge( cookie ) ).toBeGreaterThan( GUEST_CART_SECONDS - 60 );
	expect( maxAge( cookie ) ).toBeLessThanOrEqual( GUEST_CART_SECONDS );
}
/** The request header a client sends an order's access key in. */
const ORDER_KEY_HEADER = 'X-SEOCart-Order-Key';

/** A well-formed uuid no order has. */
const NO_SUCH_ORDER = '00000000-0000-7000-8000-000000000000';

/** The order planted on the site under test, if any: SEOCART_E2E_ORDER is `<uuid>:<key>`. */
const PLANTED_ORDER = ( () => {
	const [ uuid = '', key = '' ] = (
		process.env.SEOCART_E2E_ORDER ?? ''
	).split( ':' );

	return '' !== uuid && '' !== key ? { uuid, key } : null;
} )();

/** The promotion code planted on the site under test, if any: SEOCART_E2E_CODE names it. */
const PLANTED_CODE = ( process.env.SEOCART_E2E_CODE ?? '' ).trim();

/** Returns the site's home URL, with its trailing slash. */
function home(): string {
	return String( siteBaseURL() ).replace( /\/?$/, '/' );
}

/**
 * Returns the URL of a REST route, independent of the site's permalink structure.
 *
 * @param route The route, such as `/seocart/store/v1/session`.
 */
function restUrl( route: string ): string {
	return new URL(
		`?rest_route=${ encodeURIComponent( route ) }`,
		home()
	).toString();
}

/**
 * Returns the URL of an order's status read, with the emailed link's key when one is given.
 *
 * @param uuid The order's uuid.
 * @param key  Optional. The access key, as the `order_key` query parameter.
 */
function orderUrl( uuid: string, key?: string ): string {
	const url = new URL( restUrl( `/${ STORE_NAMESPACE }/orders/${ uuid }` ) );

	if ( undefined !== key ) {
		url.searchParams.set( 'order_key', key );
	}

	return url.toString();
}

/**
 * Returns the URL of the removal of a cart's promotion code: the code in the path, the cart version in the query string.
 *
 * @param code    The code.
 * @param version The cart version the removal is based on.
 */
function codeUrl( code: string, version: number ): string {
	const url = new URL(
		restUrl(
			`/${ STORE_NAMESPACE }/cart/codes/${ encodeURIComponent( code ) }`
		)
	);

	url.searchParams.set( 'cart_version', String( version ) );

	return url.toString();
}

/**
 * Starts a guest's cart with one line of a variant no product has, and returns its cookie.
 *
 * @param guest The guest's request context, which keeps the cookie as a browser does.
 */
async function startCart( guest: APIRequestContext ): Promise< string > {
	const started = await guest.post(
		restUrl( `/${ STORE_NAMESPACE }/cart/lines` ),
		{
			headers: STORE_HEADER,
			data: { lines: [ { variant_id: UNKNOWN_VARIANT, quantity: 1 } ] },
		}
	);

	expect( started.status() ).toBe( 200 );
	expect( ( await started.json() ).version ).toBe( 1 );

	return cookiePair( setCookies( started )[ 0 ] );
}

/**
 * Asserts that a response is the one answer every refused status read gets.
 *
 * @param response The response.
 */
async function expectOrderNotFound( response: APIResponse ): Promise< void > {
	expect( response.status() ).toBe( 404 );
	expect( response.headers()[ 'cache-control' ] ).toBe( NO_STORE );
	expect( setCookies( response ) ).toEqual( [] );

	const body = await response.json();

	expect( body.code ).toBe( 'order.not_found' );
	expect( Object.keys( body.data ) ).toEqual( [
		'status',
		'details',
		'correlation_id',
	] );
}

/**
 * Returns the cookies a response sets.
 *
 * @param response The response.
 */
function setCookies( response: APIResponse ): string[] {
	return response
		.headersArray()
		.filter( ( header ) => 'set-cookie' === header.name.toLowerCase() )
		.map( ( header ) => header.value );
}

/**
 * Returns the values of a response's Vary header, lower-cased.
 *
 * @param response The response.
 */
function varies( response: APIResponse ): string[] {
	return ( response.headers().vary ?? '' )
		.split( ',' )
		.map( ( value ) => value.trim().toLowerCase() )
		.filter( ( value ) => '' !== value );
}

test.describe( 'Store API, as a guest', () => {
	let guest: APIRequestContext;

	test.beforeEach( async ( { playwright } ) => {
		// An empty storage state: a new request context otherwise takes the configured one, the
		// administrator's session, and its login cookie would make every request a user's.
		guest = await playwright.request.newContext( {
			baseURL: siteBaseURL(),
			ignoreHTTPSErrors: ignoreHTTPSErrors(),
			storageState: { cookies: [], origins: [] },
		} );
	} );

	test.afterEach( async () => {
		await guest.dispose();
	} );

	test( 'a catalog page and a Store API read set no cookie', async ( {
		requestUtils,
	} ) => {
		const post = await requestUtils.rest< { id: number; link: string } >( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'Store API spec: a page a cache may keep',
				content: 'Nothing here depends on who reads it.',
				status: 'publish',
			},
		} );

		try {
			for ( const url of [ home(), post.link ] ) {
				const page = await guest.get( url );

				expect( page.status(), url ).toBe( 200 );
				expect( setCookies( page ), `${ url } set a cookie.` ).toEqual(
					[]
				);
			}

			const session = await guest.get(
				restUrl( `/${ STORE_NAMESPACE }/session` )
			);

			expect( session.status() ).toBe( 200 );
			expect(
				setCookies( session ),
				'The session read set a cookie.'
			).toEqual( [] );
			expect( session.headers()[ 'cache-control' ] ).toBe( NO_STORE );
			expect( varies( session ) ).toContain( 'cookie' );

			const body = await session.json();

			expect( body.user_id ).toBe( 0 );
			expect( typeof body.nonce ).toBe( 'string' );
			expect( body.nonce ).not.toBe( '' );
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ post.id }`,
				params: { force: true },
			} );
		}
	} );

	test( 'a cart write without the Store API header is refused and changes nothing', async () => {
		const refused = await guest.post(
			restUrl( `/${ STORE_NAMESPACE }/cart/lines` ),
			{
				data: {
					lines: [ { variant_id: UNKNOWN_VARIANT, quantity: 1 } ],
				},
			}
		);

		expect( refused.status() ).toBe( 403 );
		expect(
			setCookies( refused ),
			'A refused write set a cookie.'
		).toEqual( [] );
		expect( ( await refused.json() ).code ).toBe(
			'store_api.header_missing'
		);

		const read = await guest.get( restUrl( `/${ STORE_NAMESPACE }/cart` ) );
		const cart = await read.json();

		expect( cart.version, 'The refused write created a cart.' ).toBe( 0 );
		expect( cart.lines ).toEqual( [] );
	} );

	test( 'every cart write gets the cart cookie for as long as the cart lives, and a read of the cart gets none', async () => {
		const first = await guest.post(
			restUrl( `/${ STORE_NAMESPACE }/cart/lines` ),
			{
				headers: STORE_HEADER,
				data: {
					lines: [ { variant_id: UNKNOWN_VARIANT, quantity: 2 } ],
				},
			}
		);

		expect( first.status() ).toBe( 200 );
		expect( first.headers()[ 'cache-control' ] ).toBe( NO_STORE );

		const cookies = setCookies( first );

		expect( cookies, 'The first write set no cart cookie.' ).toHaveLength(
			1
		);
		expect( cookies[ 0 ] ).toMatch(
			new RegExp( `^${ CART_COOKIE }=[0-9a-f]{64};` )
		);
		expect( cookies[ 0 ].toLowerCase() ).toContain( 'httponly' );
		expectGuestCartLife( cookies[ 0 ] );

		const created = await first.json();

		expect( created.version ).toBe( 1 );
		expect( created.unpriced_lines ).toHaveLength( 1 );

		// The request context keeps the cookie, as a browser does, so this write names the cart.
		const second = await guest.post(
			restUrl( `/${ STORE_NAMESPACE }/cart/lines` ),
			{
				headers: STORE_HEADER,
				data: {
					lines: [ { variant_id: UNKNOWN_VARIANT, quantity: 1 } ],
					cart_version: 1,
				},
			}
		);

		expect( second.status() ).toBe( 200 );
		expect( ( await second.json() ).version ).toBe( 2 );

		const again = setCookies( second );

		expect(
			again,
			'The later write did not send the cart cookie again.'
		).toHaveLength( 1 );
		expect(
			cookiePair( again[ 0 ] ),
			'The later write sent another token.'
		).toBe( cookiePair( cookies[ 0 ] ) );
		expectGuestCartLife( again[ 0 ] );

		const read = await guest.get( restUrl( `/${ STORE_NAMESPACE }/cart` ) );

		expect( read.status() ).toBe( 200 );
		expect(
			setCookies( read ),
			'A read of the cart set a cookie.'
		).toEqual( [] );
		expect( read.headers()[ 'cache-control' ] ).toBe( NO_STORE );
		expect( ( await read.json() ).version ).toBe( 2 );
	} );

	test( 'a checkout write rides the cart version and sends a guest no address back', async () => {
		const started = await guest.post(
			restUrl( `/${ STORE_NAMESPACE }/cart/lines` ),
			{
				headers: STORE_HEADER,
				data: {
					lines: [ { variant_id: UNKNOWN_VARIANT, quantity: 1 } ],
				},
			}
		);

		expect( started.status() ).toBe( 200 );

		const cookies = setCookies( started );
		const details = {
			cart_version: 1,
			shipping_address: {
				country: 'GB',
				first_name: 'Ada',
				last_name: 'Lovelace',
				line1: "12 St James's Square",
				city: 'London',
				postcode: 'SW1Y 4JH',
			},
			shipping_method_key: 'flat',
			payment_method_key: 'stub',
		};

		const written = await guest.put(
			restUrl( `/${ STORE_NAMESPACE }/checkout` ),
			{ headers: STORE_HEADER, data: details }
		);

		expect( written.status() ).toBe( 200 );
		expect( written.headers()[ 'cache-control' ] ).toBe( NO_STORE );

		const answer = await written.json();

		expect( answer.version ).toBe( 2 );
		expect( answer.checkout_session ).toEqual( {
			billing_address: null,
			shipping_address: {},
			shipping_method_key: 'flat',
			payment_method_key: 'stub',
		} );
		expect( answer.totals.summary.shipping_total_minor ).toBe( 0 );

		const again = setCookies( written );

		expect(
			again,
			'The checkout write did not send the cart cookie again.'
		).toHaveLength( 1 );
		expect( cookiePair( again[ 0 ] ) ).toBe( cookiePair( cookies[ 0 ] ) );

		const replay = await guest.put(
			restUrl( `/${ STORE_NAMESPACE }/checkout` ),
			{ headers: STORE_HEADER, data: details }
		);

		expect( replay.status() ).toBe( 409 );
		expect( ( await replay.json() ).code ).toBe( 'cart.version_stale' );
	} );

	test( 'a code no promotion has is refused, sets no cookie and changes nothing', async () => {
		await startCart( guest );

		const refused = await guest.post(
			restUrl( `/${ STORE_NAMESPACE }/cart/codes` ),
			{
				headers: STORE_HEADER,
				data: { code: 'E2E-NO-SUCH-CODE', cart_version: 1 },
			}
		);

		expect( refused.status() ).toBe( 400 );
		expect( refused.headers()[ 'cache-control' ] ).toBe( NO_STORE );
		expect( setCookies( refused ), 'A refused code set a cookie.' ).toEqual(
			[]
		);

		const body = await refused.json();

		expect( body.code ).toBe( 'promotion.code_invalid' );
		expect( body.message ).toBe( 'That code cannot be applied.' );

		const cart = await (
			await guest.get( restUrl( `/${ STORE_NAMESPACE }/cart` ) )
		).json();

		expect( cart.version, 'The refused code changed the cart.' ).toBe( 1 );
		expect( cart.promotion_codes ).toEqual( [] );
	} );

	test( 'a guest applies a promotion code as typed, and removes it through its path', async () => {
		test.skip(
			'' === PLANTED_CODE,
			'Plant an active promotion code with no usage limit and no fixed amount on the site under test and name it in SEOCART_E2E_CODE.'
		);

		const cookie = await startCart( guest );
		const applied = await guest.post(
			restUrl( `/${ STORE_NAMESPACE }/cart/codes` ),
			{
				headers: STORE_HEADER,
				data: {
					code: ` ${ PLANTED_CODE.toLowerCase() } `,
					cart_version: 1,
				},
			}
		);

		expect( applied.status() ).toBe( 200 );
		expect( applied.headers()[ 'cache-control' ] ).toBe( NO_STORE );
		expect( setCookies( applied ).map( cookiePair ) ).toEqual( [ cookie ] );

		const withCode = await applied.json();

		expect( withCode.version ).toBe( 2 );
		expect( withCode.promotion_codes ).toEqual( [
			{ code: PLANTED_CODE.toUpperCase() },
		] );

		const removed = await guest.delete(
			codeUrl( PLANTED_CODE.toLowerCase(), 2 ),
			{ headers: STORE_HEADER }
		);

		expect( removed.status() ).toBe( 200 );
		expect( setCookies( removed ).map( cookiePair ) ).toEqual( [ cookie ] );

		const withoutCode = await removed.json();

		expect( withoutCode.version ).toBe( 3 );
		expect( withoutCode.promotion_codes ).toEqual( [] );

		const replay = await guest.delete(
			codeUrl( PLANTED_CODE.toLowerCase(), 2 ),
			{ headers: STORE_HEADER }
		);

		expect( replay.status() ).toBe( 409 );
		expect( ( await replay.json() ).code ).toBe( 'cart.version_stale' );
	} );

	test( 'an unknown Store API path is answered in the one error shape, never cached', async () => {
		const unknown = await guest.get(
			restUrl( `/${ STORE_NAMESPACE }/nothing-here` )
		);

		expect( unknown.status() ).toBe( 404 );
		expect( unknown.headers()[ 'cache-control' ] ).toBe( NO_STORE );
		expect( setCookies( unknown ) ).toEqual( [] );

		const body = await unknown.json();

		expect( body.code ).toBe( 'rest_no_route' );
		expect( Object.keys( body.data ) ).toEqual( [
			'status',
			'details',
			'correlation_id',
		] );
		expect( body.data.status ).toBe( 404 );
	} );

	test( 'the status read of an order that does not exist is order.not_found, never cached', async () => {
		await expectOrderNotFound(
			await guest.get( orderUrl( NO_SUCH_ORDER ) )
		);
		await expectOrderNotFound(
			await guest.get( orderUrl( NO_SUCH_ORDER, '0'.repeat( 32 ) ) )
		);
	} );

	test( "a guest with the order's key reads its status, in the header or the link", async () => {
		test.skip(
			null === PLANTED_ORDER,
			'Plant an order on the site under test and name it in SEOCART_E2E_ORDER as <uuid>:<key>.'
		);

		const order = PLANTED_ORDER as { uuid: string; key: string };
		const reads = [
			await guest.get( orderUrl( order.uuid ), {
				headers: { [ ORDER_KEY_HEADER ]: order.key },
			} ),
			await guest.get( orderUrl( order.uuid, order.key ) ),
		];

		for ( const read of reads ) {
			expect( read.status() ).toBe( 200 );
			expect( read.headers()[ 'cache-control' ] ).toBe( NO_STORE );
			expect( varies( read ) ).toContain( 'cookie' );
			expect(
				setCookies( read ),
				'The status read set a cookie.'
			).toEqual( [] );

			const body = await read.json();

			expect( body.uuid ).toBe( order.uuid );
			expect( typeof body.order_number ).toBe( 'string' );
			expect( typeof body.grand_total_minor ).toBe( 'number' );
			expect( body ).not.toHaveProperty( 'email' );
			expect( body.lines.length ).toBeGreaterThan( 0 );

			for ( const line of body.lines ) {
				expect( typeof line.title ).toBe( 'string' );
				expect( typeof line.sku ).toBe( 'string' );
				expect( line.quantity ).toBeGreaterThan( 0 );
				expect( typeof line.line_total_minor ).toBe( 'number' );
			}
		}
	} );

	test( "a guest without the order's key, or with a wrong one, gets the answer an order that does not exist gets", async () => {
		test.skip(
			null === PLANTED_ORDER,
			'Plant an order on the site under test and name it in SEOCART_E2E_ORDER as <uuid>:<key>.'
		);

		const order = PLANTED_ORDER as { uuid: string; key: string };

		await expectOrderNotFound( await guest.get( orderUrl( order.uuid ) ) );
		await expectOrderNotFound(
			await guest.get( orderUrl( order.uuid ), {
				headers: { [ ORDER_KEY_HEADER ]: '0'.repeat( 32 ) },
			} )
		);
	} );
} );
