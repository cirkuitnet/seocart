/**
 * A payment island for the payment fixture, registered for the stand-in gateway: it confirms with
 * the stand-in's tokens and takes the steps the stand-in asks for as the test tells it to.
 *
 * A classic script, as a payment plugin's island is: it registers with the statement every island
 * uses, whether it runs before or after the checkout's module. A test sets its options before the
 * page loads, on `window.__fakeIslandOptions`:
 *
 * - `id` and `contractVersion`: what it registers as; `stub` and `0.1.0` by default;
 * - `token`: the payment token confirm() returns, such as `stub:approve`, `stub:decline` or
 *   `stub:requires_action`; a list gives one token per confirmation, the last repeating;
 * - `action`: what action() resolves to, `completed`, `failed` or `left`; `decline` registers an
 *   island that takes no step, so the host takes it;
 * - `hold`: action() waits until the test calls `window.__fakeIsland.release()`;
 * - `mutate`: mount() writes to the cart, as an island must never do;
 * - `delay`: how long confirm() takes, in milliseconds.
 *
 * Every call made to it is recorded in `window.__fakeIsland.calls`, with the context it was given.
 */
( function () {
	const options = Object.assign(
		{
			id: 'stub',
			contractVersion: '0.1.0',
			token: 'stub:approve',
			action: 'completed',
			hold: false,
			mutate: false,
			delay: 0,
		},
		window.__fakeIslandOptions || {}
	);
	const tokens = [].concat( options.token );
	const record = ( window.__fakeIsland = window.__fakeIsland || {
		calls: [],
	} );
	let mounts = 0;

	const wait = ( ms ) =>
		new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

	const element = ( tag, className, text ) => {
		const created = document.createElement( tag );

		if ( className ) {
			created.className = className;
		}

		if ( text ) {
			created.textContent = text;
		}

		return created;
	};

	/**
	 * Writes a line to the cart, as an island must never do.
	 */
	const mutate = () => {
		const config = JSON.parse(
			document.querySelector( '.seocart-payments-config' ).textContent
		);

		window.fetch( config.restRoot + 'seocart/store/v1/cart/lines', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-SEOCart-Store': '1',
			},
			body: JSON.stringify( {
				lines: [ { variant_id: 990001, quantity: 1 } ],
			} ),
		} );
	};

	( ( window.seocart = window.seocart || {} ).payments = window.seocart
		.payments || {
		queue: [],
		register( island ) {
			this.queue.push( island );
		},
	} ).register( {
		id: options.id,
		contractVersion: options.contractVersion,
		supports: { action: 'decline' !== options.action },
		canMakePayment: () => true,
		mount( el, ctx ) {
			const field = `fake-island-token-${ ++mounts }`;
			const frame = element( 'div', 'seocart-island__frame' );
			const label = element( 'label', '', 'Test payment token' );
			const input = element( 'input' );

			record.calls.push( { name: 'mount', ctx: { ...ctx } } );

			frame.setAttribute( 'role', 'group' );
			frame.setAttribute( 'aria-label', 'Test payment details' );
			label.htmlFor = field;
			input.id = field;
			input.readOnly = true;
			input.value = tokens[ 0 ];
			frame.append( label, input );
			el.append(
				frame,
				element(
					'span',
					'seocart-island__note',
					'Test payments are answered as their token says. No money moves.'
				)
			);

			if ( options.mutate ) {
				mutate();
			}

			return {
				async confirm() {
					const token =
						tokens.length > 1 ? tokens.shift() : tokens[ 0 ];

					record.calls.push( { name: 'confirm', token } );
					await wait( options.delay );

					return { payment_data: { payment_token: token } };
				},
				async action( next, resume ) {
					record.calls.push( {
						name: 'action',
						type: next.type,
						url: next.url,
						handle: next.client_token,
						resume,
					} );

					if ( options.hold ) {
						await new Promise( ( resolve ) => {
							record.release = resolve;
						} );
					}

					return options.action;
				},
				showError( error ) {
					const notice = element(
						'div',
						'seocart-notice is-error',
						'The test payment was not accepted.'
					);

					record.calls.push( { name: 'showError', error } );
					notice.setAttribute( 'role', 'alert' );
					el.prepend( notice );
				},
				unmount() {
					record.calls.push( { name: 'unmount' } );
					el.replaceChildren();
				},
			};
		},
	} );
} )();
