/**
 * Internal dependencies
 */
import {
	areaState,
	availableAcross,
	batchesOf,
	combinationCount,
	combinationKey,
	enabledShown,
	entryOfRead,
	fromPrice,
	GROSS,
	isOffered,
	keyFromLabel,
	labelOf,
	locationsOf,
	matrixOf,
	namedAxes,
	NET,
	needsPrice,
	needsSku,
	offSaleOnSave,
	onHandIn,
	parseLabel,
	parseSwatch,
	priceIn,
	priceShown,
	seocartEdit,
	skusFromPrefix,
	stockAt,
	stockBadge,
	weightShown,
	withEnabled,
	withOnHand,
	withPrice,
	withPriceFields,
	withSku,
	withWeight,
} from '../model';

const LIMIT = 250;

const SIZE = {
	key: 'size',
	label: 'Size',
	position: 0,
	values: [
		{
			key: '250g',
			label: '250 g',
			position: 0,
			image_id: null,
			swatch: null,
		},
		{
			key: '1kg',
			label: '1 kg',
			position: 1,
			image_id: 7,
			swatch: '#7b4a2d',
		},
	],
};

const GRIND = {
	key: 'grind',
	label: 'Grind',
	position: 1,
	values: [
		{
			key: 'whole',
			label: 'Whole bean',
			position: 0,
			image_id: null,
			swatch: null,
		},
		{
			key: 'filter',
			label: 'Filter',
			position: 1,
			image_id: null,
			swatch: null,
		},
	],
};

/**
 * Builds a variant as the `edit` context reads it, with the read-only fields a save must never send.
 *
 * @param {Array<Object>} combination The pairs.
 * @param {Object}        fields      Fields to change.
 * @return {Object} The variant.
 */
function readVariant( combination, fields = {} ) {
	return {
		variant_id: 100,
		combination,
		sku: 'SKU',
		enabled: true,
		position: 0,
		label: null,
		weight_grams: 250,
		prices: [
			{
				currency: 'USD',
				price_minor: 1800,
				compare_at_minor: null,
				amount_basis: NET,
			},
		],
		stock: [
			{
				location_uuid: 'loc-1',
				on_hand: 5,
				expected_on_hand: null,
			},
		],
		sellability: 'sellable',
		...fields,
	};
}

/**
 * Builds a no-option product's `seocart` object as the `edit` context reads it: the flat fields and the lists.
 *
 * @return {Object} The object.
 */
function readOfOneVariant() {
	return {
		variant_id: 100,
		sku: 'SKU',
		price_minor: 1800,
		currency: 'USD',
		compare_at_minor: null,
		weight_grams: 250,
		options: [],
		variants: [ readVariant( [] ) ],
		locale: 'en_US',
		translation_of: null,
		sellability: 'sellable',
		generation_state: 'complete',
	};
}

/**
 * Builds a 2 x 2 product's `seocart` object as the `edit` context reads it.
 *
 * @return {Object} The object.
 */
function readOfFourVariants() {
	return {
		options: [ SIZE, GRIND ],
		variants: matrixOf( namedAxes( [ SIZE, GRIND ] ) ).map(
			( combination, index ) =>
				readVariant( combination, {
					variant_id: 100 + index,
					sku: `SKU-${ index }`,
				} )
		),
		sellability: 'sellable',
		generation_state: 'complete',
	};
}

/**
 * Builds a product whose one value of Size was removed, its variant kept, disabled, under no pairs, and the edit
 * that adds the value back.
 *
 * @return {Object} `saved` and `edits`.
 */
function valueBroughtBack() {
	return {
		saved: {
			options: [ { ...SIZE, values: [ SIZE.values[ 0 ] ] } ],
			variants: [
				readVariant( [ { axis: 'size', value: '250g' } ], {
					variant_id: 300,
				} ),
				readVariant( [], { variant_id: 301, enabled: false } ),
			],
		},
		edits: { options: namedAxes( [ SIZE ] ) },
	};
}

