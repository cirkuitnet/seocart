/**
 * A product's state as the page prints it, for the product module's tests: two axes, one
 * combination the product does not sell, one variant sold out and one without a price in the
 * page's currency.
 *
 * | size \ roast | light              | dark                  |
 * |--------------|--------------------|-----------------------|
 * | 250 g        | 11: 12,50 €, 14 €  | 12: 12,50 €, sold out |
 * | 1 kg         | 13: no price       | none                  |
 *
 * @return {Object} A fresh copy of the state.
 */
export function coffee() {
	const price = ( minor, text ) => ( { minor, text } );

	return {
		uuid: '0193b7a2-7c1e-7d2a-9f00-5e1a2b3c4d5e',
		name: 'House blend',
		axes: [
			{
				key: 'size',
				label: 'Size',
				values: [
					{ key: '250g', label: '250 g', swatch: null, image: null },
					{ key: '1kg', label: '1 kg', swatch: null, image: null },
				],
			},
			{
				key: 'roast',
				label: 'Roast',
				values: [
					{ key: 'light', label: 'Light', swatch: null, image: null },
					{
						key: 'dark',
						label: 'Dark',
						swatch: '#3b2412',
						image: null,
					},
				],
			},
		],
		variants: [
			{
				id: 11,
				values: { roast: 'light', size: '250g' },
				label: '250 g / Light',
				price: price( 1250, '12,50 €' ),
				compareAt: price( 1400, '14,00 €' ),
				inStock: true,
			},
			{
				id: 12,
				values: { roast: 'dark', size: '250g' },
				label: '250 g / Dark',
				price: price( 1250, '12,50 €' ),
				compareAt: null,
				inStock: false,
			},
			{
				id: 13,
				values: { roast: 'light', size: '1kg' },
				label: '1 kg / Light',
				price: null,
				compareAt: null,
				inStock: true,
			},
		],
		selection: { size: '250g', roast: 'light' },
		quantity: 1,
	};
}
