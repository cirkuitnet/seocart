/**
 * Internal dependencies
 */
import {
	CONTRACT_VERSION,
	READY_EVENT,
	installRegistry,
	seriesOf,
	supportsContract,
} from '../registry';

/**
 * Returns a stand-in for the global object: the real document and event class, a console whose
 * warnings are recorded, and the count of ready events dispatched.
 *
 * @return {Object} The scope.
 */
function freshScope() {
	const scope = {
		document: document.implementation.createHTMLDocument( 'registry' ),
		console: { warn: jest.fn() },
		CustomEvent,
		ready: [],
	};

	scope.document.addEventListener( READY_EVENT, ( event ) =>
		scope.ready.push( event.detail.registry )
	);

	return scope;
}

/**
 * Runs an island's registration exactly as an island's classic script does.
 *
 * @param {Object} window The global object.
 * @param {Object} island The island.
 */
function registerAsAnIslandDoes( window, island ) {
	( ( window.seocart = window.seocart || {} ).payments = window.seocart
		.payments || {
		queue: [],
		register( queued ) {
			this.queue.push( queued );
		},
	} ).register( island );
}

/**
 * Returns a well-formed island.
 *
 * @param {string} id      Its id.
 * @param {Object} members Optional. Members to set or replace.
 * @return {Object} The island.
 */
function anIsland( id, members = {} ) {
	return {
		id,
		contractVersion: CONTRACT_VERSION,
		canMakePayment: () => true,
		mount: () => ( { confirm: async () => ( {} ), unmount() {} } ),
		...members,
	};
}

describe( 'the contract version', () => {
	it( 'is 0.1.0 and accepts its own series only', () => {
		expect( CONTRACT_VERSION ).toBe( '0.1.0' );
		expect( supportsContract( '0.1.0' ) ).toBe( true );
		expect( supportsContract( '0.1.7' ) ).toBe( true );
		expect( supportsContract( '0.2.0' ) ).toBe( false );
		expect( supportsContract( '1.1.0' ) ).toBe( false );
		expect( supportsContract( 'one' ) ).toBe( false );
	} );

	it( 'groups versions by minor before 1.0 and by major from 1.0', () => {
		expect( seriesOf( '0.1.3' ) ).toBe( '0.1' );
		expect( seriesOf( '2.4.1' ) ).toBe( '2' );
		expect( seriesOf( '' ) ).toBeNull();
	} );
} );

describe( 'installRegistry', () => {
	it( 'drains islands that registered first, in order, onto the object they created', () => {
		const scope = freshScope();

		registerAsAnIslandDoes( scope, anIsland( 'first' ) );
		registerAsAnIslandDoes( scope, anIsland( 'second' ) );

		const created = scope.seocart.payments;

		installRegistry( scope );

		expect( scope.seocart.payments ).toBe( created );
		expect( created.ids() ).toEqual( [ 'first', 'second' ] );
		expect( created.queue ).toEqual( [] );
		expect( created.contractVersion ).toBe( CONTRACT_VERSION );
		expect( scope.ready ).toEqual( [ created ] );
	} );

	it( 'registers an island that runs after it directly, through the same statement', () => {
		const scope = freshScope();

		installRegistry( scope );
		registerAsAnIslandDoes( scope, anIsland( 'later' ) );

		expect( scope.seocart.payments.ids() ).toEqual( [ 'later' ] );
		expect( scope.seocart.payments.queue ).toEqual( [] );
		expect( scope.seocart.payments.get( 'later' ).id ).toBe( 'later' );
	} );

	it( 'dispatches the ready event once, and installs once', () => {
		const scope = freshScope();
		const installed = installRegistry( scope );

		expect( installRegistry( scope ) ).toBe( installed );
		expect( scope.ready ).toHaveLength( 1 );
	} );

	it( 'keeps the first island of an id and refuses a duplicate with a warning', () => {
		const scope = freshScope();
		const first = anIsland( 'stub' );

		registerAsAnIslandDoes( scope, first );
		registerAsAnIslandDoes( scope, anIsland( 'stub' ) );
		installRegistry( scope );

		expect( scope.seocart.payments.get( 'stub' ) ).toBe( first );
		expect( scope.seocart.payments.refused() ).toEqual( [
			{
				id: 'stub',
				reason: 'duplicate: an island with this id is registered',
			},
		] );
		expect( scope.console.warn ).toHaveBeenCalledWith(
			'seocart.payments: island "stub" refused: duplicate: an island with this id is registered'
		);
	} );

	it( 'refuses an island written against another contract, never throwing', () => {
		const scope = freshScope();

		installRegistry( scope );

		expect(
			scope.seocart.payments.register(
				anIsland( 'future', { contractVersion: '0.2.0' } )
			)
		).toBe( false );
		expect( scope.seocart.payments.ids() ).toEqual( [] );
		expect( scope.seocart.payments.refused()[ 0 ].reason ).toMatch(
			/^incompatible: written for contract 0\.2\.0/
		);
		expect( scope.console.warn.mock.calls[ 0 ][ 0 ] ).toMatch(
			/^seocart\.payments: island "future" refused: incompatible/
		);
	} );

	it.each( [
		[ 'no object', null, '', 'invalid: not an object' ],
		[ 'no id', anIsland( '', {} ), '', 'invalid: id must be a string' ],
		[
			'no mount',
			anIsland( 'broken', { mount: undefined } ),
			'broken',
			'invalid: mount must be a function',
		],
		[
			'no canMakePayment',
			anIsland( 'broken', { canMakePayment: true } ),
			'broken',
			'invalid: canMakePayment must be a function',
		],
		[
			'supports that is no object',
			anIsland( 'broken', { supports: true } ),
			'broken',
			'invalid: supports must be an object',
		],
	] )( 'refuses an island with %s', ( name, refused, id, reason ) => {
		const scope = freshScope();

		installRegistry( scope );
		scope.seocart.payments.register( refused );

		expect( scope.seocart.payments.refused() ).toEqual( [
			{ id, reason },
		] );
		expect( scope.console.warn ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'tells a host of each island registered, the late ones included, until it stops listening', () => {
		const scope = freshScope();
		const heard = [];

		registerAsAnIslandDoes( scope, anIsland( 'early' ) );

		const installed = installRegistry( scope );
		const stop = installed.onRegister( ( id ) => heard.push( id ) );

		registerAsAnIslandDoes( scope, anIsland( 'late' ) );
		stop();
		registerAsAnIslandDoes( scope, anIsland( 'later' ) );

		expect( heard ).toEqual( [ 'late' ] );
		expect( scope.seocart.payments.ids() ).toEqual( [
			'early',
			'late',
			'later',
		] );
	} );

	it( 'wraps an island of an older contract its adapter knows, with a deprecation notice', () => {
		const scope = freshScope();
		const old = anIsland( 'legacy', { contractVersion: '0.0.4' } );
		const adapted = { ...old, contractVersion: CONTRACT_VERSION };

		installRegistry( scope, { '0.0': () => adapted } );
		scope.seocart.payments.register( old );

		expect( scope.seocart.payments.get( 'legacy' ) ).toBe( adapted );
		expect( scope.seocart.payments.refused() ).toEqual( [] );
		expect( scope.console.warn ).toHaveBeenCalledWith(
			'seocart.payments: island "legacy" uses contract 0.0.4, which is deprecated; update it to 0.1.0.'
		);
	} );
} );
