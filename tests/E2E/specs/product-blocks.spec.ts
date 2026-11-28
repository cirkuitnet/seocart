/**
 * The product blocks spec: a product's page whose content is the storefront's three blocks, its
 * price, its options and its add-to-cart form, as shoppers use it at desktop width and at 390 px.
 *
 * The spec creates its products over REST as the administrator and moves them to the trash
 * afterwards. Coffee has two options, size and roast, and sells three of their four combinations:
 * 250 g light (reduced from a higher price), 250 g dark (sold out: no unit at the store's
 * location) and 1 kg light (one unit left); 1 kg dark is not sold. Tea has no options.
 *
 * Each shopper is a browser context of its own, without the administrator's session the other
 * specs share. axe (support/axe.ts) is held to WCAG 2.2 AA over the three blocks in each state: as
 * the page arrives, with the sold-out variant selected, after an add, after an add the store
 * flagged, and after a refused write. The picker and the form are used by keyboard alone. A
 * guest's add puts the line in the cart once, and a second add from the same page is sent at the
 * version the first answered, with no stale refusal. A logged-in subscriber's first add is refused
 * for its missing nonce, the page fetches one and sends the add once more, all three seen on the
 * network. The data layer holds the two events, with no `@` anywhere and no key outside the
 * published declaration (docs/data-layer.md). Every class the blocks print is one docs/styling.md
 * publishes. A screenshot of the page at each width is kept in the test's output folder.
 *
 * Over HTTP: the page is the same bytes for a guest with a cart and without one, and sets no
 * cookie. The stylesheet the site serves declares the same variables as its source. In the
 * editor, each block inserted in a product's content shows the server's preview of it.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type {
	APIRequestContext,
	Browser,
	BrowserContext,
	Page,
	Response,
} from '@playwright/test';
import { test, expect } from '../fixtures';
import { expectNoAccessibilityViolations } from '../support/axe';
import { ignoreHTTPSErrors, siteBaseURL } from '../support/environment';
import {
	createBlockProduct,
	trashBlockProduct,
	type AxisInput,
	type BlockProduct,
	type VariantInput,
} from '../support/block-product';
import { codeSpans, tableRows } from '../support/published-docs';

/** The widths the page is used at. */
const WIDTHS = [
	{ name: 'desktop', viewport: { width: 1280, height: 900 } },
	{ name: 'phone', viewport: { width: 390, height: 844 } },
] as const;

type Viewport = ( typeof WIDTHS )[ number ][ 'viewport' ];

/** The three blocks' roots, as the page prints them. */
const BLOCK_ROOTS = [
	'.seocart-price',
	'.seocart-variant-picker',
	'.seocart-add-to-cart',
];

/** The header every Store API write carries. */
const STORE_HEADER = { 'X-SEOCart-Store': '1' };

/** Coffee's options. */
const COFFEE_AXES: AxisInput[] = [
	{
		key: 'size',
		label: 'Size',
		values: [
			{ key: '250g', label: '250 g' },
			{ key: '1kg', label: '1 kg' },
		],
	},
	{
		key: 'roast',
		label: 'Roast',
		values: [
			{ key: 'light', label: 'Light' },
			{ key: 'dark', label: 'Dark' },
		],
	},
];

/** Coffee's variants: three of the four combinations, one sold out and one with a unit left. */
const COFFEE_VARIANTS: VariantInput[] = [
	{
		values: { size: '250g', roast: 'light' },
		priceMinor: 1250,
		compareAtMinor: 1400,
		onHand: 50,
	},
	{ values: { size: '250g', roast: 'dark' }, priceMinor: 1250, onHand: 0 },
	{ values: { size: '1kg', roast: 'light' }, priceMinor: 4200, onHand: 1 },
];

/** The site's home, ending in a slash. */
function home(): string {
	return `${ ( siteBaseURL() ?? '' ).replace( /\/$/, '' ) }/`;
}

/**
 * The address of a REST route, in the query-string form every permalink setting answers.
 *
 * @param route The route, such as `/seocart/store/v1/cart`.
 * @return The address.
 */
function restUrl( route: string ): string {
	return new URL(
		`?rest_route=${ encodeURIComponent( route ) }`,
		home()
	).toString();
}

