/**
 * The payment section's configuration: the JSON the page prints inside the host's root, read and
 * checked before anything is shown.
 *
 * The shape:
 *
 *     {
 *         contract: '0.1.0',           // the island contract the page was written for
 *         restRoot: 'https://…/wp-json/', // WordPress's REST root, where the Store API lives
 *         returnQueryArg: 'seocart_payment', // the argument a provider's return address carries
 *         locale: 'en_GB',
 *         nonce: '…' | null,           // optional: a logged-in shopper's REST nonce; none for a guest
 *         methods: [ {
 *             id, label,               // the gateway's id; the method's name for shoppers
 *             mode: 'test' | 'live',
 *             island: boolean,         // true when the method needs an island in the browser
 *             settings: { … },         // the gateway's public settings for the mode
 *             currencies: [ … ],       // the currencies the method takes
 *             instructions: string | null, // an offline method's instructions
 *             paysOnDelivery: boolean,
 *         } ],
 *     }
 *
 * A malformed configuration is refused with an error naming the field, so a page that prints a
 * wrong one fails where it is read, not at the moment a shopper places an order.
 */

/**
 * Internal dependencies
 */
import { supportsContract, CONTRACT_VERSION } from './registry';

/**
 * Finds the configuration inside the host's root.
 *
 * @type {string}
 */
export const CONFIG_SELECTOR =
	'script[type="application/json"].seocart-payments-config';

/**
 * The modes a gateway takes payments in.
 *
 * @type {string[]}
 */
const MODES = [ 'test', 'live' ];

/**
 * Refuses a configuration.
 *
 * @param {string} path    The field, as a path such as `methods[0].mode`; empty for the whole.
 * @param {string} problem What is wrong with it.
 */
function refuse( path, problem ) {
	const subject = path
		? `the payment configuration's "${ path }"`
		: 'the payment configuration';

	throw new Error( `seocart.payments: ${ subject } ${ problem }.` );
}

/**
 * Tells whether a value is a plain object, not an array or null.
 *
 * @param {*} value The value.
 * @return {boolean} True when it is.
 */
function isObject( value ) {
	return !! value && 'object' === typeof value && ! Array.isArray( value );
}

/**
 * Checks one field and returns its value.
 *
 * @param {Object}         from  The object holding it.
 * @param {string}         name  Its name.
 * @param {string}         path  Its path, for the error.
 * @param {function(*): *} valid Returns true for a value the field takes.
 * @param {string}         needs What the field must be, for the error.
 * @return {*} The value.
 */
function field( from, name, path, valid, needs ) {
	if ( ! valid( from[ name ] ) ) {
		refuse( path, `must be ${ needs }` );
	}

	return from[ name ];
}

const isText = ( value ) => 'string' === typeof value;
const isName = ( value ) => isText( value ) && '' !== value.trim();

/**
 * Checks one payment method.
 *
 * @param {*}      method The method.
 * @param {string} path   Its path, for the error.
 * @return {Object} The method, frozen.
 */
function checkMethod( method, path ) {
	if ( ! isObject( method ) ) {
		refuse( path, 'must be an object' );
	}

	const settings = field(
		method,
		'settings',
		`${ path }.settings`,
		( value ) =>
			isObject( value ) && Object.values( value ).every( isText ),
		'an object of strings'
	);

	return Object.freeze( {
		id: field( method, 'id', `${ path }.id`, isName, 'a gateway id' ),
		label: field(
			method,
			'label',
			`${ path }.label`,
			isName,
			'the method’s name'
		),
		mode: field(
			method,
			'mode',
			`${ path }.mode`,
			( value ) => MODES.includes( value ),
			'test or live'
		),
		island: field(
			method,
			'island',
			`${ path }.island`,
			( value ) => 'boolean' === typeof value,
			'true or false'
		),
		settings: Object.freeze( { ...settings } ),
		currencies: Object.freeze( [
			...field(
				method,
				'currencies',
				`${ path }.currencies`,
				( value ) =>
					Array.isArray( value ) &&
					value.every(
						( code ) => isText( code ) && /^[A-Z]{3}$/.test( code )
					),
				'a list of ISO 4217 codes'
			),
		] ),
		instructions: field(
			method,
			'instructions',
			`${ path }.instructions`,
			( value ) => null === value || isText( value ),
			'text or null'
		),
		paysOnDelivery: field(
			method,
			'paysOnDelivery',
			`${ path }.paysOnDelivery`,
			( value ) => 'boolean' === typeof value,
			'true or false'
		),
	} );
}

/**
 * Checks a parsed configuration and returns it frozen.
 *
 * @param {*} config The configuration, parsed.
 * @return {Object} The configuration.
 */
export function checkConfig( config ) {
	if ( ! isObject( config ) ) {
		refuse( '', 'must be an object' );
	}

	field(
		config,
		'contract',
		'contract',
		( value ) => isText( value ) && supportsContract( value ),
		`a version of contract ${ CONTRACT_VERSION }`
	);

	const methods = field(
		config,
		'methods',
		'methods',
		Array.isArray,
		'a list'
	).map( ( method, index ) => checkMethod( method, `methods[${ index }]` ) );

	methods.forEach( ( method, index ) => {
		if (
			methods.findIndex( ( other ) => other.id === method.id ) < index
		) {
			refuse(
				`methods[${ index }].id`,
				'must not repeat another method’s id'
			);
		}
	} );

	return Object.freeze( {
		contract: config.contract,
		// The Store API's routes are appended to it: `…/wp-json/` and `…/?rest_route=/` alike.
		restRoot: field(
			config,
			'restRoot',
			'restRoot',
			( value ) => isName( value ) && value.endsWith( '/' ),
			'the REST root’s address, ending in a slash'
		),
		returnQueryArg: field(
			config,
			'returnQueryArg',
			'returnQueryArg',
			( value ) => isText( value ) && /^[A-Za-z0-9_-]+$/.test( value ),
			'a query argument’s name'
		),
		locale: field( config, 'locale', 'locale', isText, 'text' ),
		nonce:
			field(
				config,
				'nonce',
				'nonce',
				( value ) =>
					undefined === value || null === value || isName( value ),
				'a nonce, or null for a guest'
			) ?? null,
		methods: Object.freeze( methods ),
	} );
}

/**
 * Reads and checks the configuration printed inside a host's root.
 *
 * @param {Element} root The host's root.
 * @return {Object} The configuration, frozen.
 */
export function readConfig( root ) {
	const script = root.querySelector( CONFIG_SELECTOR );

	if ( ! script ) {
		refuse( '', `is missing: print it in ${ CONFIG_SELECTOR }` );
	}

	let parsed;

	try {
		parsed = JSON.parse( script.textContent );
	} catch ( error ) {
		refuse( '', `is not JSON (${ error.message })` );
	}

	return checkConfig( parsed );
}