describe( 'combinations', () => {
	it( 'names a combination whatever the order of its pairs', () => {
		expect(
			combinationKey( [
				{ axis: 'size', value: '250g' },
				{ axis: 'grind', value: 'whole' },
			] )
		).toBe(
			combinationKey( [
				{ axis: 'grind', value: 'whole' },
				{ axis: 'size', value: '250g' },
			] )
		);
	} );

	it( 'builds every combination, the first axis the slowest to change', () => {
		expect(
			matrixOf( [ SIZE, GRIND ] ).map( ( pairs ) =>
				pairs.map( ( { value } ) => value ).join( '+' )
			)
		).toEqual( [ '250g+whole', '250g+filter', '1kg+whole', '1kg+filter' ] );
	} );

	it( 'has one combination of no pairs for a product without options', () => {
		expect( matrixOf( [] ) ).toEqual( [ [] ] );
		expect( combinationCount( [] ) ).toBe( 1 );
	} );

	it( 'counts combinations far too many to build', () => {
		const axis = {
			values: Array.from( { length: 100 }, ( _, key ) => ( { key } ) ),
		};

		expect( combinationCount( [ axis, axis, axis ] ) ).toBe( 1000000 );
	} );

	it( 'writes a combination with its values in the order of the axes', () => {
		expect(
			labelOf(
				[
					{ axis: 'grind', value: 'filter' },
					{ axis: 'size', value: '1kg' },
				],
				[ SIZE, GRIND ]
			)
		).toBe( '1 kg / Filter' );
		expect( labelOf( [], [] ) ).toBe( '' );
	} );

	it( 'offers a combination of one pair per axis, each naming a value of it', () => {
		const axes = [ SIZE, GRIND ];

		expect(
			isOffered(
				[
					{ axis: 'size', value: '1kg' },
					{ axis: 'grind', value: 'filter' },
				],
				axes
			)
		).toBe( true );
		expect( isOffered( [ { axis: 'size', value: '1kg' } ], axes ) ).toBe(
			false
		);
		expect(
			isOffered(
				[
					{ axis: 'size', value: 'gone' },
					{ axis: 'grind', value: 'filter' },
				],
				axes
			)
		).toBe( false );
		expect( isOffered( [], [] ) ).toBe( true );
	} );
} );

