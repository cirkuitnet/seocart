# The storefront's data layer

Experimental until 1.0: the events and their keys may change before then.

SEOCart's storefront blocks push e-commerce events onto `window.dataLayer`, creating it when the page has
none, for a tag manager the site adds to read. SEOCart loads no tag manager and sends the data layer nowhere:
pushing an event fetches nothing and sends nothing.

Each event is pushed as one object, `{ event, ecommerce }`. The events and the keys each may carry are declared
once, in `assets/blocks/store/data-layer.js`, and this page is held equal to that declaration by a test. An
event, a key of `ecommerce` or a key of an item outside these tables is refused: `pushEvent()` throws and
pushes nothing.

## Events

| Event         | Pushed                                                                                                                                                                                | Keys of `ecommerce` |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| `view_item`   | Once for each product whose add-to-cart form is on the page, when the page is first shown: not while the browser prerenders it, and not while it waits in a tab nobody has looked at. | `currency`, `items` |
| `add_to_cart` | Each time the store accepts an add, flagged or not: short of stock, out of stock, or without a price in the cart's currency.                                                          | `currency`, `items` |

`currency` is the page's currency, a three-letter ISO 4217 code such as `EUR`. `items` lists one item: for
`view_item`, the variant selected when the page was written, with a quantity of 1; for `add_to_cart`, the
variant added and how many.

## An item

| Key                | Holds                                                                                                                                          |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `product_uuid`     | The product's UUID.                                                                                                                            |
| `variant_id`       | The variant's id, a whole number.                                                                                                              |
| `name`             | The product's name.                                                                                                                            |
| `variant_label`    | The variant's label, such as `250 g / Light`.                                                                                                  |
| `quantity`         | How many units, a whole number.                                                                                                                |
| `unit_price_minor` | The price of one unit in the page's currency, as the page shows it, in minor units (cents for `EUR`), a whole number; `null` when it has none. |

No event or item carries a decimal amount, a total the browser worked out, or anything about the shopper: no
user or customer id, email, name of a person, cart token, line of the cart, order key or address.

## Example

```js
window.dataLayer.push( {
	event: 'add_to_cart',
	ecommerce: {
		currency: 'EUR',
		items: [
			{
				product_uuid: '0193b7a2-7c1e-7d2a-9f00-5e1a2b3c4d5e',
				variant_id: 11,
				name: 'House blend',
				variant_label: '250 g / Light',
				quantity: 2,
				unit_price_minor: 1250,
			},
		],
	},
} );
```
