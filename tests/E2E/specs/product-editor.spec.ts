/**
 * The product editor spec: the Product data area, a locked block in the editor's canvas, is on every
 * product and saves through the one request that saves the post.
 *
 * A merchant builds a product of six variants with stock at two locations in the area and publishes:
 * the editor must send exactly one write request to the product's own `wp/v2` item, carrying every
 * variant and every stock cell. The rest holds the area to what WordPress does not promise: it is
 * there on a product WordPress's template never reached, it stays there and it stays last, without
 * ever making the post dirty, and the Tab key never leaves it between its first control and its
 * last. It is held to WCAG 2.2 AA with axe, inside the canvas, and fits a phone.
 *
 * Prices in a second currency need a site that sells in one: run tests/E2E/seed.php and name the
 * currency it prints in SEOCART_E2E_CURRENCY. Without it those tests are skipped, and with
 * SEOCART_E2E_REQUIRE_SEEDS=1 they fail.
 */

import { writeFile } from 'node:fs/promises';
import type {
	Frame,
	FrameLocator,
	Locator,
	Page,
	Request,
	Route,
} from '@playwright/test';
import { test, expect } from '../fixtures';
import { createRequestUtils } from '../support/request-utils';

/** The administrator's REST client, as the fixtures give it. */
type RequestUtils = Awaited< ReturnType< typeof createRequestUtils > >;

/** The product post type, and its REST base. */
const POST_TYPE = 'seocart_product';
const REST_BASE = 'seocart-products';

/** The editor's canvas, and the area in it. */
const CANVAS = 'iframe[name="editor-canvas"]';
const AREA = '.seocart-area';

/** The block that hosts the area, and the marker it stores in a product's content. */
const BLOCK = 'seocart/product-data';
const MARKER = `<!-- wp:${ BLOCK } /-->`;

/** A currency the site sells in besides its base currency, if any: SEOCART_E2E_CURRENCY names it. */
const SECOND_CURRENCY = ( process.env.SEOCART_E2E_CURRENCY ?? '' ).trim();

/** The sizes and the grinds of the product a test builds or creates, and each one's key. */
const SIZES = [
	[ '250-g', '250 g' ],
	[ '1-kg', '1 kg' ],
];
const GRINDS = [
	[ 'whole-bean', 'Whole bean' ],
	[ 'filter', 'Filter' ],
	[ 'espresso', 'Espresso' ],
];

/** A picture for a value of an option: one pixel. */
const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/** The labels of the six variants, in the order of the grid. */
const VARIANTS = SIZES.flatMap( ( [ , size ] ) =>
	GRINDS.map( ( [ , grind ] ) => `${ size } / ${ grind }` )
);

interface Location {
	location_uuid: string;
	name: string;
}

interface StockCell {
	location_uuid: string;
	on_hand: number;
	expected_on_hand?: number | null;
	outcome?: string;
}

interface VariantRecord {
	variant_id?: number;
	combination: Array< { axis: string; value: string } >;
	sku?: string | null;
	enabled?: boolean;
	label?: string | null;
	weight_grams?: number | null;
	prices?: Array< {
		currency: string;
		price_minor: number;
		compare_at_minor?: number | null;
		amount_basis?: string;
	} >;
	stock?: StockCell[];
	sellability?: string;
}

interface ProductRecord {
	id: number;
	content: { raw: string };
	seocart: {
		variants?: VariantRecord[];
		options?: Array< {
			key: string;
			values: Array< { image_id: number | null; swatch: string | null } >;
		} >;
		currency?: string;
		sellability?: string;
	};
}

/** What the page's `wp.data` offers a test: a store's selectors and actions, by name. */
type StoreMethods = Record< string, ( ...args: unknown[] ) => unknown >;
type EditorWindow = Window & {
	wp: {
		data: {
			select: ( store: string ) => StoreMethods;
			dispatch: ( store: string ) => StoreMethods;
		};
		dom: {
			focus: {
				tabbable: { find: ( element: Element ) => HTMLElement[] };
			};
		};
	};
};

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
 * The area, inside the editor's canvas.
 *
 * @param page The page.
 * @return A locator of the area.
 */
function areaOf( page: Page ) {
	return ( page.frameLocator( CANVAS ) as FrameLocator ).locator( AREA );
}

/**
 * The canvas's frame.
 *
 * @param page The page.
 * @return The frame.
 */
function canvasOf( page: Page ): Frame {
	const frame = page.frame( { name: 'editor-canvas' } );

	if ( ! frame ) {
		throw new Error( 'The editor has no canvas frame.' );
	}

	return frame;
}

/**
 * The root blocks' names, and whether the post is dirty, as the editor's stores say.
 *
 * @param page The page.
 * @return The names and the state.
 */
async function editorState( page: Page ) {
	return page.evaluate( () => {
		const { select } = ( window as unknown as EditorWindow ).wp.data;

		return {
			blocks: (
				select( 'core/block-editor' ).getBlocks() as Array< {
					name: string;
				} >
			 ).map( ( { name } ) => name ),
			dirty: select( 'core/editor' ).isEditedPostDirty() as boolean,
			undo: select( 'core/editor' ).hasEditorUndo() as boolean,
		};
	} );
}

/**
 * The id of the post the editor edits.
 *
 * @param page The page.
 * @return The id.
 */
async function currentPostId( page: Page ): Promise< number > {
	return page.evaluate(
		() =>
			( window as unknown as EditorWindow ).wp.data
				.select( 'core/editor' )
				.getCurrentPostId() as number
	);
}

/**
 * Waits for the editor to have settled: the block in place, the stock read.
 *
 * @param page The page.
 */
async function areaReady( page: Page ): Promise< void > {
	await expect( areaOf( page ) ).toBeVisible();
	await expect( areaOf( page ).getByText( 'Loading stock…' ) ).toHaveCount(
		0
	);
}

/**
 * Records every write request to a product until stopped.
 *
 * Neither a read nor the question the editor asks of what the user may do, an `OPTIONS` request, is one.
 *
 * @param page The page.
 * @return The requests, and the function that stops recording.
 */
function recordWrites( page: Page ) {
	const writes: Request[] = [];
	const record = ( request: Request ) => {
		if (
			! [ 'GET', 'OPTIONS' ].includes( request.method() ) &&
			restRoute( request.url() ).startsWith( `/wp/v2/${ REST_BASE }` )
		) {
			writes.push( request );
		}
	};

	page.on( 'request', record );

	return { writes, stop: () => page.off( 'request', record ) };
}

/**
 * Waits for the editor to be neither saving nor holding a change to save: a save asked for has had
 * its answer, or there was nothing to save.
 *
 * @param page The page.
 */
async function untilSaved( page: Page ): Promise< void > {
	await expect
		.poll( () =>
			page.evaluate( () => {
				const editor = (
					window as unknown as EditorWindow
				 ).wp.data.select( 'core/editor' );

				return ! editor.isSavingPost() && ! editor.isEditedPostDirty();
			} )
		)
		.toBe( true );
}

/**
 * Saves a published product through the editor's button, and waits for the answer.
 *
 * @param page The page.
 */
async function saveProduct( page: Page ): Promise< void > {
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await untilSaved( page );
}

/**
 * Holds back the answer to the next save of a product, as a slow connection would: the request
 * reaches the site, which stores what it carries, and the editor goes on saving until the answer is
 * let through.
 *
 * @param page The page.
 * @return A promise of the site having stored the save, and the function that lets its answer
 *         through and waits for the editor to have it.
 */
async function holdSaveAnswer( page: Page ) {
	let reached = () => {};
	let letThrough = () => {};
	const stored = new Promise< void >( ( resolve ) => {
		reached = () => resolve();
	} );
	const released = new Promise< void >( ( resolve ) => {
		letThrough = () => resolve();
	} );
	let holding = true;

	await page.route(
		( url ) => restRoute( url.href ).startsWith( `/wp/v2/${ REST_BASE }/` ),
		async ( route: Route ) => {
			if (
				! holding ||
				[ 'GET', 'OPTIONS' ].includes( route.request().method() )
			) {
				return route.fallback();
			}

			holding = false;

			const response = await route.fetch();

			reached();
			await released;

			return route.fulfill( { response } );
		}
	);

	return {
		stored,
		async release() {
			letThrough();
			await expect.poll( () => isSaving( page ) ).toBe( false );
		},
	};
}

/**
 * Whether the editor is saving the post.
 *
 * @param page The page.
 * @return Whether it is.
 */
async function isSaving( page: Page ): Promise< boolean > {
	return page.evaluate(
		() =>
			( window as unknown as EditorWindow ).wp.data
				.select( 'core/editor' )
				.isSavingPost() as boolean
	);
}

/**
 * Saves with Ctrl+S in the code editor, which takes the area away with the field the merchant is
 * in, before they leave it; then goes back to the visual editor.
 *
 * @param page The page.
 * @return The write requests the save sent.
 */
async function saveFromCodeEditor( page: Page ): Promise< Request[] > {
	await openCodeEditor( page );

	return saveInCodeEditor( page );
}

/**
 * Opens the code editor, which takes the area away with the field the merchant is in, before they
 * leave it.
 *
 * @param page The page.
 */
async function openCodeEditor( page: Page ): Promise< void > {
	await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
	await expect( page.locator( '.editor-post-text-editor' ) ).toBeVisible();
}

/**
 * Saves with Ctrl+S in the code editor, already open; then goes back to the visual editor.
 *
 * @param page The page.
 * @return The write requests the save sent.
 */
