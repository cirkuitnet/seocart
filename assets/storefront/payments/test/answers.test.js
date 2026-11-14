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
		[
			'a duplicate with no payment status',
			200,
			{ outcome: 'duplicate' },
			'pending',
		],
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

describe( 'a duplicate, by the order’s statuses', () => {
	// Every order status the store writes, in the order of each row's kinds below.
	const ORDERS = [
		'pending_payment',
		'awaiting_review',
		'processing',
		'on_hold',
		'preorder',
		'completed',
		'cancelled',
		'failed',
	];
	const SETTLED =
		'settled settled settled settled settled settled settled settled';
	const WAITING =
		'pending pending pending pending pending pending voided declined';

	it.each( [
		[ 'authorized', SETTLED ],
		[ 'paid', SETTLED ],
		[ 'partially_paid', SETTLED ],
		[ 'partially_refunded', SETTLED ],
		[ 'refunded', SETTLED ],
		[ 'disputed', SETTLED ],
		[ 'terms_pending', SETTLED ],
		[
			'failed',
			'declined declined declined declined declined declined declined declined',
		],
		[ 'voided', 'voided voided voided voided voided voided voided voided' ],
		[ 'unpaid', WAITING ],
		[ 'pending', WAITING ],
		[
			'one this module does not know',
			'pending pending pending pending pending pending pending pending',
		],
	] )(
		'reads a payment status of %s, order status by order status, as: %s',
		( paymentStatus, kinds ) => {
			expect(
				ORDERS.map( ( status ) =>
					classify( {
						status: 200,
						body: {
							outcome: 'duplicate',
							status,
							payment_status: paymentStatus,
						},
					} )
				)
			).toEqual( kinds.split( ' ' ) );
		}
	);

	it( 'waits on an order status it does not know, whatever the payment', () => {
		expect(
			classify( {
				status: 200,
				body: {
					outcome: 'duplicate',
					status: 'archived',
					payment_status: 'unpaid',
				},
			} )
		).toBe( 'pending' );
	} );
} );
