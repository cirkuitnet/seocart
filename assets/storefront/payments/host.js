/**
 * The host of the checkout's payment section: it shows the payment methods, mounts the selected
 * method's island, and places the order.
 *
 * The flow of `place()`:
 *
 *     idle → confirming   the island's confirm() prepares the payment data; a refusal sends nothing
 *          → placing      POST /checkout with the cart's version, its total and an idempotency key
 *          → settled      approved, processing, pending: onPlaced() is handed the order
 *          → acting       requires_action: the island, or the host by a redirect, takes the step
 *          → resuming     POST /checkout/resume, once the shopper has acted
 *          → settled      approved, or duplicate when the store settled the payment first
 *
 * and back to idle with an error code on any other answer, the idempotency key kept where the
 * same request must be sent again (answers.js, keeper.js). The browser never decides a payment:
 * every outcome is the Store API's.
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
import { contextFor, needsRemount } from './context';
import { createKeeper, keepsKey, samePlacement } from './keeper';
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
 * Creates a host.
 *
 * @param {HTMLElement} root    The element the methods are shown in.
 * @param {Object}      options The configuration, the cart and the callbacks (see index.js).
 * @param {Object}      env     The page: `window`, the registry, `onRegister` and `wait( ms )`.
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
	const api = createStoreApi( config.restRoot, ( ...args ) =>
		scope.fetch( ...args )
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
			warn( `${ name }() failed: ${ thrown?.message }` );
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
			warn( `island "${ id }" failed: ${ thrown?.message }` );
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
				`island "${ method.id }" failed canMakePayment(): ${ thrown?.message }`
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
						`island "${ method.id }" could not be mounted: ${ thrown?.message }`
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
	 * @param {string}      reason Why: the answer's kind.
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
			warn( `onStale() failed: ${ thrown?.message }` );
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
	 * Ends a declined payment: the island shows it, the cart is read again, and the next attempt
	 * gets a fresh key.
	 *
	 * @param {Object|null} body The answer.
	 * @return {Promise<void>} Once idle.
	 */
	async function decline( body ) {
		showError( {
			code: 'declined',
			message: String( body?.message ?? '' ),
		} );
		// The store opened the cart again, at a newer version.
		await refresh( 'declined', body );
		idle( problem( 'declined', body ) );
	}

	/**
	 * Acts on a placement's answer.
	 *
	 * @param {Object} response  What the placement came to.
	 * @param {Object} placement The kept placement.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function answered( response, placement ) {
		const kind = classify( response );
		const { body } = response;

		if ( ! keepsKey( kind ) ) {
			keeper.clear();
		}

		if ( 'acting' === kind ) {
			order = body;
			keeper.write( {
				...placement,
				intentUuid: body.intent_uuid ?? null,
			} );
			return act( body );
		}

		if ( 'settled' === kind ) {
			return settle( body, body );
		}

		if ( 'declined' === kind ) {
			return decline( body );
		}

		if ( 'stale' === kind || 'voided' === kind ) {
			// The island is told nothing: its payment was not refused.
			await refresh( kind, body );
		}

		idle( problem( kind, body ) );
	}

	/**
	 * Sends a placement, waiting and sending it again while another request with its key places
	 * the order.
	 *
	 * @param {Object} placement The kept placement.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function send( placement ) {
		enter( 'placing' );
		lastStep = 'placement';
		keeper.write( placement );

		let response = await api.place( placement );

		for (
			let waits = 0;
			'in_progress' === classify( response ) && waits < MAX_WAITS;
			waits++
		) {
			await env.wait(
				Math.min(
					Math.max( response.retryAfter, 1 ),
					MAX_WAIT_SECONDS
				) * 1000
			);

			if ( destroyed ) {
				return;
			}

			response = await api.place( placement );
		}

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
		const route = routeAction( registry.get( selected ), handle, next );

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
				} );
			} catch ( thrown ) {
				warn(
					`island "${ selected }" failed its action: ${ thrown?.message }`
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
	 * @param {Object} response What the resume came to.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function resumed( response ) {
		const kind = classify( response );
		const { body } = response;

		if (
			'acting' === kind ||
			( 'settled' === kind && 'processing' === body.outcome )
		) {
			// The shopper has not finished: the same request again replays the same step.
			lastStep = 'placement';
			return idle( { code: 'action_incomplete' } );
		}

		if ( ! keepsKey( kind ) ) {
			keeper.clear();
		}

		if ( 'settled' === kind ) {
			return settle( order ?? {}, body );
		}

		if ( 'declined' === kind ) {
			return decline( body );
		}

		if ( 'voided' === kind ) {
			await refresh( kind, body );
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
	 * Resumes the payment a provider sent the shopper back for, then sends the kept placement again
	 * to recover the order and its key.
	 *
	 * @param {string} intentUuid The payment's uuid, from the page's address.
	 * @return {Promise<void>} Once the flow rests.
	 */
	async function returnFrom( intentUuid ) {
		enter( 'resuming' );
		lastStep = 'resume';

		const response = await api.resume( intentUuid );
		const kept = keeper.read();

		if (
			! destroyed &&
			'settled' === classify( response ) &&
			kept?.intentUuid === intentUuid
		) {
			const replay = await api.place( kept );

			if ( 'settled' === classify( replay ) ) {
				order = replay.body;
			}
		}

		if ( ! destroyed ) {
			await resumed( response );
		}
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
		 * Places the order with the selected method. A placement already under way is not
		 * started again.
		 *
		 * @return {Promise<boolean>} Whether a placement was sent.
		 */
		async place() {
			if ( destroyed || 'idle' !== phase ) {
				return false;
			}

			const method = methods.get( selected );

			if ( ! method ) {
				idle( { code: 'no_method' } );
				return false;
			}

			const inputs = {
				amountMinor: cart.amountMinor,
				currency: cart.currency,
				gatewayId: method.id,
			};
			const kept = keeper.read();

			if ( samePlacement( kept, inputs ) ) {
				// The same placement, unanswered or unsettled: sent again as it was.
				await send( kept );
				return true;
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

					prepared = await handle.confirm();
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

			await send(
				keeper.begin( {
					cartVersion: cart.version,
					...inputs,
					paymentData: { ...prepared.payment_data },
				} )
			);

			return true;
		},

		/**
		 * Sends the kept placement again, as it was, or resumes its payment again when the resume
		 * was what failed; places afresh when nothing is kept.
		 *
		 * @return {Promise<boolean>} Whether a request was sent.
		 */
		async retry() {
			if ( destroyed || 'idle' !== phase ) {
				return false;
			}

			const kept = keeper.read();

			if ( ! kept ) {
				return host.place();
			}

			if ( 'resume' === lastStep && kept.intentUuid ) {
				await resume( kept.intentUuid );
			} else {
				await send( kept );
			}

			return true;
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
			view.destroy();
		},
	};

	/**
	 * Selects the method whose radio the shopper picked.
	 *
	 * @param {Event} event The change.
	 */
	const onPick = ( event ) => host.select( event.target.value );

	// An island that registers after the host was created is shown, and mounted when selected.
	const stopListening = env.onRegister( ( id ) => {
		if ( methods.has( id ) && 'idle' === phase && ! destroyed ) {
			render( true );
			emit();
		}
	} );

	view.group.addEventListener( 'change', onPick );
	render( true );
	emit();

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
		returnFrom( returned );
	}

	return host;
}
