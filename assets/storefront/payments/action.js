/**
 * The step a shopper takes for a payment provider (`next_action`), and the way back.
 *
 * The island is offered the step first, whatever its kind, when it says it takes one
 * (`supports.action`). An island that does not take it leaves a `redirect` to the host, which sends
 * the shopper to the provider's page; an `sdk` step needs the provider's script, so without an
 * island to run it the host cannot take it.
 *
 * The provider sends the shopper back to the checkout's address with one argument, the payment's
 * uuid, which the host reads when the page loads again and resumes.
 */

/**
 * A uuid, as the store writes one.
 *
 * @type {RegExp}
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Says who takes a step: `island`, `redirect` (the host) or `unsupported`.
 *
 * @param {Object|undefined} island The island registered for the method, if any.
 * @param {Object|null}      handle What its mount returned, if it is mounted.
 * @param {Object}           next   The step: type, url, client_token.
 * @return {string} Who takes it.
 */
export function routeAction( island, handle, next ) {
	if ( island?.supports?.action && 'function' === typeof handle?.action ) {
		return 'island';
	}

	return 'redirect' === next?.type && next.url ? 'redirect' : 'unsupported';
}

/**
 * Returns the address a provider sends the shopper back to: this page, with the payment's uuid as
 * its only argument.
 *
 * @param {string} href       The page's address.
 * @param {string} queryArg   The argument's name.
 * @param {string} intentUuid The payment's uuid.
 * @return {string} The address.
 */
export function returnUrlFor( href, queryArg, intentUuid ) {
	const url = new URL( href );

	url.search = '';
	url.hash = '';
	url.searchParams.set( queryArg, intentUuid );

	return url.toString();
}

/**
 * Returns the payment's uuid a page's address carries back from a provider, or null.
 *
 * @param {string} href     The page's address.
 * @param {string} queryArg The argument's name.
 * @return {string|null} The uuid; null when the argument is absent or no uuid.
 */
export function returnedIntent( href, queryArg ) {
	const value = new URL( href ).searchParams.get( queryArg );

	return value && UUID.test( value ) ? value.toLowerCase() : null;
}

/**
 * Returns a page's address without the argument a provider sent the shopper back with.
 *
 * @param {string} href     The page's address.
 * @param {string} queryArg The argument's name.
 * @return {string} The address.
 */
export function withoutReturn( href, queryArg ) {
	const url = new URL( href );

	url.searchParams.delete( queryArg );

	return url.toString();
}