/**
 * Tells whether a response answers a write of cart lines.
 *
 * @param response The response.
 * @return True for `POST …/seocart/store/v1/cart/lines`.
 */
function isLinesWrite( response: Response ): boolean {
	return (
		'POST' === response.request().method() &&
		decodeURIComponent( response.url() ).includes(
			'seocart/store/v1/cart/lines'
		)
	);
}

/**
 * A new shopper: a browser context with no session and no cookie, and its page.
 *
 * @param browser  The browser.
 * @param viewport The page's size.
 * @return The context and its page.
 */
async function shopper(
	browser: Browser,
	viewport: Viewport
): Promise< { context: BrowserContext; page: Page } > {
	const context = await browser.newContext( {
		baseURL: siteBaseURL(),
		ignoreHTTPSErrors: ignoreHTTPSErrors(),
		storageState: { cookies: [], origins: [] },
		viewport,
	} );

	return { context, page: await context.newPage() };
}

/**
 * Opens a product's page and waits until its blocks have started: the page's view_item is pushed.
 *
 * @param page    The shopper's page.
 * @param product The product.
 */
async function openProduct(
	page: Page,
	product: BlockProduct
): Promise< void > {
	await page.goto( product.link );
	await page.waitForFunction( () =>
		(
			( window as unknown as { dataLayer?: Array< { event: string } > } )
				.dataLayer ?? []
		).some( ( entry ) => 'view_item' === entry.event )
	);
}

/**
 * The add-to-cart form's parts.
 *
 * @param page The shopper's page.
 * @return The locators.
 */
function form( page: Page ) {
	const root = page.locator( 'form.seocart-add-to-cart' );

	return {
		root,
		button: root.locator( 'button[type="submit"]' ),
		quantity: root.locator( '.seocart-qty__input' ),
		status: root.locator( '[role="status"]' ),
		alert: root.locator( '[role="alert"]' ),
	};
}

/**
 * Picks a value of an option by clicking its pill.
 *
 * @param page  The shopper's page.
 * @param label The value's label.
 */
async function pick( page: Page, label: string ): Promise< void > {
	await page
		.locator( 'label.seocart-pill', {
			has: page.locator( '.seocart-pill__label', {
				hasText: new RegExp( `^${ label }$` ),
			} ),
		} )
		.click();
}

/**
 * Reads the cart through a shopper's own cookies.
 *
 * @param request The shopper's request context.
 * @return The cart's lines.
 */
async function cartLines(
	request: APIRequestContext
): Promise< Array< { variant_id: number; quantity: number } > > {
	const answer = await request.get( restUrl( '/seocart/store/v1/cart' ) );

	expect( answer.status() ).toBe( 200 );

	return ( await answer.json() ).lines;
}

/**
 * Checks the data layer: each event's keys and its items' keys within the published declaration,
 * and no `@` anywhere in it.
 *
 * @param page The shopper's page.
 * @return The events' names, in order.
 */
async function checkDataLayer( page: Page ): Promise< string[] > {
	const events = Object.fromEntries(
		tableRows( 'data-layer.md', 'Events' ).map( ( [ event, , keys ] ) => [
			codeSpans( event )[ 0 ],
			codeSpans( keys ),
		] )
	);
	const itemKeys = tableRows( 'data-layer.md', 'An item' ).map(
		( [ key ] ) => codeSpans( key )[ 0 ]
	);
	const layer: Array< {
		event: string;
		ecommerce: Record< string, unknown > & {
			items: Array< Record< string, unknown > >;
		};
	} > = await page.evaluate(
		() => ( window as unknown as { dataLayer?: [] } ).dataLayer ?? []
	);

	expect( JSON.stringify( layer ) ).not.toContain( '@' );

	for ( const entry of layer ) {
		expect( Object.keys( entry ).sort() ).toEqual( [
			'ecommerce',
			'event',
		] );
		expect( Object.keys( events ) ).toContain( entry.event );
		expect( Object.keys( entry.ecommerce ) ).toEqual(
			events[ entry.event ]
		);

		for ( const item of entry.ecommerce.items ) {
			expect( Object.keys( item ) ).toEqual( itemKeys );
		}
	}

	return layer.map( ( { event } ) => event );
}