describe( 'what a save names', () => {
	it( 'names a variant nobody touched by its combination and its SKU, and nothing it read', () => {
		const entry = entryOfRead(
			readVariant( [ { axis: 'size', value: '250g' } ] )
		);

		expect( entry ).toEqual( {
			combination: [ { axis: 'size', value: '250g' } ],
			sku: 'SKU',
		} );
	} );

	it( 'never sends a placeholder: a variant whose SKU another took reads none and sends none', () => {
		const entry = entryOfRead(
			readVariant( [], { sku: null, enabled: false } )
		);

		expect( 'sku' in entry ).toBe( false );
	} );

	it( 'sends nothing for a product nobody changed, new or stored', () => {
		for ( const saved of [
			undefined,
			{ sellability: 'no_binding' },
			{ options: [], variants: [], sellability: 'incomplete' },
			readOfOneVariant(),
			readOfFourVariants(),
		] ) {
			const { axes, rows } = areaState( saved, undefined, LIMIT );

			expect(
				seocartEdit(
					saved,
					undefined,
					axes,
					rows.map( ( { entry } ) => entry ),
					LIMIT
				)
			).toEqual( {} );
		}
	} );

	it( 'sends no flat field for a product without options, whose read carries both shapes', () => {
		const saved = readOfOneVariant();
		const { axes, rows } = areaState( saved, undefined, LIMIT );
		const edit = seocartEdit(
			saved,
			undefined,
			axes,
			[ withSku( rows[ 0 ].entry, 'NEW' ) ],
			LIMIT
		);

		expect( Object.keys( edit ) ).toEqual( [ 'variants' ] );
		expect( edit.variants ).toHaveLength( 1 );
		expect( edit.variants[ 0 ].sku ).toBe( 'NEW' );
		expect( edit.variants[ 0 ].combination ).toEqual( [] );
	} );

	it( 'takes a change back to nothing to send', () => {
		const saved = readOfFourVariants();
		const { axes, rows } = areaState( saved, undefined, LIMIT );
		const changed = seocartEdit(
			saved,
			undefined,
			axes,
			rows.map( ( { entry } ) => withSku( entry, 'CHANGED' ) ),
			LIMIT
		);

		expect( changed.variants ).toHaveLength( 4 );

		const back = areaState( saved, changed, LIMIT );

		expect(
			seocartEdit(
				saved,
				changed,
				back.axes,
				back.rows.map( ( { entry }, index ) =>
					withSku( entry, `SKU-${ index }` )
				),
				LIMIT
			)
		).toEqual( {} );
	} );

	it( 'names every combination when one variant changes, the others by their combination and SKU alone', () => {
		const saved = readOfFourVariants();
		const { axes, rows } = areaState( saved, undefined, LIMIT );
		const edit = seocartEdit(
			saved,
			undefined,
			axes,
			rows.map( ( { entry, read }, index ) =>
				index === 2 ? withEnabled( entry, read, false ) : entry
			),
			LIMIT
		);

		expect( edit.options ).toBeUndefined();
		expect( edit.variants ).toEqual(
			rows.map( ( { combination }, index ) => ( {
				combination,
				...( index === 2 ? { enabled: false } : {} ),
				sku: `SKU-${ index }`,
			} ) )
		);
	} );

	describe( 'a variant the merchant touched names what they changed, and nothing else', () => {
		/**
		 * Changes the second of four stored variants, and says what the save names of each.
		 *
		 * @param {Function} change Receives the row's entry and its read, and returns the entry.
		 * @return {Array<Object>} The entries the save names.
		 */
		const sentAfter = ( change ) => {
			const saved = readOfFourVariants();

			saved.variants[ 1 ].prices.push( {
				currency: 'EUR',
				price_minor: 1700,
				compare_at_minor: 1900,
				amount_basis: GROSS,
			} );

			const { axes, rows } = areaState( saved, undefined, LIMIT );

			return seocartEdit(
				saved,
				undefined,
				axes,
				rows.map( ( { entry, read }, index ) =>
					index === 1 ? change( entry, read ) : entry
				),
				LIMIT
			).variants;
		};
		const untouched = ( variants ) =>
			variants
				.filter( ( _, index ) => index !== 1 )
				.map( ( entry ) => Object.keys( entry ) );

		it( 'its price: the whole list of its prices', () => {
			const sent = sentAfter( ( entry, read ) =>
				withPrice( entry, read, 'USD', 1900, 'USD' )
			);

			expect( sent[ 1 ] ).toEqual( {
				combination: sent[ 1 ].combination,
				sku: 'SKU-1',
				prices: [
					{
						currency: 'EUR',
						price_minor: 1700,
						compare_at_minor: 1900,
						amount_basis: GROSS,
					},
					{
						currency: 'USD',
						price_minor: 1900,
						compare_at_minor: null,
						amount_basis: NET,
					},
				],
			} );
			expect( untouched( sent ) ).toEqual(
				Array( 3 ).fill( [ 'combination', 'sku' ] )
			);
		} );

		it( 'its weight', () => {
			const sent = sentAfter( ( entry, read ) =>
				withWeight( entry, read, 300 )
			);

			expect( sent[ 1 ] ).toEqual( {
				combination: sent[ 1 ].combination,
				sku: 'SKU-1',
				weight_grams: 300,
			} );
			expect( untouched( sent ) ).toEqual(
				Array( 3 ).fill( [ 'combination', 'sku' ] )
			);
		} );

		it( 'whether it is enabled', () => {
			const sent = sentAfter( ( entry, read ) =>
				withEnabled( entry, read, false )
			);

			expect( sent[ 1 ] ).toEqual( {
				combination: sent[ 1 ].combination,
				enabled: false,
				sku: 'SKU-1',
			} );
			expect( untouched( sent ) ).toEqual(
				Array( 3 ).fill( [ 'combination', 'sku' ] )
			);
		} );

		it( 'its SKU', () => {
			const sent = sentAfter( ( entry ) => withSku( entry, 'NEW-1' ) );

			expect( sent[ 1 ] ).toEqual( {
				combination: sent[ 1 ].combination,
				sku: 'NEW-1',
			} );
			expect( untouched( sent ) ).toEqual(
				Array( 3 ).fill( [ 'combination', 'sku' ] )
			);
		} );

		it( 'nothing of a field changed and changed back', () => {
			expect(
				sentAfter( ( entry, read ) =>
					withEnabled(
						withWeight(
							withWeight(
								withEnabled( entry, read, false ),
								read,
								300
							),
							read,
							250
						),
						read,
						true
					)
				)
			).toBeUndefined();
		} );
	} );

	it( 'shows a field the entry does not name as read', () => {
		const read = readVariant( [], { enabled: false } );
		const row = { entry: entryOfRead( read ), read };

		expect( enabledShown( row ) ).toBe( false );
		expect( weightShown( row ) ).toBe( 250 );
		expect( priceShown( row, 'USD' ).price_minor ).toBe( 1800 );
		expect(
			weightShown( {
				...row,
				entry: withWeight( row.entry, read, null ),
			} )
		).toBeNull();
		expect(
			priceShown(
				{
					...row,
					entry: withPrice( row.entry, read, 'USD', null, 'USD' ),
				},
				'USD'
			)
		).toBeUndefined();
		expect( weightShown( { entry: { combination: [] } } ) ).toBeNull();
	} );

	it( 'always names the combinations with changed options, even when only a label changed', () => {
		const saved = readOfFourVariants();
		const read = areaState( saved, undefined, LIMIT );
		const axes = [
			{ ...read.axes[ 0 ], label: 'Bag size' },
			read.axes[ 1 ],
		];
		const edit = seocartEdit(
			saved,
			undefined,
			axes,
			read.rows.map( ( { entry } ) => entry ),
			LIMIT
		);

		expect( edit.options[ 0 ].label ).toBe( 'Bag size' );
		expect( edit.variants ).toHaveLength( 4 );
	} );

	it( 'keeps a new translation’s fields in the edit', () => {
		const edit = seocartEdit(
			undefined,
			{ translation_of: 12, locale: 'de_DE', variants: [], options: [] },
			[],
			[ { combination: [] } ],
			LIMIT
		);

		expect( edit ).toEqual( { translation_of: 12, locale: 'de_DE' } );
	} );

	it( 'never sends an empty list: options that make too many keep the save locked with no variant named', () => {
		const axes = namedAxes( [ SIZE ] );
		const edit = seocartEdit(
			readOfFourVariants(),
			undefined,
			axes,
			[],
			LIMIT
		);

		expect( edit.options ).toEqual( axes );
		expect( 'variants' in edit ).toBe( false );
	} );

	it( 'names a value’s image and swatch, and no position', () => {
		expect( namedAxes( [ SIZE ] )[ 0 ] ).toEqual( {
			key: 'size',
			label: 'Size',
			values: [
				{ key: '250g', label: '250 g', image_id: null, swatch: null },
				{ key: '1kg', label: '1 kg', image_id: 7, swatch: '#7b4a2d' },
			],
		} );
	} );
} );

