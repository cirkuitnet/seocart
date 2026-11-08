/**
 * What the Product data area edits, as plain functions: from the record the editor read and the
 * edit it holds, to the rows the area shows and the `seocart` object a save sends.
 *
 * A save is built here from named fields, and never from the read record: a read sent back whole
 * is refused, since a stock cell reads `expected_on_hand` as null and a product without options
 * reads both its flat fields and its lists. It names every combination of the current options, so
 * a variant whose row lost a value is healed by the save that names it, and no combination is
 * disabled by being left out. A read row the options do not offer is listed apart and never sent.
 * A product nobody changed sends nothing: empty lists would start its first generation, and it
 * would no longer give way to a translation link.
 *
 * A variant the save names keeps every field its entry leaves out as it is stored, and what is
 * stored may be another writer's since the editor read it. So an entry names only what the merchant
 * changed: a row they did not touch, its combination and its SKU, which the save needs; a row they
 * touched, the fields that now differ from the read, its prices as its whole list. What a row shows
 * is its entry's field, or else the field as read.
 *
 * A figure shown from here comes from the read; nothing here computes a price or a tax.
 */

/** A price's amount basis: it does not include tax. */
export const NET = 'net';

/** A price's amount basis: it includes tax. */
export const GROSS = 'gross';

/**
 * Names a combination, whatever order its pairs come in.
 *
 * @param {Array<{axis: string, value: string}>} combination The combination.
 * @return {string} Its key.
 */
export function combinationKey( combination ) {
	return combination
		.map( ( { axis, value } ) => `${ axis }=${ value }` )
		.sort()
		.join( '\n' );
}

/**
 * Counts the combinations of some axes without building them: ten axes of ten values are far too many to build.
 *
 * @param {Array<Object>} axes The axes.
 * @return {number} How many combinations they make; one for no axes, the combination of no pairs.
 */
export function combinationCount( axes ) {
	return axes.reduce( ( count, axis ) => count * axis.values.length, 1 );
}

/**
 * Builds the combinations of some axes, the first axis the slowest to change.
 *
 * @param {Array<Object>} axes The axes.
 * @return {Array<Array<{axis: string, value: string}>>} The combinations, each with one pair per axis in axis order.
 */
export function matrixOf( axes ) {
	return axes.reduce(
		( combinations, axis ) =>
			combinations.flatMap( ( pairs ) =>
				axis.values.map( ( value ) => [
					...pairs,
					{ axis: axis.key, value: value.key },
				] )
			),
		[ [] ]
	);
}

/**
 * Writes a combination as a merchant reads it: the labels of its values in the order of the axes.
 *
 * @param {Array<{axis: string, value: string}>} combination The combination.
 * @param {Array<Object>}                        axes        The axes.
 * @return {string} The label, such as `250 g / Whole bean`; empty for no pairs.
 */
export function labelOf( combination, axes ) {
	return axes
		.map( ( axis ) => {
			const pair = combination.find(
				( { axis: key } ) => key === axis.key
			);

			return axis.values.find( ( { key } ) => key === pair?.value )
				?.label;
		} )
		.filter( Boolean )
		.join( ' / ' );
}

/**
 * Tells whether some axes offer a combination: one pair per axis, each naming a value of it.
 *
 * @param {Array<{axis: string, value: string}>} combination The combination.
 * @param {Array<Object>}                        axes        The axes.
 * @return {boolean} False for a row that lost a value, and for a row of other axes.
 */
export function isOffered( combination, axes ) {
	return (
		combination.length === axes.length &&
		axes.every( ( axis ) =>
			axis.values.some( ( { key } ) =>
				combination.some(
					( pair ) => pair.axis === axis.key && pair.value === key
				)
			)
		)
	);
}

/**
 * Names the fields of the axes a save may send.
 *
 * @param {Array<Object>|undefined} options The axes as read.
 * @return {Array<Object>} The axes, each value with its label, its image and its swatch; the order of the list is their position.
 */
export function namedAxes( options = [] ) {
	return options.map( ( { key, label, values } ) => ( {
		key,
		label,
		values: values.map( ( value ) => ( {
			key: value.key,
			label: value.label,
			image_id: value.image_id ?? null,
			swatch: value.swatch ?? null,
		} ) ),
	} ) );
}

