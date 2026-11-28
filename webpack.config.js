/**
 * webpack configuration for `npm run build`: @wordpress/scripts' own two configurations, the
 * classic scripts and the script modules, plus the plugin's own entries.
 *
 * wp-scripts builds the scripts a block.json file under assets/ names, and blockEntries() keeps
 * asking it for them, but no block of the plugin's has one there: the storefront blocks'
 * block.json files live under src/, beside the class that registers them, name no file and need
 * no build, and their renderers load what this build writes. An admin screen has no block.json,
 * so each directory under assets/admin/ that holds an index.js becomes the entry
 * `admin/<directory>`, built to build/admin/<directory>.js, where budget.json's `admin/**` rules
 * apply to it.
 *
 * The storefront's scripts are ES modules, built by wp-scripts' module configuration, which it
 * exports only when run with --experimental-modules, as `npm run build` and `npm run start` do.
 * Each entry of STOREFRONT_MODULES is built to build/<entry>: the payment host to
 * build/storefront/, where budget.json's `storefront/**` rule applies to it, and the blocks' two
 * modules and their stylesheet to build/blocks/, where its `blocks/**` rules apply. The
 * stylesheet is built in this configuration, which writes no right-to-left copy of it: it is
 * written with logical properties instead, and the empty script webpack makes for it is dropped
 * (DropEmptyScripts), its .asset.json kept. A module the others import by its id is an external
 * module of theirs (STOREFRONT_EXTERNALS), so a page holds one copy of it whatever blocks share
 * it.
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
 * The storefront's script modules and stylesheet, keyed by the path they are built to under build/.
 *
 * @type {Object<string, string>}
 */
const STOREFRONT_MODULES = {
	'storefront/payments': join( ASSETS, 'storefront', 'payments', 'index.js' ),
	'blocks/store': join( ASSETS, 'blocks', 'store', 'index.js' ),
	'blocks/product': join( ASSETS, 'blocks', 'product', 'index.js' ),
	'blocks/storefront': join( ASSETS, 'blocks', 'storefront.css' ),
};

/**
 * The storefront's modules another storefront module imports by id: left out of the importer's
 * bundle and listed in its .asset.json file, so WordPress puts the one copy in the page's import map.
 *
 * @type {string[]}
 */
const STOREFRONT_EXTERNALS = [ '@seocart/store' ];

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
						// Read in a module build only; imported statically, as @wordpress/interactivity is.
						requestToExternalModule: ( request ) =>
							STOREFRONT_EXTERNALS.includes( request )
								? `module ${ request }`
								: undefined,
					} )
				: plugin
		),
	};
}

/**
 * Drops the script webpack writes for an entry that is a stylesheet only: it is empty, and
 * nothing loads it. It runs after the dependency lists are written, so the stylesheet keeps the
 * .asset.json file its version is read from.
 */
class DropEmptyScripts {
	apply( compiler ) {
		compiler.hooks.thisCompilation.tap(
			'DropEmptyScripts',
			( compilation ) =>
				compilation.hooks.processAssets.tap(
					{
						name: 'DropEmptyScripts',
						stage: compiler.webpack.Compilation
							.PROCESS_ASSETS_STAGE_REPORT,
					},
					( assets ) =>
						Object.keys( assets )
							.filter(
								( name ) =>
									name.endsWith( '.js' ) &&
									0 === assets[ name ].size() &&
									name.replace( /\.js$/, '.css' ) in assets
							)
							.forEach( ( name ) =>
								compilation.deleteAsset( name )
							)
				)
		);
	}
}

const moduleBuild = withPluginEntries( moduleConfig, () => STOREFRONT_MODULES );

module.exports = [
	withPluginEntries( scriptConfig, adminEntries ),
	{
		...moduleBuild,
		plugins: [ ...moduleBuild.plugins, new DropEmptyScripts() ],
	},
];
