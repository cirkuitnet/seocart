/**
 * The Inventory card: the chosen variant's stock at each location, its count editable.
 *
 * Available and Committed come from the stock list. On hand is what the record read, which is the
 * count a change is guarded by: a cell is applied only while the location still holds that count.
 * A count that was not the one the location held when the product was saved comes back as a
 * conflict, with the count now, and is said here: one that moved meanwhile, or the count of a
 * variant brought back with stock this screen could not read before the save.
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
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { forwardRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { DraftField, WholeNumberField } from './fields';
import {
	availableAcross,
	enabledShown,
	locationsOf,
	needsSku,
	stockAt,
	withEnabled,
	withOnHand,
	withSku,
} from './model';
import { declaration } from './settings';
import { retryStock } from './stock';
import { VariantSelect } from './variant-select';

/**
 * Writes what a variant does when it runs out, as a merchant reads it.
 *
 * @param {string} policy The stock policy's value: no, notify or allow.
 * @return {string} The sentence's words.
 */
function backorderWords( policy ) {
	switch ( policy ) {
		case 'allow':
			return __( 'Allow backorders', 'seocart' );
		case 'notify':
			return __( 'Allow, tell the customer', 'seocart' );
		default:
			return __( 'Stop selling', 'seocart' );
	}
}

/**
 * The read-only line of a variant's stock policy.
 *
 * @param {Object}      props       The props.
 * @param {Object|void} props.level The variant's row in the stock list.
 * @return {Element|null} The line; none before the variant is saved.
 */
function PolicyLine( { level } ) {
	if ( ! level ) {
		return null;
	}

	const low = level.low_stock_threshold;

	return (
		<p className="seocart-help seocart-policy">
			{ [
				level.track
					? __( 'Track stock: yes', 'seocart' )
					: __( 'Track stock: no', 'seocart' ),
				sprintf(
					/* translators: %s: What happens when stock runs out, such as Stop selling. */
					__( 'When out of stock: %s', 'seocart' ),
					backorderWords( level.backorder_policy )
				),
				low === null
					? __( 'Low-stock alert: none', 'seocart' )
					: sprintf(
							/* translators: %d: A number of units. */
							__( 'Low-stock alert: %d', 'seocart' ),
							low
						),
			].join( ' · ' ) }
		</p>
	);
}

/**
 * The table of the chosen variant's stock by location.
 *
 * @param {Object}      props           The props.
 * @param {Object}      props.row       The chosen row.
 * @param {Object|void} props.level     The variant's row in the stock list.
 * @param {Array}       props.locations The store's locations.
 * @param {Function}    props.onChange  Receives the change to make to the row's entry.
 * @return {Element} The table.
 */
function LocationTable( { row, level, locations, onChange } ) {
	const { entry, read, label } = row;
	const shown = locationsOf( locations, level );
	const cells = shown.map( ( location ) => ( {
		location,
		figures: stockAt(
			entry,
			read,
			level?.locations.find(
				( { location_uuid: uuid } ) => uuid === location.location_uuid
			),
			location.location_uuid
		),
		conflict: read?.stock?.some(
			( { location_uuid: uuid, outcome } ) =>
				uuid === location.location_uuid && outcome === 'conflict'
		),
	} ) );
	const sum = ( figure ) =>
		cells
			.filter( ( { location } ) => location.active )
			.reduce( ( total, cell ) => total + cell.figures[ figure ], 0 );
	const onHandField = declaration( 'variants.stock.on_hand' );

	return (
		<div className="seocart-table-wrap">
			<table className="seocart-table seocart-locations">
				<thead>
					<tr>
						<th scope="col">{ __( 'Location', 'seocart' ) }</th>
						<th scope="col" className="seocart-num">
							{ __( 'Available', 'seocart' ) }
						</th>
						<th scope="col" className="seocart-num">
							{ __( 'Committed', 'seocart' ) }
						</th>
						<th scope="col" className="seocart-num">
							{ onHandField.label }
						</th>
					</tr>
				</thead>
				<tbody>
					{ cells.map( ( { location, figures, conflict } ) => (
						<tr key={ location.location_uuid }>
							<td>
								<span className="seocart-stack">
									<strong>{ location.name }</strong>
									<span className="seocart-help">
										{ ! location.active &&
											__(
												'Inactive: not counted in the totals',
												'seocart'
											) }
										{ location.is_default &&
											__( 'Default', 'seocart' ) }
									</span>
								</span>
							</td>
							<td className="seocart-num">
								{ figures.available }
							</td>
							<td className="seocart-num seocart-muted">
								{ figures.committed }
							</td>
							<td className="seocart-num">
								{ location.active ? (
									<WholeNumberField
										key={ `${ row.key }|${ location.location_uuid }` }
										hideLabelFromVision
										label={ [
											sprintf(
												/* translators: 1: The label "On hand", 2: A location's name. */
												__( '%1$s at %2$s', 'seocart' ),
												onHandField.label,
												location.name
											),
											label,
										]
											.filter( Boolean )
											.join( ', ' ) }
										maximum={ onHandField.maximum }
										value={ figures.onHand }
										help={
											conflict
												? __(
														'Your count was not applied: when you saved, the stock here was not the count you started from. This is the count now.',
														'seocart'
													)
												: undefined
										}
										onCommit={ ( count ) =>
											onChange( ( current, stored ) =>
												withOnHand(
													current,
													stored,
													location.location_uuid,
													count
												)
											)
										}
									/>
								) : (
									figures.onHand
								) }
							</td>
						</tr>
					) ) }
				</tbody>
				<tfoot>
					<tr>
						<td>{ __( 'This variant', 'seocart' ) }</td>
						<td className="seocart-num">{ sum( 'available' ) }</td>
						<td className="seocart-num">{ sum( 'committed' ) }</td>
						<td className="seocart-num">{ sum( 'onHand' ) }</td>
					</tr>
				</tfoot>
			</table>
		</div>
	);
}

