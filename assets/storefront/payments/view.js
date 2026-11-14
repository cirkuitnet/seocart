/**
 * The payment methods as the host shows them: one radio group, each method a choice with a panel
 * that holds its island or its instructions.
 *
 *     <div class="seocart-choices" role="radiogroup">
 *         <div class="seocart-choice is-selected" data-method="stripe">
 *             <label class="seocart-choice__row">
 *                 <input type="radio" value="stripe"><span class="seocart-choice__label">Card</span>
 *             </label>
 *             <div class="seocart-choice__panel">
 *                 <div class="seocart-island" data-gateway="stripe" data-mount="3"></div>
 *             </div>
 *         </div>
 *         …
 *     </div>
 *
 * The host writes classes and data attributes only: the checkout's stylesheet styles them, and the
 * words a shopper reads are the configuration's (each method's label and instructions) or the
 * checkout's. A method that cannot be used is shown disabled, `data-unavailable` naming why.
 * Each mount of an island gets a fresh element, numbered in `data-mount`.
 */

/**
 * Numbers each view, so the radio groups of two hosts on one page never share a name.
 */
let views = 0;

/**
 * Creates one element.
 *
 * @param {Document} document  The document.
 * @param {string}   tag       The tag.
 * @param {string}   className The class, or empty for none.
 * @return {HTMLElement} The element.
 */
function element( document, tag, className = '' ) {
	const created = document.createElement( tag );

	if ( className ) {
		created.className = className;
	}

	return created;
}

/**
 * Renders the methods into a host's root.
 *
 * @param {HTMLElement} root    The host's root; its `data-labelledby`, when set, names the group's label.
 * @param {Object[]}    methods The configuration's methods.
 * @return {Object} The view: show(), islandElement(), clearIsland(), destroy().
 */
export function createView( root, methods ) {
	const { ownerDocument: document } = root;
	const name = `seocart-payment-method-${ ++views }`;
	const group = element( document, 'div', 'seocart-choices' );
	const choices = new Map();

	group.setAttribute( 'role', 'radiogroup' );

	if ( root.dataset.labelledby ) {
		group.setAttribute( 'aria-labelledby', root.dataset.labelledby );
	}

	for ( const method of methods ) {
		const choice = element( document, 'div', 'seocart-choice' );
		const row = element( document, 'label', 'seocart-choice__row' );
		const input = element( document, 'input' );
		const label = element( document, 'span', 'seocart-choice__label' );
		const panel = element( document, 'div', 'seocart-choice__panel' );

		choice.dataset.method = method.id;
		input.type = 'radio';
		input.name = name;
		input.value = method.id;
		label.textContent = method.label;
		panel.hidden = true;

		if ( method.paysOnDelivery ) {
			choice.dataset.paysOnDelivery = '';
		}

		if ( ! method.island && method.instructions ) {
			const instructions = element( document, 'p' );

			instructions.textContent = method.instructions;
			panel.append( instructions );
		}

		row.append( input, label );
		choice.append( row, panel );
		group.append( choice );
		choices.set( method.id, { choice, input, panel } );
	}

	root.append( group );

	return {
		group,

		/**
		 * Shows which method is selected and which cannot be used.
		 *
		 * @param {string|null}         selected    The selected method's id.
		 * @param {Map<string, string>} unavailable Why each method that cannot be used cannot.
		 */
		show( selected, unavailable ) {
			for ( const [ id, { choice, input, panel } ] of choices ) {
				const reason = unavailable.get( id );

				choice.classList.toggle( 'is-selected', id === selected );
				choice.classList.toggle( 'is-disabled', !! reason );
				input.checked = id === selected;
				input.disabled = !! reason;
				panel.hidden = id !== selected;

				if ( reason ) {
					choice.dataset.unavailable = reason;
				} else {
					delete choice.dataset.unavailable;
				}
			}
		},

		/**
		 * Replaces a method's island element with a fresh one for a mount.
		 *
		 * @param {string} id    The method's id.
		 * @param {number} mount The mount's number.
		 * @return {HTMLElement} The element to mount the island in.
		 */
		islandElement( id, mount ) {
			const { panel } = choices.get( id );
			const island = element( document, 'div', 'seocart-island' );

			island.dataset.gateway = id;
			island.dataset.mount = String( mount );
			panel.querySelector( ':scope > .seocart-island' )?.remove();
			panel.append( island );

			return island;
		},

		/**
		 * Removes a method's island element once its island is unmounted.
		 *
		 * @param {string} id The method's id.
		 */
		clearIsland( id ) {
			choices
				.get( id )
				?.panel.querySelector( ':scope > .seocart-island' )
				?.remove();
		},

		/**
		 * Removes the view.
		 */
		destroy() {
			group.remove();
		},
	};
}
