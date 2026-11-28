/**
 * The storefront's strings as the modules' tests see them: every key the server prints in the
 * store's configuration, each sentence replaced by a marker that names its key and keeps its
 * placeholders, such as `«insufficient» {for_sale} {quantity}`.
 *
 * A text a module shows that is not a marker came from somewhere other than the configuration; a
 * key the server does not print reads as an empty string.
 */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The server's list of the storefront's strings.
 *
 * @type {string}
 */
const SOURCE = path.resolve(
	__dirname,
	'../../../../src/Platform/Storefront/StorefrontStrings.php'
);

/**
 * Returns the strings, read from the server's list.
 *
 * @return {Object<string, string>} Each key's marker.
 */
function markers() {
	const php = fs.readFileSync( SOURCE, 'utf8' );
	const entries = [
		...php.matchAll( /'([a-z_]+)'\s*=>\s*__\(\s*'((?:[^'\\]|\\.)*)'/g ),
	];

	return Object.fromEntries(
		entries.map( ( [ , key, sentence ] ) => [
			key,
			[
				`«${ key }»`,
				...( sentence.match( /\{[a-z_]+\}/g ) ?? [] ),
			].join( ' ' ),
		] )
	);
}

module.exports = { STRINGS: markers() };
