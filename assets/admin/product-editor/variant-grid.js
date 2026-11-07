/**
 * The variant grid: one row per combination of the product's options.
 *
 * Every control in it is an input, a select or a button, and no scroll region takes focus, because
 * the block editor sends the Tab key out of a block at the next focusable that is anything else.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { Button, CheckboxControl, VisuallyHidden } from '@wordpress/components';
import { memo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { AmountField, DraftField, WholeNumberField } from './fields';
import {
	availableAcross,
	enabledShown,
	priceShown,
	stockBadge,
	weightShown,
	withEnabled,
	withPrice,
	withSku,
	withWeight,
} from './model';
import { declaration, settings } from './settings';

/**
 * What the Available column shows of a variant: its units, and a button to its stock by location.
 *
 * @param {Object}      props           The props.
 * @param {Object}      props.row       The row.
 * @param {Object|void} props.level     The variant's row in the stock list.
 * @param {Array}       props.locations The store's locations.
 * @param {Function}    props.onShow    Shows the variant's stock by location.
 * @return {Element} The figure.
 */
function AvailableFigure( { row, level, locations, onShow } ) {
	const units = availableAcross( row, level, locations );
	const active = locations.filter( ( { active: isActive } ) => isActive );
	const badge = stockBadge( units, level );

	return (
		<span className="seocart-available">
			{ badge === 'out' && (
				<span className="seocart-badge is-error">
					{ __( 'Out', 'seocart' ) }
				</span>
			) }
			{ badge === 'backorder' && (
				<span className="seocart-badge is-info">
					{ __( 'Backorder', 'seocart' ) }
				</span>
			) }
			{ badge === 'low' && (
				<span className="seocart-badge is-warning">
					{ __( 'Low', 'seocart' ) }
				</span>
			) }
			<Button
				size="small"
				variant="tertiary"
				className="seocart-available-button"
				onClick={ () => onShow( row.key ) }
				label={ sprintf(
					/* translators: 1: A variant's label, 2: A number of units, 3: A number of locations. */
					_n(
						'%1$s: %2$d available across %3$d location. Show stock by location',
						'%1$s: %2$d available across %3$d locations. Show stock by location',
						active.length,
						'seocart'
					),
					row.label,
					units,
					active.length
				) }
				showTooltip={ false }
			>
				{ units }
			</Button>
		</span>
	);
}

/**
 * One row of the grid. A row re-renders only when its own entry changes.
 *
 * @param {Object}      props           The props.
 * @param {Object}      props.row       The row.
 * @param {Object}      props.currency  The currency whose price the row shows.
 * @param {Object|void} props.level     The variant's row in the stock list.
 * @param {Array}       props.locations The store's locations.
 * @param {boolean}     props.showStock Whether the user may see stock.
 * @param {Function}    props.updateRow Changes a row.
 * @param {Function}    props.onShow    Shows a variant's stock by location.
 * @return {Element} The row.
 */
const GridRow = memo( function Row( {
	row,
	currency,
	level,
	locations,
	showStock,
	updateRow,
	onShow,
} ) {
	const { key, entry, label } = row;
	const shown = enabledShown( row );
	const base = settings.currency.code;
	const sku = declaration( 'variants.sku' );
	const weight = declaration( 'variants.weight_grams' );
	const named = ( field ) =>
		sprintf(
			/* translators: 1: The name of a field, such as SKU, 2: A variant's label. */
			__( '%1$s of %2$s', 'seocart' ),
			field,
			label
		);

	return (
		<tr className={ shown === false ? 'is-disabled' : undefined }>
			<th scope="row" className="seocart-variant-name">
				{ label }
			</th>
			<td>
				<DraftField
					className="seocart-sku"
					hideLabelFromVision
					label={ named( sku.label ) }
					maxLength={ sku.maxChars ?? undefined }
					value={ entry.sku }
					format={ ( text ) => text ?? '' }
					parse={ ( text ) => text }
					onCommit={ ( text ) =>
						updateRow( key, ( current ) =>
							withSku( current, text )
						)
					}
				/>
			</td>
			<td>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={
						<VisuallyHidden>
							{ named( declaration( 'variants.enabled' ).label ) }
						</VisuallyHidden>
					}
					checked={ shown === true }
					indeterminate={ shown === null }
					onChange={ ( enabled ) =>
						updateRow( key, ( current, read ) =>
							withEnabled( current, read, enabled )
						)
					}
				/>
			</td>
			<td className="seocart-num">
				<AmountField
					key={ currency.code }
					currency={ currency }
					hideLabelFromVision
					label={ sprintf(
						/* translators: 1: The name of a price, 2: A currency code, such as USD, 3: A variant's label. */
						__( '%1$s in %2$s of %3$s', 'seocart' ),
						declaration( 'variants.prices.price_minor' ).label,
						currency.code,
						label
					) }
					placeholder={
						currency.code === base
							? undefined
							: __( 'Auto', 'seocart' )
					}
					value={ priceShown( row, currency.code )?.price_minor }
					onCommit={ ( minor ) =>
						updateRow( key, ( current, read ) =>
							withPrice(
								current,
								read,
								currency.code,
								minor,
								base
							)
						)
					}
				/>
			</td>
			<td className="seocart-num">
				{ showStock ? (
					<AvailableFigure
						row={ row }
						level={ level }
						locations={ locations }
						onShow={ onShow }
					/>
				) : (
					<VisuallyHidden>
						{ __( 'Not shown', 'seocart' ) }
					</VisuallyHidden>
				) }
			</td>
			<td className="seocart-num">
				<WholeNumberField
					className="seocart-weight"
					hideLabelFromVision
					label={ named( weight.label ) }
					maximum={ weight.maximum }
					value={ weightShown( row ) }
					onCommit={ ( grams ) =>
						updateRow( key, ( current, read ) =>
							withWeight( current, read, grams )
						)
					}
				/>
			</td>
		</tr>
	);
} );

/**
 * The grid.
 *
 * @param {Object}   props           The props.
 * @param {Array}    props.rows      The rows of the area.
 * @param {Object}   props.currency  The currency whose prices the grid shows.
 * @param {Object}   props.stock     The locations and the stock as read.
 * @param {Function} props.updateRow Changes a row.
 * @param {Function} props.onShow    Shows a variant's stock by location.
 * @return {Element} The grid.
 */
export function VariantGrid( { rows, currency, stock, updateRow, onShow } ) {
	return (
		<div className="seocart-table-wrap seocart-grid-wrap">
			<table className="seocart-table seocart-variant-grid">
				<thead>
					<tr>
						<th scope="col">
							{ declaration( 'variants.variant_id' ).label }
						</th>
						<th scope="col">
							{ declaration( 'variants.sku' ).label }
						</th>
						<th scope="col">
							{ declaration( 'variants.enabled' ).label }
						</th>
						<th scope="col" className="seocart-num">
							{ sprintf(
								/* translators: 1: The name of a price, 2: A currency code, such as USD. */
								__( '%1$s (%2$s)', 'seocart' ),
								declaration( 'variants.prices.price_minor' )
									.label,
								currency.code
							) }
						</th>
						<th scope="col" className="seocart-num">
							{ __( 'Available', 'seocart' ) }
						</th>
						<th scope="col" className="seocart-num">
							{ declaration( 'variants.weight_grams' ).label }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) => (
						<GridRow
							key={ row.key }
							row={ row }
							currency={ currency }
							level={ stock.levels[ row.read?.variant_id ] }
							locations={ stock.locations }
							showStock={ stock.status !== 'denied' }
							updateRow={ updateRow }
							onShow={ onShow }
						/>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
