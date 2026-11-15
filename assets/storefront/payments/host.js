/**
 * The host of the checkout's payment section: it shows the payment methods, mounts the selected
 * method's island, and places the order.
 *
 * The flow of `place()`:
 *
 *     idle → confirming   the island's confirm() prepares the payment data; a refusal sends nothing
 *          → placing      POST /checkout with the cart's version, its total and an idempotency key
 *          → settled      approved or processing: onPlaced() is handed the order
 *          → acting       requires_action: the island, or the host by a redirect, takes the step
 *          → resuming     POST /checkout/resume, once the shopper has acted
 *          → settled      approved, or duplicate when the store settled the payment first
 *
 * and back to idle with an error code on any other answer, the idempotency key kept where the
 * same placement must be asked for again (answers.js, keeper.js). The browser never decides a
 * payment: every outcome is the Store API's.
 *
 * When the page loads, the host settles what the tab kept from an earlier load: the payment a
 * provider sent the shopper back for, or the placement whose answer was lost (recover()). It never
 * acts by itself then: it asks the store, hands over an order the store settled, and otherwise
 * waits for the shopper to place again. A page the browser restores from its back/forward cache
 * after the shopper left it for the provider's step, by Back, is taken as a page loaded again
 * (onShow).
 *
 * The host sends those two writes and no other. It does not write the checkout's details (the
 * payment method chosen among them: onSelect() asks the checkout to), it has no Place button and
 * shows no words of its own: it reports codes through onChange(), and the checkout words them.
 *
 * A cart that changes reaches the host through update(). An island is mounted again only when the
 * payment it was mounted for changed (context.js); whatever an earlier mount was still doing is
 * then discarded. While the order is being placed, acted for or resumed, a new cart waits until
 * the host is idle again.
 */

/**
 * Internal dependencies
 */
import { classify } from './answers';
import {
	returnedIntent,
	returnUrlFor,
	routeAction,
	withoutReturn,
} from './action';
import { checkConfig } from './config';
import { billingOf, contextFor, needsRemount } from './context';
import {
	createKeeper,
	keepsKey,
	nothingToPlace,
	samePlacement,
} from './keeper';
import { createStoreApi } from './store-api';
import { createView } from './view';

/**
 * The phases during which the order is being placed, and a new cart waits.
 *
 * @type {string[]}
 */
const BUSY = [ 'placing', 'acting', 'resuming' ];

/**
 * How many times a placement still in progress is waited for and sent again.
 *
 * @type {number}
 */
const MAX_WAITS = 3;

/**
 * The longest wait for a placement in progress, in seconds, whatever Retry-After asks.
 *
 * @type {number}
 */
const MAX_WAIT_SECONDS = 30;

/**
 * How long a placement answered `pending` is waited for before it is asked once more, in ms.
 *
 * @type {number}
 */
const PENDING_WAIT_MS = 2000;

/**
 * Tells whether a cart is one the host can show.
 *
 * @param {*} cart The cart.
 * @return {boolean} True when its version and total are whole numbers and it has a currency.
 */
function isCart( cart ) {
	return (
		!! cart &&
		Number.isInteger( cart.version ) &&
		Number.isInteger( cart.amountMinor ) &&
		'string' === typeof cart.currency
	);
}

/**
 * Tells whether an island's confirm() returned payment data the placement can carry.
 *
 * @param {*} data The payment data.
 * @return {boolean} True for an object of strings.
 */
function isPaymentData( data ) {
	return (
		!! data &&
		'object' === typeof data &&
		! Array.isArray( data ) &&
		Object.values( data ).every( ( value ) => 'string' === typeof value )
	);
}

/**
 * Mints an idempotency key: a random uuid.
 *
 * `randomUUID()` exists only on a page served over HTTPS; a store served over plain HTTP gets the
 * same version 4 uuid from `getRandomValues()`.
 *
 * @param {Crypto} crypto The browser's `crypto`.
 * @return {string} The key.
 */
