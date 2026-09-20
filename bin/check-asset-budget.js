#!/usr/bin/env node
/**
 * Fails the build when a compiled asset exceeds its compressed-size budget.
 *
 * Budgets live in budget.json as an ordered list of rules; the first rule whose
 * pattern matches a file decides its ceiling. The check fails closed:
 *
 * - every .js and .css file under the build directory must match a rule, so a
 *   new entry point cannot ship without a declared ceiling;
 * - a malformed rule, an unknown flag or a missing build directory is an error,
 *   never a pass;
 * - an empty build is accepted only while the source directory holds no script
 *   or style, so a build that silently produced nothing cannot pass either.
 *
 * Usage: node bin/check-asset-budget.js [--build-dir=build] [--source-dir=assets] [--budget=budget.json]
 */

const fs = require( 'fs' );
const path = require( 'path' );
const zlib = require( 'zlib' );

const root = path.resolve( __dirname, '..' );
const options = {
	'build-dir': 'build',
	'source-dir': 'assets',
	budget: 'budget.json',
};

/**
 * Stops the check with an error.
 *
 * @param {string} message What went wrong.
 */
function fail( message ) {
	process.stderr.write( `check-asset-budget: ${ message }\n` );
	process.exit( 1 );
}

for ( const arg of process.argv.slice( 2 ) ) {
	const match = /^--([a-z-]+)=(.+)$/.exec( arg );
	if ( ! match || ! ( match[ 1 ] in options ) ) {
		fail(
			`unrecognized argument "${ arg }". Options take the form --name=value: ${ Object.keys(
				options
			).join( ', ' ) }.`
		);
	}
	options[ match[ 1 ] ] = match[ 2 ];
}

const buildDir = path.resolve( root, options[ 'build-dir' ] );
const sourceDir = path.resolve( root, options[ 'source-dir' ] );
const budgetFile = path.resolve( root, options.budget );

/**
 * Converts a glob with `*` and `**` wildcards into an anchored regular expression.
 *
 * @param {string} glob Pattern relative to the build directory, with forward slashes.
 * @return {RegExp} The equivalent expression.
 */
function globToRegExp( glob ) {
	const escaped = glob
		.replace( /[.+?^${}()|[\]\\]/g, '\\$&' )
		.replace( /\*\*\//g, '\u0000' )
		.replace( /\*\*/g, '\u0001' )
		.replace( /\*/g, '[^/]*' )
		.replace( /\u0000/g, '(?:.*/)?' )
		.replace( /\u0001/g, '.*' );
	return new RegExp( `^${ escaped }$` );
}

/**
 * Lists every file under a directory, as forward-slash paths relative to it.
 *
 * @param {string} dir    Directory to walk.
 * @param {string} prefix Path accumulated so far.
 * @return {string[]} Relative file paths.
 */
function listFiles( dir, prefix = '' ) {
	return fs
		.readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const relative = prefix
				? `${ prefix }/${ entry.name }`
				: entry.name;
			return entry.isDirectory()
				? listFiles( path.join( dir, entry.name ), relative )
				: [ relative ];
		} );
}

if ( ! fs.existsSync( budgetFile ) ) {
	fail( `budget file not found: ${ budgetFile }` );
}
if ( ! fs.existsSync( buildDir ) ) {
	fail(
		`build directory not found: ${ buildDir }. Run the build first, or pass --build-dir.`
	);
}

const budget = JSON.parse( fs.readFileSync( budgetFile, 'utf8' ) );

if ( ! Array.isArray( budget.rules ) || budget.rules.length === 0 ) {
	fail(
		`${ path.basename( budgetFile ) } must hold a non-empty "rules" list.`
	);
}

const rules = budget.rules.map( ( rule, index ) => {
	if ( typeof rule.pattern !== 'string' || rule.pattern === '' ) {
		fail( `rule ${ index + 1 } has no "pattern" string.` );
	}
	if ( ! Number.isInteger( rule.maxGzipBytes ) || rule.maxGzipBytes <= 0 ) {
		fail(
			`rule ${ index + 1 } (${ rule.pattern }) needs "maxGzipBytes" as a positive integer.`
		);
	}
	return { ...rule, expression: globToRegExp( rule.pattern ) };
} );

const isAsset = ( file ) => /\.(js|css)$/.test( file );
const isSource = ( file ) => /\.(js|jsx|ts|tsx|css|scss)$/.test( file );
const assets = listFiles( buildDir ).filter( isAsset );
const failures = [];

if ( assets.length === 0 ) {
	const sources = fs.existsSync( sourceDir )
		? listFiles( sourceDir ).filter( isSource )
		: [];
	if ( sources.length > 0 ) {
		fail(
			`${ options[ 'build-dir' ] }/ holds no compiled asset, but ${ options[ 'source-dir' ] }/ holds ${ sources.length } source file(s), e.g. ${ sources[ 0 ] }. The build produced nothing.`
		);
	}
}

for ( const asset of assets ) {
	const rule = rules.find( ( candidate ) =>
		candidate.expression.test( asset )
	);
	if ( ! rule ) {
		failures.push(
			`${ asset }: no rule in ${ path.basename(
				budgetFile
			) } matches this asset. Declare its budget.`
		);
		continue;
	}
	const bytes = zlib.gzipSync(
		fs.readFileSync( path.join( buildDir, asset ) ),
		{ level: 9 }
	).length;
	const over = bytes > rule.maxGzipBytes;
	process.stdout.write(
		`${ over ? 'OVER' : 'ok  ' } ${ asset }  ${ bytes } / ${
			rule.maxGzipBytes
		} bytes gzipped  (${ rule.pattern })\n`
	);
	if ( over ) {
		failures.push(
			`${ asset }: ${ bytes } bytes gzipped exceeds the ${ rule.maxGzipBytes } byte budget (${ rule.pattern }).`
		);
	}
}

if ( failures.length ) {
	fail(
		`asset budget failed:\n  - ${ failures.join( '\n  - ' ) }\n` +
			'Raising a budget is a reviewed change to budget.json that says why (docs/architecture/performance.md).'
	);
}

process.stdout.write(
	`Asset budget passed: ${ assets.length } asset(s) checked.\n`
);
