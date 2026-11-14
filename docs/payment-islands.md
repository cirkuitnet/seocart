# Payment islands

A payment plugin whose provider takes the shopper's payment details in the browser, such as a card
form, adds an **island** to SEOCart's checkout: a small script that mounts the provider's form
inside the checkout's payment section, prepares what the placement sends, and takes a step the
provider asks of the shopper, such as a bank's confirmation. SEOCart's own script, the **host**,
shows the payment methods, mounts the island of the one selected, places the order and resumes its
payment. The browser never decides a payment: every outcome is the Store API's.

A payment method whose gateway calls no provider, such as a bank transfer, needs no island: the
host shows its name and instructions, and places the order with no payment data.

The surface is experimental until SEOCart 1.0: its contract version is `0.1.0`.

## Register an island

An island is a classic script, enqueued by the plugin (see [Load the script](#load-the-script)).
It registers with this one statement, whatever runs first, the island or the checkout's module:

```js
( ( window.seocart = window.seocart || {} ).payments = window.seocart
	.payments || {
	queue: [],
	register( island ) {
		this.queue.push( island );
	},
} ).register( {
	id: 'my-gateway', // the id the gateway registered with
	contractVersion: '0.1.0',
	supports: { action: true },
	canMakePayment( ctx ) {
		return true;
	},
	mount( el, ctx ) {
		// …
	},
} );
```

When the island runs first, the statement creates a stand-in registry and queues the island. When
the checkout's module loads, it puts the real registry onto the same object, registers the queued
islands in the order they arrived, and dispatches `seocart:payments:ready` on `document` once. An
island that registers later is accepted all the same, and the checkout shows it at once; nothing
needs to wait for the event.

The registry, `window.seocart.payments`, has `register( island )`, `supportsContract( version )`,
`contractVersion`, `get( id )`, `ids()` and `refused()`. It never throws. It refuses, with a
console warning that names the id and the reason, and lists in `refused()`:

- `incompatible`: an island written against a contract version it does not support;
- `duplicate`: a second island for an id already registered (the first is kept);
- `invalid`: an island missing a member below, or with one of the wrong type.

A payment method whose gateway needs an island, and has none registered, is shown disabled, with a
console warning, rather than left out: a method that silently vanishes cannot be diagnosed.

## The island

```ts
interface PaymentIsland {
	id: string; // the gateway's id, exactly
	contractVersion: string; // '0.1.0'
	supports?: { action?: boolean }; // true: the island takes the steps the provider asks for
	canMakePayment( ctx: PaymentContext ): boolean;
	mount(
		el: HTMLElement,
		ctx: PaymentContext
	): MountedIsland | Promise< MountedIsland >;
}

interface MountedIsland {
	confirm(): Promise< { payment_data: Record< string, string > } >;
	action?(
		next: {
			type: 'redirect' | 'sdk';
			url: string | null;
			client_token: string | null;
		},
		resume: { intentUuid: string; returnUrl: string }
	): Promise< 'completed' | 'failed' | 'left' >;
	showError?( error: { code: string; message: string } ): void;
	unmount(): void;
}
```

- `canMakePayment( ctx )` is synchronous and cheap, and must agree with the gateway's own
  `isAvailable()` for the same amount, currency and settings. A method whose island answers false
  is shown disabled. Since the two are in different languages, test that they agree: read the
  script's limits from the file and compare them with the PHP constants.
- `mount( el, ctx )` renders the provider's form in `el`, a fresh element for every mount, and
  returns a handle. Keep everything a mount creates in its handle, not in the script's top level:
  the island may be mounted again while a promise of the earlier mount is still pending. A mount
  that throws or rejects shows the method disabled, and the others stay selectable.
- `confirm()` checks the form and returns what the placement sends the gateway, `payment_data`:
  `payment_token` today. Throw an error, with a `code`, to refuse; the host sends nothing and
  hands the error to `showError()`.
- `action( next, resume )` takes the step the provider asks for once the order is placed: give
  `next.client_token` to the provider's script, or follow `next.url`. `resume.returnUrl` is the
  address the provider may send the shopper back to: the checkout's page with the payment's uuid as
  its only argument. Resolve `completed` or `failed` and the host resumes the payment, whose answer
  decides; resolve `left` when the provider's script is taking the shopper away from the page.
- `showError( error )` shows a decline, or the island's own refusal, inside the island.
- `unmount()` is synchronous: it removes the form and abandons any work in flight. After it the
  island holds nothing of that mount.

## The context

`ctx` is frozen, and it is what the payment is for:

| Member           |                                                                                       |
| ---------------- | ------------------------------------------------------------------------------------- |
| `gatewayId`      | the gateway's id                                                                      |
| `mode`           | `test` or `live`                                                                      |
| `channel`        | `storefront`                                                                          |
| `amountMinor`    | the total, in minor units                                                             |
| `currency`       | the ISO 4217 code                                                                     |
| `billingCountry` | the billing address's country, or null                                                |
| `cartVersion`    | the cart's version the context was made from                                          |
| `settings`       | the gateway's public settings for the mode, such as a publishable key; never a secret |
| `currencies`     | the currencies the payment method takes                                               |
| `locale`         | the store's locale for the shopper                                                    |

When the checkout holds the billing address, the context also has `billing`, that address.

The host mounts the island again, `unmount()` then `mount()` with a new context, when the amount,
the currency, the gateway, the mode or a setting changes; never when only the cart's version moves.
Whatever an earlier mount was still doing is then discarded, so an island never confirms an amount
the shopper no longer sees. While the order is being placed, a cart that changes waits until the
host is idle again.

## The placement, step by step

1. The shopper places the order. The host calls `confirm()`, then sends `POST
/seocart/store/v1/checkout` with the cart's version, its total and currency, the payment data,
   and an `Idempotency-Key`.
2. An approval (or `processing`, or `pending` for a method paid later) hands the order to the
   checkout. A decline calls `showError()`, the cart is read again, and the next attempt has a new
   key.
3. `requires_action` names the step, `next_action`, and the payment, `intent_uuid`. The host offers
   the step to the island first, whatever its kind, when the island declares `supports.action`.
   An island that does not take it leaves a `redirect` to the host, which sends the shopper to
   `next_action.url`; an `sdk` step without an island to take it is reported as
   `action_unsupported`.
4. Once the island resolves, the host sends `POST /seocart/store/v1/checkout/resume` with the
   payment's uuid. `approved`, or `duplicate` when the store settled the payment first, hands the
   order over; a decline is shown as in step 2. A payment that still waits for the shopper keeps
   the key, and placing again sends the same request, which is answered with the same step.
5. Back from a provider's page, with the payment's uuid in the page's address, the host resumes the
   payment, then sends the kept placement again to recover the order and its key.

The host sends those two writes and no other. An island never writes to the cart or the checkout:
the store prices the order from what it holds, whatever an island sends.

The idempotency key is kept in the tab's `sessionStorage` under `seocart:placement`, with the
request it was sent with, until the placement settles. The same request is sent again after a
network failure, a server error, an unavailable gateway (`checkout.gateway_unavailable`), a
placement still in progress (`checkout.placement_in_progress`, after `Retry-After`), a reload in
the middle of a step, and a return from a provider's page. A changed total, currency or payment
method, a stale cart (`cart.version_stale`, `checkout.totals_changed`), a settled, declined or
voided answer and any other refusal end the key. The order's own key is handed to the checkout once
and kept nowhere.

## The configuration

The page that shows the payment section prints the host's configuration as JSON, inside the host's
element, in `<script type="application/json" class="seocart-payments-config">`:

```json
{
	"contract": "0.1.0",
	"restRoot": "https://shop.example/wp-json/",
	"returnQueryArg": "seocart_payment",
	"locale": "en_GB",
	"methods": [
		{
			"id": "my-gateway",
			"label": "Card",
			"mode": "test",
			"island": true,
			"settings": { "publishable_key": "pk_test_…" },
			"currencies": [ "USD", "GBP", "EUR" ],
			"instructions": null,
			"paysOnDelivery": false
		}
	]
}
```

`restRoot` is WordPress's REST root, ending in a slash. `returnQueryArg` is the argument the address
a provider sends the shopper back to carries the payment's uuid in. `island` is true for a method
whose gateway calls a provider: without a registered island it is shown disabled. A method's
`settings` are the gateway's settings for the mode that are public; `instructions` are an offline
method's. The host refuses a malformed configuration with an error that names the field.

## Load the script

SEOCart fires `seocart_enqueue_payment_islands` on a page that shows the payment methods, with a
`SEOCart\Checkout\Interfaces\Storefront\PaymentIslands` object. Add the island's script there:

```php
add_action(
	'seocart_enqueue_payment_islands',
	static function ( $islands ): void {
		$islands->add( 'my-gateway', 'my-gateway-island', plugins_url( 'assets/island.js', __FILE__ ), '1.0.0' );
	}
);
```

`add( $gatewayId, $handle, $src, $version )` enqueues the script deferred and in the footer, and
only when the page offers the gateway's method, so a page loads the islands it shows and no other.
An id no gateway registered with is refused with an `InvalidArgumentException`. Name the action as a
string: the plugin's main file runs before SEOCart's classes exist. An island script may ship in the
plugin's `assets/` directory, which an extension's zip may hold.

## Versions and deprecation

An island states the contract version it was written against. Before 1.0 a version is accepted
when its major and minor match SEOCart's; from 1.0, when its major does. From 1.0 the contract is
public and versioned: a new major version keeps the previous one working through an adapter that
presents an older island with the current interface, with a console notice naming the island, for
at least two minor releases and twelve months.
