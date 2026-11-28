/**
 * The storefront's data layer: the e-commerce events SEOCart pushes onto `window.dataLayer` for a
 * tag manager to read, declared once here with the keys each may carry.
 *
 * An event is `{ event, ecommerce: { currency, items } }`. An item carries ids, a quantity and its
 * unit price in minor units, as integers: no decimal amount, no total a browser worked out, and no
 * direct identifier, such as an email, a name of a person, a user or customer id, a cart token, a
 * line's identity or an address. A key outside the declaration is refused. SEOCart only pushes:
 * it loads no tag manager and sends the data layer nowhere.
 */

/**
 * The events, each with the keys of its `ecommerce` object.
 *
 * @type {Object<string, string[]>}
 */
export const EVENTS = {
	view_item: [ 'currency', 'items' ],
	add_to_cart: [ 'currency', 'items' ],
};

/**
 * The keys of an item.
 *
 * @type {string[]}
 */
export const ITEM = [
	'product_uuid',
	'variant_id',
	'name',
	'variant_label',
	'quantity',
	'unit_price_minor',
];

/**
 * Refuses what the declaration does not allow.
 *
 * @param {string}   where   What is checked, for the error.
 * @param {Object}   object  The object.
 * @param {string[]} allowed Its allowed keys.
 */
function refuseOthers( where, object, allowed ) {
	const others = Object.keys( object ).filter(
		( key ) => ! allowed.includes( key )
	);

	if ( others.length ) {
		throw new Error( `seocart.data_layer.${ where }:${ others.join() }` );
	}
}

/**
 * Pushes one event onto the data layer, created when the page has none.
 *
 * @param {string} name      The event: a key of EVENTS.
 * @param {Object} ecommerce Its `ecommerce` object.
 * @param {Object} target    The object that holds `dataLayer`: the window.
 */
export function pushEvent( name, ecommerce, target ) {
	if ( ! EVENTS[ name ] ) {
		throw new Error( `seocart.data_layer.event:${ name }` );
	}

	refuseOthers( name, ecommerce, EVENTS[ name ] );
	( ecommerce.items ?? [] ).forEach( ( entry ) =>
		refuseOthers( 'item', entry, ITEM )
	);

	target.dataLayer = target.dataLayer || [];
	target.dataLayer.push( { event: name, ecommerce } );
}

/**
 * Returns the item of a variant of a product, its unit price the page's.
 *
 * @param {Object} product  The product's state.
 * @param {Object} variant  The variant.
 * @param {number} quantity How many units.
 * @return {Object} The item.
 */
export function item( product, variant, quantity ) {
	return {
		product_uuid: product.uuid,
		variant_id: variant.id,
		name: product.name,
		variant_label: variant.label,
		quantity,
		unit_price_minor: variant.price?.minor ?? null,
	};
}

/**
 * Calls back once the document is shown: at once, or when a prerendered or hidden page is first seen.
 *
 * A page a browser prerenders, or opens in a tab not looked at, has not been viewed; an event of its
 * viewing waits until it is.
 *
 * @param {Document} document The document.
 * @param {Function} callback What to do then.
 */
export function whenShown( document, callback ) {
	if ( document.prerendering ) {
		document.addEventListener(
			'prerenderingchange',
			() => whenShown( document, callback ),
			{ once: true }
		);
	} else if ( 'hidden' === document.visibilityState ) {
		const shown = () => {
			if ( 'visible' === document.visibilityState ) {
				document.removeEventListener( 'visibilitychange', shown );
				callback();
			}
		};

		document.addEventListener( 'visibilitychange', shown );
	} else {
		callback();
	}
}