describe( 'rows', () => {
	it( 'gives a new product the one row of no pairs, shown enabled and with nothing to send but its combination', () => {
		const { rows } = areaState( undefined, undefined, LIMIT );

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].read ).toBeUndefined();
		expect( rows[ 0 ].mayBeRestored ).toBe( false );
		expect( rows[ 0 ].entry ).toEqual( { combination: [] } );
		expect( enabledShown( rows[ 0 ] ) ).toBe( true );
	} );

	it( 'sends no state for a row that may be a variant brought back, so it keeps the one it had, until the merchant sets one', () => {
		const { saved, edits } = valueBroughtBack();
		const { rows } = areaState( saved, edits, LIMIT );
		const back = rows[ 1 ];

		expect( back.read ).toBeUndefined();
		expect( back.mayBeRestored ).toBe( true );
		expect( enabledShown( back ) ).toBeNull();
		expect( 'enabled' in withSku( back.entry, 'FILLED' ) ).toBe( false );
		expect(
			enabledShown( {
				...back,
				entry: withEnabled( back.entry, undefined, true ),
			} )
		).toBe( true );
		expect( withEnabled( back.entry, undefined, false ).enabled ).toBe(
			false
		);
		expect( enabledShown( rows[ 0 ] ) ).toBe( true );
	} );

	it( 'lists a read row that lost a value apart, and never as a row', () => {
		const saved = {
			options: [ SIZE, GRIND ],
			variants: [
				...readOfFourVariants().variants,
				readVariant( [ { axis: 'size', value: '250g' } ], {
					variant_id: 200,
					sku: 'PARTIAL',
					enabled: false,
				} ),
			],
		};
		const { rows, retired } = areaState( saved, undefined, LIMIT );

		expect( rows ).toHaveLength( 4 );
		expect( retired.map( ( { variant_id: id } ) => id ) ).toEqual( [
			200,
		] );
		expect(
			JSON.stringify( rows.map( ( { entry } ) => entry ) )
		).not.toContain( 'PARTIAL' );
	} );

	it( 'gives a combination brought back with its value a row of its own, not the partial row', () => {
		const saved = {
			options: [ { ...SIZE, values: [ SIZE.values[ 0 ] ] } ],
			variants: [
				readVariant( [], {
					variant_id: 300,
					sku: 'LOST-ITS-VALUE',
					enabled: false,
				} ),
			],
		};
		const edits = {
			options: namedAxes( [ SIZE ] ),
		};
		const { rows, retired } = areaState( saved, edits, LIMIT );

		expect( rows.map( ( { read } ) => read ) ).toEqual( [
			undefined,
			undefined,
		] );
		expect( retired ).toHaveLength( 1 );
	} );

	it( 'never gives a partial row’s SKU to the combination of no pairs when the options are all removed', () => {
		const saved = {
			options: [ { ...SIZE, values: [ SIZE.values[ 0 ] ] } ],
			variants: [
				readVariant( [], {
					variant_id: 400,
					sku: 'LOST-ITS-VALUE',
					enabled: false,
				} ),
			],
		};
		const { rows } = areaState( saved, { options: [] }, LIMIT );

		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].read ).toBeUndefined();
		expect( 'sku' in rows[ 0 ].entry ).toBe( false );
	} );

	it( 'says which enabled rows a removed value takes off sale', () => {
		const saved = readOfFourVariants();
		const edits = {
			options: namedAxes( [
				SIZE,
				{ ...GRIND, values: [ GRIND.values[ 0 ] ] },
			] ),
		};
		const state = areaState( saved, edits, LIMIT );

		expect( state.rows ).toHaveLength( 2 );
		expect( state.axesChanged ).toBe( false );
		expect(
			state.goingOffSale.map( ( { variant_id: id } ) => id )
		).toEqual( [ 101, 103 ] );
	} );

	it( 'does not warn of a row that was disabled already', () => {
		const saved = readOfFourVariants();

		saved.variants[ 1 ].enabled = false;

		const state = areaState(
			saved,
			{
				options: namedAxes( [
					SIZE,
					{ ...GRIND, values: [ GRIND.values[ 0 ] ] },
				] ),
			},
			LIMIT
		);

		expect(
			state.goingOffSale.map( ( { variant_id: id } ) => id )
		).toEqual( [ 103 ] );
	} );

	it( 'says every row goes off sale when an axis is added', () => {
		const state = areaState(
			readOfOneVariant(),
			{ options: namedAxes( [ SIZE ] ) },
			LIMIT
		);

		expect( state.axesChanged ).toBe( true );
		expect(
			state.goingOffSale.map( ( { variant_id: id } ) => id )
		).toEqual( [ 100 ] );
		expect( state.rows ).toHaveLength( 2 );
	} );

	describe( 'what a save takes off sale, judged against the product as stored now', () => {
		/**
		 * Builds a product of one option, Size, as the editor read it, and as stored after another writer added a size.
		 *
		 * @return {Object} `saved` and `latest`, the `seocart` objects.
		 */
		const readThenGrown = () => {
			const variant = ( key, label, index ) =>
				readVariant( [ { axis: 'size', value: key } ], {
					variant_id: 500 + index,
					sku: `S-${ index }`,
					label,
				} );
			const saved = {
				options: [ SIZE ],
				variants: SIZE.values.map( ( { key, label }, index ) =>
					variant( key, label, index )
				),
			};
			const large = {
				key: '2kg',
				label: '2 kg',
				position: 2,
				image_id: null,
				swatch: null,
			};

			return {
				saved,
				latest: {
					options: [ { ...SIZE, values: [ ...SIZE.values, large ] } ],
					variants: [
						...saved.variants,
						variant( '2kg', '2 kg', 2 ),
					],
				},
			};
		};
		const labels = ( variants ) => variants.map( ( { label } ) => label );
		const oneSizeLeft = namedAxes( [
			{ ...SIZE, values: [ SIZE.values[ 0 ] ] },
		] );

		it( 'names a variant another writer added beside the one whose value the merchant removed, and says it was not shown', () => {
			const { saved, latest } = readThenGrown();
			const state = areaState( saved, { options: oneSizeLeft }, LIMIT );

			expect(
				labels(
					offSaleOnSave( state, saved, undefined, LIMIT ).variants
				)
			).toEqual( [ '1 kg' ] );

			const now = offSaleOnSave( state, saved, latest, LIMIT );

			expect( now.axesChanged ).toBe( false );
			expect( labels( now.variants ) ).toEqual( [ '1 kg', '2 kg' ] );
			expect( labels( now.unseen ) ).toEqual( [ '2 kg' ] );
		} );

		it( 'names it when the merchant changed no option, since the save names only the combinations the area knows', () => {
			const { saved, latest } = readThenGrown();
			const read = areaState( saved, undefined, LIMIT );
			const edits = seocartEdit(
				saved,
				undefined,
				read.axes,
				read.rows.map( ( { entry, read: variant }, index ) =>
					index === 0 ? withWeight( entry, variant, 300 ) : entry
				),
				LIMIT
			);
			const state = areaState( saved, edits, LIMIT );

			expect(
				offSaleOnSave( state, saved, undefined, LIMIT ).variants
			).toEqual( [] );
			expect(
				labels( offSaleOnSave( state, saved, latest, LIMIT ).variants )
			).toEqual( [ '2 kg' ] );
		} );

		it( 'leaves out a variant another writer took off sale already', () => {
			const { saved, latest } = readThenGrown();

			latest.variants[ 1 ] = { ...latest.variants[ 1 ], enabled: false };

			expect(
				labels(
					offSaleOnSave(
						areaState( saved, { options: oneSizeLeft }, LIMIT ),
						saved,
						latest,
						LIMIT
					).variants
				)
			).toEqual( [ '2 kg' ] );
		} );
	} );

	it( 'builds no row past the limit, and says how many it would be', () => {
		const axis = ( key ) => ( {
			key,
			label: key,
			values: Array.from( { length: 100 }, ( _, index ) => ( {
				key: `${ key }-${ index }`,
				label: `${ key } ${ index }`,
			} ) ),
		} );
		const state = areaState(
			undefined,
			{ options: [ axis( 'a' ), axis( 'b' ) ] },
			LIMIT
		);

		expect( state.tooMany ).toBe( true );
		expect( state.count ).toBe( 10000 );
		expect( state.rows ).toEqual( [] );
	} );
} );