async function saveInCodeEditor( page: Page ): Promise< Request[] > {
	const { writes, stop } = recordWrites( page );

	await page.keyboard.press( 'ControlOrMeta+s' );
	await untilSaved( page );
	stop();
	await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
	await areaReady( page );

	return writes;
}

/**
 * Saves with Ctrl+S while the merchant stays in a field, erases the field while the editor waits for
 * the answer, and opens the code editor before the answer comes, so the field goes away emptied
 * before the save has ended; then lets the answer through, and saves with Ctrl+S in the code editor.
 *
 * @param page  The page.
 * @param field The field.
 * @return The write requests the save in the code editor sent.
 */
async function eraseWhileSavedThenSaveFromCodeEditor(
	page: Page,
	field: Locator
): Promise< Request[] > {
	const answer = await holdSaveAnswer( page );

	await page.keyboard.press( 'ControlOrMeta+s' );
	await answer.stored;
	await field.press( 'ControlOrMeta+a' );
	await field.press( 'Backspace' );
	await expect( field ).toHaveValue( '' );
	await openCodeEditor( page );
	expect( await isSaving( page ) ).toBe( true );
	await answer.release();

	return saveInCodeEditor( page );
}

/**
 * Leaves the user's editor in the visual editor for the tests after this one. The editor's mode is a
 * preference WordPress saves for the user, a few seconds after it changes when it changed a moment
 * before, so the page is switched back and the preference saved at once.
 *
 * @param page         The page.
 * @param requestUtils The REST client.
 */
async function leaveVisualEditor(
	page: Page,
	requestUtils: RequestUtils
): Promise< void > {
	await page.evaluate( () =>
		( window as unknown as EditorWindow ).wp.data
			.dispatch( 'core/editor' )
			.switchEditorMode( 'visual' )
	);

	const { meta } = await requestUtils.rest< {
		meta: { persisted_preferences?: Record< string, unknown > };
	} >( {
		path: '/wp/v2/users/me',
		params: { context: 'edit' },
	} );
	const saved = meta.persisted_preferences ?? {};

	await requestUtils.rest( {
		method: 'PUT',
		path: '/wp/v2/users/me',
		data: {
			meta: {
				persisted_preferences: {
					...saved,
					core: {
						...( saved.core as Record< string, unknown > ),
						editorMode: 'visual',
					},
				},
			},
		},
	} );
}

/**
 * Adds an option with its values, through the axis editor.
 *
 * @param page   The page.
 * @param name   The option's name.
 * @param values The labels of its values.
 */
async function addOption(
	page: Page,
	name: string,
	values: string[]
): Promise< void > {
	const area = areaOf( page );

	await area
		.getByRole( 'button', { name: /^Add (an|another) option$/ } )
		.click();
	await area
		.getByRole( 'textbox', { name: 'Option name', exact: true } )
		.fill( name );
	await area
		.getByRole( 'textbox', { name: 'First value' } )
		.fill( values[ 0 ] );
	await area.getByRole( 'textbox', { name: 'First value' } ).press( 'Enter' );

	for ( const value of values.slice( 1 ) ) {
		const field = area.getByRole( 'textbox', {
			name: `New value of ${ name }`,
		} );

		await field.fill( value );
		await field.press( 'Enter' );
	}

	await area.getByRole( 'button', { name: `Close ${ name }` } ).click();
}

/**
 * Types the prices in a currency into the grid.
 *
 * @param page     The page.
 * @param currency The currency's code.
 * @param prices   The prices as a merchant types them, in the order of the grid.
 */
async function fillPrices(
	page: Page,
	currency: string,
	prices: string[]
): Promise< void > {
	for ( const [ index, label ] of VARIANTS.entries() ) {
		await areaOf( page )
			.getByRole( 'textbox', {
				name: `Price in ${ currency } of ${ label }`,
			} )
			.fill( prices[ index ] );
	}
}

/**
 * The entries of the six-variant product a test creates over REST, without going through the area.
 *
 * @param prefix    The prefix of their SKUs.
 * @param locations The locations they have stock at.
 * @return The `seocart` object of the request.
 */
function sixVariants( prefix: string, locations: Location[] ) {
	return {
		options: [
			{
				key: 'size',
				label: 'Size',
				values: SIZES.map( ( [ key, label ] ) => ( { key, label } ) ),
			},
			{
				key: 'grind',
				label: 'Grind',
				values: GRINDS.map( ( [ key, label ] ) => ( { key, label } ) ),
			},
		],
		variants: SIZES.flatMap( ( [ size ] ) =>
			GRINDS.map( ( [ grind ] ) => ( {
				combination: [
					{ axis: 'size', value: size },
					{ axis: 'grind', value: grind },
				],
				sku: `${ prefix }-${ size }-${ grind }`,
				enabled: true,
				prices: [ { currency: 'USD', price_minor: 1800 } ],
				stock: locations.map( ( { location_uuid: uuid } ) => ( {
					location_uuid: uuid,
					on_hand: 4,
					expected_on_hand: 0,
				} ) ),
			} ) )
		),
	};
}

