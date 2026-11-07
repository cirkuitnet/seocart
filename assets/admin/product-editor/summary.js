/**
 * The Product summary panel of the document sidebar: what the record says of the product, read only.
 *
 * Every figure is the record's as it was read or the stock list's, so it changes on a save and
 * not while the merchant types; the amount is the lowest authored base price, written by the one
 * formatter.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { formatMoney } from './amount';
import { LIMIT, selectRecord } from './area-model';
import { areaState, availableAcross, fromPrice } from './model';
import { LOCALE, settings } from './settings';
import { useStock } from './stock';

/**
 * One figure of the panel.
 *
 * @param {Object} props       The props.
 * @param {string} props.label What it is.
 * @param {*}      props.value What it says.
 * @return {Element} The figure.
 */
function Fact( { label, value } ) {
	return (
		<div className="seocart-fact">
			<dt>{ label }</dt>
			<dd>{ value }</dd>
		</div>
	);
}

/**
 * Writes the product's price as the summary says it.
 *
 * @param {Object|undefined} saved The `seocart` object as read.
 * @return {string} The price, such as `From $18.00`.
 */
function priceWords( saved ) {
	const { currency } = settings;
	const price = fromPrice( saved, currency.code );

	if ( price === null ) {
		return __( 'No price', 'seocart' );
	}

	const money = formatMoney( price.minor, currency, LOCALE );

	return price.several
		? sprintf(
				/* translators: %s: An amount, such as $18.00. */
				__( 'From %s', 'seocart' ),
				money
			)
		: money;
}

/**
 * The panel.
 *
 * @return {Element} The panel.
 */
export function ProductSummaryPanel() {
	const { saved } = useSelect( selectRecord, [] );
	const stock = useStock( saved?.variants );
	const variants = areaState( saved, undefined, LIMIT ).rows.filter(
		( { read } ) => read
	);
	const sentence =
		settings.sellability.reasons[ saved?.[ settings.sellability.name ] ];
	const units = variants.reduce(
		( total, row ) =>
			total +
			availableAcross(
				row,
				stock.levels[ row.read.variant_id ],
				stock.locations
			),
		0
	);

	return (
		<PluginDocumentSettingPanel
			name="seocart-product-summary"
			title={ __( 'Product summary', 'seocart' ) }
			className="seocart-product-summary"
		>
			<dl className="seocart-facts">
				<Fact
					label={ __( 'Price', 'seocart' ) }
					value={ priceWords( saved ) }
				/>
				{ stock.status !== 'denied' && (
					<Fact label={ __( 'Stock', 'seocart' ) } value={ units } />
				) }
				<Fact
					label={ __( 'Variants', 'seocart' ) }
					value={ variants.length }
				/>
			</dl>
			{ sentence && (
				<p className="seocart-product-summary-sale">
					{ sprintf(
						/* translators: 1: The label "Sale status", 2: A sentence saying whether the product is for sale. */
						__( '%1$s: %2$s', 'seocart' ),
						settings.sellability.label,
						sentence
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
}