describe( 'needsSku', () => {
	it( 'asks every new row for a SKU, enabled or not', () => {
		expect(
			needsSku( { entry: { combination: [], enabled: true } } )
		).toBe( true );
		expect(
			needsSku( { entry: { combination: [], enabled: false } } )
		).toBe( true );
	} );

	it( 'lets a stored variant whose SKU another took stay disabled without one, but not enabled', () => {
		const read = { sku: null };

		expect( needsSku( { entry: { enabled: false }, read } ) ).toBe( false );
		expect( needsSku( { entry: { enabled: true }, read } ) ).toBe( true );
	} );

	it( 'lets a stored variant whose SKU another took stay as it was read, its entry naming neither', () => {
		const read = readVariant( [], { sku: null, enabled: false } );
		const entry = entryOfRead( read );

		expect( entry ).toEqual( { combination: [] } );
		expect( needsSku( { entry, read } ) ).toBe( false );
		expect(
			needsSku( { entry: withEnabled( entry, read, true ), read } )
		).toBe( true );
	} );

	it( 'asks for the SKU back of a stored variant that has one', () => {
		expect(
			needsSku( { entry: { enabled: false }, read: { sku: 'S' } } )
		).toBe( true );
	} );
} );

describe( 'needsPrice', () => {
	const base = 'USD';

	it( 'asks an enabled row without a read for a price in the base currency', () => {
		expect( needsPrice( { entry: { combination: [] } }, base ) ).toBe(
			true
		);
		expect(
			needsPrice(
				{
					entry: withPrice(
						{ combination: [] },
						undefined,
						'EUR',
						900,
						base
					),
				},
				base
			)
		).toBe( true );
		expect(
			needsPrice(
				{
					entry: withPrice(
						{ combination: [] },
						undefined,
						base,
						900,
						base
					),
				},
				base
			)
		).toBe( false );
	} );

	it( 'lets a row without a read go without a price once it is disabled', () => {
		expect(
			needsPrice( { entry: { combination: [], enabled: false } }, base )
		).toBe( false );
	} );

	it( 'never asks a variant on sale, which keeps its prices when its entry names none', () => {
		const read = readVariant( [], { prices: [] } );

		expect( needsPrice( { entry: entryOfRead( read ), read }, base ) ).toBe(
			false
		);
	} );

	it( 'asks a variant on sale for a price once its price in the base currency is taken away, and not for one at zero', () => {
		const read = readVariant( [] );
		const taken = withPrice( entryOfRead( read ), read, base, null, base );
		const zero = readVariant( [], {
			prices: [
				{
					currency: base,
					price_minor: 0,
					compare_at_minor: null,
					amount_basis: NET,
				},
			],
		} );

		expect( needsPrice( { entry: taken, read }, base ) ).toBe( true );
		expect(
			needsPrice(
				{ entry: withPrice( taken, read, base, 0, base ), read },
				base
			)
		).toBe( false );
		expect(
			needsPrice(
				{ entry: withEnabled( taken, read, false ), read },
				base
			)
		).toBe( false );
		expect(
			needsPrice( { entry: entryOfRead( zero ), read: zero }, base )
		).toBe( false );
	} );

	it( 'never asks a variant on sale priced only in another currency for one in the base currency', () => {
		const read = readVariant( [], {
			prices: [
				{
					currency: 'EUR',
					price_minor: 1700,
					compare_at_minor: null,
					amount_basis: NET,
				},
			],
		} );
		const entry = entryOfRead( read );

		expect( needsPrice( { entry, read }, base ) ).toBe( false );
		expect(
			needsPrice(
				{ entry: withPrice( entry, read, 'EUR', 1600, base ), read },
				base
			)
		).toBe( false );
	} );

	it( 'asks a variant read disabled without a price for one when it is ticked again, and only then', () => {
		const read = readVariant( [], { enabled: false, prices: [] } );
		const entry = entryOfRead( read );

		expect( needsPrice( { entry, read }, base ) ).toBe( false );
		expect(
			needsPrice(
				{ entry: withEnabled( entry, read, true ), read },
				base
			)
		).toBe( true );
		expect(
			needsPrice(
				{
					entry: withPrice(
						withEnabled( entry, read, true ),
						read,
						base,
						900,
						base
					),
					read,
				},
				base
			)
		).toBe( false );
	} );

	it( 'lets a variant read disabled with a price be ticked again', () => {
		const read = readVariant( [], { enabled: false } );

		expect(
			needsPrice(
				{ entry: withEnabled( entryOfRead( read ), read, true ), read },
				base
			)
		).toBe( false );
	} );

	it( 'asks a value brought back for a price, though the variant it brings back has stored ones', () => {
		const { saved, edits } = valueBroughtBack();
		const [ kept, back ] = areaState( saved, edits, LIMIT ).rows;

		expect( needsPrice( kept, base ) ).toBe( false );
		expect( needsPrice( back, base ) ).toBe( true );
		expect( needsSku( back ) ).toBe( true );
	} );
} );