/**
 * Builds a variant's entry of a save, its fields always in one order so that two entries can be compared.
 *
 * @param {Object} fields The fields; one left undefined is not sent, and so keeps what is stored.
 * @return {Object} The entry.
 */
function entryOf( fields ) {
	const { enabled, sku, weight_grams: weight, prices, stock } = fields;

	return {
		combination: fields.combination,
		...( enabled === undefined ? {} : { enabled } ),
		...( sku ? { sku } : {} ),
		...( weight === undefined ? {} : { weight_grams: weight } ),
		...( prices === undefined ? {} : { prices } ),
		...( stock?.length ? { stock } : {} ),
	};
}

/**
 * Puts a variant's prices in one order, the order of their currency codes, so that two lists can be compared.
 *
 * @param {Array<Object>} prices The prices.
 * @return {Array<Object>} The prices, sorted.
 */
function sortedPrices( prices ) {
	return [ ...prices ].sort( ( a, b ) =>
		a.currency.localeCompare( b.currency )
	);
}

/**
 * Names the authored prices of a variant as read, in the order an entry lists them.
 *
 * @param {Object|void} read The variant as read, if the row has one.
 * @return {Array<Object>} The prices; none for a row without a read.
 */
function readPrices( read ) {
	return sortedPrices(
		( read?.prices ?? [] ).map( ( price ) => ( {
			currency: price.currency,
			price_minor: price.price_minor,
			compare_at_minor: price.compare_at_minor ?? null,
			amount_basis: price.amount_basis ?? NET,
		} ) )
	);
}

/**
 * Names what a save sends of a variant as read that the merchant has not touched: its combination and its SKU.
 *
 * The save keeps every field the entry leaves out as it is stored, so a change another writer made
 * to the variant since it was read stands. The SKU is named because a stored variant that has one
 * must be given it; a variant whose SKU another variant took reads none and sends none, and stays
 * disabled.
 *
 * @param {Object} read The variant as read.
 * @return {Object} The entry.
 */
export function entryOfRead( read ) {
	return entryOf( {
		combination: read.combination.map( ( { axis, value } ) => ( {
			axis,
			value,
		} ) ),
		sku: read.sku,
	} );
}

/**
 * Tells whether two values of the area's own building are the same.
 *
 * @param {*} a One.
 * @param {*} b The other.
 * @return {boolean} True when they are.
 */
function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Builds what the area shows from what was read and what is edited.
 *
 * @param {Object|undefined} saved The `seocart` object as read; a post without a product has neither list.
 * @param {Object|undefined} edits The edit of the `seocart` object.
 * @param {number}           limit The most variants a product may have.
 * @return {Object} `axes`, `readAxes` and `axesChanged`; `rows`, one per combination of the current axes, each with
 *                  its `key`, `combination`, `label`, the `entry` a save sends, the variant as `read`, if any, and
 *                  `mayBeRestored`, whether a row without a read may be a variant the product had before, which the
 *                  save brings back in the state it had unless its entry sets one;
 *                  `count` and `tooMany`, the size of the matrix and whether it is past the limit, which then has
 *                  no rows; `retired`, the read rows the axes as read do not offer; `goingOffSale`, the enabled
 *                  rows the axes as read offer and the current ones do not; and `edited`, whether a save would
 *                  send a list.
 */
export function areaState( saved, edits, limit ) {
	const readAxes = namedAxes( saved?.options );
	const axes = edits?.options ?? readAxes;
	const readVariants = saved?.variants ?? [];
	const count = combinationCount( axes );
	const tooMany = count > limit;
	const offeredAsRead = readVariants.filter( ( { combination } ) =>
		isOffered( combination, readAxes )
	);
	const readByKey = new Map(
		offeredAsRead.map( ( read ) => [
			combinationKey( read.combination ),
			read,
		] )
	);
	const editedByKey = new Map(
		( edits?.variants ?? [] ).map( ( entry ) => [
			combinationKey( entry.combination ),
			entry,
		] )
	);
	const rows = ( tooMany ? [] : matrixOf( axes ) ).map( ( combination ) => {
		const key = combinationKey( combination );
		const read = readByKey.get( key );

		return {
			key,
			combination,
			label: labelOf( combination, axes ),
			read,
			mayBeRestored: ! read && readVariants.length > 0,
			entry:
				editedByKey.get( key ) ??
				( read ? entryOfRead( read ) : entryOf( { combination } ) ),
		};
	} );
	const offeredNow = new Set( rows.map( ( { key } ) => key ) );

	return {
		axes,
		readAxes,
		axesChanged: ! same(
			axes.map( ( { key } ) => key ).sort(),
			readAxes.map( ( { key } ) => key ).sort()
		),
		rows,
		count,
		tooMany,
		retired: readVariants.filter(
			( { combination } ) => ! isOffered( combination, readAxes )
		),
		goingOffSale: tooMany
			? []
			: offeredAsRead.filter(
					( { enabled, combination } ) =>
						enabled &&
						! offeredNow.has( combinationKey( combination ) )
				),
		edited: edits?.options !== undefined || edits?.variants !== undefined,
	};
}

