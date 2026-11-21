<?php
/**
 * OrderFieldValueFields: the declared shape of a checkout field's value an order keeps
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Order\Domain\OrderFieldValue;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the members of one checkout field's value an order keeps, as every answer that shows an order's field values lists them.
 *
 * Owns one fact: what a client is told of an order's field value. `order.list_field_values`
 * answers a list of these, and a staff screen of an order embeds the same list, so a member is
 * declared here once. The value and a select's option label are personal data: an answer leaves
 * them out for a user who may not see personal data, and they are null once the order is erased.
 * The key, source, section, type, label and locale are what the field was when the order was
 * placed; `current_label` is what it is now, null for a field no longer declared or a key another
 * source declares today.
 *
 * Declarations are data: building them reads nothing.
 *
 * @since 0.2.0
 */
final class OrderFieldValueFields {

	/**
	 * Cannot be called: the class is one static declaration.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the members of one field value, in the order an answer writes them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The members.
	 */
	public static function list(): array {
		return array(
			new FieldSpec(
				name: 'key',
				type: FieldType::String,
				description: 'The checkout field\'s key, as the order keeps it, such as order_note or an extension\'s acme_po_number.',
				label: static fn(): string => __( 'Field', 'seocart' ),
				example: 'acme_po_number',
				required: true,
				max_length: CheckoutField::KEY_MAX_LENGTH
			),
			new FieldSpec(
				name: 'source',
				type: FieldType::String,
				description: 'Who declared the field when the order was placed: core for SEOCart\'s own fields, or the id of the extension that registered it.',
				label: static fn(): string => __( 'Source', 'seocart' ),
				example: 'acme',
				required: true,
				max_length: CheckoutField::KEY_MAX_LENGTH
			),
			self::closed( 'section', 'Where the checkout form showed the field: contact or order.', static fn(): string => __( 'Section', 'seocart' ), array_column( Section::cases(), 'value' ) ),
			self::closed( 'type', 'What the field\'s value was, such as text, textarea, select or checkbox; a ticked checkbox is 1.', static fn(): string => __( 'Type', 'seocart' ), array_column( CheckoutFieldType::cases(), 'value' ) ),
			self::label( 'label', 'The field\'s label as the shopper saw it, in the locale named by `locale`.', static fn(): string => __( 'Label', 'seocart' ), false ),
			self::label( 'current_label', 'The field\'s label as it is declared today, in the request\'s language; null when no field of this key and source is declared any more.', static fn(): string => __( 'Label today', 'seocart' ), true ),
			new FieldSpec(
				name: 'field_value',
				type: FieldType::String,
				description: 'The value the shopper gave: text, a select\'s chosen value, or 1 for a ticked checkbox; null once the order is erased.',
				label: static fn(): string => __( 'Value', 'seocart' ),
				example: 'PO-1234',
				required: true,
				nullable: true,
				max_length: CheckoutField::TEXTAREA_MAX_LENGTH,
				privacy: Privacy::Pii
			),
			new FieldSpec(
				name: 'value_label',
				type: FieldType::String,
				description: 'For a select, the chosen option\'s label as the shopper saw it, in the locale named by `locale`; null for any other type, and once the order is erased.',
				label: static fn(): string => __( 'Value shown', 'seocart' ),
				example: 'Morning',
				required: true,
				nullable: true,
				max_length: OrderFieldValue::LABEL_MAX_LENGTH,
				privacy: Privacy::Pii
			),
			new FieldSpec(
				name: 'locale',
				type: FieldType::String,
				description: 'The locale the labels the order keeps were resolved in: the language of the request that placed the order, which need not be the order\'s own; the order\'s own when the request\'s is no locale an order can keep.',
				label: static fn(): string => __( 'Locale', 'seocart' ),
				example: 'en_GB',
				required: true
			),
		);
	}

	/**
	 * Declares a required text member limited to a set of values, the first the example.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label.
	 * @param string[] $allowed     The values.
	 * @return FieldSpec The member.
	 *
	 * @phpstan-param \Closure(): string $label
	 * @phpstan-param list<string>       $allowed
	 */
	private static function closed( string $name, string $description, \Closure $label, array $allowed ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: $description, label: $label, example: $allowed[0], required: true, allowed: $allowed );
	}

	/**
	 * Declares a required label member, as long as an order keeps a label.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the member's own label.
	 * @param bool     $nullable    Whether it may be null.
	 * @return FieldSpec The member.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function label( string $name, string $description, \Closure $label, bool $nullable ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: $description, label: $label, example: 'Purchase order number', required: true, nullable: $nullable, max_length: OrderFieldValue::LABEL_MAX_LENGTH );
	}
}
