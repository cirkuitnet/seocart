/**
 * The Store API spec: what a visitor's browser receives from the storefront and the Store API
 * before it writes anything.
 *
 * A catalog page and a Store API read set no cookie: the cart cookie is issued only by the first
 * write that creates a cart, so a page cache can serve every visitor the same page. The session
 * read answers a guest with a nonce and user 0, and is never cached. An unknown path under the
 * Store API is answered in the plugin's one error shape, and is never cached either.
 *
 * Every request here is a guest's: a request context of its own, without the administrator's
 * session the other specs share. The page read is a post the spec publishes and deletes again.
 *
 * The cart: a write without the Store API's request header is refused and changes nothing; the
 * first write, which creates the cart, gets the cart cookie; a read of the cart gets none. The
 * cart's lines name a variant no product has, which the cart keeps and reports unpriced, so the
 * spec needs no catalog.
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

	test( 'the first cart write gets the cart cookie, and a read of the cart gets none', async () => {
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

		const created = await first.json();

		expect( created.version ).toBe( 1 );
		expect( created.unpriced_lines ).toHaveLength( 1 );

		// The request context keeps the cookie, as a browser does, so this read names the cart.
		const read = await guest.get( restUrl( `/${ STORE_NAMESPACE }/cart` ) );

		expect( read.status() ).toBe( 200 );
		expect(
			setCookies( read ),
			'A read of the cart set a cookie.'
		).toEqual( [] );
		expect( read.headers()[ 'cache-control' ] ).toBe( NO_STORE );
		expect( ( await read.json() ).version ).toBe( 1 );
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
} );
