/**
 * The registry the checkout's payment islands register with: `window.seocart.payments`.
 *
 * A payment plugin's island is a classic script, which may run before or after this module. It
 * registers with one statement that works either way:
 *
 *     ( ( window.seocart = window.seocart || {} ).payments = window.seocart.payments || {
 *         queue: [],
 *         register( island ) { this.queue.push( island ); },
 *     } ).register( island );
 *
 * When the island runs first, it creates that stand-in object and queues itself. When this module
 * is installed, it puts the real methods onto the same object, registers the queued islands in the
 * order they arrived, empties the queue, and dispatches `seocart:payments:ready` on the document
 * once. From then on `register()` is the real one, and an island that registers later is accepted
 * like any other: the hosts showing the payment methods are told and show it.
 *
 * The registry checks what it is given and never throws: an island written against another
 * contract, a second island for an id already registered (the first is kept) and an island
 * missing a member are each refused with a console warning naming the id and the reason, and
 * listed by `refused()`.
 */

/**
 * The version of the island contract this registry implements.
 *
 * @type {string}
 */
export const CONTRACT_VERSION = '0.1.0';

/**
 * The event dispatched on the document once the registry is installed and the queue is drained.
 *
 * @type {string}
 */
export const READY_EVENT = 'seocart:payments:ready';

/**
 * Wrappers that present an island written against an older contract with the current interface,
 * keyed by that contract's series (`major.minor` before 1.0, `major` from 1.0). An island of a
 * series listed here is accepted wrapped, with a deprecation notice; none is listed while the
 * contract has had one version.
 *
 * @type {Readonly<Object<string, function(Object): Object>>}
 */
export const ADAPTERS = Object.freeze( {} );

/**
 * Where the installed registry keeps its own state on the global object, out of sight of
 * enumeration, so a second copy of this module finds it instead of installing again.
 */
const INSTALLED = Symbol.for( 'seocart.payments.registry' );

/**
 * The members every island must have, and the type each must be.
 *
 * @type {Array<[string, string]>}
 */
const REQUIRED = [
	[ 'id', 'string' ],
	[ 'contractVersion', 'string' ],
	[ 'canMakePayment', 'function' ],
	[ 'mount', 'function' ],
];

/**
 * Returns the series a version belongs to: islands of one series share an interface.
 *
 * @param {string} version A version such as `0.1.0`.
 * @return {string|null} `major.minor` before 1.0, `major` from 1.0; null for no version.
 */
export function seriesOf( version ) {
	const parts = /^(\d+)\.(\d+)\.\d+/.exec( String( version ) );

	if ( ! parts ) {
		return null;
	}

	return '0' === parts[ 1 ] ? `0.${ parts[ 2 ] }` : parts[ 1 ];
}

/**
 * Tells whether an island written against a version of the contract works with this registry as
 * it is: the same major version and, before 1.0, the same minor.
 *
 * @param {string} version The version the island was written against.
 * @return {boolean} True when it does.
 */
export function supportsContract( version ) {
	const series = seriesOf( version );

	return null !== series && series === seriesOf( CONTRACT_VERSION );
}

/**
 * Returns why an island is malformed, or null when it has every member it needs.
 *
 * @param {*} island What was registered.
 * @return {string|null} The reason.
 */
function malformed( island ) {
	if ( ! island || 'object' !== typeof island ) {
		return 'invalid: not an object';
	}

	for ( const [ member, type ] of REQUIRED ) {
		if ( type !== typeof island[ member ] || '' === island[ member ] ) {
			return `invalid: ${ member } must be a ${ type }`;
		}
	}

	if (
		undefined !== island.supports &&
		( ! island.supports || 'object' !== typeof island.supports )
	) {
		return 'invalid: supports must be an object';
	}

	return null;
}

/**
 * Installs the registry onto `scope.seocart.payments`, keeping the object an island may have
 * created, and drains its queue. Installing twice returns the registry installed first.
 *
 * @param {Object}                                   scope    The global object: `window`, or a
 *                                                            test's stand-in with `document`,
 *                                                            `console` and `CustomEvent`.
 * @param {Object<string, function(Object): Object>} adapters Optional. The wrappers of older
 *                                                            contracts; ADAPTERS.
 * @return {Object} `onRegister( listener )`, which has the listener told the id of each island
 *                  registered from then on, and returns the function that stops it.
 */
export function installRegistry( scope, adapters = ADAPTERS ) {
	const seocart = ( scope.seocart = scope.seocart || {} );
	const payments = ( seocart.payments = seocart.payments || {} );

	if ( payments[ INSTALLED ] ) {
		return payments[ INSTALLED ];
	}

	const islands = new Map();
	const refusals = [];
	const listeners = new Set();

	const refuse = ( id, reason ) => {
		refusals.push( { id, reason } );
		scope.console.warn(
			`seocart.payments: island "${ id }" refused: ${ reason }`
		);
	};

	/**
	 * Registers an island, or refuses it with a warning.
	 *
	 * @param {Object} island The island.
	 * @return {boolean} True when it was registered.
	 */
	const register = ( island ) => {
		const id = String( island?.id ?? '' );
		const invalid = malformed( island );

		if ( invalid ) {
			refuse( id, invalid );
			return false;
		}

		let accepted = island;

		if ( ! supportsContract( island.contractVersion ) ) {
			const adapter = adapters[ seriesOf( island.contractVersion ) ];

			if ( ! adapter ) {
				refuse(
					id,
					`incompatible: written for contract ${ island.contractVersion }, this registry implements ${ CONTRACT_VERSION }`
				);
				return false;
			}

			scope.console.warn(
				`seocart.payments: island "${ id }" uses contract ${ island.contractVersion }, which is deprecated; update it to ${ CONTRACT_VERSION }.`
			);
			accepted = adapter( island );
		}

		if ( islands.has( id ) ) {
			refuse( id, 'duplicate: an island with this id is registered' );
			return false;
		}

		islands.set( id, accepted );
		listeners.forEach( ( listener ) => listener( id ) );

		return true;
	};

	const installed = {
		onRegister( listener ) {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
	};

	const queued = Array.isArray( payments.queue ) ? payments.queue : [];

	Object.defineProperty( payments, INSTALLED, { value: installed } );
	Object.assign( payments, {
		contractVersion: CONTRACT_VERSION,
		register,
		supportsContract,
		get: ( id ) => islands.get( id ),
		ids: () => [ ...islands.keys() ],
		refused: () => refusals.map( ( refusal ) => ( { ...refusal } ) ),
		queue: [],
	} );

	queued.forEach( register );

	scope.document.dispatchEvent(
		new scope.CustomEvent( READY_EVENT, { detail: { registry: payments } } )
	);

	return installed;
}