/**
 * The SKU and the state of a product without options, which has no grid to hold them.
 *
 * @param {Object}   props          The props.
 * @param {Object}   props.row      The row of its one variant.
 * @param {boolean}  props.edited   Whether a save would send the variant.
 * @param {Function} props.onChange Receives the change to make to the row's entry.
 * @return {Element} The fields.
 */
function OneVariantFields( { row, edited, onChange } ) {
	const sku = declaration( 'variants.sku' );

	return (
		<div className="seocart-one-variant">
			<DraftField
				label={ sku.label }
				maxLength={ sku.maxChars ?? undefined }
				value={ row.entry.sku }
				format={ ( text ) => text ?? '' }
				parse={ ( text ) => text }
				help={
					edited && needsSku( row )
						? __(
								'This product needs a SKU before it can be saved.',
								'seocart'
							)
						: undefined
				}
				onCommit={ ( text ) =>
					onChange( ( entry ) => withSku( entry, text ) )
				}
			/>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ declaration( 'variants.enabled' ).label }
				checked={ enabledShown( row ) === true }
				indeterminate={ enabledShown( row ) === null }
				onChange={ ( enabled ) =>
					onChange( ( entry, read ) =>
						withEnabled( entry, read, enabled )
					)
				}
			/>
		</div>
	);
}

/**
 * The card.
 *
 * @param {Object}   props           The props.
 * @param {Object}   props.state     What the area shows.
 * @param {Object}   props.row       The chosen row.
 * @param {Object}   props.stock     The locations and the stock as read.
 * @param {Function} props.onSelect  Receives the key of the row chosen.
 * @param {Function} props.updateRow Changes a row.
 * @param {Object}   ref             A ref to the variant select.
 * @return {Element} The card.
 */
function Inventory( { state, row, stock, onSelect, updateRow }, ref ) {
	const title = useInstanceId( Inventory, 'seocart-inventory-title' );
	const change = ( key ) => ( edit ) => updateRow( key, edit );
	const { status, locations, levels } = stock;
	const level = levels[ row.read?.variant_id ];
	const conflicts = state.rows.some( ( { read } ) =>
		read?.stock?.some( ( { outcome } ) => outcome === 'conflict' )
	);
	const total = state.rows.reduce(
		( units, each ) =>
			units +
			availableAcross( each, levels[ each.read?.variant_id ], locations ),
		0
	);

	return (
		<Card as="section" aria-labelledby={ title } className="seocart-card">
			<CardHeader>
				<h3 id={ title } className="seocart-card-title">
					{ __( 'Inventory', 'seocart' ) }
				</h3>
			</CardHeader>
			<CardBody className="seocart-card-body">
				{ state.axes.length === 0 && (
					<OneVariantFields
						row={ row }
						edited={ state.edited }
						onChange={ change( row.key ) }
					/>
				) }
				{ status === 'denied' && (
					<p className="seocart-help">
						{ __(
							'Your account may not view or change stock.',
							'seocart'
						) }
					</p>
				) }
				{ status === 'loading' && locations.length === 0 && (
					<p className="seocart-loading">
						<Spinner />
						{ __( 'Loading stock…', 'seocart' ) }
					</p>
				) }
				{ status === 'failed' && (
					<Notice status="error" isDismissible={ false }>
						{ __( 'The stock could not be loaded.', 'seocart' ) }{ ' ' }
						<Button variant="link" onClick={ retryStock }>
							{ __( 'Try again', 'seocart' ) }
						</Button>
					</Notice>
				) }
				{ locations.length > 0 && (
					<>
						{ conflicts && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'Some counts were not applied: when you saved, the stock there was not the count you started from. The counts below are the ones now.',
									'seocart'
								) }
							</Notice>
						) }
						<div className="seocart-bar">
							{ state.rows.length > 1 && (
								<VariantSelect
									ref={ ref }
									label={ __(
										'Stock by location for',
										'seocart'
									) }
									rows={ state.rows }
									value={ row.key }
									onChange={ onSelect }
								/>
							) }
							<p className="seocart-total">
								<span className="seocart-help">
									{ __(
										'Total available, all variants',
										'seocart'
									) }
								</span>
								<b>{ total }</b>
							</p>
						</div>
						<LocationTable
							row={ row }
							level={ level }
							locations={ locations }
							onChange={ change( row.key ) }
						/>
						<PolicyLine level={ level } />
						<p className="seocart-help">
							{ __(
								'Committed units are held for unfulfilled orders and pickups. Change On hand after a stock count or a delivery; Available updates to match.',
								'seocart'
							) }
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}

export const InventoryCard = forwardRef( Inventory );
