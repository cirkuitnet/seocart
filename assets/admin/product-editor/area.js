/**
 * The Product data area: the full-width block under the post's content.
 *
 * On a translation post it is one sentence and a button to the source post, since a product's
 * prices, stock and variants are one for all its posts and are edited there.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { Button, Path, SVG } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useCallback, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { useAreaModel, useSourcePost } from './area-model';
import { InventoryCard } from './inventory-card';
import { OptionsCard } from './options-card';
import { PricingCard } from './pricing-card';
import { ShippingCard } from './shipping-card';
import { useStock } from './stock';

/**
 * What a translation post shows instead of the cards.
 *
 * A button, not a link: the block editor sends the Tab key out of a block at a link.
 *
 * @param {Object} props        The props.
 * @param {number} props.source The source post's id.
 * @return {Element} The note.
 */
function TranslationNote( { source } ) {
	return (
		<div className="seocart-area-translation">
			<p>
				{ __(
					'This post is a translation: the product’s prices, stock and variants are edited on its source post.',
					'seocart'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ () =>
					window.location.assign(
						`post.php?post=${ source }&action=edit`
					)
				}
			>
				{ __( 'Edit the source post', 'seocart' ) }
			</Button>
		</div>
	);
}

/**
 * The four cards, over one choice of variant.
 *
 * @return {Element} The cards.
 */
function Cards() {
	const { saved, state, offSale, updateRow, updateRows, updateAxes } =
		useAreaModel();
	const stock = useStock( saved?.variants );
	const [ chosenKey, setChosenKey ] = useState( null );
	const inventorySelect = useRef();
	const { rows } = state;
	const row = rows.find( ( { key } ) => key === chosenKey ) ?? rows[ 0 ];

	const showStock = useCallback( ( key ) => {
		setChosenKey( key );
		inventorySelect.current?.focus();
	}, [] );

	const cards = {
		state,
		row,
		onSelect: setChosenKey,
		updateRow,
	};

	return (
		<>
			{ row && (
				<>
					<PricingCard { ...cards } />
					<InventoryCard
						{ ...cards }
						ref={ inventorySelect }
						stock={ stock }
					/>
					<ShippingCard { ...cards } />
				</>
			) }
			<OptionsCard
				state={ state }
				offSale={ offSale }
				stock={ stock }
				updateRow={ updateRow }
				updateRows={ updateRows }
				updateAxes={ updateAxes }
				onShow={ showStock }
			/>
		</>
	);
}

/**
 * The area.
 *
 * @return {Element} The area.
 */
export function ProductDataArea() {
	const title = useInstanceId(
		ProductDataArea,
		'seocart-product-data-title'
	);
	const [ collapsed, setCollapsed ] = useState( false );
	const source = useSourcePost();

	return (
		<section className="seocart-area" aria-labelledby={ title }>
			<div className="seocart-area-bar">
				<h2 id={ title }>{ __( 'Product data', 'seocart' ) }</h2>
				{ ! source && (
					<Button
						className={
							collapsed
								? 'seocart-area-toggle is-collapsed'
								: 'seocart-area-toggle'
						}
						size="small"
						label={
							collapsed
								? __( 'Expand product data', 'seocart' )
								: __( 'Collapse product data', 'seocart' )
						}
						aria-expanded={ ! collapsed }
						icon={
							<SVG
								xmlns="http://www.w3.org/2000/svg"
								viewBox="0 0 24 24"
							>
								<Path d="M17.5 11.6 12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z" />
							</SVG>
						}
						onClick={ () => setCollapsed( ! collapsed ) }
					/>
				) }
			</div>
			{ source ? (
				<TranslationNote source={ source } />
			) : (
				<div className="seocart-area-inner" hidden={ collapsed }>
					<Cards />
				</div>
			) }
		</section>
	);
}