describe( 'changing an entry', () => {
	const base = 'USD';
	const entry = { combination: [], enabled: true };

	it( 'trims a SKU, and takes an empty one away', () => {
		expect( withSku( entry, ' A-1 ' ).sku ).toBe( 'A-1' );
		expect( 'sku' in withSku( { ...entry, sku: 'A' }, '  ' ) ).toBe(
			false
		);
	} );

	it( 'sends no weight for a new row without one, sends null to clear a stored one, and none for the weight as read', () => {
		const read = { weight_grams: 250 };

		expect( 'weight_grams' in withWeight( entry, undefined, null ) ).toBe(
			false
		);
		expect( withWeight( entry, undefined, 250 ).weight_grams ).toBe( 250 );
		expect( withWeight( entry, read, null ).weight_grams ).toBeNull();
		expect( 'weight_grams' in withWeight( entry, read, 250 ) ).toBe(
			false
		);
	} );

	it( 'sends no state for a stored variant ticked to the state it was read in', () => {
		expect(
			'enabled' in withEnabled( entry, { enabled: true }, true )
		).toBe( false );
		expect( withEnabled( entry, { enabled: true }, false ).enabled ).toBe(
			false
		);
		expect( withEnabled( entry, undefined, true ).enabled ).toBe( true );
	} );

	it( 'adds a price, its basis the base price’s', () => {
		const priced = withPrice( entry, undefined, base, 1800, base );
		const gross = withPriceFields( priced, undefined, base, {
			amount_basis: GROSS,
		} );
		const both = withPrice( gross, undefined, 'EUR', 1700, base );

		expect( priceIn( both, 'EUR' ) ).toEqual( {
			currency: 'EUR',
			price_minor: 1700,
			compare_at_minor: null,
			amount_basis: GROSS,
		} );
		expect( both.prices.map( ( { currency } ) => currency ) ).toEqual( [
			'EUR',
			'USD',
		] );
	} );

	it( 'keeps a price’s compare-at and basis when the amount changes', () => {
		const priced = withPriceFields(
			withPrice( entry, undefined, base, 1800, base ),
			undefined,
			base,
			{ compare_at_minor: 2000, amount_basis: GROSS }
		);

		expect(
			priceIn( withPrice( priced, undefined, base, 1900, base ), base )
		).toEqual( {
			currency: base,
			price_minor: 1900,
			compare_at_minor: 2000,
			amount_basis: GROSS,
		} );
	} );

	it( 'keeps a stored compare-at and basis when a price is cleared and typed again, so typing it back is no change', () => {
		const read = readVariant( [], {
			prices: [
				{
					currency: base,
					price_minor: 2000,
					compare_at_minor: 3000,
					amount_basis: GROSS,
				},
			],
		} );
		const saved = { ...readOfOneVariant(), variants: [ read ] };
		const stored = entryOfRead( read );
		const cleared = withPrice( stored, read, base, null, base );

		expect(
			priceIn( withPrice( cleared, read, base, 2100, base ), base )
		).toEqual( {
			currency: base,
			price_minor: 2100,
			compare_at_minor: 3000,
			amount_basis: GROSS,
		} );

		const back = withPrice( cleared, read, base, 2000, base );

		expect( back ).toEqual( stored );
		expect( seocartEdit( saved, undefined, [], [ back ], LIMIT ) ).toEqual(
			{}
		);
	} );

	it( 'sends an emptied list for a stored variant, and none for a new row, which may be an earlier generation’s', () => {
		const priced = withPrice( entry, undefined, base, 1800, base );

		expect(
			withPrice( priced, readVariant( [] ), base, null, base ).prices
		).toEqual( [] );
		expect(
			'prices' in withPrice( priced, undefined, base, null, base )
		).toBe( false );
	} );

	it( 'makes a cell of a changed count, carrying the count it was read at, and none of a count taken back', () => {
		const read = {
			stock: [
				{ location_uuid: 'a', on_hand: 5, expected_on_hand: null },
			],
		};
		const counted = withOnHand( entry, read, 'a', 8 );

		expect( counted.stock ).toEqual( [
			{ location_uuid: 'a', on_hand: 8, expected_on_hand: 5 },
		] );
		expect( onHandIn( counted, read, 'a' ) ).toBe( 8 );
		expect( 'stock' in withOnHand( counted, read, 'a', 5 ) ).toBe( false );
		expect( 'stock' in withOnHand( counted, read, 'a', null ) ).toBe(
			false
		);
		expect( onHandIn( entry, read, 'a' ) ).toBe( 5 );
	} );

	it( 'reads a count of zero for a cell the record does not have', () => {
		expect(
			withOnHand( entry, undefined, 'new', 3 ).stock[ 0 ].expected_on_hand
		).toBe( 0 );
	} );
} );