export function mintKey( crypto ) {
	if ( 'function' === typeof crypto.randomUUID ) {
		return crypto.randomUUID();
	}

	const bytes = crypto.getRandomValues( new Uint8Array( 16 ) );

	// The version, 4, in the high half of byte 6; the variant, binary 10, in the top bits of byte 8.
	bytes[ 6 ] = 0x40 + ( bytes[ 6 ] % 16 );
	bytes[ 8 ] = 0x80 + ( bytes[ 8 ] % 64 );

	const hex = Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );

	return `${ hex.slice( 0, 8 ) }-${ hex.slice( 8, 12 ) }-${ hex.slice(
		12,
		16
	) }-${ hex.slice( 16, 20 ) }-${ hex.slice( 20 ) }`;
}

/**
 * Returns the error a kind of answer reports.
 *
 * @param {string}      kind The answer's kind (answers.js).
 * @param {Object|null} body The answer.
 * @return {{code: string, detail?: string}} The error: the kind, and the Store API's code when there is one.
 */
function problem( kind, body ) {
	return 'string' === typeof body?.code
		? { code: kind, detail: body.code }
		: { code: kind };
}

/**
 * The names of the errors JavaScript and the browser throw themselves.
 *
 * @type {string[]}
 */
const BUILT_IN_ERRORS = [
	'Error',
	'AggregateError',
	'EvalError',
	'RangeError',
	'ReferenceError',
	'SyntaxError',
	'TypeError',
	'URIError',
	'DOMException',
];

/**
 * Names an error for the console by its kind alone: a provider's script may put a client secret
 * in an error's message, its code or even its name, and the console is no place for one. The
 * name is printed when it is a built-in error's or reads as an error class's (`…Error`);
 * anything else is printed as `Error`.
 *
 * @param {*} thrown What was thrown.
 * @return {string} The error's name, or `Error`.
 */
function nameOf( thrown ) {
	let name;

	try {
		name = thrown?.name;
	} catch {
		return 'Error';
	}

	return BUILT_IN_ERRORS.includes( name ) ||
		( 'string' === typeof name &&
			/^[A-Z][A-Za-z]{2,40}Error$/.test( name ) )
		? name
		: 'Error';
}

/**
 * Creates a host.
 *
 * @param {HTMLElement} root    The element the methods are shown in.
 * @param {Object}      options The configuration, the cart and the callbacks (see index.js).
 * @param {Object}      env     The page: `window`, the registry, `onRegister`, `wait( ms )` and,
 *                              optionally, `now()` in milliseconds (`Date.now` by default).
 * @return {Object} The host: update(), select(), place(), retry(), destroy().
 */