/**
 * Says which variants a save of the area takes off sale, among those stored when the product was last read.
 *
 * The save names the combinations of its options, and a variant it does not name goes off sale:
 * one whose value the merchant removed, and also one another writer added after the editor read
 * the product, which the area never showed. So when the product has been read again since, its
 * variants as stored then are the ones judged.
 *
 * @param {Object}           state  What the area shows.
 * @param {Object|undefined} saved  The `seocart` object as the editor read it.
 * @param {Object|undefined} latest The `seocart` object as read again, if it was.
 * @param {number}           limit  The most variants a product may have.
 * @return {Object} `axesChanged`, whether the save starts the variants again, and `variants`, the ones it takes off sale,
 *                  as `areaState()` says them of the product judged; `unseen`, those of them the editor's read does not hold.
 */
export function offSaleOnSave( state, saved, latest, limit ) {
	const judged =
		latest === undefined
			? state
			: areaState( latest, { options: state.axes }, limit );
	const seen = new Set(
		( saved?.variants ?? [] ).map( ( { variant_id: id } ) => id )
	);

	return {
		axesChanged: judged.axesChanged,
		variants: judged.goingOffSale,
		unseen: judged.goingOffSale.filter(
			( { variant_id: id } ) => ! seen.has( id )
		),
	};
}

/**
 * Builds the edit of the `seocart` object that says the merchant wants these axes and these entries.
 *
 * Only what differs from the read is in it, so a change taken back leaves nothing to send. Changed
 * axes always come with the entries, so that every combination of them is named.
 *
 * @param {Object|undefined} saved    The `seocart` object as read.
 * @param {Object|undefined} previous The edit it replaces; its fields but the two lists, such as a new translation's, stay.
 * @param {Array<Object>}    axes     The axes.
 * @param {Array<Object>}    entries  The entries, one per combination of the axes.
 * @param {number}           limit    The most variants a product may have.
 * @return {Object} The edit, empty when nothing is left to send.
 */
export function seocartEdit( saved, previous, axes, entries, limit ) {
	const { options, variants, ...rest } = previous ?? {};
	const read = areaState( saved, undefined, limit );
	const optionsChanged = ! same( axes, read.readAxes );
	const entriesChanged =
		optionsChanged ||
		! same(
			entries,
			read.rows.map( ( { entry } ) => entry )
		);

	// No entries means the options make too many to build: a list given empty would disable every variant.
	return {
		...rest,
		...( optionsChanged ? { options: axes } : {} ),
		...( entriesChanged && entries.length > 0
			? { variants: entries }
			: {} ),
	};
}

/**
 * Tells whether a row's entry cannot be saved for want of a SKU.
 *
 * Every variant needs one but a stored one whose SKU another variant took, for as long as it stays disabled.
 *
 * @param {Object} row A row of the area.
 * @return {boolean} True when the save would be refused.
 */
export function needsSku( row ) {
	const { entry, read } = row;

	return (
		! entry.sku && ! ( read && ! read.sku && enabledShown( row ) === false )
	);
}

/**
 * Tells whether a row's entry cannot be saved for want of a price in the base currency.
 *
 * Only a row the save would leave enabled without one, unless it was read enabled without one. One
 * without a read may be a variant the product had before, and a save that named no price would put
 * it back on sale at a price nobody sees here; one read disabled may have no price, and enabling it
 * would take the product off sale; one read enabled with a price in the base currency would take the
 * product off sale too once that price is taken away. A price typed for it replaces its stored ones.
 * A variant read enabled without one, such as one priced only in other currencies, is never asked
 * for one, since the save takes nothing from it.
 *
 * @param {Object} row  A row of the area.
 * @param {string} base The base currency's code.
 * @return {boolean} True when the save waits for a price, or for the row to be disabled.
 */
