/**
 * The product editor's commerce panel: the SKU, the price, the compare-at price and the weight,
 * and whether the product may be sold.
 *
 * The panel edits the `seocart` object of the product's own REST resource through the editor's
 * entity, so the block editor sends it in the same request as the title and the content, and the
 * product is saved in one call. Only the fields the merchant changes are sent. Everything the panel
 * shows comes from what the plugin prints before this script: the fields and their labels, the
 * base currency and its decimal places, and a sentence for every verdict.
 *
 * On a new post opened from a multilingual plugin's "add translation" link, the first save also
 * names the post it translates and the locale of the language the link asks for, in the fields
 * the plugin names, so the new post joins that post's product in that locale in that same
 * request rather than becoming a product of its own.
 */

/**
 * WordPress dependencies
 *
 * WordPress's own scripts: the build leaves them out and lists them in the .asset.php file, and
 * WordPress loads them before this one, so none of them is installed or listed as a package.
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { TextControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import { formatAmount, parseAmount, parseWholeNumber } from './amount';
import { translatedPost, translationLocale } from './translation';

const settings = window.seocartProductEditor;

/**
 * One field of the panel, whose text is kept while the merchant types and read on every change.
 *
 * An amount or a whole number that cannot be read keeps the product from being saved until it can.
 *
 * @param {Object}   props           The props.
 * @param {Object}   props.field     The field, as the plugin describes it.
 * @param {*}        props.value     Its value: the one being edited, or else the saved one.
 * @param {Function} props.onChange  Receives the value to send.
 * @param {Function} props.onInvalid Receives whether the text cannot be read.
 * @return {Element} The control.
 */
function CommerceField( { field, value, onChange, onInvalid } ) {
	const { code, exponent } = settings.currency;
	const [ draft, setDraft ] = useState( null );

	if ( field.kind === 'text' ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ field.label }
				value={ value ?? '' }
				maxLength={ field.maxChars ?? undefined }
				onChange={ onChange }
			/>
		);
	}

	const isAmount = field.kind === 'amount';
	const read = ( text ) =>
		isAmount ? parseAmount( text, exponent ) : parseWholeNumber( text );
	const shown =
		draft ??
		( isAmount ? formatAmount( value, exponent ) : String( value ?? '' ) );
	const invalid = draft !== null && read( draft ) === undefined;
	const example = isAmount ? formatAmount( 1999, exponent ) : '250';

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={
				isAmount
					? sprintf(
							/* translators: 1: The name of a price, 2: A currency code, such as USD. */
							__( '%1$s (%2$s)', 'seocart' ),
							field.label,
							code
						)
					: field.label
			}
			value={ shown }
			inputMode={ isAmount ? 'decimal' : 'numeric' }
			help={
				invalid
					? sprintf(
							/* translators: %s: An example of a valid value, such as 19.99. */
							__( 'Enter a number such as %s.', 'seocart' ),
							example
						)
					: undefined
			}
			onChange={ ( text ) => {
				const next = read( text );

				setDraft( text );
				onInvalid( next === undefined );

				if ( next !== undefined ) {
					onChange( next );
				}
			} }
			onBlur={ () => {
				if ( draft !== null && read( draft ) !== undefined ) {
					setDraft( null );
				}
			} }
		/>
	);
}

/**
 * The panel, on the product editor only.
 *
 * @return {Element|null} The panel.
 */
function CommercePanel() {
	const { postType, postId, isNew, saved, edits } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const type = editor.getCurrentPostType();
		const id = editor.getCurrentPostId();
		const core = select( coreStore );

		return {
			postType: type,
			postId: id,
			isNew: editor.isEditedPostNew(),
			saved:
				core.getEntityRecord( 'postType', type, id )?.[
					settings.property
				] ?? {},
			edits:
				core.getEntityRecordEdits( 'postType', type, id )?.[
					settings.property
				] ?? {},
		};
	}, [] );
	const { editEntityRecord } = useDispatch( coreStore );
	const { lockPostSaving, unlockPostSaving } = useDispatch( editorStore );
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
		if ( postType === settings.postType && original !== undefined ) {
			editEntityRecord( 'postType', postType, postId, {
				[ settings.property ]: {
					...edits,
					[ translation.field ]: original,
					...( locale === undefined
						? {}
						: { [ translation.locale ]: locale } ),
				},
			} );
		}
		// Once for the screen: the first save carries it, and the saved post then names its product's source.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ postType, postId, original, locale ] );

	if ( postType !== settings.postType ) {
		return null;
	}

	const edit = ( name, value ) =>
		editEntityRecord( 'postType', postType, postId, {
			[ settings.property ]: { ...edits, [ name ]: value },
		} );
	const reason =
		settings.sellability.reasons[ saved[ settings.sellability.name ] ];

	return (
		<PluginDocumentSettingPanel
			name="seocart-commerce"
			title={ settings.title }
			className="seocart-commerce-panel"
		>
			{ settings.fields.map( ( field ) => (
				<CommerceField
					key={ field.name }
					field={ field }
					value={
						field.name in edits
							? edits[ field.name ]
							: saved[ field.name ]
					}
					onChange={ ( value ) => edit( field.name, value ) }
					onInvalid={ ( invalid ) =>
						invalid
							? lockPostSaving( `seocart-${ field.name }` )
							: unlockPostSaving( `seocart-${ field.name }` )
					}
				/>
			) ) }
			{ reason && (
				<p className="seocart-commerce-panel__sellability">
					{ sprintf(
						/* translators: 1: The label "Sale status", 2: A sentence saying whether the product is for sale. */
						__( '%1$s: %2$s', 'seocart' ),
						settings.sellability.label,
						reason
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'seocart-product-editor', { render: CommercePanel } );