/**
 * Checks that every class the blocks print is one docs/styling.md publishes.
 *
 * @param page The shopper's page.
 */
async function checkPublishedClasses( page: Page ): Promise< void > {
	const published = tableRows( 'styling.md', 'Classes' ).map(
		( [ name ] ) => codeSpans( name )[ 0 ]
	);
	const printed = await page.evaluate( ( roots ) => {
		const classes = new Set< string >();

		document
			.querySelectorAll( roots.join() )
			.forEach( ( root ) =>
				[ root, ...root.querySelectorAll( '*' ) ].forEach(
					( element ) =>
						element.classList.forEach( ( name ) =>
							classes.add( name )
						)
				)
			);

		return [ ...classes ];
	}, BLOCK_ROOTS );
	const ours = printed.filter(
		( name ) => name.startsWith( 'seocart-' ) || name.startsWith( 'is-' )
	);

	expect( ours.length ).toBeGreaterThan( 10 );
	expect( ours.filter( ( name ) => ! published.includes( name ) ) ).toEqual(
		[]
	);
}

for ( const width of WIDTHS ) {
	test.describe( `Product blocks at ${ width.name } width`, () => {
		let coffee: BlockProduct;
		let tea: BlockProduct;
		let shop: { context: BrowserContext; page: Page };

		test.beforeAll( async ( { requestUtils } ) => {
			coffee = await createBlockProduct(
				requestUtils,
				'Product blocks coffee',
				COFFEE_AXES,
				COFFEE_VARIANTS
			);
			tea = await createBlockProduct(
				requestUtils,
				'Product blocks tea',
				[],
				[ { values: {}, priceMinor: 900, onHand: 50 } ]
			);
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await trashBlockProduct( requestUtils, coffee.id );
			await trashBlockProduct( requestUtils, tea.id );
		} );

		test.beforeEach( async ( { browser } ) => {
			shop = await shopper( browser, width.viewport );
		} );

		test.afterEach( async () => {
			await shop.context.close();
		} );

		test( 'the page shows the variant the server selected, and prints only published classes', async () => {
			const { page } = shop;
			const { button, status, alert } = form( page );

			await openProduct( page, coffee );

			await expect( page.locator( '.seocart-price__amount' ) ).toHaveText(
				'$12.50'
			);
			await expect(
				page.locator( '.seocart-price__compare-at' )
			).toBeVisible();
			await expect(
				page.locator( '.seocart-price__compare-at' )
			).toContainText( '$14.00' );
			await expect(
				page.locator( '.seocart-options__current' )
			).toHaveText( [ '250 g', 'Light' ] );
			await expect( button ).toHaveText( 'Add to cart' );
			await expect( button ).toHaveAttribute( 'aria-disabled', 'false' );
			await expect( status ).toBeEmpty();
			await expect( alert ).toBeEmpty();
			await expect(
				page.locator( 'label.seocart-pill.is-unavailable' )
			).toHaveText( [ /^Dark\s*\(unavailable\)$/ ] );

			await expectNoViolations( page );
			await checkPublishedClasses( page );
			await page.screenshot( {
				path: test
					.info()
					.outputPath( `product-page-${ width.name }.png` ),
				fullPage: true,
			} );
		} );

		test( 'the sold-out variant reads Sold out and cannot be added', async () => {
			const { page } = shop;
			const { button } = form( page );
			const writes: Response[] = [];

			await openProduct( page, coffee );
			page.on( 'response', ( response ) => {
				if ( isLinesWrite( response ) ) {
					writes.push( response );
				}
			} );

			await pick( page, 'Dark' );

			await expect( button ).toHaveText( 'Sold out' );
			await expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
			await expect( page.locator( '.seocart-price__amount' ) ).toHaveText(
				'$12.50'
			);
			await expect(
				page.locator( '.seocart-price__compare-at' )
			).toBeHidden();
			await expect(
				page.locator( '.seocart-options__current' ).nth( 1 )
			).toHaveText( 'Dark' );

			// The button is marked disabled, not made so, to keep its place in the tab order.
			await button.click( { force: true } );

			await expect( form( page ).status ).toBeEmpty();
			expect( writes ).toEqual( [] );

			await expectNoViolations( page );
		} );

		test( 'the picker and the form work by keyboard alone, and the focus stays on the button', async () => {
			const { page } = shop;
			const { button, quantity, status } = form( page );

			await openProduct( page, coffee );

			for ( let presses = 0; presses < 60; presses++ ) {
				await page.keyboard.press( 'Tab' );

				if (
					await page.locator( '.seocart-pill__input:focus' ).count()
				) {
					break;
				}
			}

			const focused = page.locator( '.seocart-pill__input:focus' );

			await expect( focused ).toHaveAttribute( 'value', '250g' );

			await page.keyboard.press( 'ArrowRight' );

			await expect( focused ).toHaveAttribute( 'value', '1kg' );
			await expect( page.locator( '.seocart-price__amount' ) ).toHaveText(
				'$42.00'
			);
			await expect(
				page.locator( '.seocart-options__current' ).first()
			).toHaveText( '1 kg' );

			await page.keyboard.press( 'ArrowLeft' );
			await page.keyboard.press( 'Tab' );

			await expect( focused ).toHaveAttribute( 'value', 'light' );

			await page.keyboard.press( 'ArrowRight' );

			await expect( button ).toHaveText( 'Sold out' );

			await page.keyboard.press( 'ArrowLeft' );

			await expect( button ).toHaveText( 'Add to cart' );

			// The decrease button, the quantity, the increase button.
			await page.keyboard.press( 'Tab' );
			await page.keyboard.press( 'Tab' );

			await expect( quantity ).toBeFocused();

			await page.keyboard.press( 'Tab' );
			await page.keyboard.press( 'Enter' );
			await page.keyboard.press( 'Space' );

			await expect( quantity ).toHaveValue( '3' );

			await page.keyboard.press( 'Tab' );

			await expect( button ).toBeFocused();

			await page.keyboard.press( 'Enter' );

			await expect( status ).toHaveText(
				'Added to cart: Product blocks coffee · 250 g / Light'
			);
			await expect( button ).toBeFocused();
			await expect( button ).toHaveText( 'Add to cart' );
		} );

		test( "a guest's add puts the line in the cart once, and a second add is sent at the version held", async () => {
			const { context, page } = shop;
			const { button, status } = form( page );
			const writes: Response[] = [];
			const [ variant ] = coffee.variants;

			await openProduct( page, coffee );
			page.on( 'response', ( response ) => {
				if ( isLinesWrite( response ) ) {
					writes.push( response );
				}
			} );

			await button.click();

			await expect( status ).toHaveText(
				'Added to cart: Product blocks coffee · 250 g / Light'
			);
			await expect( button ).toHaveAttribute( 'aria-busy', 'false' );
			expect(
				( await cartLines( context.request ) ).filter(
					( line ) => line.variant_id === variant.id
				)
			).toEqual( [ expect.objectContaining( { quantity: 1 } ) ] );

			await expectNoViolations( page );

			const first = await writes[ 0 ].json();

			await button.click();

			await expect( async () =>
				expect( writes ).toHaveLength( 2 )
			).toPass();
			expect( writes.map( ( write ) => write.status() ) ).toEqual( [
				200, 200,
			] );
			expect( writes[ 1 ].request().postDataJSON().cart_version ).toBe(
				first.version
			);
			expect(
				( await cartLines( context.request ) ).filter(
					( line ) => line.variant_id === variant.id
				)
			).toEqual( [ expect.objectContaining( { quantity: 2 } ) ] );
			expect( await checkDataLayer( page ) ).toEqual( [
				'view_item',
				'add_to_cart',
				'add_to_cart',
			] );
		} );

		test( 'an add past the units for sale says so, and caps the quantity', async () => {
			const { page } = shop;
			const { button, quantity, status, root } = form( page );

			await openProduct( page, coffee );
			await pick( page, '1 kg' );
			await root.locator( '.seocart-qty__step' ).last().click();

			await expect( quantity ).toHaveValue( '2' );

			await button.click();

			await expect( status ).toHaveText(
				'Only 1 available. Your cart holds 2.'
			);
			await expect( quantity ).toHaveAttribute( 'max', '1' );
			await expect( quantity ).toHaveValue( '1' );

			await expectNoViolations( page );
		} );

		test( 'a refused write is said in the alert region', async () => {
			const { page } = shop;
			const { button, status, alert } = form( page );

			await page.route(
				( url ) =>
					decodeURIComponent( url.toString() ).includes(
						'seocart/store/v1/cart/lines'
					),
				( route ) =>
					route.fulfill( {
						status: 429,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'store_api.rate_limited',
							message: 'Too many requests.',
							data: { status: 429 },
						} ),
					} )
			);
			await openProduct( page, coffee );
			await button.click();

			await expect( alert ).toHaveText(
				'Too many requests. Wait a moment and try again.'
			);
			await expect( status ).toBeEmpty();
			await expect( button ).toBeFocused();

			await expectNoViolations( page );
		} );

		test( "a subscriber's add is refused for its nonce, which the page fetches before sending the add once more", async ( {
			requestUtils,
		} ) => {
			const { page } = shop;
			const { button, status } = form( page );
			const password = `E2e-${ Math.random().toString( 36 ).slice( 2 ) }-pw`;
			const username = `e2e-subscriber-${ Date.now() }`;
			const user = await requestUtils.createUser( {
				username,
				email: `${ username }@example.org`,
				firstName: 'E2E',
				lastName: 'Subscriber',
				password,
				roles: [ 'subscriber' ],
			} );
			const admin = await requestUtils.rest< { id: number } >( {
				path: '/wp/v2/users/me',
			} );

			try {
				await page.goto( `${ home() }wp-login.php` );
				await page.locator( '#user_login' ).fill( username );
				await page.locator( '#user_pass' ).fill( password );
				await page.locator( '#wp-submit' ).click();
				await page.waitForURL( /wp-admin/ );

				const seen: Response[] = [];

				page.on( 'response', ( response ) => {
					if (
						isLinesWrite( response ) ||
						response.url().includes( 'action=rest-nonce' )
					) {
						seen.push( response );
					}
				} );

				await openProduct( page, coffee );
				await button.click();

				await expect( status ).toHaveText(
					'Added to cart: Product blocks coffee · 250 g / Light'
				);
				expect( seen ).toHaveLength( 3 );

				const [ refused, nonce, resent ] = seen;

				expect( isLinesWrite( refused ) ).toBe( true );
				expect( refused.status() ).toBe( 403 );
				expect( ( await refused.json() ).code ).toBe(
					'store_api.nonce_missing'
				);
				expect( refused.request().headers()[ 'x-wp-nonce' ] ).toBe(
					undefined
				);
				expect( nonce.request().method() ).toBe( 'GET' );
				expect( nonce.status() ).toBe( 200 );
				expect( isLinesWrite( resent ) ).toBe( true );
				expect( resent.status() ).toBe( 200 );
				expect(
					Boolean( resent.request().headers()[ 'x-wp-nonce' ] )
				).toBe( true );
				expect(
					(
						( await resent.json() ).lines as Array< {
							variant_id: number;
							quantity: number;
						} >
					 ).filter(
						( line ) => line.variant_id === coffee.variants[ 0 ].id
					)
				).toEqual( [ expect.objectContaining( { quantity: 1 } ) ] );
			} finally {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/users/${ user.id }`,
					params: { force: true, reassign: admin.id },
				} );
			}
		} );

		test( 'a product without options shows its price and its form, and no picker', async () => {
			const { page } = shop;
			const { button, status } = form( page );

			await openProduct( page, tea );

			await expect( page.locator( '.seocart-price__amount' ) ).toHaveText(
				'$9.00'
			);
			await expect(
				page.locator( '.seocart-variant-picker' )
			).toHaveCount( 0 );
			await expect( page.locator( 'fieldset' ) ).toHaveCount( 0 );

			await button.click();

			await expect( status ).toHaveText(
				/^Added to cart: Product blocks tea/
			);

			await expectNoViolations( page, [
				'.seocart-price',
				'.seocart-add-to-cart',
			] );
		} );
	} );
}

test.describe( 'Product blocks over HTTP and in the editor', () => {
	let coffee: BlockProduct;

	test.beforeAll( async ( { requestUtils } ) => {
		coffee = await createBlockProduct(
			requestUtils,
			'Product blocks coffee',
			COFFEE_AXES,
			COFFEE_VARIANTS
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await trashBlockProduct( requestUtils, coffee.id );
	} );

	test( 'the page is the same bytes for a guest with a cart and without one, and sets no cookie', async ( {
		playwright,
	} ) => {
		const guest = await playwright.request.newContext( {
			baseURL: siteBaseURL(),
			ignoreHTTPSErrors: ignoreHTTPSErrors(),
			storageState: { cookies: [], origins: [] },
		} );

		try {
			const without = await guest.get( coffee.link );

			expect( without.status() ).toBe( 200 );
			expect( without.headers()[ 'set-cookie' ] ).toBeUndefined();

			const write = await guest.post(
				restUrl( '/seocart/store/v1/cart/lines' ),
				{
					headers: STORE_HEADER,
					data: {
						lines: [
							{
								variant_id: coffee.variants[ 0 ].id,
								quantity: 1,
							},
						],
						cart_version: 0,
					},
				}
			);

			expect( write.status() ).toBe( 200 );
			expect(
				( await guest.storageState() ).cookies.map(
					( { name } ) => name
				)
			).toContain( 'seocart_cart_token' );

			const withCart = await guest.get( coffee.link );

			expect( withCart.status() ).toBe( 200 );
			expect( withCart.headers()[ 'set-cookie' ] ).toBeUndefined();
			expect(
				( await withCart.body() ).equals( await without.body() )
			).toBe( true );
		} finally {
			await guest.dispose();
		}
	} );

	test( 'the stylesheet the site serves declares the same variables as its source', async ( {
		playwright,
	} ) => {
		const guest = await playwright.request.newContext( {
			baseURL: siteBaseURL(),
			ignoreHTTPSErrors: ignoreHTTPSErrors(),
			storageState: { cookies: [], origins: [] },
		} );

		try {
			const page = await ( await guest.get( coffee.link ) ).text();
			const href = page.match(
				/<link[^>]+id=['"]seocart-storefront-css['"][^>]+href=['"]([^'"]+)['"]/
			)?.[ 1 ];

			expect( href ).toBeDefined();

			const served = await (
				await guest.get(
					( href ?? '' ).replace( /&#0?38;|&amp;/g, '&' )
				)
			).text();
			const source = readFileSync(
				resolve(
					__dirname,
					'..',
					'..',
					'..',
					'assets',
					'blocks',
					'storefront.css'
				),
				'utf8'
			);
			const declared = ( css: string ) =>
				[
					...new Set( css.match( /--seocart-[a-z-]+(?=\s*:)/g ) ),
				].sort();

			expect( declared( served ).length ).toBeGreaterThan( 0 );
			expect( declared( served ) ).toEqual( declared( source ) );
		} finally {
			await guest.dispose();
		}
	} );

	test( "each block inserted in a product's content shows the server's preview of it", async ( {
		admin,
		editor,
		requestUtils,
	} ) => {
		const bare = await createBlockProduct(
			requestUtils,
			'Product blocks editor',
			COFFEE_AXES,
			COFFEE_VARIANTS,
			'USD',
			''
		);

		try {
			await admin.editPost( bare.id );
			await editor.insertBlock( { name: 'seocart/product-price' } );
			await editor.insertBlock( { name: 'seocart/variant-picker' } );
			await editor.insertBlock( { name: 'seocart/add-to-cart' } );

			await expect(
				editor.canvas.locator( '.seocart-price__amount' )
			).toHaveText( '$12.50' );
			await expect(
				editor.canvas.locator( 'fieldset.seocart-options' )
			).toHaveCount( 2 );
			await expect(
				editor.canvas.locator(
					'form.seocart-add-to-cart button[type="submit"]'
				)
			).toHaveText( 'Add to cart' );
		} finally {
			await trashBlockProduct( requestUtils, bare.id );
		}
	} );
} );

/**
 * Holds the blocks on the page to WCAG 2.2 AA.
 *
 * @param page  The shopper's page.
 * @param roots The blocks' roots to scan; the three by default.
 */
async function expectNoViolations(
	page: Page,
	roots: string[] = BLOCK_ROOTS
): Promise< void > {
	await expectNoAccessibilityViolations( page, { include: roots } );
}