export function needsPrice( row, base ) {
	const { read } = row;

	return (
		( ! read ||
			! read.enabled ||
			readPrices( read ).some( ( price ) => price.currency === base ) ) &&
		enabledShown( row ) !== false &&
		! priceShown( row, base )
	);
}

/**
 * Tells whether the area keeps the product from being saved.
 *
 * It does while a save would send the area's lists and one of them waits for the merchant: options
 * that make more variants than a product may have, whose save would name none of them; a variant
 * without a SKU; or one without a price in the base currency. A card says each in words. A
 * translation post edits none of it, its area being a way to the post its product is edited on.
 *
 * @param {Object}           state    What the area shows, as `areaState()` builds it.
 * @param {string}           base     The base currency's code.
 * @param {number|undefined} [source] The post a translation's product is edited on, when the post is a translation.
 * @return {boolean} True while saving waits.
 */
export function saveLocked( state, base, source ) {
	return (
		! source &&
		state.edited &&
		( state.tooMany ||
			state.rows.some(
				( row ) => needsSku( row ) || needsPrice( row, base )
			) )
	);
}

/**
 * Builds the middleware of the editor's requests that refuses to write the area's lists to the
 * product while saving is locked.
 *
 * The editor saves another entity's changes, and a product that is not the post it shows, without
 * asking whether saving is locked: the panel that lists the changes waiting does. So the request is
 * refused where every way of saving meets: a write to that product's own route, which is not an
 * autosave, and carries the product's commerce property, as its data or as a JSON body. A request
 * goes where its path points on a WordPress page, whose own middleware makes the URL from it, so the
 * route is its path, and its URL only when it has no path. Anything else goes through unchanged,
 * whatever is locked: another product's route, a read, any other write.
 *
 * @param {Function} isHeld   Tells whether a lock on saving is held.
 * @param {Function} routeOf  Gives the locked product's REST route, such as `/wp/v2/seocart-products/17`, or nothing while none is known.
 * @param {string}   property The name of the product's commerce property, as the plugin declares it.
 * @param {Function} message  Gives the sentence the refusal carries.
 * @return {Function} The middleware: ( options, next ) giving next( options ), or a promise rejected with an Error.
 */
export function refusingLockedWrites( isHeld, routeOf, property, message ) {
	/**
	 * Tells whether a request's body names the area.
	 *
	 * @param {Object} options The request.
	 * @return {boolean} True when its data or its JSON body has the property.
	 */
	const carriesArea = ( options ) => {
		if ( options.data?.[ property ] !== undefined ) {
			return true;
		}

		try {
			return (
				typeof options.body === 'string' &&
				JSON.parse( options.body )?.[ property ] !== undefined
			);
		} catch {
			return false;
		}
	};

	return ( options, next ) => {
		const target = options.path ?? options.url ?? '';
		const [ location, query = '' ] = target.split( '?' );
		// Whether the site serves pretty REST URLs or `?rest_route=`.
		const route =
			new URLSearchParams( query ).get( 'rest_route' ) ??
			location.replace( /^.*\/wp-json(?=\/)/, '' );
		const locked = routeOf();

		if (
			locked &&
			( options.method ?? 'GET' ).toUpperCase() !== 'GET' &&
			route.replace( /\/$/, '' ) === locked &&
			isHeld() &&
			carriesArea( options )
		) {
			return Promise.reject( new Error( message() ) );
		}

		return next( options );
	};
}

/**
 * Gives an entry a SKU, or takes it away.
 *
 * @param {Object} entry The entry.
 * @param {string} text  What the merchant typed.
 * @return {Object} The entry.
 */
export function withSku( entry, text ) {
	return entryOf( { ...entry, sku: text.trim() } );
}

/**
 * Says what a row's Enabled box shows.
 *
 * @param {Object} row A row of the area.
 * @return {boolean|null} Whether the save leaves the variant enabled; null while it keeps the state of a variant
 *                        the product may have had before, which the read does not say.
 */
export function enabledShown( row ) {
	return (
		row.entry.enabled ??
		row.read?.enabled ??
		( row.mayBeRestored ? null : true )
	);
}