export function createHost( root, options, env ) {
	const { window: scope, registry } = env;
	const config = checkConfig( options.config );

	if ( ! isCart( options.cart ) ) {
		throw new Error(
			'seocart.payments: the cart needs a version, an amountMinor and a currency.'
		);
	}

	const methods = new Map(
		config.methods.map( ( method ) => [ method.id, method ] )
	);
	const keeper = createKeeper( scope, () => mintKey( scope.crypto ) );
	const api = createStoreApi(
		config.restRoot,
		( ...args ) => scope.fetch( ...args ),
		config.nonce
	);
	const view = createView( root, config.methods );
	const failed = new Map();
	const warned = new Set();

	let cart = { ...options.cart };
	let selected = methods.has( options.selected ) ? options.selected : null;
	let phase = 'idle';
	let error = null;
	let pending = null;
	let mounted = null;
	let mounts = 0;
	let lastStep = 'placement';
	let order = null;
	let destroyed = false;
	// When the store asked for no more requests until then (Retry-After), in milliseconds.
	let notBefore = 0;

	const now = () => ( env.now ?? Date.now )();

	const warn = ( message ) =>
		scope.console.warn( `seocart.payments: ${ message }` );

	/**
	 * Calls one of the embedding checkout's callbacks, a failure of it logged and never the host's.
	 *
	 * @param {string} name The callback's name.
	 * @param {*}      arg  What it is handed.
	 * @return {*} What it returned.
	 */
	const notify = ( name, arg ) => {
		if ( 'function' !== typeof options[ name ] ) {
			return undefined;
		}

		try {
			return options[ name ]( arg );
		} catch ( thrown ) {
			warn( `${ name }() failed: ${ nameOf( thrown ) }` );
			return undefined;
		}
	};

	/**
	 * Runs an island's call, a failure of it logged.
	 *
	 * @param {string}   id   The island's id.
	 * @param {Function} call The call.
	 */
	const quietly = ( id, call ) => {
		try {
			call();
		} catch ( thrown ) {
			warn( `island "${ id }" failed: ${ nameOf( thrown ) }` );
		}
	};

	const emit = () => notify( 'onChange', { selected, phase, error } );

	/**
	 * Says why a method cannot be used now, or null when it can.
	 *
	 * @param {Object} method The method.
	 * @return {string|null} The reason's code.
	 */
	function unavailableReason( method ) {
		const island = registry.get( method.id );

		if ( ! island ) {
			if ( ! method.island ) {
				return null;
			}

			if ( ! warned.has( method.id ) ) {
				warned.add( method.id );
				warn(
					`method "${ method.id }" needs an island and none is registered: it is shown unavailable.`
				);
			}

			return 'island_missing';
		}

		if ( failed.has( method.id ) ) {
			return failed.get( method.id );
		}

		try {
			return island.canMakePayment( contextFor( method, cart, config ) )
				? null
				: 'cannot_make_payment';
		} catch ( thrown ) {
			warn(
				`island "${ method.id }" failed canMakePayment(): ${ nameOf( thrown ) }`
			);
			return 'cannot_make_payment';
		}
	}

	/**
	 * Mounts the selected method's island in a fresh element, when it has one.
	 */
	function mountSelected() {
		const method = methods.get( selected );
		const island = method && registry.get( method.id );

		if ( ! island ) {
			return;
		}

		const mount = ++mounts;
		const element = view.islandElement( method.id, mount );
		const ctx = contextFor( method, cart, config );
		const current = { id: method.id, ctx, handle: null };

		mounted = current;
		current.ready = Promise.resolve()
			// A mount superseded before it began never reaches the island.
			.then( () =>
				mount === mounts ? island.mount( element, ctx ) : null
			)
			.then( ( handle ) => {
				if ( mount !== mounts || destroyed ) {
					quietly( method.id, () => handle?.unmount() );
					return null;
				}

				if (
					'function' !== typeof handle?.confirm ||
					'function' !== typeof handle?.unmount
				) {
					throw new Error(
						'mount() returned no confirm() and unmount()'
					);
				}

				current.handle = handle;

				return handle;
			} )
			.catch( ( thrown ) => {
				if ( mount === mounts && ! destroyed ) {
					warn(
						`island "${ method.id }" could not be mounted: ${ nameOf( thrown ) }`
					);
					failed.set( method.id, 'island_failed' );
					unmount();
					render();
					emit();
				}

				return null;
			} );
	}

	/**
	 * Unmounts the mounted island, discarding whatever it was still doing.
	 */
	function unmount() {
		if ( ! mounted ) {
			return;
		}

		const { id, handle } = mounted;

		mounted = null;
		mounts++;
		view.clearIsland( id );

		if ( handle ) {
			quietly( id, () => handle.unmount() );
		}
	}

	/**
	 * Shows the methods, and mounts the selected one's island when it is not mounted.
	 *
	 * @param {boolean} pick Optional. Select the first method that can be used when none is. Default false.
	 */
	function render( pick = false ) {
		const unavailable = new Map();

		for ( const method of config.methods ) {
			const reason = unavailableReason( method );

			if ( reason ) {
				unavailable.set( method.id, reason );
			}
		}

		if ( selected && unavailable.has( selected ) ) {
			unmount();
			selected = null;
		}

		if ( ! selected && pick ) {
			selected =
				config.methods.find(
					( method ) => ! unavailable.has( method.id )
				)?.id ?? null;

			if ( selected ) {
				notify( 'onSelect', selected );
			}
		}

		view.show( selected, unavailable );

		if ( selected && ! mounted ) {
			mountSelected();
		}

		return unavailable;
	}

	/**
	 * Takes a newer cart, mounting the island again when the payment it was mounted for changed.
	 *
	 * @param {Object} next The cart.
	 */
	function applyCart( next ) {
		if ( ! isCart( next ) || next.version < cart.version ) {
			return;
		}

		cart = { ...next };

		const method = methods.get( selected );

		if (
			mounted &&
			method &&
			needsRemount( mounted.ctx, contextFor( method, cart, config ) )
		) {
			unmount();
		}

		render();
	}

	/**
	 * Returns to idle, with an error or none, and takes the cart that waited.
	 *
	 * @param {Object|null} next Optional. The error. Default null.
	 */
	function idle( next = null ) {
		phase = 'idle';
		error = next;

		if ( pending ) {
			const waited = pending;

			pending = null;
			applyCart( waited );
		} else {
			render();
		}

		emit();
	}

	/**
	 * Enters a phase.
	 *
	 * @param {string} next The phase.
	 */
	function enter( next ) {
		phase = next;
		error = null;
		emit();
	}

	/**
	 * Hands an error to the mounted island to show, when it shows errors.
	 *
	 * @param {{code: string, message: string}} shown The error.
	 */
	function showError( shown ) {
		const handle = mounted?.handle;

		if ( 'function' === typeof handle?.showError ) {
			quietly( mounted.id, () => handle.showError( shown ) );
		}
	}

	/**
	 * Asks the checkout for the cart as it is now, and takes it.
	 *
	 * @param {string}      reason Why: `stale`, `declined` or `voided`.
	 * @param {Object|null} body   The answer.
	 * @return {Promise<void>} Once the cart is taken.
	 */
	async function refresh( reason, body ) {
		if ( 'function' !== typeof options.onStale ) {
			return;
		}

		try {
			const next = await options.onStale( {
				reason,
				code: body?.code ?? null,
			} );

			if ( ! destroyed ) {
				applyCart( next );
			}
		} catch ( thrown ) {
			warn( `onStale() failed: ${ nameOf( thrown ) }` );
		}
	}

	/**
	 * Ends a placement whose order the shopper moves on with, and hands the order over.
	 *
	 * @param {Object} placed The placement's answer: the order's uuid, number and key.
	 * @param {Object} answer The answer that settled it: the outcome and the statuses.
	 */
	function settle( placed, answer ) {
		const result = {
			outcome: answer.outcome,
			orderUuid: placed.order_uuid ?? null,
			orderNumber: placed.order_number ?? null,
			orderKey: placed.order_key ?? null,
			status: answer.status ?? null,
			paymentStatus: answer.payment_status ?? null,
			intentUuid: answer.intent_uuid ?? null,
		};

		// The order key is handed over once and kept nowhere.
		order = null;
		phase = 'settled';
		error = null;
		emit();
		notify( 'onPlaced', result );
	}

	/**
	 * Ends a payment that came to no order the shopper moves on with: a decline, which the island
	 * shows, or a void, which it is not told. The cart is read again, since the store opened it
	 * again at a newer version, and the next attempt gets a fresh key.
	 *
	 * @param {string}      kind `declined` or `voided`.
	 * @param {Object|null} body The answer.
	 * @return {Promise<void>} Once idle.
	 */
	async function release( kind, body ) {
		keeper.clear();

		if ( 'declined' === kind ) {
			showError( {
				code: 'declined',
				message: String( body?.message ?? '' ),
			} );
		}

		await refresh( kind, body );
		idle( problem( kind, body ) );
	}

	/**
	 * Asks the store for a placement, waiting and asking again while another request with its key
	 * places the order.
	 *
	 * @param {Object} placement The placement.
	 * @return {Promise<Object>} What the last request came to.
	 */
	async function ask( placement ) {
		let response = await api.place( placement );

		for (
			let waits = 0;
			'in_progress' === classify( response ) &&
			waits < MAX_WAITS &&
			! destroyed;
			waits++
		) {
			await env.wait(
				Math.min(
					Math.max( response.retryAfter, 1 ),
					MAX_WAIT_SECONDS
				) * 1000
			);

			// A host destroyed while it waited sends nothing more.
			if ( destroyed ) {
				break;
			}

			response = await api.place( placement );
		}

		return response;
	}

	/**
	 * Rests idle with an answer that keeps the key, and the error the checkout words.
	 *
	 * Too many requests is told with how long to wait (Retry-After), and nothing is sent before
	 * then; a session that needs a fresh nonce with its code.
	 *
	 * @param {string} kind     The answer's kind: one that keeps the key.
	 * @param {Object} response What the request came to.
	 */
	function rest( kind, response ) {
		const { body } = response;

		if ( 'session' === kind ) {
			return idle( { code: 'session_expired', detail: body?.code } );
		}

		if ( 'rate_limited' === kind ) {
			notBefore = now() + response.retryAfter * 1000;

			return idle( {
				...problem( kind, body ),
				retryAfter: response.retryAfter,
			} );
		}

		idle( problem( kind, body ) );
	}

	/**
	 * Refuses to send before the moment the store asked for no more requests until, resting idle
	 * with how many seconds are left.
	 *
	 * @return {boolean} True when it refused.
	 */
	function tooSoon() {
		const left = Math.ceil( ( notBefore - now() ) / 1000 );

		if ( left <= 0 ) {
			return false;
		}

		idle( {
			code: 'rate_limited',
			detail: 'store_api.rate_limited',
			retryAfter: left,
		} );

		return true;
	}

	/**
	 * Acts on a placement's answer.
	 *
	 * @param {Object}  response   What the placement came to.
	 * @param {Object}  placement  The kept placement.
	 * @param {boolean} recovering Optional. Whether the host asks on loading, when it never acts and
	 *                             drops a placement the store does not hold. Default false.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function answered( response, placement, recovering = false ) {
		let answer = response;
		let kind = classify( answer );

		if ( 'pending' === kind ) {
			// Still at the gateway: asked once more after a moment, then left to the store.
			await env.wait( PENDING_WAIT_MS );

			if ( destroyed ) {
				return;
			}

			answer = await ask( placement );
			kind = classify( answer );
		}

		const { body } = answer;

		if ( destroyed ) {
			return;
		}

		if ( recovering && ( 'stale' === kind || 'refused' === kind ) ) {
			// The store holds nothing under the key: the kept placement never reached it.
			keeper.clear();
			return idle();
		}

		if ( ! keepsKey( kind ) ) {
			keeper.clear();
		}

		if ( 'acting' === kind ) {
			order = body;
			keeper.write( {
				...placement,
				intentUuid: body.intent_uuid ?? null,
			} );

			if ( recovering ) {
				// The shopper takes the step once they place again; nothing acts on loading.
				lastStep = 'placement';
				return idle( { code: 'action_incomplete' } );
			}

			return act( body );
		}

		if ( 'settled' === kind ) {
			return settle( body, body );
		}

		if ( 'declined' === kind || 'voided' === kind ) {
			return release( kind, body );
		}

		if ( 'stale' === kind ) {
			// The island is told nothing: its payment was not refused.
			await refresh( kind, body );
		}

		if ( keepsKey( kind ) ) {
			return rest( kind, answer );
		}

		idle( problem( kind, body ) );
	}

	/**
	 * Sends a placement, and acts on the answer.
	 *
	 * @param {Object} placement The placement, with the payment data just confirmed.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function send( placement ) {
		enter( 'placing' );
		lastStep = 'placement';
		keeper.write( placement );

		const response = await ask( placement );

		if ( ! destroyed ) {
			await answered( response, placement );
		}
	}

	/**
	 * Takes the step the provider asks of the shopper: the island first, when it takes steps; a
	 * redirect by the host otherwise.
	 *
	 * @param {Object} answer The placement's answer.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function act( answer ) {
		enter( 'acting' );

		const next = answer.next_action;
		const intentUuid = answer.intent_uuid;

		if ( ! next || ! intentUuid ) {
			return idle( { code: 'action_incomplete' } );
		}

		const handle = mounted ? await mounted.ready : null;
		let route;

		try {
			route = routeAction( registry.get( selected ), handle, next );
		} catch ( thrown ) {
			// A handle that cannot say whether it takes the step fails it: the store asks the
			// provider, and its answer decides, as after an action() that throws.
			warn(
				`island "${ selected }" failed its action: ${ nameOf( thrown ) }`
			);
			return resume( intentUuid );
		}

		if ( 'island' === route ) {
			const mount = mounts;
			let result;

			try {
				result = await handle.action( next, {
					intentUuid,
					returnUrl: returnUrlFor(
						scope.location.href,
						config.returnQueryArg,
						intentUuid
					),
					billing: billingOf( cart ),
				} );
			} catch ( thrown ) {
				warn(
					`island "${ selected }" failed its action: ${ nameOf( thrown ) }`
				);
				result = 'failed';
			}

			// `left`: the provider's script is taking the shopper away, and the return resumes.
			if ( destroyed || mount !== mounts || 'left' === result ) {
				return;
			}

			// Completed or failed alike: the store asks the provider, and its answer decides.
			return resume( intentUuid );
		}

		if ( 'redirect' === route ) {
			scope.location.assign( next.url );
			return;
		}

		idle( { code: 'action_unsupported' } );
	}

	/**
	 * Acts on the resume's answer.
	 *
	 * A `duplicate`, a payment another path settled first, is taken by the order's statuses
	 * (answers.js): a payment that went through hands the order over; a failed payment, or a failed
	 * order with nothing taken, is a decline, and a voided payment, or a cancelled order with
	 * nothing taken, a void; any other waits.
	 *
	 * @param {Object}  response What the resume came to.
	 * @param {boolean} own      Optional. Whether the payment resumed is the one the tab kept; a
	 *                           refusal of another, such as a forged return address, leaves the
	 *                           kept placement alone. Default true.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function resumed( response, own = true ) {
		const kind = classify( response );
		const { body } = response;

		if ( 'pending' === kind && 'duplicate' === body?.outcome ) {
			// Settled elsewhere, though neither paid nor failed: the order waits.
			return idle( { code: 'pending' } );
		}

		if (
			'acting' === kind ||
			'pending' === kind ||
			( 'settled' === kind && 'processing' === body.outcome )
		) {
			// The shopper has not finished: placing again is answered the same step.
			lastStep = 'placement';
			return idle( { code: 'action_incomplete' } );
		}

		if ( ! keepsKey( kind ) && ( own || 'refused' !== kind ) ) {
			keeper.clear();
		}

		if ( 'settled' === kind ) {
			return settle( order ?? {}, body );
		}

		if ( 'declined' === kind || 'voided' === kind ) {
			return release( kind, body );
		}

		if ( keepsKey( kind ) ) {
			return rest( kind, response );
		}

		idle( problem( kind, body ) );
	}

	/**
	 * Resumes a payment the shopper acted for.
	 *
	 * @param {string} intentUuid The payment's uuid.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function resume( intentUuid ) {
		enter( 'resuming' );
		lastStep = 'resume';

		const response = await api.resume( intentUuid );

		if ( ! destroyed ) {
			await resumed( response );
		}
	}

	/**
	 * Settles, when the page loads, what the tab kept from an earlier load, and never acts.
	 *
	 * A payment to resume (the one a provider sent the shopper back for, or else the one the kept
	 * placement names) is resumed; once the store settled it, the kept placement is asked again,
	 * with no payment data, for the order and its key; until it answers with them, the host
	 * rests with the placement kept, and retry() asks again.
	 *
	 * A kept placement whose answer was lost is asked again, with no payment data, once the cart
	 * has moved past the version it was sent with, which a placement that committed does; the
	 * answer is taken as a placement's, except that a step the provider asks for waits for the
	 * shopper to place again, and a placement the store does not hold is dropped. While the cart is
	 * still at that version the request may never have arrived, or may still be on its way: the
	 * placement is kept, nothing is sent, and the next placement sends its key, which either places
	 * for the first time or is answered the order the earlier request placed.
	 *
	 * @param {string|null} returned The payment's uuid the page's address carried back, if any.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function recover( returned ) {
		const kept = keeper.read();
		const intentUuid = returned ?? kept?.intentUuid ?? null;

		if ( intentUuid ) {
			enter( 'resuming' );
			lastStep = 'recovery';

			const response = await api.resume( intentUuid );
			const kind = classify( response );

			if ( destroyed ) {
				return;
			}

			if (
				'settled' === kind &&
				'processing' !== response.body.outcome &&
				kept?.intentUuid === intentUuid
			) {
				const replay = await ask( { ...kept, paymentData: {} } );
				const replayed = classify( replay );

				if ( destroyed ) {
					return;
				}

				if ( 'settled' !== replayed ) {
					// The payment is settled, but its order and key did not come back: the placement is
					// kept, and retry() asks for them again. No order is handed over without its key.
					return keepsKey( replayed )
						? rest( replayed, replay )
						: idle( problem( replayed, replay.body ) );
				}

				order = replay.body;
			}

			await resumed( response, ! kept || kept.intentUuid === intentUuid );

			return;
		}

		if ( ! kept || cart.version === kept.cartVersion ) {
			// Nothing kept; or kept, and sent with the next placement.
			return;
		}

		enter( 'placing' );
		lastStep = 'recovery';

		const placement = { ...kept, paymentData: {} };
		const response = await ask( placement );

		if ( ! destroyed ) {
			await answered( response, placement, true );
		}
	}

	/**
	 * Settles what the tab kept as the page loads (recover()): the payment the address carries back
	 * from a provider, taken off it so that a reload does not resume it again, or else the kept
	 * placement; a placement kept for another shopper is forgotten first.
	 *
	 * @return {Promise<void>} Once the flow rests.
	 */
	function load() {
		const returned = returnedIntent(
			scope.location.href,
			config.returnQueryArg
		);

		if ( returned ) {
			// Once handled, a reload must not resume again.
			scope.history.replaceState(
				scope.history.state,
				'',
				withoutReturn( scope.location.href, config.returnQueryArg )
			);
		}

		const inherited = keeper.read();

		// A placement kept for another shopper of this tab, another account or a guest's against an
		// account's, is forgotten without a request: its order is not this shopper's to be handed.
		if ( inherited && inherited.shopper !== config.shopper ) {
			keeper.clear();
		}

		return recover( returned );
	}

	const host = {
		/**
		 * Takes the cart as it is now. An older version than the one held is ignored; while the
		 * order is being placed, the cart waits until the host is idle.
		 *
		 * @param {Object} next The cart: version, amountMinor, currency, billingCountry, billing.
		 */
		update( next ) {
			if ( destroyed || 'settled' === phase ) {
				return;
			}

			if ( BUSY.includes( phase ) ) {
				if (
					isCart( next ) &&
					( ! pending || next.version >= pending.version )
				) {
					pending = next;
				}

				return;
			}

			applyCart( next );
			emit();
		},

		/**
		 * Selects a payment method, when the host is idle and the method can be used.
		 *
		 * @param {string} id The method's id.
		 */
		select( id ) {
			if (
				destroyed ||
				'idle' !== phase ||
				id === selected ||
				! methods.has( id ) ||
				unavailableReason( methods.get( id ) )
			) {
				render();
				return;
			}

			unmount();
			selected = id;
			error = null;
			render();
			notify( 'onSelect', id );
			emit();
		},

		/**
		 * Places the order with the selected method: the island confirms what the form shows now,
		 * then the placement is sent under the kept key when it is the one kept, or when the cart
		 * has nothing to place in its stead (nothingToPlace()), and under a fresh key otherwise.
		 * While the asking on loading for a kept placement has had no verdict from the store,
		 * placing asks it again under its key, as retry() does, and confirms nothing. A placement
		 * already under way is not started again.
		 *
		 * @return {Promise<boolean>} Whether a request was sent.
		 */
		async place() {
			if ( destroyed || 'idle' !== phase || tooSoon() ) {
				return false;
			}

			const kept = keeper.read();

			if ( kept && 'recovery' === lastStep ) {
				// The store has not answered for the kept placement since the page loaded: it may be
				// an order. Placing asks for it under its key, and never mints another.
				await recover( null );
				return true;
			}

			const method = methods.get( selected );

			if ( ! method ) {
				idle( { code: 'no_method' } );
				return false;
			}

			enter( 'confirming' );

			const mount = mounts;
			let prepared;

			try {
				if ( registry.get( method.id ) ) {
					const handle = mounted ? await mounted.ready : null;

					if ( ! handle ) {
						throw Object.assign( new Error( 'not mounted' ), {
							code: 'island_failed',
						} );
					}

					prepared = await handle.confirm( {
						billing: billingOf( cart ),
					} );
				} else {
					prepared = { payment_data: {} };
				}
			} catch ( thrown ) {
				if ( destroyed ) {
					return false;
				}

				const refused = {
					code: 'island_refused',
					detail: String( thrown?.code ?? '' ),
					message: String( thrown?.message ?? '' ),
				};

				if ( mount === mounts ) {
					showError( refused );
				}

				idle( mount === mounts ? refused : { code: 'stale' } );
				return false;
			}

			if ( destroyed ) {
				return false;
			}

			// The island was mounted again while it confirmed: its result is for another payment.
			if ( mount !== mounts ) {
				idle( { code: 'stale' } );
				return false;
			}

			if ( ! isPaymentData( prepared?.payment_data ) ) {
				warn(
					`island "${ method.id }" confirmed no payment_data object of strings.`
				);
				idle( { code: 'island_refused', detail: 'payment_data' } );
				return false;
			}

			const paymentData = { ...prepared.payment_data };
			const inputs = {
				amountMinor: cart.amountMinor,
				currency: cart.currency,
				gatewayId: method.id,
			};
			await send(
				kept &&
					( samePlacement( kept, inputs ) ||
						nothingToPlace( kept, cart ) )
					? { ...kept, paymentData }
					: keeper.begin( {
							cartVersion: cart.version,
							...inputs,
							shopper: config.shopper,
							paymentData,
						} )
			);

			return true;
		},

		/**
		 * Repeats the step that failed: the resume, the asking on loading, or the placement.
		 *
		 * @return {Promise<boolean>} Whether a request was sent.
		 */
		async retry() {
			if ( destroyed || 'idle' !== phase || tooSoon() ) {
				return false;
			}

			const kept = keeper.read();

			if ( 'resume' === lastStep && kept?.intentUuid ) {
				await resume( kept.intentUuid );
				return true;
			}

			if ( 'recovery' === lastStep && kept ) {
				await recover( null );
				return true;
			}

			return host.place();
		},

		/**
		 * Unmounts the island and removes the methods. A request under way is ignored when it ends.
		 */
		destroy() {
			if ( destroyed ) {
				return;
			}

			destroyed = true;
			unmount();
			stopListening();
			view.group.removeEventListener( 'change', onPick );
			scope.removeEventListener( 'pageshow', onShow );
			view.destroy();
		},
	};

	/**
	 * Selects the method whose radio the shopper picked.
	 *
	 * @param {Event} event The change.
	 */
	const onPick = ( event ) => host.select( event.target.value );

	/**
	 * Treats a page the browser restores from its back/forward cache while the host was in the
	 * provider's step as a page loaded again. The shopper left it for the step (an island's `left`,
	 * the host's redirect, or their own way off the page while the step was under way) and came
	 * back by Back: the page would otherwise stay in the step, and placing would do nothing. The
	 * island is mounted again, which abandons whatever the step was still doing, and the host
	 * settles what the tab kept as on loading. Restored in any other phase, the page carries on as
	 * it was left: nothing was left mid-step, and a request under way is answered as it would have
	 * been.
	 *
	 * @param {PageTransitionEvent} event The page's showing.
	 */
	const onShow = ( event ) => {
		if ( event.persisted && 'acting' === phase ) {
			unmount();
			render();
			load();
		}
	};

	// An island that registers after the host was created is shown, and mounted when selected.
	const stopListening = env.onRegister( ( id ) => {
		if ( methods.has( id ) && 'idle' === phase && ! destroyed ) {
			render( true );
			emit();
		}
	} );

	view.group.addEventListener( 'change', onPick );
	scope.addEventListener( 'pageshow', onShow );
	render( true );
	emit();
	load();

	return host;
}
