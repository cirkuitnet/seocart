/**
 * `@seocart/store`: the storefront's session and cart, as the Interactivity store `seocart/store`.
 */

/**
 * WordPress dependencies
 *
 * WordPress's own module: the build leaves it out and lists it in the .asset.json file, and
 * WordPress puts it in the page's import map, so it is not installed or listed as a package.
 */
/* eslint-disable import/no-unresolved */
import { store } from '@wordpress/interactivity';
/* eslint-enable import/no-unresolved */

export const { state, actions } = store( 'seocart/store', {
	state: {},
	actions: {},
} );
