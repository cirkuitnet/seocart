/**
 * What an island is mounted with: the payment context, and when a change to it needs the island
 * mounted again.
 */

/**
 * Freezes a value and everything it holds.
 *
 * @param {*} value The value.
 * @return {*} The value, frozen.
 */
function deepFreeze( value ) {
	if ( value && 'object' === typeof value && ! Object.isFrozen( value ) ) {
		Object.values( value ).forEach( deepFreeze );
		Object.freeze( value );
	}

	return value;
}

/**
 * Returns the context an island is mounted with for a payment method and a cart.
 *
 * Its ten members say what the payment is for; `billing`, the billing address the checkout holds,
 * is added when the checkout gives one. The context is frozen: an island reads it and never
 * changes it, and a change of cart gives the island a new one.
 *
 * @param {Object} method The method, as the configuration lists it.
 * @param {Object} cart   The cart: version, amountMinor, currency, billingCountry, and billing.
 * @param {Object} config The configuration.
 * @return {Object} The context.
 */
export function contextFor( method, cart, config ) {
	const context = {
		gatewayId: method.id,
		mode: method.mode,
		channel: 'storefront',
		amountMinor: cart.amountMinor,
		currency: cart.currency,
		billingCountry: cart.billingCountry ?? null,
		cartVersion: cart.version,
		settings: method.settings,
		currencies: method.currencies,
		locale: config.locale,
	};

	if ( cart.billing ) {
		// A copy, so freezing it leaves the checkout's own address alone; an address is plain data.
		context.billing = JSON.parse( JSON.stringify( cart.billing ) );
	}

	return deepFreeze( context );
}

/**
 * Tells whether a mounted island must be mounted again for a new context: when the payment it was
 * mounted for changed, never only because the cart's version moved.
 *
 * @param {Object} prev The context the island was mounted with.
 * @param {Object} next The context it would have now.
 * @return {boolean} True when the amount, the currency, the gateway, the mode or a setting differ.
 */
export function needsRemount( prev, next ) {
	if (
		[ 'amountMinor', 'currency', 'gatewayId', 'mode' ].some(
			( member ) => prev[ member ] !== next[ member ]
		)
	) {
		return true;
	}

	const before = Object.keys( prev.settings );
	const after = Object.keys( next.settings );

	return (
		before.length !== after.length ||
		after.some(
			( name ) => prev.settings[ name ] !== next.settings[ name ]
		)
	);
}
