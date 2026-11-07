/**
 * The Options and variants card: the axis editor above the grid.
 *
 * An axis joins the product when it has a name and a first value; until then it is the merchant's
 * draft here and nothing a save would send. Its key, and a value's, is derived from the label when
 * it is created and never changes, since a variant is identified by its keys. Removing an axis or a
 * value takes rows off sale, and the card says so before the save.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Notice,
	TextControl,
	VisuallyHidden,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { DraftField } from './fields';
import {
	availableAcross,
	enabledShown,
	keyFromLabel,
	needsPrice,
	needsSku,
	parseLabel,
	parseSwatch,
	skusFromPrefix,
	withSku,
} from './model';
import { currencyOf, declaration, settings } from './settings';
import { VariantGrid } from './variant-grid';

const AXES = declaration( 'options' );
const VALUE_LABEL = declaration( 'options.values.label' );

/**
 * Reads the images of the values that have one, in one request.
 *
 * @param {Array<Object>} axes The axes.
 * @return {Object<number, Object>} The attachments that exist, by id.
 */
function useValueImages( axes ) {
	const ids = axes
		.flatMap( ( { values } ) => values.map( ( value ) => value.image_id ) )
		.filter( Boolean );
	const include = [ ...new Set( ids ) ].sort().join( ',' );

	return useSelect(
		( select ) => {
			if ( include === '' ) {
				return {};
			}

			const images = {};

			for ( const image of select( coreStore ).getEntityRecords(
				'postType',
				'attachment',
				{
					include,
					per_page: declaration( 'options.values' ).maxItems,
					context: 'view',
				}
			) ?? [] ) {
				images[ image.id ] = image;
			}

			return images;
		},
		[ include ]
	);
}

/**
 * The cell of a value's image: core's media modal.
 *
 * @param {Object}   props          The props.
 * @param {Object}   props.value    The value.
 * @param {Object}   props.image    The attachment, once read.
 * @param {Function} props.onChange Receives the attachment's id, or null.
 * @return {Element} The cell's controls.
 */
function ValueImage( { value, image, onChange } ) {
	return (
		<span className="seocart-value-image">
			{ image && (
				<img
					src={
						image.media_details?.sizes?.thumbnail?.source_url ??
						image.source_url
					}
					alt=""
					width="32"
					height="32"
				/>
			) }
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ value.image_id ?? undefined }
					onSelect={ ( media ) => onChange( media.id ) }
					render={ ( { open } ) => (
						<Button
							size="small"
							variant="secondary"
							onClick={ open }
						>
							{ value.image_id
								? sprintf(
										/* translators: %s: The label of an option's value. */
										__(
											'Replace the image of %s',
											'seocart'
										),
										value.label
									)
								: sprintf(
										/* translators: %s: The label of an option's value. */
										__(
											'Choose an image for %s',
											'seocart'
										),
										value.label
									) }
						</Button>
					) }
				/>
			</MediaUploadCheck>
			{ value.image_id && (
				<Button
					size="small"
					variant="tertiary"
					onClick={ () => onChange( null ) }
				>
					{ sprintf(
						/* translators: %s: The label of an option's value. */
						__( 'Remove the image of %s', 'seocart' ),
						value.label
					) }
				</Button>
			) }
		</span>
	);
}

/**
 * The row of one value of an axis in the editor.
 *
 * @param {Object}   props           The props.
 * @param {Object}   props.value     The value.
 * @param {Object}   props.image     The attachment, once read.
 * @param {boolean}  props.removable Whether the value may be removed: an axis keeps at least one.
 * @param {Function} props.onChange  Receives the fields that change.
 * @param {Function} props.onRemove  Removes the value.
 * @return {Element} The row.
 */
