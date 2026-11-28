/**
 * The performance-budget spec: every template that is not the plugin's loads none of its bytes,
 * and the plugin's own admin screen loads only its own script, within its budget.
 *
 * Covers: the front page and one ordinary published post, both logged out and logged in as an
 * administrator (the admin bar must not pull plugin assets either), and three non-plugin admin
 * screens: the dashboard, the edit-post screen for an ordinary post, and General Settings. The
 * plugin's first admin screen is the product editor, whose Product data area is one script and
 * one stylesheet; their ceilings are read from budget.json's rules through fileBudgetBytes(), the
 * way bin/check-asset-budget.js reads them for the build, never restated here.
 *
 * A product's page, whose content is the storefront's three product blocks, loads the storefront's
 * two script modules and its stylesheet and nothing else of the plugin, logged out and logged in:
 * each file within its budget.json rule, the modules within the one budget the file does not hold,
 * the plugin's script modules on one template together (STOREFRONT_MODULES_BYTES).
 */

import { test, expect } from '../fixtures';
import {
	describeAssetByteReport,
	type AssetBytes,
} from '../fixtures/asset-bytes';
import { fileBudgetBytes } from '../support/budget';
import {
	createBlockProduct,
	trashBlockProduct,
} from '../support/block-product';

/**
 * The most compressed bytes the plugin's script modules may weigh together on one storefront
 * template, beyond what WordPress ships: a budget of the template, not of a file, so budget.json,
 * whose rules are per file, does not hold it.
 */
const STOREFRONT_MODULES_BYTES = 25 * 1024;

/** The most script modules of the plugin's one storefront template may load. */
const STOREFRONT_MODULES_COUNT = 3;

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

	test( 'the product editor loads only the Product data area, within its budgets', async ( {
		admin,
		assetBytes,
		pluginBasePath,
	} ) => {
		const report = await assetBytes.measure(
			'product editor (post-new.php, a product)',
			() =>
				admin.visitAdminPage(
					'post-new.php',
					'post_type=seocart_product'
				)
		);
		const buildBase = `${ pluginBasePath }build/`;
		const files = report.hits.map( ( hit ) => {
			const path = new URL( hit.url ).pathname;

			return path.startsWith( buildBase )
				? path.slice( buildBase.length )
				: path;
		} );
		const budgets = files.map( ( file ) => fileBudgetBytes( file ) );
		const description = describeAssetByteReport(
			report,
			budgets.reduce< number >(
				( total, budget ) => total + ( budget ?? 0 ),
				0
			)
		);

		// eslint-disable-next-line no-console
		console.log( description );

		expect( [ ...new Set( files ) ].sort(), description ).toEqual( [
			'admin/product-editor.js',
			'admin/style-product-editor.css',
		] );

		report.hits.forEach( ( hit, index ) => {
			expect( budgets[ index ], description ).toBeDefined();
			expect( hit.bytes, description ).toBeLessThanOrEqual(
				budgets[ index ] ?? 0
			);
		} );
		expect(
			report.hits.some( ( hit ) => hit.bytes > 0 ),
			description
		).toBe( true );
	} );

	for ( const loggedIn of [ false, true ] as const ) {
		const who = loggedIn ? 'an administrator' : 'a logged-out visitor';

		test( `a product page loads the storefront's two modules and its stylesheet, within their budgets, for ${ who }`, async ( {
			page,
			assetBytes,
			pluginBasePath,
			requestUtils,
		} ) => {
			const product = await createBlockProduct(
				requestUtils,
				'Asset budget product',
				[
					{
						key: 'size',
						label: 'Size',
						values: [
							{ key: 'small', label: 'Small' },
							{ key: 'large', label: 'Large' },
						],
					},
				],
				[
					{ values: { size: 'small' }, priceMinor: 1800, onHand: 5 },
					{ values: { size: 'large' }, priceMinor: 5800, onHand: 5 },
				]
			);

			try {
				if ( ! loggedIn ) {
					await page.context().clearCookies();
				}

				const report = await assetBytes.measure(
					`product page (${ who })`,
					() => page.goto( product.link ).then( () => undefined )
				);
				const buildBase = `${ pluginBasePath }build/`;
				const files = report.hits.map( ( hit ) => {
					const path = new URL( hit.url ).pathname;

					return path.startsWith( buildBase )
						? path.slice( buildBase.length )
						: path;
				} );
				const budgets = files.map( ( file ) =>
					fileBudgetBytes( file )
				);
				const modules = report.hits.filter( ( hit, index ) =>
					files[ index ].endsWith( '.js' )
				);
				const moduleBytes = modules.reduce(
					( total, hit ) => total + hit.bytes,
					0
				);
				const description = describeAssetByteReport(
					report,
					budgets.reduce< number >(
						( total, budget ) => total + ( budget ?? 0 ),
						0
					)
				);

				// eslint-disable-next-line no-console
				console.log(
					`${ description }\nScript modules: ${ modules.length } of ${ STOREFRONT_MODULES_COUNT }, ${ moduleBytes } of ${ STOREFRONT_MODULES_BYTES } bytes together.`
				);

				expect( [ ...new Set( files ) ].sort(), description ).toEqual( [
					'blocks/product.js',
					'blocks/store.js',
					'blocks/storefront.css',
				] );
				report.hits.forEach( ( hit, index ) => {
					expect( budgets[ index ], description ).toBeDefined();
					expect( hit.bytes, description ).toBeGreaterThan( 0 );
					expect( hit.bytes, description ).toBeLessThanOrEqual(
						budgets[ index ] ?? 0
					);
				} );
				expect( modules.length, description ).toBeLessThanOrEqual(
					STOREFRONT_MODULES_COUNT
				);
				expect( moduleBytes, description ).toBeLessThanOrEqual(
					STOREFRONT_MODULES_BYTES
				);
			} finally {
				await trashBlockProduct( requestUtils, product.id );
			}
		} );
	}

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
