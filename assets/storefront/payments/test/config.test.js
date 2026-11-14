/**
 * Internal dependencies
 */
import { CONFIG_SELECTOR, checkConfig, readConfig } from '../config';

/**
 * Returns a well-formed configuration, with fields replaced.
 *
 * @param {Object} fields Optional. Top-level fields to set or replace.
 * @param {Object} method Optional. Fields of the first method to set or replace.
 * @return {Object} The configuration.
 */
function config( fields = {}, method = {} ) {
	return {
		contract: '0.1.0',
		restRoot: 'https://shop.example/wp-json/',
		returnQueryArg: 'seocart_payment',
		locale: 'en_GB',
		methods: [
			{
				id: 'stub',
				label: 'Test payments',
				mode: 'test',
				island: false,
				settings: {},
				currencies: [ 'GBP', 'EUR' ],
				instructions: null,
				paysOnDelivery: false,
				...method,
			},
		],
		...fields,
	};
}

/**
 * Returns a root holding a configuration script with the given text.
 *
 * @param {string} text The script's text.
 * @return {HTMLElement} The root.
 */
function rootWith( text ) {
	const root = document.createElement( 'div' );
	const script = document.createElement( 'script' );

	script.type = 'application/json';
	script.className = 'seocart-payments-config';
	script.textContent = text;
	root.append( script );

	return root;
}

describe( 'readConfig', () => {
	it( 'reads the configuration printed in the root, frozen', () => {
		const read = readConfig( rootWith( JSON.stringify( config() ) ) );

		expect( read ).toEqual( { ...config(), nonce: null } );
		expect( Object.isFrozen( read ) ).toBe( true );
		expect( Object.isFrozen( read.methods[ 0 ].settings ) ).toBe( true );
	} );

	it( 'reads a logged-in shopper’s nonce, and none for a guest', () => {
		expect(
			readConfig(
				rootWith( JSON.stringify( config( { nonce: 'a1b2c3d4e5' } ) ) )
			).nonce
		).toBe( 'a1b2c3d4e5' );
		expect(
			readConfig(
				rootWith( JSON.stringify( config( { nonce: null } ) ) )
			).nonce
		).toBeNull();
	} );

	it( 'refuses a root without a configuration', () => {
		expect( () => readConfig( document.createElement( 'div' ) ) ).toThrow(
			`seocart.payments: the payment configuration is missing: print it in ${ CONFIG_SELECTOR }.`
		);
	} );

	it( 'refuses a configuration that is not JSON', () => {
		expect( () => readConfig( rootWith( '{ methods' ) ) ).toThrow(
			/^seocart\.payments: the payment configuration is not JSON/
		);
	} );
} );

describe( 'checkConfig', () => {
	it.each( [
		[ 'contract', config( { contract: '0.2.0' } ) ],
		[ 'contract', config( { contract: undefined } ) ],
		[ 'methods', config( { methods: {} } ) ],
		[ 'restRoot', config( { restRoot: '' } ) ],
		[ 'restRoot', config( { restRoot: 'https://shop.example/wp-json' } ) ],
		[ 'returnQueryArg', config( { returnQueryArg: 'a b' } ) ],
		[ 'locale', config( { locale: 7 } ) ],
		[ 'nonce', config( { nonce: 7 } ) ],
		[ 'nonce', config( { nonce: '' } ) ],
		[ 'methods[0]', config( { methods: [ 'stub' ] } ) ],
		[ 'methods[0].id', config( {}, { id: '' } ) ],
		[ 'methods[0].label', config( {}, { label: null } ) ],
		[ 'methods[0].mode', config( {}, { mode: 'sandbox' } ) ],
		[ 'methods[0].island', config( {}, { island: 'yes' } ) ],
		[ 'methods[0].settings', config( {}, { settings: { key: 1 } } ) ],
		[ 'methods[0].settings', config( {}, { settings: [] } ) ],
		[ 'methods[0].currencies', config( {}, { currencies: [ 'gbp' ] } ) ],
		[ 'methods[0].instructions', config( {}, { instructions: 3 } ) ],
		[ 'methods[0].paysOnDelivery', config( {}, { paysOnDelivery: 1 } ) ],
	] )( 'refuses a malformed "%s", naming it', ( field, malformed ) => {
		expect( () => checkConfig( malformed ) ).toThrow(
			`the payment configuration's "${ field }"`
		);
	} );

	it( 'refuses a configuration that is not an object', () => {
		expect( () => checkConfig( [] ) ).toThrow(
			'seocart.payments: the payment configuration must be an object.'
		);
	} );

	it( 'refuses two methods with one id, naming the second', () => {
		const twice = config();

		twice.methods.push( { ...twice.methods[ 0 ] } );

		expect( () => checkConfig( twice ) ).toThrow(
			'the payment configuration\'s "methods[1].id" must not repeat'
		);
	} );
} );
