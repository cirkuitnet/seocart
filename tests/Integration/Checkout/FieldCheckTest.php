<?php
/**
 * Tests that a checkout field's own check judges its value, after every other rule, at the session write and at placement
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * A declaration's own check is the last rule a value meets: it is given a value every other rule of its field accepted, and answers null to accept it or the sentence that says why not; a check that throws refuses the value too.
 *
 * A refusal is the reason `rejected`, with the check's sentence, when it gave one, as the entry's
 * `message`. The session write and the placement judge through the one validator, so both refuse
 * alike. The test declares the built-in fields with one changed: the second address line, given a
 * check that refuses a post office box and throws on a value it cannot judge. No built-in field
 * declares a check.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutRules::checkFault(), answer null before calling the check: no value is rejected;
 * - in CheckoutRules::checkFault(), let what the check throws through: the throwing row ends in
 *   the check's exception instead of a refusal;
 * - in CheckoutRules::faultOf(), call the check before the field's other rules: a value too long,
 *   or holding a refused character, reaches the check.
 *
 * @since 0.2.0
 */
final class FieldCheckTest extends CheckoutTestCase {

	/**
	 * The sentence the test's check refuses a post office box with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUSAL = 'A delivery cannot be sent to a post office box.';

	/**
	 * Every value the test's check was given, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $checked = array();

	/**
	 * Provides values of the second address line: what the write answers, and what the check was given.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, array<string, string>|null, list<string>}> The value, the fault without its path (null when accepted), and the values checked.
	 */
	public static function values(): array {
		return array(
			'accepted by the check'                 => array( 'Flat 3', null, array( 'Flat 3' ) ),
			'refused by the check, with its reason' => array(
				'PO Box 12',
				array(
					'reason'  => 'rejected',
					'message' => self::REFUSAL,
				),
				array( 'PO Box 12' ),
			),
			'a check that throws'                   => array( 'boom', array( 'reason' => 'rejected' ), array( 'boom' ) ),
			'empty, so not given and not checked'   => array( '', null, array() ),
			'too long, so never checked'            => array( 'PO Box ' . str_repeat( '1', 255 ), array( 'reason' => 'too_long' ), array() ),
			'a refused character, so never checked' => array( "PO Box \u{202E}12", array( 'reason' => 'characters' ), array() ),
		);
	}

	/**
	 * Tests one value on the session write: refused or accepted, and checked only once every other rule accepted it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider values
	 *
	 * @param string                     $line2   The value of the billing address's second line.
	 * @param array<string, string>|null $fault   The fault it is refused with, without its path; null when it is accepted.
	 * @param string[]                   $checked The values the check is given.
	 *
	 * @phpstan-param list<string> $checked
	 */
	public function test_the_session_write_runs_the_check_last( string $line2, ?array $fault, array $checked ): void {
		$input = array(
			Carrier::BILLING  => array( 'line2' => $line2 ) + self::wireAddress( Carrier::BILLING, 'GB', 'ada@example.com' ),
			Carrier::SHIPPING => self::wireAddress( Carrier::SHIPPING, 'GB' ),
		);

		try {
			$this->rules( $this->fields() )->validate( $input );
			$this->assertNull( $fault, 'The value was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::InvalidFields, $refused->errorCode() );
			$this->assertSame( array( array( 'field' => Carrier::BILLING . '.line2' ) + (array) $fault ), $refused->details()['fields'] ?? null );
		}

		$this->assertSame( $checked, $this->checked, 'The values the check was given.' );
	}

	/**
	 * Tests the placement: a kept value the check accepts is placed, and one it refuses is answered as the write answers it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_placement_runs_the_check(): void {
		$placed = $this->rules( $this->fields() )->forPlacement( self::session( 'Flat 3' ) );

		$this->assertNotNull( $placed->details->billingAddress );
		$this->assertSame( 'Flat 3', AddressDocument::of( $placed->details->billingAddress )['line2'] );

		try {
			$this->rules( $this->fields() )->forPlacement( self::session( 'PO Box 12' ) );
			$this->fail( 'A kept value the check refuses was placed.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::InvalidFields, $refused->errorCode() );
			$this->assertSame(
				array(
					array(
						'field'   => Carrier::BILLING . '.line2',
						'reason'  => 'rejected',
						'message' => self::REFUSAL,
					),
				),
				$refused->details()['fields'] ?? null
			);
		}

		$this->assertSame( array( 'Flat 3', 'PO Box 12' ), $this->checked, 'The values the check was given.' );
	}

	/**
	 * Returns the built-in fields, the second address line declared with the test's check.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CheckoutField> The fields, in form order.
	 */
	private function fields(): array {
		return array_map(
			fn( CheckoutField $field ): CheckoutField => 'line2' !== $field->key ? $field : new CheckoutField(
				key: $field->key,
				section: $field->section,
				type: $field->type,
				label: $field->label,
				maxLength: $field->maxLength,
				presences: $field->presences,
				autocomplete: $field->autocomplete,
				check: function ( string $value ): ?string {
					$this->checked[] = $value;

					if ( 'boom' === $value ) {
						throw new \RuntimeException( 'The check cannot judge this value.' );
					}

					return str_starts_with( $value, 'PO Box' ) ? self::REFUSAL : null;
				}
			),
			BuiltInFields::all()
		);
	}

	/**
	 * Returns a session as the placement reads it: both addresses, the billing one with a second line, and a payment method.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line2 The billing address's second line.
	 * @return CheckoutSession The session.
	 */
	private static function session( string $line2 ): CheckoutSession {
		$billing = new Address( 'GB', first_name: 'Ada', last_name: 'Lovelace', line1: '12 St James\'s Square', line2: $line2, city: 'London', postcode: 'SW1Y 4JH', email: 'ada@example.com' );

		return new CheckoutSession( 1, new CheckoutDetails( $billing, self::address( 'GB' ), null, 'stub' ), 1, null );
	}
}
