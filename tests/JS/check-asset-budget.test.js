const { spawnSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const script = path.resolve( __dirname, '../../bin/check-asset-budget.js' );

let fixture;
let buildDir;
let sourceDir;
let budgetFile;

function writeBudget( rules = [ { pattern: '**/*.js', maxGzipBytes: 1024 } ] ) {
	fs.writeFileSync( budgetFile, JSON.stringify( { rules } ) );
}

function runCheck( ...extraArguments ) {
	return spawnSync(
		process.execPath,
		[
			script,
			`--build-dir=${ buildDir }`,
			`--source-dir=${ sourceDir }`,
			`--budget=${ budgetFile }`,
			...extraArguments,
		],
		{ encoding: 'utf8' }
	);
}

beforeEach( () => {
	fixture = fs.mkdtempSync(
		path.join( os.tmpdir(), 'seocart-asset-budget-' )
	);
	buildDir = path.join( fixture, 'build' );
	sourceDir = path.join( fixture, 'assets' );
	budgetFile = path.join( fixture, 'budget.json' );
	fs.mkdirSync( buildDir );
	fs.mkdirSync( sourceDir );
	writeBudget();
} );

afterEach( () => {
	fs.rmSync( fixture, { recursive: true, force: true } );
} );

test( 'accepts a build whose assets are within budget', () => {
	fs.writeFileSync( path.join( buildDir, 'main.js' ), 'const ready = true;' );

	const result = runCheck();

	expect( result.status ).toBe( 0 );
	expect( result.stdout ).toContain( 'ok   main.js' );
	expect( result.stdout ).toContain(
		'Asset budget passed: 1 asset(s) checked.'
	);
	expect( result.stderr ).toBe( '' );
} );

test( 'rejects an asset over its budget', () => {
	fs.writeFileSync( path.join( buildDir, 'main.js' ), 'const ready = true;' );
	writeBudget( [ { pattern: '**/*.js', maxGzipBytes: 1 } ] );

	const result = runCheck();

	expect( result.status ).toBe( 1 );
	expect( result.stdout ).toContain( 'OVER main.js' );
	expect( result.stderr ).toContain( 'main.js:' );
	expect( result.stderr ).toContain( 'exceeds the 1 byte budget' );
} );

test( 'rejects a missing build directory', () => {
	fs.rmdirSync( buildDir );

	const result = runCheck();

	expect( result.status ).toBe( 1 );
	expect( result.stderr ).toContain( 'build directory not found:' );
	expect( result.stderr ).toContain( 'Run the build first' );
} );

test( 'rejects an unknown flag', () => {
	const result = runCheck( '--unknown=value' );

	expect( result.status ).toBe( 1 );
	expect( result.stderr ).toContain(
		'unrecognized argument "--unknown=value"'
	);
} );

test( 'rejects a flag without a value', () => {
	const result = runCheck( '--budget' );

	expect( result.status ).toBe( 1 );
	expect( result.stderr ).toContain( 'unrecognized argument "--budget"' );
} );

test( 'rejects an invalid rule in the budget file', () => {
	writeBudget( [ { pattern: '**/*.js', maxGzipBytes: 0 } ] );

	const result = runCheck();

	expect( result.status ).toBe( 1 );
	expect( result.stderr ).toContain(
		'rule 1 (**/*.js) needs "maxGzipBytes" as a positive integer.'
	);
} );

test( 'rejects an empty build while source assets exist', () => {
	fs.writeFileSync(
		path.join( sourceDir, 'main.js' ),
		'const ready = true;'
	);

	const result = runCheck();

	expect( result.status ).toBe( 1 );
	expect( result.stderr ).toContain( 'holds no compiled asset' );
	expect( result.stderr ).toContain( 'holds 1 source file(s)' );
	expect( result.stderr ).toContain( 'The build produced nothing.' );
} );
