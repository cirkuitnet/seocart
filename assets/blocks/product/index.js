/**
 * `@seocart/product`: the products a page shows and the variant picked of each, as the Interactivity store `seocart/product`.
 */

/**
 * WordPress dependencies
 *
 * WordPress's own module, and the storefront's store module: the build leaves both out and lists
 * them in the .asset.json file, and WordPress puts both in the page's import map, so neither is
 * installed or listed as a package.
 */
/* eslint-disable import/no-unresolved */
import { store } from '@wordpress/interactivity';
import { actions as cart } from '@seocart/store';
/* eslint-enable import/no-unresolved */

store( 'seocart/product', {
	actions: {
		add: () => cart,
	},
} );
