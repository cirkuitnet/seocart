/**
 * The area's hold on the editor: what the record says, and the one way anything in the area is changed.
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
import { addQueryArgs } from '@wordpress/url';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { areaState, offSaleOnSave, seocartEdit } from './model';
import { declaration, settings } from './settings';

/** The most variants a product may have, as declared. */
export const LIMIT = declaration( 'variants' ).maxItems;

/**
 * Reads the post the editor edits, its `seocart` object as read, and the edit of it.
 *
 * @param {Function} select The data registry's select.
 * @return {Object} `postType`, `postId`, `saved` and `edits`.
 */
export function selectRecord( select ) {
	const editor = select( editorStore );
	const core = select( coreStore );
	const postType = editor.getCurrentPostType();
	const postId = editor.getCurrentPostId();

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
