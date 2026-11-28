/**
 * Scans of the storefront modules' sources: what no module may hold, whatever its tests run.
 *
 * - No sentence: every word a shopper reads is printed by the server, translated, in the store's
 *   configuration or in a block's markup. A string in a module that holds two words parted by a
 *   space is a sentence written in the wrong place.
 * - No arithmetic on money: no arithmetic operator is applied to a name that ends `_minor` or
 *   `Minor`, or is `minor`, as a price's member is. The browser shows the amounts the server wrote
 *   and adds up nothing. The allow-list is empty.
 * - Every notice a module names is a key of the server's strings.
 *
 * Planted violations, each shown red and removed: `message: 'Something went wrong.'` in the
 * queue's refusal (the sentence scan); `unit_price_minor: variant.price.minor * quantity` in the
 * product module's `add_to_cart` item (the arithmetic scan); `notice( 'cart_gone' )` in the queue
 * (the key check).
 */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Internal dependencies
 */
const { STRINGS } = require( './fixtures/strings' );

/**
 * The folder of the storefront modules.
 *
 * @type {string}
 */
const ROOT = path.resolve( __dirname, '..' );

/**
 * Names whose arithmetic the scan allows: none.
 *
 * @type {string[]}
 */
const ARITHMETIC_ALLOWED = [];

/**
 * Lists the modules' source files: every `.js` file under the folder, tests and their fixtures left out.
 *
 * @param {string} folder The folder.
 * @return {string[]} The files.
 */
function modules( folder = ROOT ) {
	return fs
		.readdirSync( folder, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const where = path.join( folder, entry.name );

			if ( entry.isDirectory() ) {
				return 'test' === entry.name ? [] : modules( where );
			}

			return entry.name.endsWith( '.js' ) ? [ where ] : [];
		} );
}

/**
 * Returns a source without its comments, and the text of each string in it.
 *
 * A small reader of JavaScript enough for these modules: it knows comments, quoted strings and
 * template literals, and reads a regular expression as code.
 *
 * @param {string} source The source.
 * @return {{code: string, strings: string[]}} The code with each comment and string's text taken out, and the strings.
 */
function read( source ) {
	let code = '';
	const strings = [];
	let at = 0;

	while ( at < source.length ) {
		const two = source.slice( at, at + 2 );
		const quote = source[ at ];

		if ( '//' === two ) {
			at = source.indexOf( '\n', at );
			at = -1 === at ? source.length : at;
		} else if ( '/*' === two ) {
			at = source.indexOf( '*/', at ) + 2;
		} else if ( "'" === quote || '"' === quote || '`' === quote ) {
			let end = at + 1;

			while ( source[ end ] !== quote ) {
				end += '\\' === source[ end ] ? 2 : 1;
			}

			strings.push( source.slice( at + 1, end ) );
			code += `${ quote }${ quote }`;
			at = end + 1;
		} else {
			code += quote;
			at++;
		}
	}

	return { code, strings };
}

const files = modules();

test( 'the scans read the storefront modules', () => {
	const names = files.map( ( file ) => path.relative( ROOT, file ) );

	expect( names ).toEqual(
		expect.arrayContaining( [
			path.join( 'store', 'index.js' ),
			path.join( 'store', 'queue.js' ),
			path.join( 'store', 'client.js' ),
		] )
	);
} );

test.each( files.map( ( file ) => [ path.relative( ROOT, file ), file ] ) )(
	'%s holds no sentence',
	( name, file ) => {
		const { strings } = read( fs.readFileSync( file, 'utf8' ) );

		expect(
			strings.filter( ( string ) =>
				/\p{L}\s+\p{L}/u.test( string.replace( /\$\{[^}]*\}/g, '' ) )
			)
		).toEqual( [] );
	}
);

test.each( files.map( ( file ) => [ path.relative( ROOT, file ), file ] ) )(
	'%s applies no arithmetic to an amount in minor units',
	( name, file ) => {
		const { code } = read( fs.readFileSync( file, 'utf8' ) );
		const minor = String.raw`[\w$.?]*(?:_minor|Minor|\bminor)\b`;
		const operator = String.raw`(?:\*\*?|[+\-/%])=?`;
		const found = [
			...code.matchAll(
				new RegExp(
					String.raw`${ minor }\s*${ operator }|${ operator }\s*${ minor }`,
					'g'
				)
			),
		].map( ( [ match ] ) => match.trim() );

		expect(
			found.filter(
				( match ) =>
					! ARITHMETIC_ALLOWED.some( ( allowed ) =>
						match.includes( allowed )
					)
			)
		).toEqual( [] );
	}
);

test( "every notice a module names is one of the server's strings", () => {
	const named = files.flatMap( ( file ) =>
		[
			...fs
				.readFileSync( file, 'utf8' )
				.matchAll( /\bnotice\(\s*'([a-z_]+)'/g ),
		].map( ( [ , key ] ) => key )
	);

	expect( named.length ).toBeGreaterThan( 0 );
	expect( named.filter( ( key ) => ! ( key in STRINGS ) ) ).toEqual( [] );
} );
