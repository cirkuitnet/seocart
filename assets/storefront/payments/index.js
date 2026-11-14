/**
 * The checkout's payment section, as a script module: the registry payment methods' islands
 * register with, and the host that shows the methods and places the order.
 *
 * Importing it installs the registry on `window.seocart.payments` and registers the islands that
 * queued there before it loaded. The checkout then reads its configuration and creates the host:
 *
 *     const host = createPaymentHost( root, {
 *         config: readConfig( root ),
 *         cart: { version, amountMinor, currency, billingCountry, billing },
 *         selected,                 // optional: the method the checkout's details name
 *         onSelect( id ) {},        // write the method into the checkout's details
 *         async onStale( detail ) {}, // read the cart again and return it
 *         onPlaced( result ) {},    // the order, its key handed over once
 *         onChange( state ) {},     // { selected, phase, error }
 *     } );
 *
 *     host.update( cart ); host.select( id ); await host.place(); await host.retry(); host.destroy();
 */

/**
 * Internal dependencies
 */
import { createHost } from './host';
import { installRegistry } from './registry';

export { CONTRACT_VERSION } from './registry';
export { readConfig } from './config';

const installed = installRegistry( window );

/**
 * Creates the host of a checkout's payment section in an element.
 *
 * @param {HTMLElement} root    The element the methods are shown in; it holds the configuration.
 * @param {Object}      options The configuration, the cart and the callbacks.
 * @return {Object} The host: update(), select(), place(), retry(), destroy().
 */
export function createPaymentHost( root, options ) {
	return createHost( root, options, {
		window,
		registry: window.seocart.payments,
		onRegister: installed.onRegister,
		wait: ( ms ) =>
			new Promise( ( resolve ) => window.setTimeout( resolve, ms ) ),
	} );
}
