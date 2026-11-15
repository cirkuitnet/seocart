<?php
/**
 * CheckoutFieldType: the kinds of value a checkout field takes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * What a checkout field's value is, which decides how the form asks for it and how the checkout checks it.
 *
 * Owns one fact: the seven types. Every value is a string; an empty string means "not given". A
 * textarea alone may hold a line break. A checkbox's value is `1` when it is ticked. A select's
 * value is one of the values its field lists.
 *
 * Experimental until SEOCart 1.0.
 *
 * @since 0.2.0
 *
 * @api
 */
enum CheckoutFieldType: string {

	/**
	 * One line of text.
	 *
	 * @since 0.2.0
	 */
	case Text = 'text';

	/**
	 * An email address.
	 *
	 * @since 0.2.0
	 */
	case Email = 'email';

	/**
	 * A phone number, as the shopper writes it.
	 *
	 * @since 0.2.0
	 */
	case Tel = 'tel';

	/**
	 * An ISO 3166-1 alpha-2 country code, two upper-case letters.
	 *
	 * @since 0.2.0
	 */
	case Country = 'country';

	/**
	 * Text of several lines.
	 *
	 * @since 0.2.0
	 */
	case Textarea = 'textarea';

	/**
	 * One of the values the field lists.
	 *
	 * @since 0.2.0
	 */
	case Select = 'select';

	/**
	 * A box the shopper ticks: `1` when ticked.
	 *
	 * @since 0.2.0
	 */
	case Checkbox = 'checkbox';

	/**
	 * Tells whether a value of this type may run over several lines.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for a textarea.
	 */
	public function isMultiline(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return self::Textarea === $this;
	}
}
