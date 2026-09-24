/**
 * webpack configuration for `npm run build`: @wordpress/scripts' own, plus one entry per admin screen.
 *
 * wp-scripts finds the scripts of the blocks under assets/ through their block.json files, and
 * keeps doing so. An admin screen has no block.json, so each directory under assets/admin/ that
 * holds an index.js becomes the entry `admin/<directory>`, built to build/admin/<directory>.js,
 * where budget.json's `admin/**` rules apply to it.
 *
 * The list of a script's WordPress dependencies and its version is written as a .asset.json file,
 * not wp-scripts' default .asset.php: every PHP file the plugin ships must refuse to run on its
 * own, and a generated one cannot, while a JSON file is data that PHP reads.
 */

const { existsSync, readdirSync } = require( 'fs' );
const { join } = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
// Installed with @wordpress/scripts, whose own configuration uses it.
// eslint-disable-next-line import/no-extraneous-dependencies
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const ASSETS = join( __dirname, 'assets' );
const ADMIN = join( ASSETS, 'admin' );

/**
 * The entries of the admin screens.
 *
 * @return {Object<string, string>} Each screen's index.js, keyed `admin/<directory>`.
 */
function adminEntries() {
	const entries = {};

	if ( ! existsSync( ADMIN ) ) {
		return entries;
	}

	for ( const entry of readdirSync( ADMIN, { withFileTypes: true } ) ) {
		const script = join( ADMIN, entry.name, 'index.js' );

		if ( entry.isDirectory() && existsSync( script ) ) {
			entries[ `admin/${ entry.name }` ] = script;
		}
	}

	return entries;
}

/**
 * The entries wp-scripts finds itself: the blocks' scripts. Asked for only when a block.json
 * exists, so a build without blocks does not warn that assets/ has no index.js.
 *
 * @return {Object<string, string>} The blocks' entries.
 */
function blockEntries() {
	const hasBlocks = readdirSync( ASSETS, { recursive: true } ).some(
		( file ) =>
			String( file ).replace( /\\/g, '/' ).endsWith( 'block.json' )
	);

	return hasBlocks ? defaultConfig.entry() : {};
}

module.exports = {
	...defaultConfig,
	entry: () => ( { ...blockEntries(), ...adminEntries() } ),
	plugins: defaultConfig.plugins.map( ( plugin ) =>
		plugin instanceof DependencyExtractionWebpackPlugin
			? new DependencyExtractionWebpackPlugin( { outputFormat: 'json' } )
			: plugin
	),
};
