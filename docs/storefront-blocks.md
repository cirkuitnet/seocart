# Storefront blocks

Experimental until 1.0: the blocks' markup, their stores' state and the way a block is added may change
before then.

SEOCart's storefront is made of blocks that a theme places in a product's content, or in the template a
product's page is rendered with. Three are built so far, for a product's page:

| Block                    | Shows                                                                               |
| ------------------------ | ----------------------------------------------------------------------------------- |
| `seocart/product-price`  | The price of the variant selected, and its price before a reduction when it has one |
| `seocart/variant-picker` | One group of radio buttons for each option of the product                           |
| `seocart/add-to-cart`    | The quantity, the add button, and what an add did                                   |

Each block is rendered by the server, the same for every visitor: the page holds no nonce, no cart, no count
of units and nothing about the visitor, and sets no cookie, so a page cache can serve it to everyone. The
blocks show the variants the sellability rule lets a visitor buy. A variant the rule refuses is left out of
the page, so its combination of values reads as unavailable, as a combination the product does not sell does.

The classes the blocks print and the variables a theme styles them with are in [styling.md](styling.md), and
the events they push for a tag manager in [data-layer.md](data-layer.md).

## How a block is added

1. **The list.** `Modules::STOREFRONT_BLOCKS` in `src/Platform/Kernel/Modules.php` names each block with the
   class that renders it. On `init`, `Blocks::register()` registers each block from its folder. The renderer
   is taken from the container the first time its block renders, not when the block is registered.
2. **`block.json`**, in `src/Platform/Storefront/blocks/<name>/`: `apiVersion` 3, the block's name, title,
   category, icon, description and keywords, `usesContext` with `postId` and `postType`, `supports` with
   `html: false`, `interactivity: true` and `autoRegister: true`, and the text domain. It names no script, module, stylesheet or render
   file. With `autoRegister`, the editor registers the block itself and shows the server's
   preview of it, and the renderer loads what a page needs. `tests/Unit/Platform/Storefront/BlockListTest.php`
   holds the list and the folders equal, and refuses a member that names such a file.
3. **A renderer**: a class that implements `SEOCart\Platform\Storefront\BlockRenderer`, bound in the
   container. A product block's renderer hands its component to `ProductBlocks::render()`, which finds the
   product of the post in the block's context, prints the product's state once a request, and loads the
   stylesheet; for a block a shopper acts on, when the product has a variant to buy, it also prints the
   store's configuration and loads the module `@seocart/product`. Where the post has no product it renders
   nothing, and in the editor's preview, which WordPress renders over the REST API, a one-line placeholder.
4. **A component**: a class whose static `markup()` writes the block's HTML, escaped, from the product's
   state and the storefront's words (`StorefrontStrings`), with only the classes `styling.md` lists.
   `tests/Integration/Storefront/ComponentsTest.php` holds every class printed to that list.
5. **`StorefrontAssets`**: `module()` registers a module of `StorefrontAssets::MODULES` with the
   dependencies and the version its `build/blocks/<file>.asset.json` lists, the storefront's modules it
   imports first, and enqueues it; `style()` does the same for the stylesheet. Each is registered once a
   request, and only when a block asks for it, so a page without a block loads nothing of the plugin. An
   `.asset.json` that is missing or cannot be read is reported as `storefront.asset_unreadable`, and its file
   is not loaded. `npm run build` builds the modules from `assets/blocks/store/` and `assets/blocks/product/`,
   and the stylesheet from `assets/blocks/storefront.css`.

## The two stores

### `seocart/product`

The page prints each product's state once, under its post's id: `state.products[ postId ]` is
`{ uuid, name, axes, variants, selection, quantity }`. `axes` lists each option, with its values (`key`,
`label`, `swatch`, `image`). `variants` lists the variants a visitor may buy, each
`{ id, values, label, price, compareAt, inStock }`, a price being `{ minor, text }` in the page's currency, or
`null` for none. `selection` is the value selected on each option, and `quantity` is 1. In the browser the
module adds `adding`, `status` and `alert` to a product's state, and `max` after an add the store flags short
of stock.

Its getters are `priceText`, `compareAtText`, `axisValueLabel`, `valueUnavailable`, `valueUnavailableText`,
`buttonLabel`, `cannotAdd`, `adding`, `quantity`, `maxQuantity`, `statusText` and `alertText`. The server
answers six of them for the page's first paint: `priceText`, `compareAtText`, `axisValueLabel`, `buttonLabel`,
`cannotAdd` and `quantity`. Its actions are `select`, `decrease`, `increase`, `setQuantity` and `add`, and its
callback `viewItem` pushes `view_item`. It shows the text the server wrote for each price, and computes with
none.

### `seocart/store`

Its state is `cart`, the last cart answer taken or `null`; `cartStatus`, which is `unknown`, `reading`,
`ready` or `failed`; `pending`; `lastAdded`, `{ variantId, quantity }`; and `notice`. Its actions are
`addLines( lines )` and `readCart()`. Its configuration is printed once a request with
`wp_interactivity_config()`: `restRoot`, `nonceUrl`, `locale`, `currency`, `priceVersion` and `strings`,
every sentence the storefront's modules show. Nothing in it depends on the visitor.

The store sends every request to the Store API. One write is out at a time, carrying the version of the cart
held, or 0 when none is. An add refused because the cart's version moved on is sent once more, at the version
the refusal names. A write that gets no answer is never sent again: the cart is read instead. For a
logged-in shopper, a write refused for want of a REST nonce fetches one from WordPress's `rest-nonce` action
and is sent once more with it; the nonce is held in memory only.

## No request when a page loads

No block sends a request when its page loads: the first request is the shopper's first add, and a page a
browser prerenders and never shows sends nothing and pushes nothing. `assets/blocks/store/test/store.test.js`
and `assets/blocks/product/test/product.test.js` hold both.
