/**
 * `@seocart/product`: the products a page shows and the variant picked of each, as the Interactivity
 * store `seocart/product`.
 *
 * The page prints each product's state under its post's id (`state.products[ postId ]`): its
 * options, the variants a visitor may buy with their prices as minor units and the server's text,
 * whether each is in stock, the selection and the quantity. The product's blocks, its price, its
 * picker and its form, are siblings that share that one state, each naming the post in its context.
 *
 * Picking a value selects the variant of the new combination, and the price, the legends and the
 * button follow; a value that makes no variant that can be bought with the others is marked
 * unavailable and stays selectable. The module never computes with a price: it shows the text the
 * server wrote for the variant selected. An add goes through the store `seocart/store`, which owns
 * every request; the button reads "Adding…" and is busy meanwhile, keeps the focus, and the form
 * then says what came of it (outcome.js). The data layer gets `view_item` once per product, when
 * the page is first shown, and `add_to_cart` for each add the store accepted.
 */

/**
 * WordPress dependencies, and the storefront's store module
 *
 * The build leaves both out and lists them in the .asset.json file, and WordPress puts both in
 * the page's import map.
 */
/* eslint-disable import/no-unresolved */
import {
	store,
	getContext,
	getConfig,
	withSyncEvent,
} from '@wordpress/interactivity';
import {
	actions as cart,
	pushEvent,
	item,
	text,
	whenShown,
} from '@seocart/store';
/* eslint-enable import/no-unresolved */

/**
 * Internal dependencies
 */
import {
	available,
	buttonLabel,
	findVariant,
	purchasable,
	valueLabel,
} from './matcher';
import { afterAdd } from './outcome';

/**
 * The store's configuration, which holds the page's currency and every sentence.
 *
 * @return {Object} The configuration.
 */
const config = () => getConfig( 'seocart/store' );

/**
 * The products whose viewing was pushed onto the data layer, by post id.
 *
 * @type {Set<number>}
 */
const viewed = new Set();

/**
 * Keeps a quantity within one and the most the form allows.
 *
 * @param {number}      quantity The quantity asked for.
 * @param {number|null} most     The most, or null for no limit.
 * @return {number} The quantity.
 */
const bounded = ( quantity, most ) =>
	Math.max( 1, Math.min( quantity || 1, most ?? Infinity ) );

const { state } = store( 'seocart/product', {
	state: {
		get product() {
			return state.products[ getContext().postId ];
		},
		get selected() {
			return findVariant(
				state.product.variants,
				state.product.selection
			);
		},
		get priceText() {
			return state.selected?.price?.text ?? '';
		},
		get compareAtText() {
			return state.selected?.compareAt?.text ?? '';
		},
		get axisValueLabel() {
			return valueLabel( state.product, getContext().axis );
		},
		get valueUnavailable() {
			const { axis, value } = getContext();

			return ! available( state.product, axis, value );
		},
		get valueUnavailableText() {
			return state.valueUnavailable
				? text( config().strings, 'value_unavailable' )
				: '';
		},
		get cannotAdd() {
			return ! purchasable( state.selected );
		},
		get adding() {
			return Boolean( state.product.adding );
		},
		get buttonLabel() {
			return text(
				config().strings,
				state.product.adding ? 'adding' : buttonLabel( state.selected )
			);
		},
		get quantity() {
			return state.product.quantity;
		},
		get maxQuantity() {
			return state.product.max ?? null;
		},
		get statusText() {
			return state.product.status ?? '';
		},
		get alertText() {
			return state.product.alert ?? '';
		},
	},
	actions: {
		select() {
			const { axis, value } = getContext();

			state.product.selection[ axis ] = value;
		},
		decrease() {
			state.product.quantity = bounded(
				state.product.quantity - 1,
				state.maxQuantity
			);
		},
		increase() {
			state.product.quantity = bounded(
				state.product.quantity + 1,
				state.maxQuantity
			);
		},
		setQuantity( event ) {
			state.product.quantity = bounded(
				parseInt( event.target.value, 10 ),
				state.maxQuantity
			);
		},
		add: withSyncEvent( function* ( event ) {
			event.preventDefault();

			const product = state.product;
			const variant = state.selected;
			const quantity = product.quantity;

			if ( product.adding || ! purchasable( variant ) ) {
				return;
			}

			product.adding = true;
			product.status = '';
			product.alert = '';

			const outcome = yield cart.addLines( [
				{ variant_id: variant.id, quantity },
			] );
			const said = afterAdd(
				outcome,
				product,
				variant,
				config().strings
			);

			product.adding = false;
			product.status = said.status;
			product.alert = said.alert;

			if ( undefined !== said.max ) {
				product.max = said.max;
				product.quantity = bounded( product.quantity, said.max );
			}

			if ( said.soldOut ) {
				variant.inStock = false;
			}

			if ( outcome.ok ) {
				pushEvent(
					'add_to_cart',
					{
						currency: config().currency,
						items: [ item( product, variant, quantity ) ],
					},
					window
				);
			}
		} ),
	},
	callbacks: {
		viewItem() {
			const { postId } = getContext();

			if ( viewed.has( postId ) ) {
				return;
			}

			viewed.add( postId );

			const product = state.product;
			const variant = state.selected;
			const { currency } = config();

			whenShown( document, () =>
				pushEvent(
					'view_item',
					{
						currency,
						items: [
							item(
								product,
								variant ?? product.variants[ 0 ],
								1
							),
						],
					},
					window
				)
			);
		},
	},
} );