function ValueRow( { value, image, removable, onChange, onRemove } ) {
	const swatch = declaration( 'options.values.swatch' );

	return (
		<tr>
			<td>
				<DraftField
					hideLabelFromVision
					label={ sprintf(
						/* translators: 1: The label "Value", 2: The key of an option's value. */
						__( '%1$s %2$s', 'seocart' ),
						VALUE_LABEL.label,
						value.key
					) }
					maxLength={ VALUE_LABEL.maxChars ?? undefined }
					value={ value.label }
					format={ ( text ) => text }
					parse={ parseLabel }
					invalidHelp={ __( 'A value needs a name.', 'seocart' ) }
					onCommit={ ( label ) => onChange( { label } ) }
				/>
			</td>
			<td>
				<ValueImage
					value={ value }
					image={ image }
					onChange={ ( id ) => onChange( { image_id: id } ) }
				/>
			</td>
			<td>
				<span className="seocart-swatch">
					<span
						className="seocart-swatch-chip"
						aria-hidden="true"
						style={
							value.swatch
								? { backgroundColor: value.swatch }
								: undefined
						}
					/>
					<DraftField
						hideLabelFromVision
						label={ sprintf(
							/* translators: 1: The label "Swatch", 2: The label of an option's value. */
							__( '%1$s of %2$s', 'seocart' ),
							swatch.label,
							value.label
						) }
						maxLength={ swatch.maxChars ?? undefined }
						placeholder="#rrggbb"
						value={ value.swatch }
						format={ ( text ) => text ?? '' }
						parse={ parseSwatch }
						invalidHelp={ __(
							'Enter a colour such as #7b4a2d.',
							'seocart'
						) }
						onCommit={ ( color ) => onChange( { swatch: color } ) }
					/>
				</span>
			</td>
			<td>
				<Button
					size="small"
					variant="tertiary"
					isDestructive
					disabled={ ! removable }
					onClick={ onRemove }
				>
					{ sprintf(
						/* translators: %s: The label of an option's value. */
						__( 'Remove %s', 'seocart' ),
						value.label
					) }
				</Button>
			</td>
		</tr>
	);
}

/**
 * The field that adds a value to an axis, on its button or on the Enter key.
 *
 * @param {Object}   props          The props.
 * @param {string}   props.label    The field's label.
 * @param {boolean}  props.disabled Whether the product has all the values it may have.
 * @param {Object}   props.inputRef A ref to the field.
 * @param {Function} props.onAdd    Receives the label; returns false when it cannot be added.
 * @return {Element} The field.
 */
function AddValue( { label, disabled, inputRef, onAdd } ) {
	const [ text, setText ] = useState( '' );
	const add = () => {
		const read = parseLabel( text );

		if ( read !== undefined && onAdd( read ) !== false ) {
			setText( '' );
		}
	};

	return (
		<div className="seocart-add-value">
			<TextControl
				ref={ inputRef }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				disabled={ disabled }
				maxLength={ VALUE_LABEL.maxChars ?? undefined }
				value={ text }
				onChange={ setText }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						add();
					}
				} }
			/>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				disabled={ disabled }
				onClick={ add }
			>
				{ __( 'Add value', 'seocart' ) }
			</Button>
		</div>
	);
}

/**
 * One axis of the product: closed, its name and values; open, the editor.
 *
 * @param {Object}   props             The props.
 * @param {Object}   props.axis        The axis.
 * @param {Object}   props.images      The values' attachments, by id.
 * @param {boolean}  props.mayAddValue Whether the product may have another value.
 * @param {boolean}  props.startOpen   Whether the editor is open to begin with.
 * @param {Function} props.onChange    Receives the axis as it should be.
 * @param {Function} props.onRemove    Removes the axis.
 * @return {Element} The axis.
 */
