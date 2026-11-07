/**
 * The Pricing card: a row per currency the store offers, for the chosen variant.
 *
 * A currency without a row of its own is converted from the base price when a shopper pays in it,
 * so nothing here works out an amount: the card shows what was authored and nothing else.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
	VisuallyHidden,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { currencyName } from './amount';
import { AmountField } from './fields';
import {
	GROSS,
	NET,
	needsPrice,
	priceShown,
	withPrice,
	withPriceFields,
} from './model';
import { declaration, LOCALE, settings } from './settings';
import { VariantSelect } from './variant-select';

/**
 * Whether a price includes tax: two buttons, the one in force pressed.
 *
 * @param {Object}   props          The props.
 * @param {string}   props.code     The currency's code.
 * @param {string}   props.basis    The amount basis.
 * @param {boolean}  props.disabled Whether there is no price to say it of.
 * @param {Function} props.onChange Receives the new basis.
 * @return {Element} The buttons.
 */
function AmountBasis( { code, basis, disabled, onChange } ) {
	return (
		<div
			className="seocart-segmented"
			role="group"
			aria-label={ sprintf(
				/* translators: %s: A currency code, such as USD. */
				__( '%s amount basis', 'seocart' ),
				code
			) }
		>
			<Button
				size="small"
				variant="secondary"
				disabled={ disabled }
				isPressed={ basis === GROSS }
				onClick={ () => onChange( GROSS ) }
			>
				{ __( 'Incl. tax', 'seocart' ) }
			</Button>
			<Button
				size="small"
				variant="secondary"
				disabled={ disabled }
				isPressed={ basis !== GROSS }
				onClick={ () => onChange( NET ) }
			>
				{ __( 'Excl. tax', 'seocart' ) }
			</Button>
		</div>
	);
}

/**
 * The row of one currency.
 *
 * Turning "auto-convert" off gives the currency a price field; the currency has a row of its own
 * once a price is typed in it, and a merchant who types none leaves it converted.
 *
 * @param {Object}   props          The props.
 * @param {Object}   props.currency The currency.
 * @param {Object}   props.row      The chosen row of the area.
 * @param {boolean}  props.needed   Whether saving waits for a price in this currency.
 * @param {Function} props.onChange Receives the change to make to the row's entry.
 * @return {Element} The row.
 */
function PriceRow( { currency, row, needed, onChange } ) {
	const { code } = currency;
	const base = settings.currency.code;
	const isBase = code === base;
	const price = priceShown( row, code );
	const [ priced, setPriced ] = useState( false );
	const converted = ! isBase && ! price && ! priced;

	return (
		<tr>
			<td>
				<span className="seocart-stack">
					<b>{ code }</b>
					<span className="seocart-help">
						{ currencyName( code, LOCALE ) }
					</span>
				</span>
			</td>
			<td className="seocart-num">
				<AmountField
					currency={ currency }
					hideLabelFromVision
					label={ sprintf(
						/* translators: 1: The name of a price, 2: A currency code, such as USD. */
						__( '%1$s in %2$s', 'seocart' ),
						declaration( 'variants.prices.price_minor' ).label,
						code
					) }
					disabled={ converted }
					help={
						needed
							? __(
									'This variant needs a price, or to be disabled, before the product can be saved.',
									'seocart'
								)
							: undefined
					}
					value={ price?.price_minor }
					onCommit={ ( minor ) =>
						onChange( ( entry, read ) =>
							withPrice( entry, read, code, minor, base )
						)
					}
				/>
			</td>
			<td className="seocart-num">
				<AmountField
					currency={ currency }
					hideLabelFromVision
					label={ sprintf(
						/* translators: 1: The name of a price, 2: A currency code, such as USD. */
						__( '%1$s in %2$s', 'seocart' ),
						declaration( 'variants.prices.compare_at_minor' ).label,
						code
					) }
					placeholder={ __( 'None', 'seocart' ) }
					disabled={ ! price }
					value={ price?.compare_at_minor }
					onCommit={ ( minor ) =>
						onChange( ( entry, read ) =>
							withPriceFields( entry, read, code, {
								compare_at_minor: minor,
							} )
						)
					}
				/>
			</td>
			<td>
				<AmountBasis
					code={ code }
					basis={ price?.amount_basis }
					disabled={ ! price }
					onChange={ ( basis ) =>
						onChange( ( entry, read ) =>
							withPriceFields( entry, read, code, {
								amount_basis: basis,
							} )
						)
					}
				/>
			</td>
			<td>
				{ isBase ? (
					<span className="seocart-badge">
						{ __( 'Base currency', 'seocart' ) }
					</span>
				) : (
					<ToggleControl
						__nextHasNoMarginBottom
						label={
							<>
								<VisuallyHidden>{ `${ code }: ` }</VisuallyHidden>
								{ sprintf(
									/* translators: %s: The base currency's code, such as USD. */
									__( 'Auto-convert from %s', 'seocart' ),
									base
								) }
							</>
						}
						checked={ converted }
						onChange={ ( automatic ) => {
							setPriced( ! automatic );

							if ( automatic ) {
								onChange( ( entry, read ) =>
									withPrice( entry, read, code, null, base )
								);
							}
						} }
					/>
				) }
			</td>
		</tr>
	);
}

/**
 * The card.
 *
 * @param {Object}   props           The props.
 * @param {Object}   props.state     What the area shows.
 * @param {Object}   props.row       The chosen row.
 * @param {Function} props.onSelect  Receives the key of the row chosen.
 * @param {Function} props.updateRow Changes a row.
 * @return {Element} The card.
 */
export function PricingCard( { state, row, onSelect, updateRow } ) {
	const title = useInstanceId( PricingCard, 'seocart-pricing-title' );
	const base = settings.currency.code;
	const waiting = state.edited && needsPrice( row, base );

	return (
		<Card as="section" aria-labelledby={ title } className="seocart-card">
			<CardHeader>
				<h3 id={ title } className="seocart-card-title">
					{ __( 'Pricing', 'seocart' ) }
				</h3>
				{ state.rows.length > 1 && (
					<VariantSelect
						label={ __( 'Prices for', 'seocart' ) }
						rows={ state.rows }
						value={ row.key }
						onChange={ onSelect }
					/>
				) }
			</CardHeader>
			<CardBody className="seocart-card-table" size="none">
				<div className="seocart-table-wrap">
					<table className="seocart-table seocart-pricing">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Currency', 'seocart' ) }
								</th>
								<th scope="col" className="seocart-num">
									{
										declaration(
											'variants.prices.price_minor'
										).label
									}
								</th>
								<th scope="col" className="seocart-num">
									{
										declaration(
											'variants.prices.compare_at_minor'
										).label
									}
								</th>
								<th scope="col">
									{ __( 'Amount basis', 'seocart' ) }
								</th>
								<th scope="col">
									{ __( 'Source', 'seocart' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ settings.currencies.map( ( currency ) => (
								<PriceRow
									key={ `${ row.key }|${ currency.code }` }
									currency={ currency }
									row={ row }
									needed={ waiting && currency.code === base }
									onChange={ ( change ) =>
										updateRow( row.key, change )
									}
								/>
							) ) }
						</tbody>
					</table>
				</div>
			</CardBody>
		</Card>
	);
}
