/**
 * The area's text fields, whose text is kept while the merchant types and read on every change.
 *
 * Text that cannot be read keeps the product from being saved until it can. A field emptied while
 * the merchant types holds the value it had when they came into it or a successful save carried
 * since, or the value a running save carries, never what its text read, until they leave it, and
 * keeps the product from being saved until then.
 */

/**
 * WordPress dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies */
import { TextControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
/* eslint-enable import/no-unresolved, import/no-extraneous-dependencies */

/**
 * Internal dependencies
 */
import {
	currencySymbol,
	formatAmount,
	parseAmount,
	parseWholeNumber,
} from './amount';
import { committedOnLeaving, committedOnTyping } from './draft';
import { LOCALE } from './settings';

/** The names of the locks on saving the area holds now. */
const held = new Set();

/**
 * Keeps the product from being saved while a condition holds: by its Save button, and by a preview,
 * which saves a draft whenever an autosave may run.
 *
 * @param {string}  name   A name for the lock, its own.
 * @param {boolean} locked Whether saving is locked.
 */
export function useSaveLock( name, locked ) {
	const {
		lockPostSaving,
		unlockPostSaving,
		lockPostAutosaving,
		unlockPostAutosaving,
	} = useDispatch( editorStore );

	useEffect( () => {
		if ( ! locked ) {
			return undefined;
		}

		held.add( name );
		lockPostSaving( name );
		lockPostAutosaving( name );

		return () => {
			held.delete( name );
			unlockPostSaving( name );
			unlockPostAutosaving( name );
		};
	}, [
		name,
		locked,
		lockPostSaving,
		unlockPostSaving,
		lockPostAutosaving,
		unlockPostAutosaving,
	] );
}

/**
 * Says why the area keeps the product from being saved.
 *
 * @return {string} The sentence.
 */
export function lockedMessage() {
	return __(
		'The Product data area has something to put right before the product can be saved.',
		'seocart'
	);
}

/**
 * Refuses to save the product while the area holds a lock, whatever asked for the save: the
 * editor's `editor.preSavePost` filter.
 *
 * The Save button and the autosave keep to the locks, but WordPress saves a draft for its preview
 * regardless when a meta box is on the screen. An autosave goes through: it sends the title, the
 * excerpt and the content, and nothing of the area.
 *
 * @param {Object} edits   The edits the save sends.
 * @param {Object} options The save's options.
 * @return {Object} The edits, unchanged.
 */
export function refuseSavingWhileLocked( edits, options ) {
	if ( held.size > 0 && ! options?.isAutosave ) {
		throw new Error( lockedMessage() );
	}

	return edits;
}

/**
 * A text control over a value, with what the merchant is typing kept until it can be read.
 *
 * @param {Object}   props             The props; the others go to the control.
 * @param {*}        props.value       The value.
 * @param {Function} props.format      Writes the value as text.
 * @param {Function} props.parse       Reads text: the value, or undefined when it is not one.
 * @param {Function} props.onCommit    Receives each value read.
 * @param {string}   props.invalidHelp What the field says of text that cannot be read.
 * @param {string}   [props.help]      What the field says otherwise.
 * @return {Element} The control.
 */
export function DraftField( {
	value,
	format,
	parse,
	onCommit,
	invalidHelp,
	help,
	...control
} ) {
	const lock = useInstanceId( DraftField, 'seocart-field' );
	const registry = useRegistry();
	const [ draft, setDraft ] = useState( null );
	const focused = useRef();
	const sending = useRef( null );
	const saving = useSelect(
		( select ) =>
			select( editorStore ).isSavingPost() &&
			! select( editorStore ).isAutosavingPost(),
		[]
	);
	const invalid = draft !== null && parse( draft ) === undefined;
	const emptied = draft !== null && draft.trim() === '';

	// A field emptied while the merchant types shows nothing, but it still holds a value, as said
	// below: a save now would keep a value the field does not show.
	useSaveLock( lock, invalid || emptied );

	/**
	 * Commits what the field's text reads as, as each keystroke does. Emptied, the field holds the
	 * value the running save carries while a save runs, and the value it kept otherwise.
	 *
	 * @param {string} text The text.
	 */
	const commitTyped = ( text ) => {
		const kept =
			sending.current === null ? focused.current : sending.current.value;
		const next = committedOnTyping( text, parse, kept );

		if ( next !== undefined ) {
			onCommit( next );
		}
	};

	// The value an emptied field holds is the one it had when the merchant came into it, or the one a
	// successful save carried since, or, while a save runs, the one that save carries: never what its
	// text read. A save made with Ctrl+S while the merchant stays in the field stores what they typed,
	// and emptying the field after it, or while it runs, must keep that, not bring back what the save
	// replaced: the field can go away before the answer comes, as the code editor takes it, and what it
	// committed last is then what the next save sends, beside anything else the first one carried.
	// The value a save carries is the one the field had as the save began: WordPress takes the edits it
	// sends as it starts saving, and keeps what the merchant types while the request runs as edits
	// still to save. So the value is remembered as a save begins, and kept once the save has
	// succeeded; taken as the save ends, it would be whatever the text read by then, such as 25 of a
	// 25.99 being erased. A save that failed stored nothing: the field holds what it held before that
	// save, and a field emptied while it ran commits that value in place of the one the save carried.
	// An autosave is not watched: it stores nothing of the area, and the field may then read only the
	// start of what the merchant is typing, such as 2 of 20.00.
	useEffect( () => {
		if ( saving ) {
			// Held in an object, so that a field without a value is remembered too.
			sending.current = { value };
		} else if ( sending.current === null ) {
			// The field first shows, and no save is running.
			focused.current = value;
		} else {
			const sent = sending.current.value;

			sending.current = null;

			if ( registry.select( editorStore ).didPostSaveRequestSucceed() ) {
				focused.current = sent;
			}

			if ( emptied ) {
				commitTyped( draft );
			}
		}
		// Run as a save begins and as it ends (and as the field first shows), never as the value changes
		// keystroke by keystroke.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ saving ] );

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			{ ...control }
			value={ draft ?? format( value ) }
			aria-invalid={ invalid || undefined }
			help={ invalid ? invalidHelp : help }
			onFocus={ () => {
				focused.current = value;
			} }
			onChange={ ( text ) => {
				setDraft( text );
				commitTyped( text );
			} }
			onBlur={ () => {
				const left = committedOnLeaving( draft, parse );

				setDraft( left.draft );

				if ( left.commit !== undefined ) {
					onCommit( left.commit );
				}
			} }
		/>
	);
}

/**
 * A field for an amount in minor units of a currency.
 *
 * @param {Object}                           props          The props; the others go to the control.
 * @param {{code: string, exponent: number}} props.currency The currency.
 * @return {Element} The control.
 */
export function AmountField( { currency: { code, exponent }, ...props } ) {
	return (
		<div className="seocart-amount-field">
			<span className="seocart-affix" aria-hidden="true">
				{ currencySymbol( code, LOCALE ) }
			</span>
			<DraftField
				inputMode="decimal"
				{ ...props }
				format={ ( minor ) => formatAmount( minor, exponent ) }
				parse={ ( text ) => parseAmount( text, exponent ) }
				invalidHelp={ sprintf(
					/* translators: %s: An example of a valid value, such as 19.99. */
					__( 'Enter a number such as %s.', 'seocart' ),
					formatAmount( 1999, exponent )
				) }
			/>
		</div>
	);
}

/**
 * A field for a whole number, such as a weight or a count.
 *
 * @param {Object} props           The props; the others go to the control.
 * @param {number} [props.maximum] The largest number the field takes.
 * @return {Element} The control.
 */
export function WholeNumberField( { maximum, ...props } ) {
	return (
		<DraftField
			inputMode="numeric"
			{ ...props }
			format={ ( number ) => String( number ?? '' ) }
			parse={ ( text ) => {
				const number = parseWholeNumber( text );

				return number > maximum ? undefined : number;
			} }
			invalidHelp={ sprintf(
				/* translators: %s: An example of a valid value, such as 250. */
				__( 'Enter a number such as %s.', 'seocart' ),
				'250'
			) }
		/>
	);
}