function AxisRow( {
	axis,
	images,
	mayAddValue,
	startOpen,
	onChange,
	onRemove,
} ) {
	const [ open, setOpen ] = useState( startOpen );
	const addValue = useRef();

	// An axis just created replaces its draft, which held the focus.
	useEffect( () => {
		if ( startOpen ) {
			addValue.current?.focus();
		}
		// Once, when the axis appears.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const name = declaration( 'options.label' );
	const replaceValue = ( key, fields ) =>
		onChange( {
			...axis,
			values: axis.values.map( ( value ) =>
				value.key === key ? { ...value, ...fields } : value
			),
		} );

	return (
		<div className="seocart-axis">
			<div className="seocart-axis-head">
				<div className="seocart-axis-summary">
					<strong>{ axis.label }</strong>
					<ul className="seocart-axis-values">
						{ axis.values.map( ( value ) => (
							<li key={ value.key } className="seocart-chip">
								{ value.swatch && (
									<span
										className="seocart-swatch-chip"
										aria-hidden="true"
										style={ {
											backgroundColor: value.swatch,
										} }
									/>
								) }
								{ value.label }
							</li>
						) ) }
					</ul>
				</div>
				<Button
					size="small"
					variant="tertiary"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					{ open
						? sprintf(
								/* translators: %s: The name of an option, such as Size. */
								__( 'Close %s', 'seocart' ),
								axis.label
							)
						: sprintf(
								/* translators: %s: The name of an option, such as Size. */
								__( 'Edit %s', 'seocart' ),
								axis.label
							) }
				</Button>
			</div>
			{ open && (
				<div className="seocart-axis-editor">
					<DraftField
						label={ name.label }
						maxLength={ name.maxChars ?? undefined }
						value={ axis.label }
						format={ ( text ) => text }
						parse={ parseLabel }
						invalidHelp={ __(
							'An option needs a name.',
							'seocart'
						) }
						help={ sprintf(
							/* translators: %s: The fixed key of an option. */
							__(
								'Key: %s. It stays the same if you rename the option.',
								'seocart'
							),
							axis.key
						) }
						onCommit={ ( label ) => onChange( { ...axis, label } ) }
					/>
					<div className="seocart-table-wrap">
						<table className="seocart-table seocart-values">
							<thead>
								<tr>
									<th scope="col">{ VALUE_LABEL.label }</th>
									<th scope="col">
										{
											declaration(
												'options.values.image_id'
											).label
										}
									</th>
									<th scope="col">
										{
											declaration(
												'options.values.swatch'
											).label
										}
									</th>
									<th scope="col">
										<VisuallyHidden>
											{ __( 'Remove', 'seocart' ) }
										</VisuallyHidden>
									</th>
								</tr>
							</thead>
							<tbody>
								{ axis.values.map( ( value ) => (
									<ValueRow
										key={ value.key }
										value={ value }
										image={ images[ value.image_id ] }
										removable={ axis.values.length > 1 }
										onChange={ ( fields ) =>
											replaceValue( value.key, fields )
										}
										onRemove={ () => {
											onChange( {
												...axis,
												values: axis.values.filter(
													( { key } ) =>
														key !== value.key
												),
											} );
											addValue.current?.focus();
										} }
									/>
								) ) }
							</tbody>
						</table>
					</div>
					<AddValue
						label={ sprintf(
							/* translators: %s: The name of an option, such as Size. */
							__( 'New value of %s', 'seocart' ),
							axis.label
						) }
						disabled={ ! mayAddValue }
						inputRef={ addValue }
						onAdd={ ( label ) =>
							onChange( {
								...axis,
								values: [
									...axis.values,
									{
										key: keyFromLabel(
											label,
											axis.values.map(
												( { key } ) => key
											),
											`value-${ axis.values.length + 1 }`
										),
										label,
										image_id: null,
										swatch: null,
									},
								],
							} )
						}
					/>
					<Button
						className="seocart-axis-remove"
						size="small"
						variant="tertiary"
						isDestructive
						onClick={ onRemove }
					>
						{ sprintf(
							/* translators: %s: The name of an option, such as Size. */
							__( 'Remove the option %s', 'seocart' ),
							axis.label
						) }
					</Button>
				</div>
			) }
		</div>
	);
}

/**
 * An axis being created: a name and a first value, which together make it an axis of the product.
 *
 * @param {Object}   props          The props.
 * @param {Function} props.onCreate Receives the name and the first value's label.
 * @param {Function} props.onCancel Drops the draft.
 * @return {Element} The draft.
 */
function NewAxis( { onCreate, onCancel } ) {
	const [ name, setName ] = useState( '' );
	const [ missingName, setMissingName ] = useState( false );
	const nameField = useRef();
	const field = declaration( 'options.label' );

	// The draft replaces the button that opened it, which held the focus.
	useEffect( () => {
		nameField.current?.focus();
	}, [] );

	return (
		<div className="seocart-axis seocart-axis-editor">
			<TextControl
				ref={ nameField }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ field.label }
				maxLength={ field.maxChars ?? undefined }
				value={ name }
				help={
					missingName
						? __( 'Name the option first.', 'seocart' )
						: __(
								'For example Size or Colour. The option joins the product when it has a first value.',
								'seocart'
							)
				}
				onChange={ ( text ) => {
					setName( text );
					setMissingName( false );
				} }
			/>
			<AddValue
				label={ __( 'First value', 'seocart' ) }
				disabled={ false }
				onAdd={ ( label ) => {
					const read = parseLabel( name );

					if ( read === undefined ) {
						setMissingName( true );

						return false;
					}

					onCreate( read, label );

					return true;
				} }
			/>
			<Button size="small" variant="tertiary" onClick={ onCancel }>
				{ __( 'Cancel', 'seocart' ) }
			</Button>
		</div>
	);
}

/**
 * Writes the labels of some variants as a list.
 *
 * @param {Array<Object>} variants The variants as read.
 * @return {string} Their labels; a variant without one is not named.
 */
function labelsOf( variants ) {
	return variants
		.map( ( { label } ) => label )
		.filter( Boolean )
		.join( ', ' );
}

/**
 * What the card says before a save takes rows off sale, among them any added since the product was read.
 *
 * @param {Object} props         The props.
 * @param {Object} props.offSale What a save takes off sale, as `offSaleOnSave()` says it.
 * @return {Element|null} The warning, when a save would.
 */
function OffSaleWarning( { offSale } ) {
	const { variants, axesChanged, unseen } = offSale;

	if ( variants.length === 0 ) {
		return null;
	}

	const count = variants.length;
	const names = labelsOf( variants );
	const added = labelsOf( unseen );

	return (
		<Notice status="warning" isDismissible={ false }>
			{ axesChanged
				? sprintf(
						/* translators: %d: A number of variants. */
						_n(
							'Changing which options this product has starts its variants again. When you save, the %d variant it has now goes off sale and keeps its stock on its own row, out of this list; the new variants start with no stock.',
							'Changing which options this product has starts its variants again. When you save, the %d variants it has now go off sale and keep their stock on their own rows, out of this list; the new variants start with no stock.',
							count,
							'seocart'
						),
						count
					)
				: sprintf(
						/* translators: 1: A number of variants, 2: Their labels. */
						_n(
							'When you save, %1$d variant goes off sale because its value is gone: %2$s. It keeps its stock, and comes back off sale if you add the value again, until you enable it.',
							'When you save, %1$d variants go off sale because their values are gone: %2$s. They keep their stock, and come back off sale if you add the values again, until you enable them.',
							count,
							'seocart'
						),
						count,
						names
					) }
			{ added !== '' &&
				' ' +
					sprintf(
						/* translators: %s: The labels of some variants. */
						__(
							'Added since you opened this product, and not shown here: %s. Reload the page to see the product as it is now.',
							'seocart'
						),
						added
					) }
		</Notice>
	);
}

/**
 * The read rows the product's options no longer offer, listed apart and never sent back.
 *
 * @param {Object}        props      The props.
 * @param {Array<Object>} props.rows The variants as read.
 * @return {Element|null} The list.
 */
function RetiredRows( { rows } ) {
	if ( rows.length === 0 ) {
		return null;
	}

	return (
		<div className="seocart-retired">
			<h4>{ __( 'No longer offered', 'seocart' ) }</h4>
			<p className="seocart-help">
				{ __(
					'These variants lost an option value. They cannot be sold, and they keep their stock.',
					'seocart'
				) }
			</p>
			<ul>
				{ rows.map( ( { variant_id: id, label, sku, stock = [] } ) => (
					<li key={ id }>
						{ [
							label || __( 'Variant', 'seocart' ),
							sku,
							sprintf(
								/* translators: %d: A number of units. */
								__( '%d on hand', 'seocart' ),
								stock.reduce(
									( units, cell ) => units + cell.on_hand,
									0
								)
							),
						]
							.filter( Boolean )
							.join( ' · ' ) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The buttons that choose the currency of the grid's prices.
 *
 * @param {Object}   props          The props.
 * @param {string}   props.value    The chosen currency's code.
 * @param {Function} props.onChange Receives the code of the currency chosen.
 * @return {Element|null} The buttons; none when the store offers one currency.
 */
function CurrencySwitch( { value, onChange } ) {
	if ( settings.currencies.length < 2 ) {
		return null;
	}

	return (
		<div
			className="seocart-segmented"
			role="group"
			aria-label={ __( 'Price currency', 'seocart' ) }
		>
			{ settings.currencies.map( ( { code } ) => (
				<Button
					key={ code }
					size="small"
					variant="secondary"
					isPressed={ code === value }
					onClick={ () => onChange( code ) }
				>
					{ code }
				</Button>
			) ) }
		</div>
	);
}

/**
 * The tools above the grid: filling SKUs from a prefix, and the currency of its prices.
 *
 * The prefix is a helper: it is not stored, and it fills only the rows without a SKU.
 *
 * @param {Object}   props            The props.
 * @param {Array}    props.rows       The rows of the area.
 * @param {string}   props.currency   The chosen currency's code.
 * @param {Function} props.onCurrency Receives the code of the currency chosen.
 * @param {Function} props.updateRows Replaces every row's entry.
 * @return {Element} The tools.
 */
function GridTools( { rows, currency, onCurrency, updateRows } ) {
	const [ prefix, setPrefix ] = useState( '' );
	const maxLength = declaration( 'variants.sku' ).maxChars;

	return (
		<div className="seocart-bar seocart-grid-tools">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'SKU prefix', 'seocart' ) }
				maxLength={ maxLength ?? undefined }
				value={ prefix }
				onChange={ setPrefix }
			/>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				onClick={ () => {
					const filled = skusFromPrefix( rows, prefix, maxLength );

					updateRows( ( { key, entry } ) =>
						filled.has( key )
							? withSku( entry, filled.get( key ) )
							: entry
					);
				} }
			>
				{ __( 'Fill SKUs from prefix', 'seocart' ) }
			</Button>
			<CurrencySwitch value={ currency } onChange={ onCurrency } />
		</div>
	);
}

/**
 * The card.
 *
 * @param {Object}   props            The props.
 * @param {Object}   props.state      What the area shows.
 * @param {Object}   props.offSale    What a save takes off sale.
 * @param {Object}   props.stock      The locations and the stock as read.
 * @param {Function} props.updateRow  Changes a row.
 * @param {Function} props.updateRows Replaces every row's entry.
 * @param {Function} props.updateAxes Gives the product its axes.
 * @param {Function} props.onShow     Shows a variant's stock by location.
 * @return {Element} The card.
 */
export function OptionsCard( {
	state,
	offSale,
	stock,
	updateRow,
	updateRows,
	updateAxes,
	onShow,
} ) {
	const title = useInstanceId( OptionsCard, 'seocart-options-title' );
	const [ adding, setAdding ] = useState( false );
	const [ created, setCreated ] = useState( [] );
	const [ currencyCode, setCurrencyCode ] = useState(
		settings.currency.code
	);
	const addOption = useRef();
	const images = useValueImages( state.axes );
	const { axes, rows, count } = state;
	const limit = declaration( 'variants' ).maxItems;
	const values = axes.reduce(
		( total, axis ) => total + axis.values.length,
		0
	);
	const mayAddValue =
		values < ( declaration( 'options.values' ).maxItems ?? Infinity );
	const missingSkus = rows.filter( needsSku ).length;
	const missingPrices = rows.filter( ( row ) =>
		needsPrice( row, settings.currency.code )
	).length;
	const total = rows.reduce(
		( units, row ) =>
			units +
			availableAcross(
				row,
				stock.levels[ row.read?.variant_id ],
				stock.locations
			),
		0
	);
	const activeLocations = stock.locations.filter(
		( { active } ) => active
	).length;

	return (
		<Card as="section" aria-labelledby={ title } className="seocart-card">
			<CardHeader>
				<h3 id={ title } className="seocart-card-title">
					{ __( 'Options and variants', 'seocart' ) }
				</h3>
				{ axes.length > 0 && (
					<span className="seocart-help">
						{ sprintf(
							/* translators: 1: A number of variants, 2: The most variants a product may have. */
							__( '%1$d of %2$d variants', 'seocart' ),
							count,
							limit
						) }
					</span>
				) }
			</CardHeader>
			<CardBody className="seocart-card-body">
				<OffSaleWarning offSale={ offSale } />
				{ rows.some( ( row ) => enabledShown( row ) === null ) && (
					<p className="seocart-help">
						{ __(
							'A dash under Enabled marks a variant this product may have had before: saving brings it back as it was, on sale or not, with its stock and weight, and enables a variant that is new. Tick or untick it to choose.',
							'seocart'
						) }
					</p>
				) }
				{ state.tooMany && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: A number of variants, 2: The most variants a product may have. */
							__(
								'These options make %1$d variants, and a product can have at most %2$d. Remove some values to save.',
								'seocart'
							),
							count,
							limit
						) }
					</Notice>
				) }
				{ axes.length === 0 && ! adding && (
					<p className="seocart-help">
						{ __(
							'This product has no options. Add one, such as Size, to sell it in several variants.',
							'seocart'
						) }
					</p>
				) }
				{ ( axes.length > 0 || adding ) && (
					<div className="seocart-axes">
						{ axes.map( ( axis ) => (
							<AxisRow
								key={ axis.key }
								axis={ axis }
								images={ images }
								mayAddValue={ mayAddValue }
								startOpen={ created.includes( axis.key ) }
								onChange={ ( next ) =>
									updateAxes(
										axes.map( ( each ) =>
											each.key === axis.key ? next : each
										)
									)
								}
								onRemove={ () => {
									updateAxes(
										axes.filter(
											( { key } ) => key !== axis.key
										)
									);
									addOption.current?.focus();
								} }
							/>
						) ) }
						{ adding && (
							<NewAxis
								onCancel={ () => {
									setAdding( false );
									addOption.current?.focus();
								} }
								onCreate={ ( name, firstValue ) => {
									const key = keyFromLabel(
										name,
										axes.map( ( each ) => each.key ),
										`option-${ axes.length + 1 }`
									);

									setCreated( [ ...created, key ] );
									setAdding( false );
									updateAxes( [
										...axes,
										{
											key,
											label: name,
											values: [
												{
													key: keyFromLabel(
														firstValue,
														[],
														'value-1'
													),
													label: firstValue,
													image_id: null,
													swatch: null,
												},
											],
										},
									] );
								} }
							/>
						) }
					</div>
				) }
				{ ! adding && (
					<Button
						ref={ addOption }
						className="seocart-add-option"
						size="small"
						variant="secondary"
						disabled={
							axes.length >= ( AXES.maxItems ?? Infinity )
						}
						onClick={ () => setAdding( true ) }
					>
						{ axes.length === 0
							? __( 'Add an option', 'seocart' )
							: __( 'Add another option', 'seocart' ) }
					</Button>
				) }
				{ axes.length > 0 && rows.length > 0 && (
					<>
						<GridTools
							rows={ rows }
							currency={ currencyCode }
							onCurrency={ setCurrencyCode }
							updateRows={ updateRows }
						/>
						{ state.edited && missingSkus > 0 && (
							<p className="seocart-help seocart-needs">
								{ sprintf(
									/* translators: %d: A number of variants. */
									_n(
										'%d variant needs a SKU before this product can be saved.',
										'%d variants need a SKU before this product can be saved.',
										missingSkus,
										'seocart'
									),
									missingSkus
								) }
							</p>
						) }
						{ state.edited && missingPrices > 0 && (
							<p className="seocart-help seocart-needs">
								{ sprintf(
									/* translators: 1: A number of variants, 2: The base currency's code, such as USD. */
									_n(
										'%1$d variant needs a price in %2$s, or to be disabled, before this product can be saved.',
										'%1$d variants need a price in %2$s, or to be disabled, before this product can be saved.',
										missingPrices,
										'seocart'
									),
									missingPrices,
									settings.currency.code
								) }
							</p>
						) }
						<VariantGrid
							rows={ rows }
							currency={ currencyOf( currencyCode ) }
							stock={ stock }
							updateRow={ updateRow }
							onShow={ onShow }
						/>
					</>
				) }
				<RetiredRows rows={ state.retired } />
			</CardBody>
			{ axes.length > 0 && activeLocations > 0 && (
				<CardFooter>
					<span className="seocart-help">
						{ sprintf(
							/* translators: 1: A number of units, 2: A number of locations. */
							_n(
								'Total available: %1$d across %2$d location. Select a figure to edit stock by location.',
								'Total available: %1$d across %2$d locations. Select a figure to edit stock by location.',
								activeLocations,
								'seocart'
							),
							total,
							activeLocations
						) }
					</span>
				</CardFooter>
			) }
		</Card>
	);
}
