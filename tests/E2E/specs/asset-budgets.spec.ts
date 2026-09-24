/**
 * The performance-budget spec: every template here loads none of the plugin's own bytes.
 *
 * Covers: the front page and one ordinary published post, both logged out and logged in as an
 * administrator (the admin bar must not pull plugin assets either), and three non-plugin admin
 * screens: the dashboard, the edit-post screen for an ordinary post, and General Settings.
 * SEOCart has no admin screen of its own yet, so a non-zero ceiling on a plugin's own admin
 * screen has no case to cover here; when one ships, its budget is read from budget.json's
 * rules, the way bin/check-asset-budget.js already reads them for the build.
 */

import { test, expect } from '../fixtures';
import {
	describeAssetByteReport,
	type AssetBytes,
} from '../fixtures/asset-bytes';

/**
 * Measures one template, prints its report unconditionally (so the byte total and the plugin
 * URLs seen are visible on a pass, not only a failure), and asserts it loaded none of the
 * plugin's own bytes.
 *
 * The budget every template below is held to is 0, so nothing is read from budget.json here:
 * the assertion below is that literal zero, not a number from a file. It checks that no plugin
 * URL appears in the report at all, not only that the byte total comes out to zero -- a
 * request the browser blocks before its body transfers (a MIME-sniffing refusal, for example)
 * can finish at 0 measured bytes while still proving the plugin loaded something on a page
 * meant to load nothing from it, and asserting the URL list is empty is what actually catches
 * that case.
 *
 * @param assetBytes The fixture, bound to the test's page.
 * @param template   The name printed in the report and in a failure message.
 * @param load       Navigates to (or otherwise causes) the page load to measure.
 */
async function expectZeroPluginBytes(
	assetBytes: AssetBytes,
	template: string,
	load: () => Promise< void >
): Promise< void > {
	const report = await assetBytes.measure( template, load );
	const description = describeAssetByteReport( report, 0 );

	// Printed for every template, pass or fail, so a passing run still shows what was checked.
	// eslint-disable-next-line no-console
	console.log( description );

	expect( report.totalBytes, description ).toBe( 0 );
	expect( report.hits, description ).toEqual( [] );
}

test.describe( 'Asset byte budgets', () => {
	let postId: number;
	let postLink: string;

	test.beforeAll( async ( { requestUtils } ) => {
		const post = await requestUtils.createPost( {
			title: 'Asset budget fixture post',
			status: 'publish',
			date_gmt: new Date().toISOString(),
		} );
		postId = post.id;
		postLink = post.link;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/posts/${ postId }`,
			params: { force: true },
		} );
	} );

	for ( const loggedIn of [ false, true ] as const ) {
		const who = loggedIn ? 'an administrator' : 'a logged-out visitor';

		test( `the front page loads no plugin bytes for ${ who }`, async ( {
			page,
			assetBytes,
		} ) => {
			if ( ! loggedIn ) {
				await page.context().clearCookies();
			}

			await expectZeroPluginBytes(
				assetBytes,
				`front page (${ who })`,
				() => page.goto( '/' ).then( () => undefined )
			);
		} );

		test( `an ordinary published post loads no plugin bytes for ${ who }`, async ( {
			page,
			assetBytes,
		} ) => {
			if ( ! loggedIn ) {
				await page.context().clearCookies();
			}

			await expectZeroPluginBytes(
				assetBytes,
				`published post (${ who })`,
				() => page.goto( postLink ).then( () => undefined )
			);
		} );
	}

	test( 'the dashboard (index.php) loads no plugin bytes', async ( {
		admin,
		assetBytes,
	} ) => {
		await expectZeroPluginBytes(
			assetBytes,
			'wp-admin dashboard (index.php)',
			() => admin.visitAdminPage( 'index.php' )
		);
	} );

	test( 'the edit-post screen for an ordinary post loads no plugin bytes', async ( {
		admin,
		assetBytes,
	} ) => {
		await expectZeroPluginBytes(
			assetBytes,
			'edit post screen (post.php, an ordinary post)',
			() =>
				admin.visitAdminPage(
					'post.php',
					`post=${ postId }&action=edit`
				)
		);
	} );

	test( 'General Settings (options-general.php) loads no plugin bytes', async ( {
		admin,
		assetBytes,
	} ) => {
		await expectZeroPluginBytes(
			assetBytes,
			'General Settings (options-general.php)',
			() => admin.visitAdminPage( 'options-general.php' )
		);
	} );
} );