test.describe( 'Product editor', () => {
	const products: number[] = [];
	const locations: Location[] = [];

	/**
	 * Creates a product over REST, for the end of the run to delete.
	 *
	 * @param requestUtils The REST client.
	 * @param data         The request's body.
	 * @return The record, in the `edit` context.
	 */
	async function createProduct(
		requestUtils: RequestUtils,
		data: Record< string, unknown >
	): Promise< ProductRecord > {
		const record = await requestUtils.rest< ProductRecord >( {
			method: 'POST',
			path: `/wp/v2/${ REST_BASE }`,
			data: { status: 'publish', ...data },
		} );

		products.push( record.id );

		return record;
	}

	/**
	 * Reads a product as the editor does.
	 *
	 * @param requestUtils The REST client.
	 * @param id           The post's id.
	 * @return The record.
	 */
	async function readProduct(
		requestUtils: RequestUtils,
		id: number
	): Promise< ProductRecord > {
		return requestUtils.rest< ProductRecord >( {
			path: `/wp/v2/${ REST_BASE }/${ id }`,
			params: { context: 'edit' },
		} );
	}

	test.beforeAll( async ( { requestUtils } ) => {
		const stamp = Date.now();

		for ( const name of [ `Portland ${ stamp }`, `Reno ${ stamp }` ] ) {
			locations.push(
				await requestUtils.rest< Location >( {
					method: 'POST',
					path: '/seocart/v1/locations',
					data: { name },
				} )
			);
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const id of products ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/${ REST_BASE }/${ id }`,
				params: { force: true },
			} );
		}

		for ( const { location_uuid: uuid } of locations ) {
			// A location with a history cannot be deleted, and is taken out of use instead.
			await requestUtils
				.rest( {
					method: 'DELETE',
					path: `/seocart/v1/locations/${ uuid }`,
				} )
				.catch( () =>
					requestUtils.rest( {
						method: 'POST',
						path: `/seocart/v1/locations/${ uuid }/deactivation`,
					} )
				);
		}
	} );

	test( 'a new product shows the area on open, and is not dirty', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { postType: POST_TYPE } );
		await areaReady( page );

		const state = await editorState( page );

		expect( state.blocks ).toEqual( [ BLOCK ] );
		expect( state.dirty ).toBe( false );
		await expect(
			page.getByRole( 'button', { name: 'Save draft' } )
		).toBeDisabled();
		await expect(
			areaOf( page ).locator( '.seocart-area-bar' ),
			'The stylesheet reached the canvas.'
		).toHaveCSS( 'display', 'flex' );
	} );

	test( 'a product created over REST shows the area on open, is not dirty and leaves no undo level', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product from elsewhere',
			content:
				'<!-- wp:paragraph --><p>Its description.</p><!-- /wp:paragraph -->',
		} );

		await admin.editPost( id );
		await areaReady( page );

		const state = await editorState( page );

		expect( state.blocks ).toEqual( [ 'core/paragraph', BLOCK ] );
		expect( state.dirty ).toBe( false );
		expect( state.undo ).toBe( false );
		await expect(
			page.getByRole( 'button', { name: 'Save', exact: true } )
		).toBeDisabled();

		// A change after the area was put in: Undo takes that change back, and finds no level of the area's own.
		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.editPost( { title: 'Renamed' } )
		);
		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.undo()
		);

		const undone = await editorState( page );

		expect( undone.undo ).toBe( false );
		expect( undone.blocks ).toEqual( [ 'core/paragraph', BLOCK ] );
	} );

	test( 'a title alone sends no commerce field, so a new product stays untouched', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost( {
			postType: POST_TYPE,
			title: 'Only a title',
		} );
		await areaReady( page );

		const { writes, stop } = recordWrites( page );

		await editor.publishPost();
		stop();

		const id = await currentPostId( page );

		products.push( id );

		expect( writes ).toHaveLength( 1 );
		expect( writes[ 0 ].postDataJSON().seocart ).toBeUndefined();

		const { seocart, content } = await readProduct( requestUtils, id );

		expect( seocart.variants ).toEqual( [] );
		expect( seocart.options ).toEqual( [] );
		expect( content.raw ).toBe( MARKER );
	} );

	test( 'a product without options saves its SKU, its price and whether the price includes tax in one request', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const sku = `E2E-${ Date.now() }`;

		await admin.createNewPost( {
			postType: POST_TYPE,
			title: 'A product of one variant',
		} );
		await areaReady( page );

		const area = areaOf( page );

		await area
			.getByRole( 'textbox', { name: 'Price in USD', exact: true } )
			.fill( '19.99' );
		await expect(
			area.getByText( 'This product needs a SKU before it can be saved.' )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'region', { name: 'Editor top bar' } )
				.getByRole( 'button', { name: 'Publish', exact: true } )
		).toBeDisabled();

		await area
			.getByRole( 'textbox', { name: 'SKU', exact: true } )
			.fill( sku );
		await area
			.getByRole( 'group', { name: 'USD amount basis' } )
			.getByRole( 'button', { name: 'Incl. tax' } )
			.click();

		const { writes, stop } = recordWrites( page );

		await editor.publishPost();
		stop();

		const id = await currentPostId( page );

		products.push( id );

		expect( writes ).toHaveLength( 1 );

		const { seocart: sent } = writes[ 0 ].postDataJSON() as {
			seocart: Record< string, unknown >;
		};

		expect( sent ).toEqual( {
			variants: [
				{
					combination: [],
					sku,
					prices: [
						{
							currency: 'USD',
							price_minor: 1999,
							compare_at_minor: null,
							amount_basis: 'gross',
						},
					],
				},
			],
		} );

		const { seocart } = await readProduct( requestUtils, id );

		expect( seocart.sellability ).toBe( 'sellable' );
		expect( seocart.variants?.[ 0 ].prices?.[ 0 ].amount_basis ).toBe(
			'gross'
		);

		await page.reload();
		await areaReady( page );

		await expect(
			areaOf( page ).getByRole( 'textbox', {
				name: 'Price in USD',
				exact: true,
			} )
		).toHaveValue( '19.99' );
		await expect(
			areaOf( page ).getByRole( 'textbox', { name: 'SKU', exact: true } )
		).toHaveValue( sku );
	} );

	test( 'Delete and Backspace on the selected area do nothing, and the block cannot be removed or moved', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { postType: POST_TYPE } );
		await areaReady( page );

		await areaOf( page )
			.getByRole( 'heading', { name: 'Product data', level: 2 } )
			.click();

		const clientId = await page.evaluate(
			() =>
				( window as unknown as EditorWindow ).wp.data
					.select( 'core/block-editor' )
					.getSelectedBlockClientId() as string
		);

		expect( clientId ).toBeTruthy();
		await page.keyboard.press( 'Delete' );
		await page.keyboard.press( 'Backspace' );

		expect( ( await editorState( page ) ).blocks ).toEqual( [ BLOCK ] );
		expect(
			await page.evaluate( ( id ) => {
				const { select } = ( window as unknown as EditorWindow ).wp
					.data;
				const blockEditor = select( 'core/block-editor' );

				return [
					blockEditor.canRemoveBlock( id ),
					blockEditor.canMoveBlock( id ),
				];
			}, clientId )
		).toEqual( [ false, false ] );
	} );

	test( 'a paragraph appended by clicking below the area ends above it, with the focus kept', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { postType: POST_TYPE } );
		await areaReady( page );

		// Below the last block, in the padding WordPress leaves under the content for this.
		const canvas = page
			.frameLocator( CANVAS )
			.locator( '.editor-styles-wrapper' );
		const box = await canvas.boundingBox();

		await canvas.evaluate( ( element ) =>
			element.scrollIntoView( { block: 'end' } )
		);
		await canvas.click( {
			position: {
				x: ( box?.width ?? 0 ) / 2,
				y: ( box?.height ?? 0 ) - 20,
			},
		} );
		await page.keyboard.type( 'Typed after the click' );

		expect( ( await editorState( page ) ).blocks ).toEqual( [
			'core/paragraph',
			BLOCK,
		] );
		await expect(
			page
				.frameLocator( CANVAS )
				.locator( '[data-type="core/paragraph"]' )
		).toHaveText( 'Typed after the click' );
		await expect(
			page
				.frameLocator( CANVAS )
				.locator( '[data-type="core/paragraph"]' )
		).toBeFocused();
	} );

	test( 'the marker deleted in the code editor is back after switching to the visual editor', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { postType: POST_TYPE } );
		await areaReady( page );

		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.switchEditorMode( 'text' )
		);

		const code = page.locator( '.editor-post-text-editor' );

		await expect( code ).toHaveValue( MARKER );
		await code.fill( '' );
		await code.blur();

		expect( ( await editorState( page ) ).blocks ).toEqual( [] );

		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.switchEditorMode( 'visual' )
		);
		await areaReady( page );

		expect( ( await editorState( page ) ).blocks ).toEqual( [ BLOCK ] );
	} );

	test( 'six variants with stock at two locations, built in the area, are published in one request', async ( {
		admin,
		editor,
		page,
		requestUtils,
		expectNoAccessibilityViolations,
	}, testInfo ) => {
		test.setTimeout( 300_000 );

		test.skip(
			'' === SECOND_CURRENCY &&
				'1' !== process.env.SEOCART_E2E_REQUIRE_SEEDS,
			'Run tests/E2E/seed.php on the site under test and name the currency it prints in SEOCART_E2E_CURRENCY.'
		);
		expect( SECOND_CURRENCY ).not.toBe( '' );

		const [ portland, reno ] = locations;
		const prefix = `E2E${ Date.now() }`;

		await admin.createNewPost( {
			postType: POST_TYPE,
			title: 'Ethiopia Yirgacheffe',
		} );
		await areaReady( page );

		const area = areaOf( page );

		await addOption(
			page,
			'Size',
			SIZES.map( ( [ , label ] ) => label )
		);
		await addOption(
			page,
			'Grind',
			GRINDS.map( ( [ , label ] ) => label )
		);
		await expect( area.getByText( '6 of 250 variants' ) ).toBeVisible();
		await expect(
			area.getByText(
				'6 variants need a SKU before this product can be saved.'
			)
		).toBeVisible();

		await area
			.getByRole( 'textbox', { name: 'SKU prefix' } )
			.fill( prefix );
		await area
			.getByRole( 'button', { name: 'Fill SKUs from prefix' } )
			.click();
		await expect(
			area.getByRole( 'textbox', { name: `SKU of ${ VARIANTS[ 0 ] }` } )
		).toHaveValue( `${ prefix }-250-G-WHOLE-BEAN` );

		await fillPrices( page, 'USD', [
			'18.00',
			'18.00',
			'18.50',
			'64.00',
			'64.00',
			'64.50',
		] );
		await area
			.getByRole( 'group', { name: 'Price currency' } )
			.getByRole( 'button', { name: SECOND_CURRENCY } )
			.click();
		await fillPrices( page, SECOND_CURRENCY, [
			'17.90',
			'17.90',
			'18.40',
			'62.90',
			'62.90',
			'63.40',
		] );

		for ( const [ index, label ] of VARIANTS.entries() ) {
			await area
				.getByRole( 'combobox', { name: 'Stock by location for' } )
				.selectOption( { label } );
			await area
				.getByRole( 'textbox', {
					name: `On hand at ${ portland.name }, ${ label }`,
				} )
				.fill( String( 10 + index ) );
			await area
				.getByRole( 'textbox', {
					name: `On hand at ${ reno.name }, ${ label }`,
				} )
				.fill( String( 20 + index ) );
		}

		await area
			.getByRole( 'combobox', { name: 'Weight of' } )
			.selectOption( { label: VARIANTS[ 3 ] } );
		await area
			.getByRole( 'textbox', { name: 'Weight (g)', exact: true } )
			.fill( '1030' );

		const { writes, stop } = recordWrites( page );

		await editor.publishPost();
		stop();

		const id = await currentPostId( page );

		products.push( id );

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

		expect(
			writes.map( ( request ) => restRoute( request.url() ) ),
			'One write request, to the product itself.'
		).toEqual( [ `/wp/v2/${ REST_BASE }/${ id }` ] );

		const { seocart: sent } = writes[ 0 ].postDataJSON() as {
			seocart: {
				options: unknown[];
				variants: VariantRecord[];
				sku?: string;
				price_minor?: number;
			};
		};

		expect( sent.options ).toHaveLength( 2 );
		expect( sent.variants ).toHaveLength( 6 );
		expect( sent.sku, 'No flat field beside the lists.' ).toBeUndefined();
		expect( sent.price_minor ).toBeUndefined();
		expect(
			sent.variants.flatMap( ( { stock } ) => stock ?? [] ),
			'Twelve cells, each read at zero.'
		).toHaveLength( 12 );
		expect(
			sent.variants
				.flatMap( ( { stock } ) => stock ?? [] )
				.every( ( cell ) => cell.expected_on_hand === 0 )
		).toBe( true );
		expect(
			sent.variants.every(
				( entry ) =>
					! ( 'variant_id' in entry ) && ! ( 'label' in entry )
			),
			'No field a read carries and a save may not.'
		).toBe( true );

		await expect(
			page.locator( '.components-notice.is-error' )
		).toHaveCount( 0 );

		const saved = await readProduct( requestUtils, id );

		expect( saved.content.raw ).toBe( MARKER );
		expect( saved.seocart.sellability ).toBe( 'sellable' );

		await page.reload();
		await areaReady( page );

		const reloaded = areaOf( page );

		expect( ( await editorState( page ) ).dirty ).toBe( false );
		await expect(
			reloaded.getByRole( 'textbox', {
				name: `SKU of ${ VARIANTS[ 5 ] }`,
			} )
		).toHaveValue( `${ prefix }-1-KG-ESPRESSO` );
		await expect(
			reloaded.getByRole( 'textbox', {
				name: `Price in USD of ${ VARIANTS[ 2 ] }`,
			} )
		).toHaveValue( '18.50' );
		await reloaded
			.getByRole( 'group', { name: 'Price currency' } )
			.getByRole( 'button', { name: SECOND_CURRENCY } )
			.click();
		await expect(
			reloaded.getByRole( 'textbox', {
				name: `Price in ${ SECOND_CURRENCY } of ${ VARIANTS[ 4 ] }`,
			} )
		).toHaveValue( '62.90' );

		await reloaded
			.getByRole( 'combobox', { name: 'Stock by location for' } )
			.selectOption( { label: VARIANTS[ 4 ] } );
		await expect(
			reloaded.getByRole( 'textbox', {
				name: `On hand at ${ portland.name }, ${ VARIANTS[ 4 ] }`,
			} )
		).toHaveValue( '14' );
		await expect(
			reloaded.getByRole( 'textbox', {
				name: `On hand at ${ reno.name }, ${ VARIANTS[ 4 ] }`,
			} )
		).toHaveValue( '24' );

		const ids = ( saved.seocart.variants ?? [] ).map(
			( { variant_id: variantId } ) => variantId
		);
		const listed = await requestUtils.rest< {
			variants: Array< {
				locations: Array< {
					location_uuid: string;
					on_hand: number;
				} >;
			} >;
		} >( {
			path: '/seocart/v1/stock-items',
			params: { variant_ids: ids.join( ',' ) },
		} );

		for ( const [ index, variant ] of listed.variants.entries() ) {
			const at = ( { location_uuid: uuid }: Location ) =>
				variant.locations.find( ( row ) => row.location_uuid === uuid )
					?.on_hand;

			expect( at( portland ) ).toBe( 10 + index );
			expect( at( reno ) ).toBe( 20 + index );
		}

		// Selecting the block turned the sidebar to its own tab.
		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Product', exact: true } ).click();

		const summaryToggle = page.getByRole( 'button', {
			name: 'Product summary',
		} );

		if (
			( await summaryToggle.getAttribute( 'aria-expanded' ) ) !== 'true'
		) {
			await summaryToggle.click();
		}

		const summary = page.locator( '.seocart-product-summary' );

		await expect( summary ).toContainText( 'From $18.00' );
		await expect( summary ).toContainText( 'For sale.' );
		await expect( summary.getByText( '6', { exact: true } ) ).toBeVisible();
		// Ten to fifteen at one location and twenty to twenty-five at the other, none committed.
		await expect( summary ).toContainText( '210' );

		await expectNoAccessibilityViolations( {
			frame: CANVAS,
			include: AREA,
		} );

		await page.setViewportSize( { width: 1440, height: 2600 } );
		await page.screenshot( {
			path: testInfo.outputPath( 'product-editor-1440.png' ),
		} );

		// The screen's own notices are core's (an update nag, the no-JavaScript notice); none is the plugin's.
		await expect(
			page.locator( '.notice', { hasText: 'SEOCart' } ),
			'SEOCart showed an admin notice on the product editor.'
		).toHaveCount( 0 );
	} );

	test( 'the Tab key walks every control of the area, there and back, and never leaves it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		test.setTimeout( 240_000 );

		const { id } = await createProduct( requestUtils, {
			title: 'A product to tab through',
			seocart: sixVariants( `TAB${ Date.now() }`, locations ),
		} );

		await admin.editPost( id );
		await areaReady( page );

		const canvas = canvasOf( page );
		const area = areaOf( page );

		// Open the axes' editors too, so that the walk meets every kind of control.
		await area.getByRole( 'button', { name: 'Edit Size' } ).click();
		await area.getByRole( 'button', { name: 'Edit Grind' } ).click();

		const controls = await area.evaluate( ( element ) =>
			(
				element.ownerDocument.defaultView
					?.parent as unknown as EditorWindow
			 ).wp.dom.focus.tabbable
				.find( element )
				.map( ( control: HTMLElement ) => ( {
					tag: control.tagName,
					name:
						( control as HTMLInputElement ).labels?.[ 0 ]
							?.textContent ||
						control.getAttribute( 'aria-label' ) ||
						control.textContent ||
						'',
				} ) )
		);

		expect( controls.length ).toBeGreaterThan( 40 );
		expect(
			controls.filter(
				( { tag }: { tag: string } ) =>
					! [ 'INPUT', 'SELECT', 'BUTTON', 'TEXTAREA' ].includes(
						tag
					)
			),
			'Every focusable of the area is a form element: the block editor sends Tab out of a block at anything else.'
		).toEqual( [] );

		const focused = () =>
			canvas.evaluate( ( selector ) => {
				// This runs in the canvas's own document.
				const active =
					// eslint-disable-next-line @wordpress/no-global-active-element
					document.activeElement as HTMLInputElement | null;

				return {
					inside: Boolean( active?.closest( selector ) ),
					name:
						active?.labels?.[ 0 ]?.textContent ||
						active?.getAttribute( 'aria-label' ) ||
						active?.textContent ||
						'',
				};
			}, AREA );

		await area
			.getByRole( 'button', { name: 'Collapse product data' } )
			.focus();

		const walked = [ ( await focused() ).name ];

		for ( const [ index, control ] of controls.slice( 1 ).entries() ) {
			await page.keyboard.press( 'Tab' );

			const now = await focused();

			expect(
				now.inside,
				`Tab left the area after ${ walked[ index ] }.`
			).toBe( true );
			expect( now.name ).toBe( control.name );
			walked.push( now.name );
		}

		for ( const control of controls.slice( 0, -1 ).reverse() ) {
			await page.keyboard.press( 'Shift+Tab' );

			const now = await focused();

			expect( now.inside, `Shift+Tab left the area.` ).toBe( true );
			expect( now.name ).toBe( control.name );
		}

		// Printed on a pass too: the walk, in order.
		// eslint-disable-next-line no-console
		console.log(
			`Tab walk of ${ walked.length } controls: ${ JSON.stringify( walked ) }`
		);
	} );

	test( 'at 390 px the area fits, with no horizontal scroll', async ( {
		admin,
		page,
		requestUtils,
	}, testInfo ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product on a phone',
			seocart: sixVariants( `PHONE${ Date.now() }`, locations ),
		} );

		await page.setViewportSize( { width: 390, height: 844 } );
		await admin.editPost( id );
		await areaReady( page );

		const overflow = () => ( {
			width: document.documentElement.clientWidth,
			scroll: document.documentElement.scrollWidth,
		} );

		for ( const measured of [
			await page.evaluate( overflow ),
			await canvasOf( page ).evaluate( overflow ),
		] ) {
			expect( measured.scroll ).toBeLessThanOrEqual( measured.width );
		}

		await page.setViewportSize( { width: 390, height: 3000 } );
		await page.screenshot( {
			path: testInfo.outputPath( 'product-editor-390.png' ),
		} );
	} );

	test( 'removing a value says so before the save, the rows left out are listed apart, and bringing it back heals them', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		test.setTimeout( 180_000 );

		const prefix = `HEAL${ Date.now() }`;
		const { id, seocart } = await createProduct( requestUtils, {
			title: 'A product that loses a value',
			seocart: sixVariants( prefix, locations ),
		} );
		const before = new Map(
			( seocart.variants ?? [] ).map( ( variant ) => [
				variant.label,
				variant.variant_id,
			] )
		);

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );

		await area.getByRole( 'button', { name: 'Edit Grind' } ).click();
		await area.getByRole( 'button', { name: 'Remove Espresso' } ).click();
		await expect(
			area.getByText(
				'When you save, 2 variants go off sale because their values are gone: 250 g / Espresso, 1 kg / Espresso.'
			)
		).toBeVisible();
		await expect( area.getByText( '4 of 250 variants' ) ).toBeVisible();

		let { writes, stop } = recordWrites( page );

		await saveProduct( page );
		stop();

		const removal = writes[ 0 ].postDataJSON() as {
			seocart: { options: unknown[]; variants: VariantRecord[] };
		};

		expect( removal.seocart.options ).toHaveLength( 2 );
		expect( removal.seocart.variants ).toHaveLength( 4 );

		await page.reload();
		await areaReady( page );
		await expect( area.getByText( 'No longer offered' ) ).toBeVisible();
		await expect( area.locator( '.seocart-retired li' ) ).toHaveCount( 2 );
		await expect(
			area.getByRole( 'textbox', { name: /^SKU of .*Espresso$/ } )
		).toHaveCount( 0 );

		// The value back: a whole read would be refused, and the area names every combination instead.
		await area.getByRole( 'button', { name: 'Edit Grind' } ).click();

		const value = area.getByRole( 'textbox', {
			name: 'New value of Grind',
		} );

		await value.fill( 'Espresso' );
		await value.press( 'Enter' );
		await expect(
			area.getByText(
				'2 variants need a SKU before this product can be saved.'
			)
		).toBeVisible();
		await expect(
			area.getByText(
				'2 variants need a price in USD, or to be disabled, before this product can be saved.'
			)
		).toBeVisible();

		await area
			.getByRole( 'textbox', { name: 'SKU prefix' } )
			.fill( prefix );
		await area
			.getByRole( 'button', { name: 'Fill SKUs from prefix' } )
			.click();

		// Their variants have prices of their own, which a save naming none would put back on sale unseen.
		const save = page.getByRole( 'button', { name: 'Save', exact: true } );
		const espresso = VARIANTS.filter( ( label ) =>
			label.endsWith( 'Espresso' )
		);

		await expect( save ).toBeDisabled();

		for ( const label of espresso ) {
			const enabled = area.getByRole( 'checkbox', {
				name: `Enabled of ${ label }`,
			} );

			// Neither ticked nor not: the save would keep the state the variant has, off sale since its value went.
			await expect( enabled ).toHaveJSProperty( 'indeterminate', true );
			await area
				.getByRole( 'textbox', { name: `Price in USD of ${ label }` } )
				.fill( '21.00' );
			await enabled.check();
		}

		await expect( save ).toBeEnabled();

		// A count typed for a variant brought back, which holds stock this screen could not read before the save.
		await area
			.getByRole( 'combobox', { name: 'Stock by location for' } )
			.selectOption( { label: espresso[ 0 ] } );

		const count = area.getByRole( 'textbox', {
			name: `On hand at ${ locations[ 0 ].name }, ${ espresso[ 0 ] }`,
		} );

		await expect( count ).toHaveValue( '0' );
		await count.fill( '9' );

		( { writes, stop } = recordWrites( page ) );
		await saveProduct( page );
		stop();

		expect( writes, 'One write request.' ).toHaveLength( 1 );
		await expect( count ).toHaveValue( '4' );
		await expect(
			area.getByText(
				'Your count was not applied: when you saved, the stock here was not the count you started from.'
			)
		).toBeVisible();
		await expect(
			area.getByText( 'changed while you were editing' )
		).toHaveCount( 0 );

		const healed = await readProduct( requestUtils, id );

		expect(
			( healed.seocart.variants ?? [] ).map( ( variant ) => [
				variant.label,
				variant.enabled,
			] )
		).toEqual( VARIANTS.map( ( label ) => [ label, true ] ) );

		for ( const variant of healed.seocart.variants ?? [] ) {
			expect( before.get( variant.label ) ).toBe( variant.variant_id );
			expect(
				variant.prices?.map( ( { price_minor: minor } ) => minor ),
				'A typed price replaces the ones a variant brought back had.'
			).toEqual( [
				espresso.includes( variant.label ?? '' ) ? 2100 : 1800,
			] );
		}
	} );

	test( 'stock that cannot be read says so, and can be asked for again', async ( {
		admin,
		page,
	} ) => {
		const LOCATIONS = /seocart(\/|%2F)v1(\/|%2F)locations/;

		await page.route( LOCATIONS, ( route ) => route.abort() );
		await admin.createNewPost( { postType: POST_TYPE } );

		const area = areaOf( page );

		await expect(
			area.getByText( 'The stock could not be loaded.' )
		).toBeVisible();
		await expect(
			area.getByRole( 'textbox', { name: /^On hand/ } )
		).toHaveCount( 0 );

		await page.unroute( LOCATIONS );
		await area.getByRole( 'button', { name: 'Try again' } ).click();

		await expect(
			area.getByRole( 'textbox', { name: /^On hand/ } ).first()
		).toBeVisible();
		await expect(
			area.getByText( 'The stock could not be loaded.' )
		).toHaveCount( 0 );
		expect( ( await editorState( page ) ).dirty ).toBe( false );
	} );

	test( 'adding the first option says the one variant goes off sale with its stock, before the save', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product about to get options',
			seocart: { sku: `ONE${ Date.now() }`, price_minor: 1800 },
		} );

		await admin.editPost( id );
		await areaReady( page );
		await addOption( page, 'Size', [ '250 g' ] );

		await expect(
			areaOf( page ).getByText(
				'When you save, the 1 variant it has now goes off sale and keeps its stock on its own row'
			)
		).toBeVisible();
	} );

	test( 'a count that changed while the merchant was editing comes back as a conflict, and the others stand', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const [ portland, reno ] = locations;
		const { id, seocart } = await createProduct( requestUtils, {
			title: 'A product counted twice',
			seocart: sixVariants( `RACE${ Date.now() }`, locations ),
		} );
		const [ { variant_id: variantId } ] = seocart.variants ?? [];

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );
		const label = VARIANTS[ 0 ];
		const portlandCount = area.getByRole( 'textbox', {
			name: `On hand at ${ portland.name }, ${ label }`,
		} );

		await expect( portlandCount ).toHaveValue( '4' );

		await requestUtils.rest( {
			method: 'POST',
			path: `/seocart/v1/stock-items/${ variantId }/adjustments`,
			data: {
				delta: 3,
				reason: 'received',
				location_uuid: portland.location_uuid,
			},
		} );

		await portlandCount.fill( '9' );
		await area
			.getByRole( 'textbox', {
				name: `On hand at ${ reno.name }, ${ label }`,
			} )
			.fill( '8' );
		await saveProduct( page );

		await expect(
			area.getByText(
				'Some counts were not applied: when you saved, the stock there was not the count you started from.'
			)
		).toBeVisible();
		await expect( portlandCount ).toHaveValue( '7' );
		await expect(
			area.getByRole( 'textbox', {
				name: `On hand at ${ reno.name }, ${ label }`,
			} )
		).toHaveValue( '8' );
	} );

	test( 'a value takes an image from the media modal and a swatch, and text that cannot be read keeps the product from saving', async ( {
		admin,
		page,
		requestUtils,
	}, testInfo ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product with swatches',
			seocart: sixVariants( `SWATCH${ Date.now() }`, [] ),
		} );
		const picture = testInfo.outputPath( 'value.gif' );

		await writeFile( picture, Buffer.from( PIXEL, 'base64' ) );

		const media = await requestUtils.uploadMedia( picture );

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );

		await area.getByRole( 'button', { name: 'Edit Size' } ).click();

		const swatch = area.getByRole( 'textbox', { name: 'Swatch of 1 kg' } );
		const save = page.getByRole( 'button', { name: 'Save', exact: true } );

		await swatch.fill( 'brown' );
		await expect(
			area.getByText( 'Enter a colour such as #7b4a2d.' )
		).toBeVisible();
		await expect( save ).toBeDisabled();
		await swatch.fill( '#7B4A2D' );
		await expect( save ).toBeEnabled();

		const price = area.getByRole( 'textbox', {
			name: `Price in USD of ${ VARIANTS[ 0 ] }`,
		} );

		await price.fill( 'twelve' );
		await expect( save ).toBeDisabled();
		await price.fill( '12.00' );
		await expect( save ).toBeEnabled();

		await area
			.getByRole( 'button', { name: 'Choose an image for 250 g' } )
			.click();
		await page.getByRole( 'tab', { name: 'Media Library' } ).click();
		await page.locator( `.attachment[data-id="${ media.id }"]` ).click();
		await page
			.locator( '.media-modal' )
			.getByRole( 'button', { name: 'Select', exact: true } )
			.click();
		await expect(
			area.getByRole( 'button', { name: 'Replace the image of 250 g' } )
		).toBeVisible();

		await saveProduct( page );

		const { seocart } = await readProduct( requestUtils, id );
		const [ size ] = seocart.options ?? [];

		expect( size.values[ 0 ].image_id ).toBe( media.id );
		expect( size.values[ 1 ].swatch ).toBe( '#7b4a2d' );

		await page.reload();
		await areaReady( page );
		await area.getByRole( 'button', { name: 'Edit Size' } ).click();
		await expect(
			area.locator( '.seocart-value-image img' )
		).toBeVisible();
	} );

	test( 'a value added to a product on sale waits for its prices, or for its variants to be disabled, the others stay as they were, and one ticked again waits for its price', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const prefix = `GROW${ Date.now() }`;
		const { id, seocart } = await createProduct( requestUtils, {
			title: 'A product that gets a size',
			seocart: sixVariants( prefix, locations ),
		} );

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );
		const save = page.getByRole( 'button', { name: 'Save', exact: true } );

		await area.getByRole( 'button', { name: 'Edit Size' } ).click();

		const value = area.getByRole( 'textbox', {
			name: 'New value of Size',
		} );

		await value.fill( '2 kg' );
		await value.press( 'Enter' );
		await area
			.getByRole( 'textbox', { name: 'SKU prefix' } )
			.fill( prefix );
		await area
			.getByRole( 'button', { name: 'Fill SKUs from prefix' } )
			.click();
		await expect(
			area.getByText(
				'3 variants need a price in USD, or to be disabled, before this product can be saved.'
			)
		).toBeVisible();
		await expect( save ).toBeDisabled();

		for ( const [ , grind ] of GRINDS ) {
			const enabled = area.getByRole( 'checkbox', {
				name: `Enabled of 2 kg / ${ grind }`,
			} );

			// From the dash, the first click enables and the second disables.
			await enabled.check();
			await enabled.uncheck();
		}

		await expect( save ).toBeEnabled();
		await saveProduct( page );

		const grown = await readProduct( requestUtils, id );
		const variants = grown.seocart.variants ?? [];

		expect( grown.seocart.sellability ).toBe( seocart.sellability );
		expect(
			variants
				.filter( ( { label } ) => label?.startsWith( '2 kg' ) )
				.map( ( { enabled } ) => enabled )
		).toEqual( [ false, false, false ] );

		for ( const stored of seocart.variants ?? [] ) {
			expect(
				variants.find(
					( { variant_id: variantId } ) =>
						variantId === stored.variant_id
				)?.sellability,
				`${ stored.label } kept its verdict.`
			).toBe( stored.sellability );
		}

		// One of them ticked again, stored disabled with no price: saving waits for its price too.
		const label = `2 kg / ${ GRINDS[ 0 ][ 1 ] }`;
		const again = area.getByRole( 'checkbox', {
			name: `Enabled of ${ label }`,
		} );

		await expect( again ).not.toBeChecked();
		await again.check();
		await expect(
			area.getByText(
				'1 variant needs a price in USD, or to be disabled, before this product can be saved.'
			)
		).toBeVisible();
		await expect( save ).toBeDisabled();
		await area
			.getByRole( 'textbox', { name: `Price in USD of ${ label }` } )
			.fill( '30.00' );
		await expect( save ).toBeEnabled();
		await saveProduct( page );

		const ticked = await readProduct( requestUtils, id );

		expect( ticked.seocart.sellability ).toBe( seocart.sellability );
		expect(
			( ticked.seocart.variants ?? [] )
				.filter( ( variant ) => variant.label === label )
				.map( ( { enabled, prices } ) => [
					enabled,
					prices?.map( ( { price_minor: minor } ) => minor ),
				] )
		).toEqual( [ [ true, [ 3000 ] ] ] );
	} );

	test( 'a variant on sale whose price in the base currency is taken away waits for a price, or to be disabled, before the product can be saved', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is taken away',
			seocart: sixVariants( `UNPRICED${ Date.now() }`, [] ),
		} );

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );
		const save = page.getByRole( 'button', { name: 'Save', exact: true } );
		const price = area.getByRole( 'textbox', {
			name: `Price in USD of ${ VARIANTS[ 0 ] }`,
		} );
		const waiting = area.getByText(
			'1 variant needs a price in USD, or to be disabled, before this product can be saved.'
		);

		await price.fill( '' );
		await price.press( 'Tab' );
		await expect( waiting ).toBeVisible();
		await expect( save ).toBeDisabled();

		await price.fill( '19.00' );
		await expect( waiting ).toHaveCount( 0 );
		await saveProduct( page );

		const { seocart } = await readProduct( requestUtils, id );

		expect( seocart.sellability ).toBe( 'sellable' );
		expect(
			seocart.variants
				?.find( ( { label } ) => label === VARIANTS[ 0 ] )
				?.prices?.map( ( { price_minor: minor } ) => minor )
		).toEqual( [ 1900 ] );
	} );

	test( 'a price or a weight erased with Backspace keeps the product from saving, Ctrl+S too, until it is typed again', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is erased',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `ERASED${ Date.now() }`,
						enabled: true,
						weight_grams: 250,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );
		const save = page.getByRole( 'button', { name: 'Save', exact: true } );
		const { writes, stop } = recordWrites( page );

		for ( const [ name, typed ] of [
			[ 'Weight (g)', '300' ],
			[ 'Price in USD', '25.00' ],
		] ) {
			const field = area.getByRole( 'textbox', { name, exact: true } );

			// Erased one character at a time, as a merchant erases: every text but the empty one can be
			// read, such as 2 of 250 and 2.00 of 20.00.
			await field.click();
			await field.press( 'End' );

			const { length } = await field.inputValue();

			for ( let erased = 0; erased < length; erased++ ) {
				await field.press( 'Backspace' );
			}

			await expect( field ).toHaveValue( '' );
			await expect( save ).toBeDisabled();
			await page.keyboard.press( 'ControlOrMeta+s' );
			await field.pressSequentially( typed );
			await expect( save ).toBeEnabled();
		}

		await saveProduct( page );
		stop();

		expect( writes, 'Ctrl+S saved an erased field.' ).toHaveLength( 1 );

		const [ variant ] =
			( await readProduct( requestUtils, id ) ).seocart.variants ?? [];

		expect( [
			variant?.weight_grams,
			variant?.prices?.map( ( { price_minor: minor } ) => minor ),
		] ).toEqual( [ 300, [ 2500 ] ] );
	} );

	test( 'a price or a weight erased with Backspace keeps its stored value when the code editor takes the field away, so Ctrl+S there saves nothing of it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is erased before the code editor',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `CODEMODE${ Date.now() }`,
						enabled: true,
						weight_grams: 250,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const code = page.locator( '.editor-post-text-editor' );
		const { writes, stop } = recordWrites( page );

		try {
			for ( const name of [ 'Weight (g)', 'Price in USD' ] ) {
				const field = areaOf( page ).getByRole( 'textbox', {
					name,
					exact: true,
				} );

				// Erased one character at a time, as a merchant erases: 2 of 250 and 2.00 of 20.00 can
				// be read on the way, and the field is never left.
				await field.click();
				await field.press( 'End' );

				const { length } = await field.inputValue();

				for ( let erased = 0; erased < length; erased++ ) {
					await field.press( 'Backspace' );
				}

				await expect( field ).toHaveValue( '' );
				expect(
					await page.evaluate( () =>
						( window as unknown as EditorWindow ).wp.data
							.select( 'core/editor' )
							.isPostSavingLocked()
					)
				).toBe( true );

				// The code editor takes the area away with the field in it, and its lock with it.
				await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
				await expect( code ).toBeVisible();
				await page.keyboard.press( 'ControlOrMeta+s' );
				await untilSaved( page );

				const [ variant ] =
					( await readProduct( requestUtils, id ) ).seocart
						.variants ?? [];

				expect(
					[
						variant?.weight_grams,
						variant?.prices?.map(
							( { price_minor: minor } ) => minor
						),
					],
					`${ name } erased, then the code editor and Ctrl+S`
				).toEqual( [ 250, [ 2000 ] ] );

				await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
				await areaReady( page );
			}
		} finally {
			stop();
			await leaveVisualEditor( page, requestUtils );
		}

		expect( writes, 'Ctrl+S saved an erased field.' ).toHaveLength( 0 );
	} );

	test( 'a weight or a price saved with Ctrl+S while its field keeps the focus, then erased with Backspace, keeps what was saved when the code editor takes the field away', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product saved with a field focused, then erased',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `SAVEDFOCUSED${ Date.now() }`,
						enabled: true,
						weight_grams: 250,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const code = page.locator( '.editor-post-text-editor' );
		const stored = async () => {
			const [ variant ] =
				( await readProduct( requestUtils, id ) ).seocart.variants ??
				[];

			return [
				variant?.weight_grams,
				variant?.prices?.map( ( { price_minor: minor } ) => minor ),
			];
		};

		try {
			for ( const [ name, typed, saved ] of [
				[ 'Weight (g)', '400', [ 400, [ 2000 ] ] ],
				[ 'Price in USD', '30.00', [ 400, [ 3000 ] ] ],
			] as const ) {
				const field = areaOf( page ).getByRole( 'textbox', {
					name,
					exact: true,
				} );

				// Typed over and saved with Ctrl+S, the merchant still in the field.
				await field.click();
				await field.press( 'ControlOrMeta+a' );
				await field.pressSequentially( typed );
				await page.keyboard.press( 'ControlOrMeta+s' );
				await untilSaved( page );
				await expect( field ).toBeFocused();
				expect( await stored(), `${ name } saved` ).toEqual( saved );

				// Then erased one character at a time without leaving the field, which the code
				// editor takes away before it is left.
				await field.press( 'End' );

				const { length } = await field.inputValue();

				for ( let erased = 0; erased < length; erased++ ) {
					await field.press( 'Backspace' );
				}

				await expect( field ).toHaveValue( '' );

				const { writes, stop } = recordWrites( page );

				await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
				await expect( code ).toBeVisible();
				await page.keyboard.press( 'ControlOrMeta+s' );
				await untilSaved( page );
				stop();

				expect(
					writes,
					`Ctrl+S saved the erased ${ name }.`
				).toHaveLength( 0 );
				expect(
					await stored(),
					`${ name } erased, then the code editor and Ctrl+S`
				).toEqual( saved );

				await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
				await areaReady( page );
			}
		} finally {
			await leaveVisualEditor( page, requestUtils );
		}
	} );

	test( 'an autosave while an erased price reads only its first digit leaves the saved price for the erase to keep', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A draft autosaved while its price is erased',
			status: 'draft',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `AUTOSAVED${ Date.now() }`,
						enabled: true,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const field = areaOf( page ).getByRole( 'textbox', {
			name: 'Price in USD',
			exact: true,
		} );
		const code = page.locator( '.editor-post-text-editor' );
		const { writes, stop } = recordWrites( page );

		try {
			// Erased to the 2 of 20.00, a price that can be read, so nothing locks the autosave.
			await field.click();
			await field.press( 'End' );

			for ( let erased = 0; erased < 4; erased++ ) {
				await field.press( 'Backspace' );
			}

			await expect( field ).toHaveValue( '2' );

			// An autosave sends the title, the excerpt and the content, and nothing of the area.
			await page.evaluate( () =>
				( window as unknown as EditorWindow ).wp.data
					.dispatch( 'core/editor' )
					.autosave()
			);
			expect(
				writes.map( ( request ) => restRoute( request.url() ) )
			).toEqual( [ `/wp/v2/${ REST_BASE }/${ id }/autosaves` ] );

			// The last digit erased, and the code editor takes the field away before it is left.
			await field.press( 'Backspace' );
			await expect( field ).toHaveValue( '' );
			await page.keyboard.press( 'ControlOrMeta+Shift+Alt+M' );
			await expect( code ).toBeVisible();
			await page.keyboard.press( 'ControlOrMeta+s' );
			await untilSaved( page );
		} finally {
			stop();
			await leaveVisualEditor( page, requestUtils );
		}

		expect( writes, 'Ctrl+S saved the erased price.' ).toHaveLength( 1 );

		const [ variant ] =
			( await readProduct( requestUtils, id ) ).seocart.variants ?? [];

		expect(
			variant?.prices?.map( ( { price_minor: minor } ) => minor )
		).toEqual( [ 2000 ] );
	} );

	/**
	 * The prices in minor units a product's one variant has stored, or their compare-at prices.
	 *
	 * @param requestUtils The REST client.
	 * @param id           The post's id.
	 * @param amount       The amount of each price: the price itself, or its compare-at price.
	 * @return The amounts.
	 */
	async function storedPrices(
		requestUtils: RequestUtils,
		id: number,
		amount: 'price_minor' | 'compare_at_minor' = 'price_minor'
	) {
		const [ variant ] =
			( await readProduct( requestUtils, id ) ).seocart.variants ?? [];

		return variant?.prices?.map( ( price ) => price[ amount ] );
	}

	test( 'a price saved with Ctrl+S and erased before the answer comes keeps what the save stored when the code editor takes the field away', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is erased while it is saved',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `ERASEDINFLIGHT${ Date.now() }`,
						enabled: true,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const field = areaOf( page ).getByRole( 'textbox', {
			name: 'Price in USD',
			exact: true,
		} );

		try {
			await field.click();
			await field.press( 'ControlOrMeta+a' );
			await field.pressSequentially( '25.99' );

			// Saved with Ctrl+S, the merchant still in the field, who erases a character while the
			// editor waits for the answer: the site has stored 25.99 by then.
			const answer = await holdSaveAnswer( page );

			await page.keyboard.press( 'ControlOrMeta+s' );
			await answer.stored;
			await field.press( 'End' );
			await field.press( 'Backspace' );
			await expect( field ).toHaveValue( '25.9' );
			expect( await isSaving( page ) ).toBe( true );
			expect( await storedPrices( requestUtils, id ) ).toEqual( [
				2599,
			] );

			// The answer comes while the field reads 25.9, and the rest is erased before it is left.
			await answer.release();

			for ( let erased = 0; erased < 4; erased++ ) {
				await field.press( 'Backspace' );
			}

			await expect( field ).toHaveValue( '' );
			expect(
				await saveFromCodeEditor( page ),
				'Ctrl+S saved the erased price.'
			).toHaveLength( 0 );
			expect( await storedPrices( requestUtils, id ) ).toEqual( [
				2599,
			] );
		} finally {
			await leaveVisualEditor( page, requestUtils );
		}
	} );

	test( 'a price typed and left, then another saved with Ctrl+S and erased while it is saved, keeps the one saved when the code editor takes the field away', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is retyped, saved and erased',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `RETYPEDINFLIGHT${ Date.now() }`,
						enabled: true,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const field = areaOf( page ).getByRole( 'textbox', {
			name: 'Price in USD',
			exact: true,
		} );

		try {
			// 30.00 typed and left, and not saved.
			await field.click();
			await field.press( 'ControlOrMeta+a' );
			await field.pressSequentially( '30.00' );
			await field.press( 'Tab' );
			await expect( field ).not.toBeFocused();

			// Back in, 35.00 typed and saved with Ctrl+S, and erased while the editor waits for the
			// answer: the emptied field holds the 30.00 it had when the merchant came back into it.
			await field.click();
			await field.press( 'ControlOrMeta+a' );
			await field.pressSequentially( '35.00' );

			const answer = await holdSaveAnswer( page );

			await page.keyboard.press( 'ControlOrMeta+s' );
			await answer.stored;
			await field.press( 'End' );

			for ( let erased = 0; erased < 5; erased++ ) {
				await field.press( 'Backspace' );
			}

			await expect( field ).toHaveValue( '' );
			expect( await isSaving( page ) ).toBe( true );
			expect( await storedPrices( requestUtils, id ) ).toEqual( [
				3500,
			] );

			// The answer comes with the field still empty, and the code editor takes it away.
			await answer.release();
			expect(
				await saveFromCodeEditor( page ),
				'Ctrl+S saved the erased price.'
			).toHaveLength( 0 );
			expect( await storedPrices( requestUtils, id ) ).toEqual( [
				3500,
			] );
		} finally {
			await leaveVisualEditor( page, requestUtils );
		}
	} );

	test( 'a price saved with Ctrl+S beside a new compare-at price, erased while it is saved and taken away by the code editor before the answer, keeps what the save stored', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is erased while it is saved with a new compare-at price',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `ERASEDBESIDE${ Date.now() }`,
						enabled: true,
						prices: [
							{
								currency: 'USD',
								price_minor: 2000,
								compare_at_minor: 2500,
							},
						],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const area = areaOf( page );
		const field = area.getByRole( 'textbox', {
			name: 'Price in USD',
			exact: true,
		} );
		const compareAt = area.getByRole( 'textbox', {
			name: 'Compare-at price in USD',
			exact: true,
		} );

		try {
			// The compare-at price changed and left, then a price typed: the save carries both, so the
			// price the field had before the save is not the one the site answers with.
			await compareAt.click();
			await compareAt.press( 'ControlOrMeta+a' );
			await compareAt.pressSequentially( '30.00' );
			await compareAt.press( 'Tab' );
			await field.click();
			await field.press( 'ControlOrMeta+a' );
			await field.pressSequentially( '25.99' );

			expect(
				await eraseWhileSavedThenSaveFromCodeEditor( page, field ),
				'Ctrl+S saved the erased price.'
			).toHaveLength( 0 );
			expect( await storedPrices( requestUtils, id ) ).toEqual( [
				2599,
			] );
			expect(
				await storedPrices( requestUtils, id, 'compare_at_minor' )
			).toEqual( [ 3000 ] );
		} finally {
			await leaveVisualEditor( page, requestUtils );
		}
	} );

	test( 'a price typed and left, then another saved with Ctrl+S, erased while it is saved and taken away by the code editor before the answer, keeps the one saved', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product whose price is retyped, saved, erased and taken away',
			seocart: {
				variants: [
					{
						combination: [],
						sku: `RETYPEDGONE${ Date.now() }`,
						enabled: true,
						prices: [ { currency: 'USD', price_minor: 2000 } ],
					},
				],
			},
		} );

		await admin.editPost( id );
		await areaReady( page );

		const field = areaOf( page ).getByRole( 'textbox', {
			name: 'Price in USD',
			exact: true,
		} );

		try {
			// 30.00 typed and left, and not saved: the field has it when the merchant comes back in.
			await field.click();
			await field.press( 'ControlOrMeta+a' );
			await field.pressSequentially( '30.00' );
			await field.press( 'Tab' );
			await expect( field ).not.toBeFocused();
			await field.click();
			await field.press( 'ControlOrMeta+a' );
			await field.pressSequentially( '35.00' );

			expect(
				await eraseWhileSavedThenSaveFromCodeEditor( page, field ),
				'Ctrl+S saved the erased price.'
			).toHaveLength( 0 );
			expect( await storedPrices( requestUtils, id ) ).toEqual( [
				3500,
			] );
		} finally {
			await leaveVisualEditor( page, requestUtils );
		}
	} );

	test( 'a save names only what the merchant changed, so a change made elsewhere to a variant they did not touch stands', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id, seocart } = await createProduct( requestUtils, {
			title: 'A product changed in two places',
			seocart: sixVariants( `ELSEWHERE${ Date.now() }`, [] ),
		} );

		await admin.editPost( id );
		await areaReady( page );

		// Another writer, after the editor read the product: a price and a state for a variant the merchant leaves alone.
		const [ touched, other ] = VARIANTS;

		await requestUtils.rest( {
			method: 'PUT',
			path: `/wp/v2/${ REST_BASE }/${ id }`,
			data: {
				seocart: {
					variants: ( seocart.variants ?? [] ).map(
						( { combination, sku, label } ) => ( {
							combination,
							sku,
							...( label === other
								? {
										enabled: false,
										prices: [
											{
												currency: 'USD',
												price_minor: 2400,
											},
										],
									}
								: {} ),
						} )
					),
				},
			},
		} );

		await areaOf( page )
			.getByRole( 'textbox', { name: `Weight (g) of ${ touched }` } )
			.fill( '300' );

		const { writes, stop } = recordWrites( page );

		await saveProduct( page );
		stop();

		const { variants: sent } = (
			writes[ 0 ].postDataJSON() as {
				seocart: { variants: VariantRecord[] };
			}
		 ).seocart;

		expect( sent.map( ( entry ) => Object.keys( entry ) ) ).toEqual( [
			[ 'combination', 'sku', 'weight_grams' ],
			...Array( 5 ).fill( [ 'combination', 'sku' ] ),
		] );
		expect( sent[ 0 ].weight_grams ).toBe( 300 );

		const after = new Map(
			(
				( await readProduct( requestUtils, id ) ).seocart.variants ?? []
			).map( ( variant ) => [ variant.label, variant ] )
		);

		expect( after.get( touched )?.weight_grams ).toBe( 300 );
		expect( after.get( touched )?.enabled ).toBe( true );
		expect(
			[
				after.get( other )?.enabled,
				after
					.get( other )
					?.prices?.map( ( { price_minor: minor } ) => minor ),
			],
			'The variant changed elsewhere kept that change.'
		).toEqual( [ false, [ 2400 ] ] );
	} );

	test( 'the warning before a save names a variant added elsewhere since the product was opened, which the save takes off sale too', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const prefix = `ADDED${ Date.now() }`;
		const sizes = [
			[ 'small', 'Small' ],
			[ 'medium', 'Medium' ],
			[ 'large', 'Large' ],
		];
		const product = ( count: number ) => ( {
			options: [
				{
					key: 'size',
					label: 'Size',
					values: sizes
						.slice( 0, count )
						.map( ( [ key, label ] ) => ( { key, label } ) ),
				},
			],
			variants: sizes.slice( 0, count ).map( ( [ key ] ) => ( {
				combination: [ { axis: 'size', value: key } ],
				sku: `${ prefix }-${ key }`,
				enabled: true,
				prices: [ { currency: 'USD', price_minor: 1000 } ],
			} ) ),
		} );
		const { id } = await createProduct( requestUtils, {
			title: 'A product that grows elsewhere',
			seocart: product( 2 ),
		} );

		await admin.editPost( id );
		await areaReady( page );

		const grown = await requestUtils.rest< ProductRecord >( {
			method: 'PUT',
			path: `/wp/v2/${ REST_BASE }/${ id }`,
			params: { context: 'edit' },
			data: { seocart: product( 3 ) },
		} );
		const area = areaOf( page );

		await area.getByRole( 'button', { name: 'Edit Size' } ).click();
		await area.getByRole( 'button', { name: 'Remove Medium' } ).click();
		await expect(
			area.getByText(
				'When you save, 2 variants go off sale because their values are gone: Medium, Large.'
			)
		).toBeVisible();
		await expect(
			area.getByText(
				'Added since you opened this product, and not shown here: Large.'
			)
		).toBeVisible();

		await saveProduct( page );

		const enabled = new Map(
			(
				( await readProduct( requestUtils, id ) ).seocart.variants ?? []
			).map( ( variant ) => [ variant.variant_id, variant.enabled ] )
		);

		expect(
			( grown.seocart.variants ?? [] ).map( ( variant ) => [
				variant.label,
				enabled.get( variant.variant_id ),
			] )
		).toEqual( [
			[ 'Small', true ],
			[ 'Medium', false ],
			[ 'Large', false ],
		] );
	} );

	test( 'Undo after a save leaves the area alone, so the next save sends only what changed', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product saved, then undone',
			seocart: { sku: `UNDO${ Date.now() }`, price_minor: 1800 },
		} );

		await admin.editPost( id );
		await areaReady( page );

		const price = areaOf( page ).getByRole( 'textbox', {
			name: 'Price in USD',
			exact: true,
		} );

		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.editPost( { title: 'Renamed before the save' } )
		);
		await price.fill( '25.00' );
		await saveProduct( page );

		// Undo takes the title back, and leaves the price the save sent.
		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.undo()
		);
		await expect( price ).toHaveValue( '25.00' );

		let { writes, stop } = recordWrites( page );

		await saveProduct( page );
		stop();

		expect( writes ).toHaveLength( 1 );
		expect( writes[ 0 ].postDataJSON().seocart ).toBeUndefined();

		await price.fill( '26.00' );
		( { writes, stop } = recordWrites( page ) );
		await saveProduct( page );
		stop();

		const { seocart: sent } = writes[ 0 ].postDataJSON() as {
			seocart: Record< string, unknown >;
		};

		expect( Object.keys( sent ) ).toEqual( [ 'variants' ] );
		expect(
			( await readProduct( requestUtils, id ) ).seocart.variants?.[ 0 ]
				.prices?.[ 0 ].price_minor
		).toBe( 2600 );
	} );

	test( 'a draft’s preview saves nothing while saving is locked', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: POST_TYPE,
			title: 'A draft to preview',
		} );
		await areaReady( page );
		await addOption( page, 'Size', [ 'Small', 'Medium' ] );
		await expect(
			areaOf( page ).getByText(
				'2 variants need a SKU before this product can be saved.'
			)
		).toBeVisible();

		const { writes, stop } = recordWrites( page );
		const popup = page.waitForEvent( 'popup' );

		await page.getByRole( 'button', { name: 'View', exact: true } ).click();
		await page
			.getByRole( 'menuitem', { name: /Preview in new tab/ } )
			.click();

		const preview = await popup;

		// The preview opens the post's link once the editor is done with it, and has saved it or not.
		await preview.waitForURL( /[?&]p=\d+/ );
		await preview.close();
		stop();

		expect(
			writes,
			'The preview saved the draft while saving was locked.'
		).toHaveLength( 0 );
		await expect(
			page.locator( '.components-notice.is-error' )
		).toHaveCount( 0 );
	} );

	test( 'a draft’s preview beside a meta box saves nothing while saving is locked, and says why', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: POST_TYPE,
			title: 'A draft to preview beside a meta box',
		} );
		await areaReady( page );
		await addOption( page, 'Size', [ 'Small', 'Medium' ] );
		await expect(
			areaOf( page ).getByText(
				'2 variants need a SKU before this product can be saved.'
			)
		).toBeVisible();

		// A meta box, as another plugin's would be: with one on the screen, WordPress saves a draft for its preview
		// whatever the locks on saving.
		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/edit-post' )
				.setAvailableMetaBoxesPerLocation( {
					advanced: [ { id: 'e2e-meta-box', title: 'A meta box' } ],
				} )
		);

		const { writes, stop } = recordWrites( page );
		const popup = page.waitForEvent( 'popup' );

		await page.getByRole( 'button', { name: 'View', exact: true } ).click();
		await page
			.getByRole( 'menuitem', { name: /Preview in new tab/ } )
			.click();

		const preview = await popup;

		await preview.waitForURL( /[?&]p=\d+/ );
		await preview.close();
		stop();

		expect(
			writes,
			'The preview saved the draft while saving was locked.'
		).toHaveLength( 0 );
		await expect(
			page.locator( '.components-notice.is-error' )
		).toContainText(
			'The Product data area has something to put right before the product can be saved.'
		);
	} );

	test( 'a second marker in a product’s content gives no second area, and leaves the post as it was', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product with two markers',
			content: `<!-- wp:paragraph --><p>Its description.</p><!-- /wp:paragraph -->${ MARKER }${ MARKER }`,
		} );

		await admin.editPost( id );
		await expect( page.frameLocator( CANVAS ).locator( AREA ) ).toHaveCount(
			1
		);
		await areaReady( page );

		const state = await editorState( page );

		expect( state.blocks ).toEqual( [ 'core/paragraph', BLOCK ] );
		expect( state.dirty ).toBe( false );
		expect( state.undo ).toBe( false );
		await expect(
			page.frameLocator( CANVAS ).locator( '.block-editor-warning' )
		).toHaveCount( 0 );
	} );

	test( 'a marker inside a group gives no second area, keeps the rest of the group, and leaves the post as it was', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'A product with its marker in a group',
			content: `<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inside the group.</p><!-- /wp:paragraph -->${ MARKER }</div><!-- /wp:group -->`,
		} );

		await admin.editPost( id );
		await expect( page.frameLocator( CANVAS ).locator( AREA ) ).toHaveCount(
			1
		);
		await areaReady( page );

		const state = await editorState( page );

		expect( state.blocks ).toEqual( [ 'core/group', BLOCK ] );
		expect( state.dirty ).toBe( false );
		expect( state.undo ).toBe( false );
		await expect(
			page.frameLocator( CANVAS ).getByText( 'Inside the group.' )
		).toBeVisible();
		await expect(
			page.frameLocator( CANVAS ).locator( '.block-editor-warning' )
		).toHaveCount( 0 );

		// The next save stores the one marker, last, and the group with what else it held.
		await page.evaluate( () =>
			( window as unknown as EditorWindow ).wp.data
				.dispatch( 'core/editor' )
				.editPost( { title: 'Its marker taken out of the group' } )
		);
		await saveProduct( page );

		const { content } = await readProduct( requestUtils, id );

		expect( content.raw.split( MARKER ) ).toEqual( [
			expect.stringContaining( 'Inside the group.' ),
			'',
		] );
	} );

	test( 'a new translation shows one sentence and a button to its source post, and edits nothing', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { id } = await createProduct( requestUtils, {
			title: 'The source of a translation',
		} );

		await admin.visitAdminPage(
			'post-new.php',
			`post_type=${ POST_TYPE }&from_post=${ id }`
		);

		const area = areaOf( page );

		await expect( area ).toBeVisible();
		await expect( area ).toContainText(
			'This post is a translation: the product’s prices, stock and variants are edited on its source post.'
		);
		await expect( area.getByRole( 'textbox' ) ).toHaveCount( 0 );
		await expect( area.getByRole( 'button' ) ).toHaveCount( 1 );

		// Its first save names the post it translates, an edit Undo cannot take back.
		const source = () =>
			page.evaluate( () => {
				const { select } = ( window as unknown as EditorWindow ).wp
					.data;
				const editor = select( 'core/editor' );
				const edits = select( 'core' ).getEntityRecordEdits(
					'postType',
					editor.getCurrentPostType(),
					editor.getCurrentPostId()
				) as { seocart?: { translation_of?: number } } | undefined;

				return edits?.seocart?.translation_of;
			} );

		await expect.poll( source ).toBe( id );

		for ( let undo = 0; undo < 3; undo++ ) {
			await page.evaluate( () =>
				( window as unknown as EditorWindow ).wp.data
					.dispatch( 'core/editor' )
					.undo()
			);
		}

		expect( await source() ).toBe( id );

		await area
			.getByRole( 'button', { name: 'Edit the source post' } )
			.click();
		await page.waitForURL( new RegExp( `post=${ id }&action=edit` ) );
	} );
} );