/**
 * Says what a row's weight field shows.
 *
 * @param {Object} row A row of the area.
 * @return {number|null} The weight the save leaves the variant with, or null for none.
 */
export function weightShown( row ) {
	return row.entry.weight_grams === undefined
		? ( row.read?.weight_grams ?? null )
		: row.entry.weight_grams;
}

/**
 * Lists the prices a save leaves a row with: its entry's list, or else its list as read.
 *
 * @param {Object}      entry The row's entry.
 * @param {Object|void} read  The variant as read.
 * @return {Array<Object>} The prices.
 */
function pricesOf( entry, read ) {
	return entry.prices ?? readPrices( read );
}

/**
 * Finds a row's price in a currency, as the save leaves it.
 *
 * @param {Object} row      A row of the area.
 * @param {string} currency The currency's code.
 * @return {Object|undefined} The price; none means the currency is converted from the base price.
 */
export function priceShown( row, currency ) {
	return pricesOf( row.entry, row.read ).find(
		( price ) => price.currency === currency
	);
}

/**
 * Enables or disables an entry. A row whose variant was read in that state names none.
 *
 * @param {Object}      entry   The entry.
 * @param {Object|void} read    The variant as read.
 * @param {boolean}     enabled Whether the variant may be sold.
 * @return {Object} The entry.
 */
export function withEnabled( entry, read, enabled ) {
	return entryOf( {
		...entry,
		enabled: read?.enabled === enabled ? undefined : enabled,
	} );
}

/**
 * Gives an entry a weight. A row names none when it is the weight as read.
 *
 * A new row left without one sends none, since a row an earlier generation holds keeps its own.
 *
 * @param {Object}      entry The entry.
 * @param {Object|void} read  The variant as read.
 * @param {number|null} grams The weight, or null for none.
 * @return {Object} The entry.
 */
export function withWeight( entry, read, grams ) {
	const stored = read ? ( read.weight_grams ?? null ) : null;

	return entryOf( {
		...entry,
		weight_grams: grams === stored ? undefined : grams,
	} );
}

/**
 * Replaces an entry's prices. A row names none when they are its prices as read: a new row left
 * without any sends none, since a row an earlier generation holds keeps its own.
 *
 * @param {Object}        entry  The entry.
 * @param {Object|void}   read   The variant as read.
 * @param {Array<Object>} prices The prices.
 * @return {Object} The entry.
 */
function withPrices( entry, read, prices ) {
	const sorted = sortedPrices( prices );

	return entryOf( {
		...entry,
		prices: same( sorted, readPrices( read ) ) ? undefined : sorted,
	} );
}

/**
 * Finds the price in a currency an entry names.
 *
 * @param {Object} entry    The entry.
 * @param {string} currency The currency's code.
 * @return {Object|undefined} The price; none when the entry names no prices, or none in that currency.
 */
export function priceIn( entry, currency ) {
	return entry.prices?.find( ( price ) => price.currency === currency );
}

/**
 * Sets an entry's price in a currency, or takes the currency's row away. A new row takes its amount basis from the base price's.
 *
 * The entry names the row's whole list of prices, or none when the list is the one read. A price
 * taken away and typed again keeps the compare-at and the basis it was read with, so typing the
 * amount back takes the change back.
 *
 * @param {Object}      entry    The entry.
 * @param {Object|void} read     The variant as read.
 * @param {string}      currency The currency's code.
 * @param {number|null} minor    The price in minor units, or null for none.
 * @param {string}      base     The base currency's code.
 * @return {Object} The entry.
 */
export function withPrice( entry, read, currency, minor, base ) {
	const prices = pricesOf( entry, read );
	const inCurrency = ( code ) => ( price ) => price.currency === code;
	const others = prices.filter( ( price ) => price.currency !== currency );
	const stored =
		prices.find( inCurrency( currency ) ) ??
		readPrices( read ).find( inCurrency( currency ) );

	return withPrices(
		entry,
		read,
		minor === null
			? others
			: [
					...others,
					{
						currency,
						price_minor: minor,
						compare_at_minor: stored?.compare_at_minor ?? null,
						amount_basis:
							stored?.amount_basis ??
							prices.find( inCurrency( base ) )?.amount_basis ??
							NET,
					},
				]
	);
}

