/**
 * A product whose page holds the storefront's three product blocks, created over REST by a spec as
 * the administrator and deleted by it afterwards.
 *
 * The product is published, its options and variants given in the same request as its post, as
 * the product editor sends them, each variant with a price in the store's base currency and, when
 * asked, units at the store's default location. Its content is the three blocks, each with no
 * attribute, as an editor inserts them.
 */

import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/** The storefront's product blocks, in the order a product page shows them. */
export const PRODUCT_BLOCKS = [
	'seocart/product-price',
	'seocart/variant-picker',
	'seocart/add-to-cart',
] as const;

/** A product page's content: the three blocks, as an editor saves them. */
export const PRODUCT_BLOCKS_CONTENT = PRODUCT_BLOCKS.map(
	( name ) => `<!-- wp:${ name } /-->`
).join( '\n\n' );

/** The products' REST base. */
const REST_BASE = 'seocart-products';

/** One option axis: its key and label, and its values' keys and labels, in order. */
export interface AxisInput {
	key: string;
	label: string;
	values: Array< { key: string; label: string } >;
}

/** One variant to create: its value on each axis, its price in minor units of the base currency, and its units. */
export interface VariantInput {
	values: Record< string, string >;
	priceMinor: number;
	compareAtMinor?: number;
	onHand?: number;
}

/** A product as created: its post, its page's address and its variants as stored, in the order given. */
export interface BlockProduct {
	id: number;
	link: string;
	variants: Array< { id: number; label: string | null } >;
}

/**
 * Creates a published product whose content is the three product blocks.
 *
 * @param requestUtils The administrator's REST client.
 * @param title        The product's title.
 * @param axes         Its option axes; none for a product without options.
 * @param variants     Its variants, one per combination it sells; one with no values for a product without options.
 * @param currency     The store's base currency.
 * @return The product as created.
 */
export async function createBlockProduct(
	requestUtils: RequestUtils,
	title: string,
	axes: AxisInput[],
	variants: VariantInput[],
	currency = 'USD'
): Promise< BlockProduct > {
	const [ location ] = await requestUtils.rest<
		Array< { location_uuid: string } >
	>( {
		method: 'GET',
		path: '/seocart/v1/locations',
	} );
	const stamp = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;
	const product = await requestUtils.rest< {
		id: number;
		link: string;
		seocart: {
			variants: Array< { variant_id: number; label: string | null } >;
		};
	} >( {
		method: 'POST',
		path: `/wp/v2/${ REST_BASE }`,
		params: { context: 'edit' },
		data: {
			title,
			status: 'publish',
			content: PRODUCT_BLOCKS_CONTENT,
			seocart: {
				options: axes,
				variants: variants.map( ( variant, index ) => ( {
					combination: Object.entries( variant.values ).map(
						( [ axis, value ] ) => ( { axis, value } )
					),
					sku: `E2E-BLOCKS-${ stamp }-${ index }`,
					enabled: true,
					prices: [
						{
							currency,
							price_minor: variant.priceMinor,
							...( undefined === variant.compareAtMinor
								? {}
								: {
										compare_at_minor:
											variant.compareAtMinor,
									} ),
						},
					],
					stock:
						undefined === variant.onHand
							? []
							: [
									{
										location_uuid: location.location_uuid,
										on_hand: variant.onHand,
										expected_on_hand: 0,
									},
								],
				} ) ),
			},
		},
	} );

	return {
		id: product.id,
		link: product.link,
		variants: product.seocart.variants.map( ( variant ) => ( {
			id: variant.variant_id,
			label: variant.label,
		} ) ),
	};
}

/**
 * Moves a product created by createBlockProduct() to the trash: a variant an order or a cart holds cannot be deleted.
 *
 * @param requestUtils The administrator's REST client.
 * @param id           The product's post.
 */
export async function trashBlockProduct(
	requestUtils: RequestUtils,
	id: number
): Promise< void > {
	await requestUtils.rest( {
		method: 'DELETE',
		path: `/wp/v2/${ REST_BASE }/${ id }`,
	} );
}
