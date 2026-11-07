/**
 * The select that chooses which variant the Pricing, Inventory and Shipping cards show.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { SelectControl } from '@wordpress/components';
import { forwardRef } from '@wordpress/element';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * The select.
 *
 * @param {Object}        props          The props.
 * @param {string}        props.label    Its label.
 * @param {Array<Object>} props.rows     The rows of the area.
 * @param {string}        props.value    The key of the chosen row.
 * @param {Function}      props.onChange Receives the key of the row chosen.
 * @param {Object}        ref            A ref to the select.
 * @return {Element} The select.
 */
function Select( { label, rows, value, onChange }, ref ) {
	return (
		<SelectControl
			ref={ ref }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			className="seocart-variant-select"
			label={ label }
			value={ value }
			options={ rows.map( ( row ) => ( {
				value: row.key,
				label: row.label,
			} ) ) }
			onChange={ onChange }
		/>
	);
}

export const VariantSelect = forwardRef( Select );