describe( 'stock figures', () => {
	const listed = { on_hand: 9, allocated: 2, held: 1, available: 6 };

	it( 'takes committed from the stock list and on hand from the entry, else the read, else the list', () => {
		const read = {
			stock: [ { location_uuid: 'a', on_hand: 7 } ],
		};

		expect( stockAt( {}, read, listed, 'a' ) ).toEqual( {
			onHand: 7,
			committed: 3,
			available: 4,
		} );
		expect(
			stockAt(
				{ stock: [ { location_uuid: 'a', on_hand: 10 } ] },
				read,
				listed,
				'a'
			).available
		).toBe( 7 );
		expect( stockAt( {}, undefined, listed, 'b' ).onHand ).toBe( 9 );
		expect( stockAt( {}, undefined, undefined, 'b' ).available ).toBe( 0 );
	} );

	it( 'shows every active location, and an inactive one only while it holds an item', () => {
		const locations = [
			{ location_uuid: 'a', active: true },
			{ location_uuid: 'b', active: false },
			{ location_uuid: 'c', active: false },
		];

		expect(
			locationsOf( locations, {
				locations: [ { location_uuid: 'b' } ],
			} ).map( ( { location_uuid: uuid } ) => uuid )
		).toEqual( [ 'a', 'b' ] );
		expect( locationsOf( locations, undefined ) ).toHaveLength( 1 );
	} );

	it( 'adds up what is available at active locations only', () => {
		const row = { entry: {}, read: undefined };
		const level = {
			locations: [
				{ location_uuid: 'a', on_hand: 9, allocated: 1, held: 1 },
				{ location_uuid: 'b', on_hand: 4, allocated: 0, held: 0 },
			],
		};

		expect(
			availableAcross( row, level, [
				{ location_uuid: 'a', active: true },
				{ location_uuid: 'b', active: false },
			] )
		).toBe( 7 );
	} );

	it( 'says what a figure’s badge is from the variant’s policy', () => {
		const level = {
			track: true,
			backorder_policy: 'no',
			low_stock_threshold: 5,
		};

		expect( stockBadge( 0, level ) ).toBe( 'out' );
		expect( stockBadge( -2, level ) ).toBe( 'out' );
		expect( stockBadge( 0, { ...level, backorder_policy: 'allow' } ) ).toBe(
			'backorder'
		);
		expect( stockBadge( 5, level ) ).toBe( 'low' );
		expect( stockBadge( 6, level ) ).toBeNull();
		expect(
			stockBadge( 1, { ...level, low_stock_threshold: null } )
		).toBeNull();
		expect( stockBadge( 0, { ...level, track: false } ) ).toBeNull();
		expect( stockBadge( 0, undefined ) ).toBeNull();
	} );

	it( 'asks for so many variants a request', () => {
		expect( batchesOf( [ 1, 2, 3, 4, 5 ], 2 ) ).toEqual( [
			[ 1, 2 ],
			[ 3, 4 ],
			[ 5 ],
		] );
		expect( batchesOf( [], 200 ) ).toEqual( [] );
	} );
} );

