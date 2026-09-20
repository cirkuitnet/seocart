/**
 * The Wave 0 smoke spec: the harness can reach the site as an administrator, and WordPress
 * lists the plugin under the name its header declares. It changes nothing on the site.
 */

import { test, expect } from '../fixtures';

/**
 * WordPress derives `data-slug` from the `Plugin Name` header (or from the directory entry
 * once the plugin is listed there), not from the install directory, so this holds for a
 * symlinked worktree and for an installed release zip alike.
 */
const SEOCART_ROW = 'tr[data-slug="seocart"]';

test.describe( 'Plugins screen', () => {
	test( 'lists SEOCart for an administrator', async ( {
		admin,
		page,
		expectNoAccessibilityViolations,
	} ) => {
		// Fails with "Not logged in" if the saved session did not authenticate.
		await admin.visitAdminPage( 'plugins.php' );

		await expect(
			page.getByRole( 'heading', {
				name: 'Plugins',
				level: 1,
				exact: true,
			} )
		).toBeVisible();

		const row = page.locator( SEOCART_ROW );
		await expect( row ).toBeVisible();
		await expect( row.locator( '.plugin-title strong' ) ).toHaveText(
			'SEOCart'
		);

		// Scoped to the SEOCart row, on purpose. Everything else on this screen belongs to
		// WordPress core or to whatever other plugins the instance has, and varies with
		// update nags, admin notices and the WordPress version: gating on it would make this
		// suite fail for defects nobody here can fix. The row is the only markup on the
		// screen that SEOCart's own header text feeds, so it is the part this gate can own.
		// The scan is real, not a formality: tests/E2E/selftest/axe.spec.ts proves the helper
		// fails on a violation inside an included region.
		await expectNoAccessibilityViolations( { include: SEOCART_ROW } );
	} );
} );
