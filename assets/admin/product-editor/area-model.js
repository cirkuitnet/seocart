/**
 * The area's hold on the editor: what the record says, the one way anything in the area is changed,
 * and the lock on saving that its lists hold.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { addQueryArgs } from '@wordpress/url';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { lockedMessage, useSaveLock } from './fields';
import { areaState, offSaleOnSave, saveLocked, seocartEdit } from './model';
import { declaration, settings } from './settings';
import { translatedPost } from './translation';

/** The most variants a product may have, as declared. */
export const LIMIT = declaration( 'variants' ).maxItems;

/** The notice the code editor shows while the area's lists keep the product from being saved. */
const LOCKED_NOTICE = 'seocart-area-locked';

/**
 * Reads a post, its `seocart` object as read, and the edit of it.
 *
 * @param {Function} select   The data registry's select.
 * @param {string}   postType The post's type.
 * @param {number}   postId   The post's id.
 * @return {Object} `postType`, `postId`, `saved` and `edits`.
 */
export function selectRecordOf( select, postType, postId ) {
	const core = select( coreStore );

	return {
		postType,
		postId,
		saved: core.getEntityRecord( 'postType', postType, postId )?.[
			settings.property
		],
		edits: core.getEntityRecordEdits( 'postType', postType, postId )?.[
			settings.property
		],
	};
}

/**
 * Reads the post the editor shows, as `selectRecordOf()` reads a post.
 *
 * @param {Function} select The data registry's select.
 * @return {Object} `postType`, `postId`, `saved` and `edits`.
 */
export function selectRecord( select ) {
	const editor = select( editorStore );

	return selectRecordOf(
		select,
		editor.getCurrentPostType(),
		editor.getCurrentPostId()
	);
}

/**
 * Finds the post a translation's commerce data is edited on, from the post's `seocart` object as read
 * and as edited: a new translation names its source in its first edit, which holds while the editor
 * shows another post and `isEditedPostNew()` reads that one.
 *
 * @param {Function}    select The data registry's select.
 * @param {Object|void} saved  The object as read.
 * @param {Object|void} edits  The edit of it.
 * @return {number|undefined} The source post's id, or the post an "add translation" link names on a new post; none on a source post.
 */
function sourcePostOf( select, saved, edits ) {
	return (
		saved?.[ settings.translation.field ] ??
		edits?.[ settings.translation.field ] ??
		( select( editorStore ).isEditedPostNew()
			? translatedPost( window.location.search )
			: undefined )
	);
}

/**
 * Finds the post a translation's commerce data is edited on.
 *
 * @return {number|undefined} The source post's id, or the post an "add translation" link names on a new post; none on a source post.
 */
export function useSourcePost() {
	return useSelect( ( select ) => {
		const { saved, edits } = selectRecord( select );

		return sourcePostOf( select, saved, edits );
	}, [] );
}

/**
 * Keeps the product from being saved while the area's lists wait for the merchant, as `saveLocked()`
 * says of the record as edited.
 *
 * The editor's plugin holds it, since the plugin stays in the code editor, which takes the area away
 * with everything in it: a lock the area held would go with it, and the save it kept back would go
 * through. It judges the edit alone, never the re-read the area asks for, which only says what a
 * save takes off sale. The code editor shows none of the cards that say what waits, so there a
 * notice says why the product cannot be saved, for as long as the lock holds.
 *
 * It reads the product it is given, not the post the editor shows: the editor shows the product's
 * template, or a pattern, when the merchant edits those from the product, and the lock keeps the
 * Save button dimmed there. What the panel of changes waiting would still send, the request refuses.
 *
 * @param {string} postType The product's type.
 * @param {number} postId   The product's id.
 */
export function useAreaSaveLock( postType, postId ) {
	const { saved, edits, source } = useSelect(
		( select ) => {
			const record = selectRecordOf( select, postType, postId );

			return {
				saved: record.saved,
				edits: record.edits,
				source: sourcePostOf( select, record.saved, record.edits ),
			};
		},
		[ postType, postId ]
	);
	const inCodeEditor = useSelect(
		( select ) => select( editorStore ).getEditorMode() === 'text',
		[]
	);
	const { createErrorNotice, removeNotice } = useDispatch( noticesStore );
	const locked = useMemo(
		() =>
			saveLocked(
				areaState( saved, edits, LIMIT ),
				settings.currency.code,
				source
			),
		[ saved, edits, source ]
	);

	// A save these lists would be refused for waits until the merchant has put them right.
	useSaveLock( 'seocart-variants', locked );

	useEffect( () => {
		if ( ! locked || ! inCodeEditor ) {
			return undefined;
		}

		createErrorNotice( lockedMessage(), {
			id: LOCKED_NOTICE,
			isDismissible: false,
		} );

		return () => removeNotice( LOCKED_NOTICE );
	}, [ locked, inCodeEditor, createErrorNotice, removeNotice ] );
}

