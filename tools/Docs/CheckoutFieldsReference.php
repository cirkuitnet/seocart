<?php
/**
 * CheckoutFieldsReference: generates docs/reference/checkout-fields.md from the built-in checkout fields
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Presence;

/**
 * Documents every built-in checkout field: its key, the section the form shows it in, its type, where its value is sent, the presences a store may choose, the default first, and its longest value.
 *
 * This generator owns docs/reference/checkout-fields.md whole, written from the list of fields
 * it is given, BuiltInFields::all(), in form order. Nothing is skipped.
 *
 * @since 0.2.0
 */
final class CheckoutFieldsReference implements Generator {

	/**
	 * Builds the generator.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField[] $fields The fields to document, in form order.
	 *
	 * @phpstan-param list<CheckoutField> $fields
	 */
	public function __construct( private array $fields ) {
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.2.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'reference-checkout-fields';
	}

	/**
	 * Returns the file this generator owns.
	 *
	 * @since 0.2.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'docs/reference/checkout-fields.md';
	}

	/**
	 * Returns the source items this generator may leave out: none.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> Always empty.
	 */
	public function expectedSkips(): array {
		return array();
	}

	/**
	 * Renders the document.
	 *
	 * @since 0.2.0
	 *
	 * @param string $current The current content. Ignored: the file is generated whole.
	 * @return GenerationResult The document. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		$lines = array(
			'# Checkout fields',
			'',
			FieldDocs::generatedNotice( 'the built-in checkout fields in `src/Checkout/Domain/Fields/BuiltInFields.php`' ),
			'',
			'These are the fields the checkout asks a shopper for out of the box, in the order the form shows them. A store chooses, for each, whether it is hidden, optional or required, among the presences the field allows; the first is the default, and a field that allows one is locked. The store\'s choices are read and changed with `checkout.get_fields` and `checkout.update_fields`, and a storefront renders the fields in force from `checkout.get_form`.',
			'',
			'The session write (`checkout.update_session`) refuses a value for a hidden field and any value its field does not accept, with `checkout.invalid_fields`, and never asks for a required field; the placement asks for every required field without a value, with `checkout.session_incomplete`. An `address` field is a member of the billing and of the shipping address alike; the email address is a member of the billing address alone. No value may hold a control character or an invisible formatting character, but the zero width non-joiner and joiner.',
		);

		foreach ( $this->fields as $field ) {
			array_push(
				$lines,
				'',
				'## `' . $field->key . '`',
				'',
				'- Section: ' . $field->section->value,
				'- Type: ' . $field->type->value,
				'- Sent in: `' . Carrier::of( $field )->value . '`',
				'- Presences (default first): ' . implode( ', ', array_map( static fn( Presence $presence ): string => $presence->value, $field->presences ) ),
				'- Longest value: ' . $field->maxLength
			);
		}

		return new GenerationResult( implode( "\n", $lines ) . "\n" );
	}
}
