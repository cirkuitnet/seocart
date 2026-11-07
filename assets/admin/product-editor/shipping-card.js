/**
 * The Shipping card: the chosen variant's weight.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { WholeNumberField } from './fields';
import { weightShown, withWeight } from './model';
import { declaration } from './settings';
import { VariantSelect } from './variant-select';

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
export function ShippingCard( { state, row, onSelect, updateRow } ) {
	const title = useInstanceId( ShippingCard, 'seocart-shipping-title' );
	const weight = declaration( 'variants.weight_grams' );

	return (
		<Card as="section" aria-labelledby={ title } className="seocart-card">
			<CardHeader>
				<h3 id={ title } className="seocart-card-title">
					{ __( 'Shipping', 'seocart' ) }
				</h3>
			</CardHeader>
			<CardBody className="seocart-card-body seocart-bar">
				{ state.rows.length > 1 && (
					<VariantSelect
						label={ __( 'Weight of', 'seocart' ) }
						rows={ state.rows }
						value={ row.key }
						onChange={ onSelect }
					/>
				) }
				<WholeNumberField
					key={ row.key }
					className="seocart-weight"
					label={ weight.label }
					maximum={ weight.maximum }
					value={ weightShown( row ) }
					onCommit={ ( grams ) =>
						updateRow( row.key, ( entry, read ) =>
							withWeight( entry, read, grams )
						)
					}
				/>
			</CardBody>
		</Card>
	);
}
