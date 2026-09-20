/**
 * Where the end-to-end suite finds the site under test, and how it says so when it cannot.
 *
 * The three variable names are the ones `@wordpress/e2e-test-utils-playwright` reads itself,
 * so the harness and the package share one set of names.
 *
 * Keep this module free of imports from that package. The package copies the three variables
 * out of `process.env` the moment it is first imported and falls back to a local default URL
 * and a default password. `playwright.config.ts` imports only this module, so the environment
 * file is loaded before any such copy is taken.
 */

import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

const REPOSITORY_ROOT = resolve( __dirname, '..', '..', '..' );

/** The administrator session: written by `global-setup.ts`, reused by every browser context. */
export const STORAGE_STATE_PATH = join(
	REPOSITORY_ROOT,
	'tests',
	'E2E',
	'.auth',
	'admin.json'
);

/** How to supply the variables. Shared by every failure that a wrong or missing value can cause. */
const SITE_ENVIRONMENT_HELP = [
	'The suite runs against a real, disposable WordPress site. There is no default URL and no default password.',
	'bin/dev/provision-site.sh writes WP_BASE_URL, WP_USERNAME and WP_PASSWORD for an instance to',
	'~/.seocart-dev/instances/<slug>.env on the dev server (mode 600, outside the web root). Then either:',
	'  - set SEOCART_E2E_ENV_FILE to the path of that file as this workstation sees it, or',
	'  - copy the three lines into .env in the repository root (gitignored; see .env.example), or',
	'  - export the three variables in the shell that runs "npm run test:e2e".',
].join( '\n' );

/**
 * A configuration problem, not a defect in the harness: the reader needs the message, and a
 * stack trace through the test runner would bury it.
 */
export class SiteEnvironmentError extends Error {
	constructor( problem: string ) {
		super( `${ problem }\n\n${ SITE_ENVIRONMENT_HELP }` );
		this.name = 'SiteEnvironmentError';
		this.stack = `${ this.name }: ${ this.message }`;
	}
}

export interface SiteEnvironment {
	/** Always ends in a slash, so relative requests resolve inside a subdirectory install. */
	baseURL: string;
	username: string;
	password: string;
}

/**
 * An empty value means "not set". It is what an unfilled copy of `.env.example` and an unset
 * CI secret both produce, and the package's fallback covers only `undefined`: it would parse
 * an empty WP_BASE_URL on import and die with a bare "Invalid URL".
 */
function discardEmptyVariables(): void {
	for ( const name of [ 'WP_BASE_URL', 'WP_USERNAME', 'WP_PASSWORD' ] ) {
		if ( process.env[ name ] === '' ) {
			delete process.env[ name ];
		}
	}
}

/**
 * Loads `.env` from the repository root, or the file named by `SEOCART_E2E_ENV_FILE`, then
 * rewrites WP_BASE_URL in its normalized form, so the package, which reads that variable
 * itself, works with the same value as the harness and not merely the same name.
 *
 * Variables already present in the environment win over the file, so a shell export or a
 * CI secret always overrides a checked-out `.env`. An empty variable is not present.
 */
export function loadSiteEnvironment(): void {
	const explicitFile = process.env.SEOCART_E2E_ENV_FILE;
	const file = explicitFile
		? resolve( explicitFile )
		: join( REPOSITORY_ROOT, '.env' );

	// Before the file, or an empty shell variable would shadow the value the file holds.
	discardEmptyVariables();

	if ( existsSync( file ) ) {
		if ( typeof process.loadEnvFile !== 'function' ) {
			throw new SiteEnvironmentError(
				`Cannot read ${ file }: process.loadEnvFile() needs Node 20.12 or later; "engines" in package.json names the supported versions. Upgrade Node, or export the variables in the shell.`
			);
		}
		process.loadEnvFile( file );
		discardEmptyVariables();
	} else if ( explicitFile ) {
		throw new SiteEnvironmentError(
			`SEOCART_E2E_ENV_FILE names ${ file }, which does not exist.`
		);
	}

	const baseURL = siteBaseURL();
	if ( baseURL ) {
		process.env.WP_BASE_URL = baseURL;
	} else if ( process.env.WP_BASE_URL ) {
		// Refused here, not in global setup: the package parses the variable on import and
		// would fail first, with a bare "Invalid URL".
		throw new SiteEnvironmentError(
			`WP_BASE_URL is not an absolute http(s) URL: "${ process.env.WP_BASE_URL }".`
		);
	}
}

/**
 * The site URL with a trailing slash, or `undefined` when it is unset or unusable.
 *
 * The configuration uses this form so that listing tests works without a site;
 * `requireSiteEnvironment()` is what refuses to run without one.
 */
export function siteBaseURL(): string | undefined {
	const value = process.env.WP_BASE_URL;
	if ( ! value ) {
		return undefined;
	}

	let url: URL;
	try {
		url = new URL( value );
	} catch {
		return undefined;
	}
	if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
		return undefined;
	}

	url.search = '';
	url.hash = '';
	if ( ! url.pathname.endsWith( '/' ) ) {
		url.pathname += '/';
	}
	return url.href;
}

/** Opt-in for development certificates the workstation does not trust. Off unless set to `1` or `true`. */
export function ignoreHTTPSErrors(): boolean {
	return [ '1', 'true' ].includes(
		process.env.SEOCART_E2E_IGNORE_HTTPS_ERRORS ?? ''
	);
}

/**
 * Returns the site under test, or throws the one message that explains how to configure it.
 */
export function requireSiteEnvironment(): SiteEnvironment {
	const baseURL = siteBaseURL();
	const { WP_USERNAME: username, WP_PASSWORD: password } = process.env;

	if ( ! baseURL || ! username || ! password ) {
		const missing = [
			! baseURL && 'WP_BASE_URL',
			! username && 'WP_USERNAME',
			! password && 'WP_PASSWORD',
		].filter( Boolean );
		throw new SiteEnvironmentError(
			`The site under test is not configured. Not set: ${ missing.join(
				', '
			) }.`
		);
	}

	return { baseURL, username, password };
}
