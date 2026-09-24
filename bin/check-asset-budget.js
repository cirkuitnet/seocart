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
 *
 * `globToRegExp()`, `parseBudgetRules()` and `findRule()` are also exported (this file runs
 * its CLI body only when it is the entry point, guarded below): the same first-match-wins
 * lookup against budget.json is how the Playwright asset-byte fixture
 * (tests/E2E/support/budget.ts) maps a template's enqueued files to their ceilings, and this
 * is the one place that lookup is implemented.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const zlib = require( 'zlib' );

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
 * Validates and compiles budget.json's `rules` list, first-match-wins.
 *
 * Throws rather than exiting the process, so a caller other than this file's own CLI body
 * (which turns the thrown message into its usual `fail()`) can handle the error its own way.
 *
 * @param {{rules?: Array<{pattern: string, maxGzipBytes: number}>}} budget Parsed budget.json.
 * @param {string}                                                   label  How the budget file
 *                                                                          is named in the error.
 * @return {Array<{pattern: string, maxGzipBytes: number, expression: RegExp}>} Compiled rules.
 */
function parseBudgetRules( budget, label ) {
	if ( ! Array.isArray( budget.rules ) || budget.rules.length === 0 ) {
		throw new Error( `${ label } must hold a non-empty "rules" list.` );
	}
	return budget.rules.map( ( rule, index ) => {
		if ( typeof rule.pattern !== 'string' || rule.pattern === '' ) {
			throw new Error( `rule ${ index + 1 } has no "pattern" string.` );
		}
		if (
			! Number.isInteger( rule.maxGzipBytes ) ||
			rule.maxGzipBytes <= 0
		) {
			throw new Error(
				`rule ${ index + 1 } (${ rule.pattern }) needs "maxGzipBytes" as a positive integer.`
			);
		}
		return { ...rule, expression: globToRegExp( rule.pattern ) };
	} );
}

/**
 * The first rule (first-match-wins) whose pattern matches one file, or `undefined`.
 *
 * @param {Array<{pattern: string, maxGzipBytes: number, expression: RegExp}>} rules Compiled
 *                                                                                   rules, in
 *                                                                                   order.
 * @param {string}                                                             file  Path relative to the build directory, forward slashes.
 * @return {{pattern: string, maxGzipBytes: number, expression: RegExp}|undefined} The matching rule, if any.
 */
function findRule( rules, file ) {
	return rules.find( ( candidate ) => candidate.expression.test( file ) );
}

module.exports = { globToRegExp, parseBudgetRules, findRule };

if ( require.main === module ) {
	main();
}

/**
 * The CLI body: parses argv, checks the build directory's assets against budget.json, and
 * exits non-zero on the first thing that fails.
 */
function main() {
	const root = path.resolve( __dirname, '..' );
	const options = {
		'build-dir': 'build',
		'source-dir': 'assets',
		budget: 'budget.json',
	};

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

	if ( ! fs.existsSync( budgetFile ) ) {
		fail( `budget file not found: ${ budgetFile }` );
	}
	if ( ! fs.existsSync( buildDir ) ) {
		fail(
			`build directory not found: ${ buildDir }. Run the build first, or pass --build-dir.`
		);
	}

	const budget = JSON.parse( fs.readFileSync( budgetFile, 'utf8' ) );
	const rules = compileRulesOrFail( budget, budgetFile );

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
		const rule = findRule( rules, asset );
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
				'Raising a budget is a reviewed change to budget.json that says why.'
		);
	}

	process.stdout.write(
		`Asset budget passed: ${ assets.length } asset(s) checked.\n`
	);
}

/**
 * `parseBudgetRules()`, with a validation failure turned into this CLI's usual `fail()` instead
 * of a thrown error.
 *
 * @param {{rules?: Array<{pattern: string, maxGzipBytes: number}>}} budget     Parsed
 *                                                                              budget.json.
 * @param {string}                                                   budgetFile Its path, for
 *                                                                              the error.
 * @return {Array<{pattern: string, maxGzipBytes: number, expression: RegExp}>} Compiled rules.
 */
function compileRulesOrFail( budget, budgetFile ) {
	try {
		return parseBudgetRules( budget, path.basename( budgetFile ) );
	} catch ( error ) {
		fail( error.message );
		return []; // Unreached: fail() exits the process; satisfies main()'s use of the result.
	}
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

/**
 * Stops the check with an error.
 *
 * @param {string} message What went wrong.
 */
function fail( message ) {
	process.stderr.write( `check-asset-budget: ${ message }\n` );
	process.exit( 1 );
}