describe( 'skusFromPrefix', () => {
	const rows = [
		{
			key: 'a',
			combination: [
				{ axis: 'size', value: '250g' },
				{ axis: 'grind', value: 'whole-bean' },
			],
			entry: {},
		},
		{ key: 'b', combination: [], entry: { sku: 'KEPT' } },
	];

	it( 'fills only the rows without a SKU, from their keys', () => {
		expect( [ ...skusFromPrefix( rows, ' FW ', 64 ) ] ).toEqual( [
			[ 'a', 'FW-250G-WHOLE-BEAN' ],
		] );
	} );

	it( 'fills nothing without a prefix, and no SKU that would be too long', () => {
		expect( skusFromPrefix( rows, '  ', 64 ).size ).toBe( 0 );
		expect( skusFromPrefix( rows, 'FW', 5 ).size ).toBe( 0 );
	} );
} );

describe( 'keyFromLabel', () => {
	it( 'derives a key a key may be', () => {
		expect( keyFromLabel( 'Whole bean', [], 'x' ) ).toBe( 'whole-bean' );
		expect( keyFromLabel( '250 g', [], 'x' ) ).toBe( '250-g' );
		expect( keyFromLabel( 'Café  Crème!', [], 'x' ) ).toBe( 'cafe-creme' );
		expect( keyFromLabel( 'a_b', [], 'x' ) ).toBe( 'a_b' );
		expect( keyFromLabel( 'x'.repeat( 40 ), [], 'x' ) ).toHaveLength( 32 );
		expect( keyFromLabel( `${ 'y'.repeat( 31 ) } z`, [], 'x' ) ).toBe(
			'y'.repeat( 31 )
		);
	} );

	it( 'falls back for a label with no letter or digit a key may hold', () => {
		expect( keyFromLabel( '日本語', [], 'value-3' ) ).toBe( 'value-3' );
	} );

	it( 'never takes a neighbour’s key, and stays within 32 characters', () => {
		expect( keyFromLabel( 'Size', [ 'size' ], 'x' ) ).toBe( 'size-2' );
		expect( keyFromLabel( 'Size', [ 'size', 'size-2' ], 'x' ) ).toBe(
			'size-3'
		);
		expect(
			keyFromLabel( 'z'.repeat( 32 ), [ 'z'.repeat( 32 ) ], 'x' )
		).toHaveLength( 32 );
	} );
} );

describe( 'parsing', () => {
	it( 'reads a swatch in lower case, no text as none, and anything else as no colour', () => {
		expect( parseSwatch( ' #7B4A2D ' ) ).toBe( '#7b4a2d' );
		expect( parseSwatch( '' ) ).toBeNull();
		expect( parseSwatch( '#fff' ) ).toBeUndefined();
		expect( parseSwatch( 'red' ) ).toBeUndefined();
	} );

	it( 'requires a label', () => {
		expect( parseLabel( ' Size ' ) ).toBe( 'Size' );
		expect( parseLabel( '   ' ) ).toBeUndefined();
	} );
} );

describe( 'fromPrice', () => {
	const priced = ( minor, enabled = true, currency = 'USD' ) => ( {
		enabled,
		prices: [ { currency, price_minor: minor } ],
	} );

	it( 'is the lowest base price among the enabled variants', () => {
		expect(
			fromPrice(
				{
					variants: [
						priced( 6400 ),
						priced( 1800 ),
						priced( 100, false ),
						priced( 50, true, 'EUR' ),
					],
				},
				'USD'
			)
		).toEqual( { minor: 1800, several: true } );
	} );

	it( 'says when the variants share one price', () => {
		expect(
			fromPrice( { variants: [ priced( 1800 ), priced( 1800 ) ] }, 'USD' )
				.several
		).toBe( false );
	} );

	it( 'is nothing without a price', () => {
		expect( fromPrice( undefined, 'USD' ) ).toBeNull();
		expect(
			fromPrice( { variants: [ priced( 50, true, 'EUR' ) ] }, 'USD' )
		).toBeNull();
	} );
} );
