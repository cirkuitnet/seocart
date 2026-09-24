/**
 * The lifecycle spec: a merchant deactivates SEOCart and activates it again on the plugins
 * screen. It comes back active, with no PHP notice and no admin notice of its own, and Site
 * Health reports none of SEOCart's tests as critical.
 *
 * It changes the site the way a merchant would, and leaves SEOCart active, as it found it.
 * Nothing about a failure can be planted on the shared instance, so the spec checks itself
 * instead: SEOCart's tests must be found on the Site Health screen, and the schema and secrets
 * tests among the passed ones. A test that never ran cannot pass.
 *
 * The instance must define SEOCART_ENCRYPTION_KEY in its wp-config.php: without it the secrets
 * test is critical, by design, and says so by naming the constant. wp-env gets a key of its own
 * from the `afterStart` script in .wp-env.json, generated in the container the first time the
 * environment starts and kept from then on; a development instance gets one from its operator.
 */

import { test, expect } from '../fixtures';

/** The row of the plugin on the plugins screen; plugins-screen.spec.ts says why this selector holds. */
const SEOCART_ROW = 'tr[data-slug="seocart"]';

/** How the Site Health screen names the panel of a test's result: the test's id follows. */
const RESULT_PANEL = 'health-check-accordion-block-';

/** The Site Health tests that must pass on a healthy instance. */
const PASSING_TESTS = [ 'seocart_schema', 'seocart_secrets' ];

/** The constant the secrets test checks, and names in what it reports. */
const ENCRYPTION_KEY_CONSTANT = 'SEOCART_ENCRYPTION_KEY';

/** Passes once a runner has started one of the plugin's jobs; an instance nobody visits may only show it as recommended. */
const JOBS_TEST = 'seocart_jobs';

/** What PHP prints for a notice, a warning, a deprecation or an error, when it prints to the page. */
const PHP_MESSAGE = /\b(Notice|Warning|Deprecated|Fatal error|Parse error):/;

test.describe( 'Plugin lifecycle', () => {
	test( 'deactivating and activating SEOCart leaves it active, quiet and healthy', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'plugins.php' );

		const row = page.locator( SEOCART_ROW );

		await expect( row ).toHaveClass( /\bactive\b/ );

		await row.locator( 'span.deactivate a' ).click();
		await expect( page.locator( SEOCART_ROW ) ).toHaveClass(
			/\binactive\b/
		);

		await page.locator( SEOCART_ROW ).locator( 'span.activate a' ).click();
		await expect( page.locator( SEOCART_ROW ) ).toHaveClass( /\bactive\b/ );

		await expect( page.locator( 'body' ) ).not.toContainText( PHP_MESSAGE );
		await expect(
			page.locator( '.notice', { hasText: 'SEOCart' } ),
			'SEOCart showed an admin notice after a clean activation.'
		).toHaveCount( 0 );

		await admin.visitAdminPage( 'site-health.php' );

		// The screen runs its tests, then drops the loading state.
		await expect(
			page.locator( '.site-health-progress-wrapper' )
		).not.toHaveClass( /\bloading\b/, {
			timeout: 60_000,
		} );

		await expect(
			page.locator(
				`#health-check-issues-critical [aria-controls^="${ RESULT_PANEL }seocart_"]`
			),
			"Site Health reports a critical issue from one of SEOCart's tests."
		).toHaveCount( 0 );

		for ( const id of PASSING_TESTS ) {
			await expect(
				page.locator(
					`#health-check-issues-good [aria-controls="${ RESULT_PANEL }${ id }"]`
				),
				`The Site Health test ${ id } is not among the passed tests.`
			).toHaveCount( 1 );
		}

		await expect(
			page.locator( `#${ RESULT_PANEL }seocart_secrets` ),
			`The secrets test does not name ${ ENCRYPTION_KEY_CONSTANT }, the constant it checks.`
		).toContainText( ENCRYPTION_KEY_CONSTANT );

		await expect(
			page.locator( `[aria-controls="${ RESULT_PANEL }${ JOBS_TEST }"]` ),
			'The background jobs test did not run.'
		).toHaveCount( 1 );
	} );
} );