/**
 * Changes a field of an entry's price in a currency that has one.
 *
 * @param {Object}      entry    The entry.
 * @param {Object|void} read     The variant as read.
 * @param {string}      currency The currency's code.
 * @param {Object}      fields   The fields: `compare_at_minor` or `amount_basis`.
 * @return {Object} The entry.
 */
export function withPriceFields( entry, read, currency, fields ) {
	return withPrices(
		entry,
		read,
		pricesOf( entry, read ).map( ( price ) =>
			price.currency === currency ? { ...price, ...fields } : price
		)
	);
}

/**
 * Finds the count a stock cell was read at.
 *
 * @param {Object|void} read The variant as read.
 * @param {string}      uuid The location's UUID.
 * @return {number} The units on hand; none for a location or a variant with no stock item yet.
 */
export function onHandRead( read, uuid ) {
	return (
		read?.stock?.find( ( cell ) => cell.location_uuid === uuid )?.on_hand ??
		0
	);
}

/**
 * Finds the count an entry sets at a location.
 *
 * @param {Object}      entry The entry.
 * @param {Object|void} read  The variant as read.
 * @param {string}      uuid  The location's UUID.
 * @return {number} The count of the entry's cell, or else the count as read.
 */
export function onHandIn( entry, read, uuid ) {
	return (
		entry.stock?.find( ( cell ) => cell.location_uuid === uuid )?.on_hand ??
		onHandRead( read, uuid )
	);
}

/**
 * Sets an entry's count at a location. Only a changed count is a cell: it carries the count it was read at, and a count taken back is none.
 *
 * @param {Object}      entry  The entry.
 * @param {Object|void} read   The variant as read.
 * @param {string}      uuid   The location's UUID.
 * @param {number|null} onHand The count; null takes the cell back.
 * @return {Object} The entry.
 */
export function withOnHand( entry, read, uuid, onHand ) {
	const expected = onHandRead( read, uuid );
	const others = ( entry.stock ?? [] ).filter(
		( cell ) => cell.location_uuid !== uuid
	);

	return entryOf( {
		...entry,
		stock:
			onHand === null || onHand === expected
				? others
				: [
						...others,
						{
							location_uuid: uuid,
							on_hand: onHand,
							expected_on_hand: expected,
						},
					],
	} );
}

/**
 * Works out a row's stock at a location as the area shows it: the count it sets or was read at, and what is committed there.
 *
 * @param {Object}      entry  The row's entry.
 * @param {Object|void} read   The variant as read.
 * @param {Object|void} listed The location's row in the stock list, if it has one.
 * @param {string}      uuid   The location's UUID.
 * @return {{onHand: number, committed: number, available: number}} The figures.
 */
export function stockAt( entry, read, listed, uuid ) {
	const counted =
		read?.stock?.find( ( cell ) => cell.location_uuid === uuid )?.on_hand ??
		listed?.on_hand ??
		0;
	const onHand =
		entry.stock?.find( ( cell ) => cell.location_uuid === uuid )?.on_hand ??
		counted;
	const committed = ( listed?.allocated ?? 0 ) + ( listed?.held ?? 0 );

	return { onHand, committed, available: onHand - committed };
}

/**
 * Lists the locations a variant's stock is shown at: every active one, and an inactive one that still holds an item of it.
 *
 * @param {Array<Object>} locations The store's locations.
 * @param {Object|void}   level     The variant's row in the stock list, if it has one.
 * @return {Array<Object>} The locations.
 */
export function locationsOf( locations, level ) {
	return locations.filter(
		( { active, location_uuid: uuid } ) =>
			active ||
			level?.locations.some(
				( { location_uuid: listed } ) => listed === uuid
			)
	);
}

/**
 * Adds up what a row has available at the store's active locations, which is what can still be promised.
 *
 * @param {Object}        row       A row of the area.
 * @param {Object|void}   level     The variant's row in the stock list, if it has one.
 * @param {Array<Object>} locations The store's locations.
 * @return {number} The units.
 */
export function availableAcross( row, level, locations ) {
	const { entry, read } = row;

	return locations
		.filter( ( { active } ) => active )
		.reduce(
			( total, { location_uuid: uuid } ) =>
				total +
				stockAt(
					entry,
					read,
					level?.locations.find(
						( { location_uuid: listed } ) => listed === uuid
					),
					uuid
				).available,
			0
		);
}

