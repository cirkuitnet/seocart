<?php
/**
 * Tests what a checkout field's declaration refuses
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;

/**
 * A declaration that breaks a rule is refused when it is built, so no surface compiles one: a key that is not snake_case of at most 48 characters, no presence, options on a type that is not a select, an option longer than the field's longest value, more than 50 options, a length beyond its type's.
 *
 * Planted violation: in CheckoutField::checkLength(), allow 2,000 characters to every type: the
 * one-line field of 256 characters is accepted, and its row fails.
 *
 * @since 0.2.0
 */
final class CheckoutFieldTest extends TestCase {

	/**
	 * Provides declarations the constructor refuses, each with what is wrong.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{\Closure(): CheckoutField}> The declarations.
	 */
	public static function refused(): array {
		$label = static fn(): string => 'Label';

		return array(
			'a key that is not snake_case'           => array( static fn(): CheckoutField => new CheckoutField( 'PoNumber', Section::Order, CheckoutFieldType::Text, $label, 20, array( Presence::Optional ) ) ),
			'a key of 49 characters'                 => array( static fn(): CheckoutField => new CheckoutField( str_repeat( 'a', 49 ), Section::Order, CheckoutFieldType::Text, $label, 20, array( Presence::Optional ) ) ),
			'no presence'                            => array( static fn(): CheckoutField => new CheckoutField( 'po_number', Section::Order, CheckoutFieldType::Text, $label, 20, array() ) ),
			'a presence twice'                       => array( static fn(): CheckoutField => new CheckoutField( 'po_number', Section::Order, CheckoutFieldType::Text, $label, 20, array( Presence::Optional, Presence::Optional ) ) ),
			'options on a text field'                => array( static fn(): CheckoutField => new CheckoutField( 'po_number', Section::Order, CheckoutFieldType::Text, $label, 20, array( Presence::Optional ), options: array( 'a' => $label ) ) ),
			'an option longer than its value may be' => array( static fn(): CheckoutField => new CheckoutField( 'slot', Section::Order, CheckoutFieldType::Select, $label, 2, array( Presence::Required ), options: array( 'morning' => $label ) ) ),
			'51 options'                             => array( static fn(): CheckoutField => new CheckoutField( 'slot', Section::Order, CheckoutFieldType::Select, $label, 20, array( Presence::Optional ), options: array_fill_keys( array_map( static fn( int $n ): string => 'o' . $n, range( 1, 51 ) ), $label ) ) ),
			'a one-line field of 256 characters'     => array( static fn(): CheckoutField => new CheckoutField( 'po_number', Section::Order, CheckoutFieldType::Text, $label, 256, array( Presence::Optional ) ) ),
			'a textarea of 2,001 characters'         => array( static fn(): CheckoutField => new CheckoutField( 'note', Section::Order, CheckoutFieldType::Textarea, $label, 2001, array( Presence::Optional ) ) ),
			'a length of 0'                          => array( static fn(): CheckoutField => new CheckoutField( 'po_number', Section::Order, CheckoutFieldType::Text, $label, 0, array( Presence::Optional ) ) ),
		);
	}

	/**
	 * Tests that a declaration breaking a rule is refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refused
	 *
	 * @param \Closure $build Builds the declaration.
	 */
	public function test_a_declaration_breaking_a_rule_is_refused( \Closure $build ): void {
		$this->expectException( \InvalidArgumentException::class );

		$build();
	}

	/**
	 * Tests that a select listing no option is refused, with a sentence of its own: no value could ever answer it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_select_without_options_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The checkout field slot is a select and lists no option, so no value could answer it.' );

		new CheckoutField( 'slot', Section::Order, CheckoutFieldType::Select, static fn(): string => 'Slot', 20, array( Presence::Optional ) );
	}

	/**
	 * Tests the limits themselves, accepted: a key of 48 characters, 50 options, a one-line field of 255 and a textarea of 2,000; and the default presence is the first, and a field with one presence is locked.
	 *
	 * @since 0.2.0
	 */
	public function test_the_limits_are_accepted(): void {
		$label  = static fn(): string => 'Label';
		$select = new CheckoutField( str_repeat( 'a', 48 ), Section::Order, CheckoutFieldType::Select, $label, 255, array( Presence::Hidden, Presence::Required ), options: array_fill_keys( array_map( static fn( int $n ): string => 'o' . $n, range( 1, 50 ) ), $label ) );
		$note   = new CheckoutField( 'note', Section::Order, CheckoutFieldType::Textarea, $label, 2000, array( Presence::Required ) );

		$this->assertSame( array( Presence::Hidden, false, true, false ), array( $select->defaultPresence(), $select->isLocked(), $select->allows( Presence::Required ), $select->allows( Presence::Optional ) ) );
		$this->assertSame( array( 50, true ), array( count( $select->options ), $note->isLocked() ) );
	}
}
