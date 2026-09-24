/**
 * The product editor spec: the block editor saves a product's title and its commerce fields in one request.
 *
 * A merchant creates a product, types a SKU and a price in the Commerce panel and publishes. The
 * editor must send exactly one write request to the product's own `wp/v2` item, and that request
 * must carry the `seocart` object; after a reload the panel shows the saved price. The panel is
 * held to WCAG 2.2 AA with axe, and the editor shows no error notice.
 */

import type { Page, Request } from '@playwright/test';
import { test, expect } from '../fixtures';

/** The product post type, and its REST base. */
const POST_TYPE = 'seocart_product';
const REST_BASE = 'seocart-products';

/** The panel's region, as the plugin renders it. */
const PANEL = '.seocart-commerce-panel';

/**
 * The REST route a request is for, whether the site serves pretty REST URLs or `?rest_route=`.
 *
 * @param url The request's URL.
 * @return The route, such as `/wp/v2/seocart-products/12`, or an empty string.
 */
function restRoute( url: string ): string {
	const parsed = new URL( url );
	const query = parsed.searchParams.get( 'rest_route' );

	if ( query ) {
		return query;
	}

	const index = parsed.pathname.indexOf( '/wp-json/' );

	return index === -1
		? ''
		: parsed.pathname.slice( index + '/wp-json'.length );
}

/**
 * Opens the document sidebar and, when it is collapsed, the Commerce panel in it.
 *
 * @param openSidebar Opens the document sidebar: the editor fixture's own method.
 * @param page        The page.
 */
async function openCommercePanel(
	openSidebar: () => Promise< void >,
	page: Page
): Promise< void > {
	await openSidebar();

	const toggle = page.getByRole( 'button', {
		name: 'Commerce',
		exact: true,
	} );

	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await toggle.click();
	}
}

test.describe( 'Product editor', () => {
	const created: number[] = [];

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of created ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/${ REST_BASE }/${ id }`,
				params: { force: true },
			} );
		}
	} );

	test( 'publishing saves the title and the price in one request', async ( {
		admin,
		editor,
		page,
		expectNoAccessibilityViolations,
	} ) => {
		const sku = `E2E-${ Date.now() }`;

		await admin.createNewPost( {
			postType: POST_TYPE,
			title: 'Product saved in one request',
		} );
		await openCommercePanel(
			() => editor.openDocumentSettingsSidebar(),
			page
		);

		const panel = page.locator( PANEL );

		await expect( panel ).toBeVisible();
		await panel.getByRole( 'textbox', { name: 'SKU' } ).fill( sku );
		await panel
			.getByRole( 'textbox', { name: /^Price \(/ } )
			.fill( '19.99' );

		// Every write request from here to the end of the publish.
		const writes: Request[] = [];
		const record = ( request: Request ) => {
			if (
				request.method() !== 'GET' &&
				restRoute( request.url() ).startsWith( `/wp/v2/${ REST_BASE }` )
			) {
				writes.push( request );
			}
		};

		page.on( 'request', record );
		await editor.publishPost();
		page.off( 'request', record );

		const postId = await editor.page.evaluate( () =>
			(
				window as unknown as {
					wp: {
						data: {
							select: ( store: string ) => {
								getCurrentPostId: () => number;
							};
						};
					};
				}
			 ).wp.data
				.select( 'core/editor' )
				.getCurrentPostId()
		);

		created.push( postId );

		const routes = writes.map( ( request ) => restRoute( request.url() ) );

		// Printed on a pass too: the one request, as the browser sent it.
		// eslint-disable-next-line no-console
		console.log(
			`Write requests on publish: ${ JSON.stringify(
				writes.map( ( request ) => ( {
					method: request.method(),
					override: request.headers()[ 'x-http-method-override' ],
					route: restRoute( request.url() ),
					body: request.postDataJSON(),
				} ) )
			) }`
		);

		expect( routes, 'One write request, to the product itself.' ).toEqual( [
			`/wp/v2/${ REST_BASE }/${ postId }`,
		] );

		const body = writes[ 0 ].postDataJSON() as {
			seocart?: { sku?: string; price_minor?: number };
		};

		expect( body.seocart?.price_minor ).toBe( 1999 );
		expect( body.seocart?.sku ).toBe( sku );
		await expect(
			page.locator( '.components-notice.is-error' )
		).toHaveCount( 0 );

		await expectNoAccessibilityViolations( { include: PANEL } );

		await page.reload();
		await openCommercePanel(
			() => editor.openDocumentSettingsSidebar(),
			page
		);

		const reloaded = page.locator( PANEL );

		await expect(
			reloaded.getByRole( 'textbox', { name: /^Price \(/ } )
		).toHaveValue( '19.99' );
		await expect(
			reloaded.getByRole( 'textbox', { name: 'SKU' } )
		).toHaveValue( sku );
		await expect( reloaded ).toContainText( 'For sale.' );
		// The screen's own notices are core's (an update nag, the no-JavaScript notice); none is the plugin's.
		await expect(
			page.locator( '.notice', { hasText: 'SEOCart' } ),
			'SEOCart showed an admin notice on the product editor.'
		).toHaveCount( 0 );
	} );
} );