/**
 * Says which stock badge a variant's figure carries: its stock policy decides what running out means.
 *
 * @param {number}      units The units available.
 * @param {Object|void} level The variant's row in the stock list; a variant not saved yet has none, and no badge.
 * @return {string|null} `out`, `backorder` or `low`; null for none, and for a variant whose stock is not counted.
 */
export function stockBadge( units, level ) {
	if ( ! level?.track ) {
		return null;
	}

	if ( units <= 0 ) {
		return level.backorder_policy === 'no' ? 'out' : 'backorder';
	}

	const low = level.low_stock_threshold;

	return low !== null && units <= low ? 'low' : null;
}

/**
 * Splits a list of ids into the lists of ids one request may name.
 *
 * @param {number[]} ids  The ids.
 * @param {number}   size The most a request may name.
 * @return {number[][]} The lists.
 */
export function batchesOf( ids, size ) {
	const batches = [];

	for ( let start = 0; start < ids.length; start += size ) {
		batches.push( ids.slice( start, start + size ) );
	}

	return batches;
}

/**
 * Lists the rows the merchant's SKU prefix fills: those without a SKU, each named by its values' keys.
 *
 * @param {Array<Object>} rows      The rows of the area.
 * @param {string}        prefix    The prefix.
 * @param {number}        maxLength The longest SKU.
 * @return {Map<string, string>} The SKU of each row it fills, by row key; a SKU that would be too long fills nothing.
 */
export function skusFromPrefix( rows, prefix, maxLength ) {
	const start = prefix.trim();
	const filled = new Map();

	if ( start === '' ) {
		return filled;
	}

	for ( const { key, combination, entry } of rows ) {
		const sku = [
			start,
			...combination.map( ( { value } ) => value.toUpperCase() ),
		].join( '-' );

		if ( ! entry.sku && sku.length <= maxLength ) {
			filled.set( key, sku );
		}
	}

	return filled;
}

/**
 * Derives a key from a label when an axis or a value is created. Keys never change afterwards.
 *
 * @param {string}   label    The label.
 * @param {string[]} taken    The keys its neighbours have.
 * @param {string}   fallback The key of a label with no letter or digit a key may hold.
 * @return {string} The key: 1 to 32 lower-case letters, digits, "-" or "_".
 */
export function keyFromLabel( label, taken, fallback ) {
	const base =
		label
			.normalize( 'NFKD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9_-]+/g, '-' )
			.replace( /^-+/, '' )
			.slice( 0, 32 )
			.replace( /-+$/, '' ) || fallback;

	if ( ! taken.includes( base ) ) {
		return base;
	}

	let suffix = 2;

	while (
		taken.includes(
			`${ base.slice( 0, 32 - String( suffix ).length - 1 ) }-${ suffix }`
		)
	) {
		suffix++;
	}

	return `${ base.slice( 0, 32 - String( suffix ).length - 1 ) }-${ suffix }`;
}

/**
 * Reads the text a merchant typed as a swatch.
 *
 * @param {string} text What the merchant typed.
 * @return {string|null|undefined} The colour as `#rrggbb` in lower case; null for no text; undefined when it is not a colour.
 */
export function parseSwatch( text ) {
	const trimmed = text.trim().toLowerCase();

	if ( trimmed === '' ) {
		return null;
	}

	return /^#[0-9a-f]{6}$/.test( trimmed ) ? trimmed : undefined;
}

/**
 * Reads the text a merchant typed as a required label.
 *
 * @param {string} text What the merchant typed.
 * @return {string|undefined} The label; undefined for no text.
 */
export function parseLabel( text ) {
	const trimmed = text.trim();

	return trimmed === '' ? undefined : trimmed;
}

/**
 * Finds the lowest base price among a product's enabled variants, as read, for the product's summary.
 *
 * @param {Object|undefined} saved The `seocart` object as read.
 * @param {string}           base  The base currency's code.
 * @return {{minor: number, several: boolean}|null} The price and whether the variants differ, or null when none has a price.
 */
export function fromPrice( saved, base ) {
	const amounts = ( saved?.variants ?? [] )
		.filter( ( { enabled } ) => enabled )
		.flatMap( ( { prices } ) =>
			prices
				.filter( ( { currency } ) => currency === base )
				.map( ( { price_minor: minor } ) => minor )
		);

	return amounts.length === 0
		? null
		: {
				minor: Math.min( ...amounts ),
				several: new Set( amounts ).size > 1,
			};
}
