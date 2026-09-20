/**
 * Self-test of the fail-fast rules in support/environment.ts: which site URLs are usable,
 * what counts as "not set", and what the operator is told. It needs no site.
 *
 * Every `loadSiteEnvironment()` case names its own environment file, so a developer's real
 * `.env` in the repository root never leaks into the result.
 */

import { spawnSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect, type TestInfo } from '@playwright/test';
import {
	STORAGE_STATE_PATH,
	SiteEnvironmentError,
	loadSiteEnvironment,
	requireSiteEnvironment,
	siteBaseURL,
} from '../support/environment';

const ENV_EXAMPLE = resolve( __dirname, '..', '..', '..', '.env.example' );

const VARIABLES = [
	'WP_BASE_URL',
	'WP_USERNAME',
	'WP_PASSWORD',
	'SEOCART_E2E_ENV_FILE',
];

let inherited: Array< [ string, string | undefined ] >;

test.beforeEach( () => {
	inherited = VARIABLES.map( ( name ) => [ name, process.env[ name ] ] );
	for ( const name of VARIABLES ) {
		delete process.env[ name ];
	}
} );

test.afterEach( () => {
	for ( const [ name, value ] of inherited ) {
		if ( value === undefined ) {
			delete process.env[ name ];
		} else {
			process.env[ name ] = value;
		}
	}
} );

function useEnvFile( testInfo: TestInfo, lines: string[] ): void {
	const file = testInfo.outputPath( 'site.env' );
	writeFileSync( file, lines.join( '\n' ) );
	process.env.SEOCART_E2E_ENV_FILE = file;
}

function failureOf( action: () => unknown ): Error {
	try {
		action();
	} catch ( error ) {
		return error as Error;
	}
	throw new Error( 'Nothing was thrown.' );
}

test.describe( 'siteBaseURL()', () => {
	const cases: Array< [ string, string | undefined ] > = [
		[ 'https://site.example', 'https://site.example/' ],
		[ 'https://site.example/', 'https://site.example/' ],
		[ 'http://site.example/shop', 'http://site.example/shop/' ],
		[ 'https://site.example:8443', 'https://site.example:8443/' ],
		[
			'https://site.example/shop/?preview=1#top',
			'https://site.example/shop/',
		],
		[ 'ftp://site.example/', undefined ],
		[ 'site.example', undefined ],
		[ 'not a url', undefined ],
		[ '', undefined ],
	];

	for ( const [ value, expected ] of cases ) {
		test( `reads "${ value }" as ${ expected }`, () => {
			process.env.WP_BASE_URL = value;

			expect( siteBaseURL() ).toBe( expected );
		} );
	}

	test( 'has no default when WP_BASE_URL is unset', () => {
		expect( siteBaseURL() ).toBeUndefined();
	} );
} );

test.describe( 'loadSiteEnvironment()', () => {
	test( 'reads an unfilled copy of .env.example as no configuration at all', () => {
		process.env.SEOCART_E2E_ENV_FILE = ENV_EXAMPLE;

		loadSiteEnvironment();

		// Unset, not empty: the package parses an empty WP_BASE_URL on import and dies.
		expect( process.env.WP_BASE_URL ).toBeUndefined();
		expect( failureOf( requireSiteEnvironment ).message ).toContain(
			'Not set: WP_BASE_URL, WP_USERNAME, WP_PASSWORD.'
		);
	} );

	test( 'normalizes WP_BASE_URL for the package, which reads the variable itself', ( {}, testInfo ) => {
		useEnvFile( testInfo, [ 'WP_BASE_URL=https://site.example/shop' ] );

		loadSiteEnvironment();

		expect( process.env.WP_BASE_URL ).toBe( 'https://site.example/shop/' );
	} );

	test( 'lets a shell variable win over the file, unless it is empty', ( {}, testInfo ) => {
		process.env.WP_USERNAME = 'from-the-shell';
		process.env.WP_PASSWORD = '';
		useEnvFile( testInfo, [
			'WP_USERNAME=from-the-file',
			'WP_PASSWORD=from-the-file',
		] );

		loadSiteEnvironment();

		expect( process.env.WP_USERNAME ).toBe( 'from-the-shell' );
		expect( process.env.WP_PASSWORD ).toBe( 'from-the-file' );
	} );

	test( 'refuses a WP_BASE_URL that is not an absolute http(s) URL', ( {}, testInfo ) => {
		useEnvFile( testInfo, [ 'WP_BASE_URL=site.example' ] );

		const error = failureOf( loadSiteEnvironment );

		expect( error ).toBeInstanceOf( SiteEnvironmentError );
		expect( error.message ).toContain(
			'WP_BASE_URL is not an absolute http(s) URL: "site.example".'
		);
	} );

	test( 'refuses an environment file that does not exist', ( {}, testInfo ) => {
		process.env.SEOCART_E2E_ENV_FILE = testInfo.outputPath( 'absent.env' );

		const error = failureOf( loadSiteEnvironment );

		expect( error ).toBeInstanceOf( SiteEnvironmentError );
		expect( error.message ).toContain(
			'absent.env, which does not exist.'
		);
	} );
} );

test.describe( 'requireSiteEnvironment()', () => {
	test( 'says what is missing and where the values come from, without a stack trace', () => {
		process.env.WP_BASE_URL = 'https://site.example/';

		const error = failureOf( requireSiteEnvironment );

		expect( error ).toBeInstanceOf( SiteEnvironmentError );
		expect( error.message ).toContain(
			'Not set: WP_USERNAME, WP_PASSWORD.'
		);
		expect( error.message ).toContain( 'bin/dev/provision-site.sh' );
		expect( error.message ).toContain(
			'~/.seocart-dev/instances/<slug>.env'
		);
		expect( error.stack ).toBe(
			`SiteEnvironmentError: ${ error.message }`
		);
	} );

	test( 'returns the site once all three variables are set', () => {
		process.env.WP_BASE_URL = 'https://site.example/shop';
		process.env.WP_USERNAME = 'an-administrator';
		process.env.WP_PASSWORD = 'not-a-real-password';

		expect( requireSiteEnvironment() ).toEqual( {
			baseURL: 'https://site.example/shop/',
			username: 'an-administrator',
			password: 'not-a-real-password',
		} );
	} );
} );

test.describe( 'STORAGE_STATE_PATH', () => {
	// The path is declared in support/environment.ts and ignored in .gitignore: two files,
	// one fact. The file holds live administrator cookies and a REST nonce.
	test( 'is ignored by git', () => {
		const { status, error } = spawnSync(
			'git',
			[ 'check-ignore', '--quiet', STORAGE_STATE_PATH ],
			{ cwd: __dirname }
		);

		expect(
			status,
			`git check-ignore did not report ${ STORAGE_STATE_PATH } as ignored${
				error ? `: ${ error.message }` : ''
			}. Exit status 1 means it is committable; 128 means git could not tell.`
		).toBe( 0 );
	} );
} );
