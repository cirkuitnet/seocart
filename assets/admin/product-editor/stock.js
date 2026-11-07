/**
 * The store's locations and the stock of a product's variants, read once for the area and the sidebar together.
 *
 * The two render in different trees, so what they read is kept here, and asked for again only when
 * the record changes, that is when a save has answered. The list of stock takes so many variants a
 * request, and a product may have more.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useSyncExternalStore } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { batchesOf } from './model';
import { settings } from './settings';

const { stock: stockAccess } = settings;

/**
 * What was read. `status` is `denied` for a user who may not manage inventory, else `loading`, `ready` or `failed`.
 */
let current = {
	status: stockAccess.canManage ? 'loading' : 'denied',
	locations: [],
	levels: {},
};

/** Nothing was asked yet. */
const NOT_ASKED = Symbol( 'not asked' );

/** The variants as read that `current` answers for, whose reference changes when a save has answered. */
let answered = NOT_ASKED;

/** The latest request: an answer to an earlier one is dropped. */
let latest = 0;

const listeners = new Set();

/**
 * Publishes what was read.
 *
 * @param {Object} next The fields that changed.
 */
function publish( next ) {
	current = { ...current, ...next };
	listeners.forEach( ( listener ) => listener() );
}

/**
 * Reads the locations and the stock of some variants.
 *
 * @param {Array<Object>} variants The variants as read.
 */
async function load( variants ) {
	const request = ++latest;

	publish( { status: 'loading' } );

	try {
		const ids = variants.map( ( { variant_id: id } ) => id );
		const [ { locations }, ...lists ] = await Promise.all( [
			apiFetch( { path: stockAccess.locationsPath } ),
			...batchesOf( ids, stockAccess.mostListed ).map( ( batch ) =>
				apiFetch( {
					path: addQueryArgs( stockAccess.stockPath, {
						variant_ids: batch.join( ',' ),
					} ),
				} )
			),
		] );
		const levels = {};

		for ( const list of lists ) {
			for ( const variant of list.variants ) {
				levels[ variant.variant_id ] = variant;
			}
		}

		if ( request === latest ) {
			publish( { status: 'ready', locations, levels } );
		}
	} catch {
		if ( request === latest ) {
			publish( { status: 'failed' } );
		}
	}
}

/**
 * Asks again for the stock of the variants asked for last, after a failure.
 */
export function retryStock() {
	load( answered === NOT_ASKED ? [] : ( answered ?? [] ) );
}

/**
 * Subscribes a component to what was read.
 *
 * @param {Function} listener Called on every change.
 * @return {Function} Takes the subscription back.
 */
function subscribe( listener ) {
	listeners.add( listener );

	return () => listeners.delete( listener );
}

/**
 * Returns the locations and the stock of a product's variants, reading them when the record has changed.
 *
 * @param {Array<Object>|undefined} variants The variants as read; a post without a product has none.
 * @return {{status: string, locations: Array<Object>, levels: Object<number, Object>}} What was read.
 */
export function useStock( variants ) {
	const read = useSyncExternalStore( subscribe, () => current );

	useEffect( () => {
		if ( stockAccess.canManage && variants !== answered ) {
			answered = variants;
			load( variants ?? [] );
		}
	}, [ variants ] );

	return read;
}
