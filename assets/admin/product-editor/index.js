/**
 * The product editor: the full-width Product data area under the content, and the Product summary panel.
 *
 * The area is a locked block the post type's template places and this script keeps in place; it
 * saves nothing of its own, and edits the `seocart` object of the product's own REST resource
 * through the editor's entity, so the block editor sends it in the same request as the title and
 * the content, and the product is saved in one call. Everything it shows comes from what the
 * plugin prints before this script and from the record: the fields and their labels, the
 * currencies the store offers with their decimal places, and a sentence for every verdict.
 *
 * While the area locks saving, the Save button is dimmed, Ctrl+S sends nothing, and a save asked for
 * some other way, such as the one WordPress makes for a draft's preview beside a meta box, is refused.
 * The lock the area's lists hold is held by the editor's plugin, which the code editor leaves in
 * place, and not by the block, which it takes away; and it is kept while the editor shows the
 * product's template or a pattern. The dimmed button can still be pressed, and the panel it opens
 * for the changes waiting saves the product without asking whether saving is locked, as it does
 * from the product's own editor with a pattern changed beside it. So the write itself is refused,
 * at the editor's requests: one to the product's route that carries the area's lists, while the
 * lock is held.
 *
 * On a new post opened from a multilingual plugin's "add translation" link, the first save also
 * names the post it translates and the locale of the language the link asks for, in the fields
 * the plugin names, so the new post joins that post's product in that locale in that same
 * request rather than becoming a product of its own.
 */

/**
 * WordPress dependencies
 *
 * WordPress's own scripts: the build leaves them out and lists them in the .asset.json file, and
 * WordPress loads them before this one, so none of them is installed or listed as a package.
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import {
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import apiFetch from '@wordpress/api-fetch';
import { registerBlockType } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import {
	select as registrySelect,
	useDispatch,
	useSelect,
} from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { ProductDataArea } from './area';
import { useAreaSaveLock } from './area-model';
import { isHeld, lockedMessage, refuseSavingWhileLocked } from './fields';
import { useKeepArea } from './keep-area';
import { refusingLockedWrites } from './model';
import { settings } from './settings';
import { ProductSummaryPanel } from './summary';
import { translatedPost, translationLocale } from './translation';
import './style.css';

/**
 * The block's edit component. A preview, such as a revision's, edits nothing and shows nothing.
 *
 * @return {Element} The block.
 */
function ProductDataEdit() {
	const blockProps = useBlockProps();
	const preview = useSelect(
		( select ) => select( blockEditorStore ).getSettings().isPreviewMode,
		[]
	);

	return <div { ...blockProps }>{ ! preview && <ProductDataArea /> }</div>;
}

registerBlockType( settings.block, {
	apiVersion: 3,
	title: __( 'Product data', 'seocart' ),
	category: 'widgets',
	icon: 'products',
	attributes: {
		lock: { type: 'object', default: { move: true, remove: true } },
		align: { type: 'string', default: 'full' },
	},
	supports: {
		html: false,
		multiple: false,
		reusable: false,
		inserter: false,
		customClassName: false,
		lock: false,
		align: [ 'full' ],
	},
	edit: ProductDataEdit,
	save: () => null,
} );

/**
 * Names, on a new translation's first save, the post it translates and its locale.
 */
function useFirstSaveOfATranslation() {
	const { postId, isNew, edits } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const id = editor.getCurrentPostId();

		return {
			postId: id,
			isNew: editor.isEditedPostNew(),
			edits:
				select( coreStore ).getEntityRecordEdits(
					'postType',
					settings.postType,
					id
				)?.[ settings.property ] ?? {},
		};
	}, [] );
	const { editEntityRecord } = useDispatch( coreStore );
	const { translation } = settings;
	const original = isNew
		? translatedPost( window.location.search )
		: undefined;
	const locale =
		original === undefined
			? undefined
			: translationLocale(
					window.location.search,
					translation.languages
				);

	useEffect( () => {
		// Not kept for Undo, which would take the post off its product, as the area's own edits are not.
		if ( original !== undefined ) {
			editEntityRecord(
				'postType',
				settings.postType,
				postId,
				{
					[ settings.property ]: {
						...edits,
						[ translation.field ]: original,
						...( locale === undefined
							? {}
							: { [ translation.locale ]: locale } ),
					},
				},
				{ undoIgnore: true }
			);
		}
		// Once for the screen: the first save carries it, and the saved post then names its product's source.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postId, original, locale ] );
}

/**
 * What the script adds to the editor besides the block, in the visual editor and in the code editor.
 *
 * @return {Element} The panel.
 */
function ProductEditor() {
	useKeepArea( settings.block );
	useFirstSaveOfATranslation();

	return <ProductSummaryPanel />;
}

/**
 * Holds the area's lock on saving over a product.
 *
 * @param {Object} props          The component's props.
 * @param {string} props.postType The product's type.
 * @param {number} props.postId   The product's id.
 * @return {null} Nothing.
 */
function ProductSaveLock( { postType, postId } ) {
	useAreaSaveLock( postType, postId );

	return null;
}

/**
 * The product the editor showed, kept for the page: the editor mounts its plugins again when it
 * shows another post, so the plugin cannot keep it.
 *
 * @type {{postType: string, postId: number}|undefined}
 */
let product;

/**
 * The plugin, on the product editor only, and its lock on saving wherever the editor is once it has
 * shown the product: the lock dims the Save button of the template editor and of a pattern, and its
 * notice says why, while the refusal at the request (`refusingLockedWrites()`) keeps their panel of
 * changes waiting from saving the product.
 *
 * @return {Element} What it adds.
 */
function ProductEditorPlugin() {
	const { postType, postId } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postType: editor.getCurrentPostType(),
			postId: editor.getCurrentPostId(),
		};
	}, [] );
	const shown = postType === settings.postType;

	if ( shown ) {
		product = { postType, postId };
	}

	return (
		<>
			{ product && <ProductSaveLock { ...product } /> }
			{ shown && <ProductEditor /> }
		</>
	);
}

registerPlugin( 'seocart-product-editor', { render: ProductEditorPlugin } );

addFilter(
	'editor.preSavePost',
	'seocart/product-editor/save-lock',
	refuseSavingWhileLocked
);

apiFetch.use(
	refusingLockedWrites(
		isHeld,
		// The product the editor showed, and its route once the editor knows the post type's.
		() => {
			const base = product
				? registrySelect( coreStore ).getEntityConfig(
						'postType',
						product.postType
					)?.baseURL
				: undefined;

			return base && `${ base }/${ product.postId }`;
		},
		settings.property,
		lockedMessage
	)
);