/**
 * Reads the product's `seocart` object again while the area holds an edit: the variants stored now,
 * which another writer may have added to since the editor read the product.
 *
 * Asked when the area is first edited and whenever the combinations of its options change, so that
 * what a save takes off sale is judged against the latest; an answer to an earlier question is
 * dropped, and none leaves the area judging the variants as the editor read them.
 *
 * @param {string}        postType The post's type.
 * @param {number}        postId   The post's id.
 * @param {boolean}       edited   Whether the area holds an edit.
 * @param {Array<Object>} axes     The options the area names.
 * @return {Object|undefined} The `seocart` object as read again; none while the area holds no edit.
 */
function useStoredNow( postType, postId, edited, axes ) {
	const [ latest, setLatest ] = useState();
	const path = useSelect(
		( select ) =>
			select( coreStore ).getEntityConfig( 'postType', postType )
				?.baseURL,
		[ postType ]
	);
	const asked = JSON.stringify(
		axes.map( ( { key, values } ) => [
			key,
			values.map( ( value ) => value.key ),
		] )
	);

	useEffect( () => {
		setLatest( undefined );

		if ( ! edited || ! path ) {
			return undefined;
		}

		let current = true;

		apiFetch( {
			path: addQueryArgs( `${ path }/${ postId }`, {
				context: 'edit',
				_fields: settings.property,
			} ),
		} )
			.then( ( record ) => {
				if ( current ) {
					setLatest( record?.[ settings.property ] );
				}
			} )
			.catch( () => {} );

		return () => {
			current = false;
		};
	}, [ path, postId, edited, asked ] );

	return edited ? latest : undefined;
}

/**
 * Returns what the area shows, and the two changes it makes.
 *
 * Every change is one edit of the post's `seocart` object through the editor's own entity, so the
 * block editor sends it in the same request as the title and the content. Each is built from the
 * record as it stands when it is made. None is kept for Undo: core's Undo puts back the whole
 * object as it stood before an edit, which after a save is the read, and a save of the read is
 * refused.
 *
 * @return {Object} `saved`, the object as read; `state`, what the area shows; `offSale`, what a save takes off sale, as
 *                  `offSaleOnSave()` says it; updateRow( key, change ), which gives a row's entry to change( entry, read ) and
 *                  takes what it returns; updateRows( entryOfRow ), which takes each row's entry from entryOfRow( row );
 *                  and updateAxes( axes ), which gives the product these axes.
 */
export function useAreaModel() {
	const registry = useRegistry();
	const { postType, postId, saved, edits } = useSelect( selectRecord, [] );
	const { editEntityRecord } = useDispatch( coreStore );
	const state = useMemo(
		() => areaState( saved, edits, LIMIT ),
		[ saved, edits ]
	);
	const latest = useStoredNow( postType, postId, state.edited, state.axes );
	const offSale = useMemo(
		() => offSaleOnSave( state, saved, latest, LIMIT ),
		[ state, saved, latest ]
	);

	const commit = useCallback(
		( record, axes, entries ) => {
			const edit = seocartEdit(
				record.saved,
				record.edits,
				axes,
				entries,
				LIMIT
			);

			editEntityRecord(
				'postType',
				record.postType,
				record.postId,
				{
					// A value equal to the record's drops the edit: nothing is left to send.
					[ settings.property ]:
						Object.keys( edit ).length > 0 ? edit : record.saved,
				},
				{ undoIgnore: true }
			);
		},
		[ editEntityRecord ]
	);

	const updateRow = useCallback(
		( key, change ) => {
			const record = selectRecord( registry.select );
			const { axes, rows } = areaState(
				record.saved,
				record.edits,
				LIMIT
			);

			commit(
				record,
				axes,
				rows.map( ( row ) =>
					row.key === key ? change( row.entry, row.read ) : row.entry
				)
			);
		},
		[ registry, commit ]
	);

	const updateRows = useCallback(
		( entryOfRow ) => {
			const record = selectRecord( registry.select );
			const { axes, rows } = areaState(
				record.saved,
				record.edits,
				LIMIT
			);

			commit( record, axes, rows.map( entryOfRow ) );
		},
		[ registry, commit ]
	);

	const updateAxes = useCallback(
		( axes ) => {
			const record = selectRecord( registry.select );
			const { rows } = areaState(
				record.saved,
				{ ...record.edits, options: axes },
				LIMIT
			);

			commit(
				record,
				axes,
				rows.map( ( { entry } ) => entry )
			);
		},
		[ registry, commit ]
	);

	return {
		saved,
		state,
		offSale,
		updateRow,
		updateRows,
		updateAxes,
	};
}
