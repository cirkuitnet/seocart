/**
 * Internal dependencies
 */
import { classify } from '../answers';

describe( 'classify', () => {
	it.each( [
		[ 'approved', 200, { outcome: 'approved' }, 'settled' ],
		[ 'processing', 200, { outcome: 'processing' }, 'settled' ],
		[
			'a placement still at the gateway',
			200,
			{ outcome: 'pending' },
			'pending',
		],
		[ 'duplicate', 200, { outcome: 'duplicate' }, 'settled' ],
		[ 'requires_action', 200, { outcome: 'requires_action' }, 'acting' ],
		[ 'a decline replayed', 200, { outcome: 'declined' }, 'declined' ],
		[ 'a void replayed', 200, { outcome: 'voided' }, 'voided' ],
		[ 'an answer with no outcome', 200, {}, 'server' ],
		[ 'a decline', 402, { code: 'checkout.payment_declined' }, 'declined' ],
		[ 'a stale cart', 409, { code: 'cart.version_stale' }, 'stale' ],
		[ 'changed totals', 409, { code: 'checkout.totals_changed' }, 'stale' ],
		[
			'a missing nonce',
			403,
			{ code: 'store_api.nonce_missing' },
			'session',
		],
		[
			'an expired nonce',
			403,
			{ code: 'rest_cookie_invalid_nonce' },
			'session',
		],
		[
			'a placement in progress',
			409,
			{ code: 'checkout.placement_in_progress' },
			'in_progress',
		],
		[
			'an unavailable gateway',
			502,
			{ code: 'checkout.gateway_unavailable' },
			'unavailable',
		],
		[ 'a server error', 500, { code: 'internal_server_error' }, 'server' ],
		[ 'a proxy’s error page', 504, null, 'server' ],
		[
			'a cart that is not open',
			409,
			{ code: 'cart.not_open' },
			'refused',
		],
		[
			'a method the payment cannot use',
			422,
			{ code: 'checkout.payment_method_unavailable' },
			'refused',
		],
	] )( 'reads %s as %s', ( name, status, body, kind ) => {
		expect( classify( { status, body } ) ).toBe( kind );
	} );

	it( 'reads no answer as a network failure', () => {
		expect( classify( { network: true } ) ).toBe( 'network' );
	} );
} );
