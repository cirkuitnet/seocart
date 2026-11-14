/**
 * webpack configuration for `npm run build`: @wordpress/scripts' own two configurations, the
 * classic scripts and the script modules, plus the plugin's own entries.
 *
 * wp-scripts finds the scripts of the blocks under assets/ through their block.json files, and
 * keeps doing so. An admin screen has no block.json, so each directory under assets/admin/ that
 * holds an index.js becomes the entry `admin/<directory>`, built to build/admin/<directory>.js,
 * where budget.json's `admin/**` rules apply to it.
 *
 * The storefront's scripts are ES modules, built by wp-scripts' module configuration, which it
 * exports only when run with --experimental-modules, as `npm run build` and `npm run start` do.
 * Each entry of STOREFRONT_MODULES is built to build/<entry>.js, where budget.json's
 * `storefront/**` rule applies to it.
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
 * The storefront's script modules, keyed by the path they are built to under build/.
 *
 * @type {Object<string, string>}
 */
const STOREFRONT_MODULES = {
	'storefront/payments': join( ASSETS, 'storefront', 'payments', 'index.js' ),
};

if ( ! Array.isArray( defaultConfig ) ) {
	// Without the flag wp-scripts exports the classic configuration alone, and the storefront's
	// modules would silently go unbuilt.
	throw new Error(
		'The storefront is built as script modules: run wp-scripts with --experimental-modules, as npm run build and npm run start do.'
	);
}

const [ scriptConfig, moduleConfig ] = defaultConfig;

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
 * The entries wp-scripts finds itself in one configuration: the blocks' scripts or modules.
 * Asked for only when a block.json exists, so a build without blocks does not warn that assets/
 * has no index.js.
 *
 * @param {Object} config One of wp-scripts' configurations.
 * @return {Object<string, string>} The blocks' entries.
 */
function blockEntries( config ) {
	const hasBlocks = readdirSync( ASSETS, { recursive: true } ).some(
		( file ) =>
			String( file ).replace( /\\/g, '/' ).endsWith( 'block.json' )
	);

	return hasBlocks ? config.entry() : {};
}

/**
 * One of wp-scripts' configurations, with the plugin's own entries beside the blocks' and its
 * dependency list written as JSON.
 *
 * @param {Object}                       config  One of wp-scripts' configurations.
 * @param {() => Object<string, string>} entries Returns the plugin's own entries; asked on every
 *                                               build, so `npm run start` sees a new screen.
 * @return {Object} The configuration.
 */
function withPluginEntries( config, entries ) {
	return {
		...config,
		entry: () => ( { ...blockEntries( config ), ...entries() } ),
		plugins: config.plugins.map( ( plugin ) =>
			plugin instanceof DependencyExtractionWebpackPlugin
				? new DependencyExtractionWebpackPlugin( {
						outputFormat: 'json',
					} )
				: plugin
		),
	};
}

module.exports = [
	withPluginEntries( scriptConfig, adminEntries ),
	withPluginEntries( moduleConfig, () => STOREFRONT_MODULES ),
];
