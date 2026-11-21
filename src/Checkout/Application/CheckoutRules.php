<?php
/**
 * CheckoutRules: the checkout fields in force, the values they accept, and the order limits, as this store set them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Domain\Fields\CheckoutText;
use SEOCart\Checkout\Domain\Fields\CompiledFields;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles the checkout fields with the store's presence choices, once a request, and judges the values a shopper gives against them.
 *
 * Owns one fact: what the checkout accepts. It is the one reader of the `checkout` settings group
 * and the one validator of a checkout field's value; the session write, the placement, the form
 * the storefront renders and the staff list all ask here.
 *
 * - current() reads the group once a request, with one SettingsStore::values() call that also
 *   names the settings the kernel hands it: the `inventory` group's, which the calculation reads
 *   next on the same paths, so the two groups are primed by one statement. Nothing is kept beyond
 *   the request, and nothing is read before the rules are first asked for.
 * - validate() judges every value a session write gives, before anything is written, and answers
 *   every fault at once. It never asks for a required field: a single-page form saves an address
 *   before its last field is typed.
 * - forPlacement() judges the session a placement read, once: what a required field lacks, what a
 *   hidden field held, which it leaves out, and what the declarations refuse now.
 *
 * A value is judged by the request's own values and the store's declarations alone: no refusal
 * reads a user, a customer or an order, and none names a value, only the field and why. The last
 * rule a value meets is its declaration's own check, when it has one: given a value every other
 * rule accepted, it answers null, or the sentence that says why it refuses it.
 *
 * @since 0.2.0
 */
final class CheckoutRules {

	/**
	 * The reason of a value for a field the checkout does not ask for: hidden, undeclared, or not carried where it was sent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_ACCEPTED = 'not_accepted';

	/**
	 * The reason of a value longer than its field allows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TOO_LONG = 'too_long';

	/**
	 * The reason of a value holding a character the checkout refuses (CheckoutText).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CHARACTERS = 'characters';

	/**
	 * The reason of a value that is not what its type is: an email address, a country code, a ticked box.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FORMAT = 'format';

	/**
	 * The reason of a select's value that is not one of its options.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_AN_OPTION = 'not_an_option';

	/**
	 * The value of a ticked checkbox.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TICKED = '1';

	/**
	 * The reason of a value its declaration's own check refuses; the check's sentence, when it gave one, is the fault's message.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REJECTED = 'rejected';

	/**
	 * The store's settings.
	 *
	 * @since 0.2.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * The settings read with the group: the ones the calculation reads next.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Setting>
	 */
	private array $alongside;

	/**
	 * The declared fields, in form order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<CheckoutField>
	 */
	private array $fields;

	/**
	 * The fields with their presences, once read.
	 *
	 * @since 0.2.0
	 *
	 * @var CompiledFields|null
	 */
	private ?CompiledFields $compiled = null;

	/**
	 * The order limits, by currency code, once read.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{max: int|null, min: int|null}>
	 */
	private array $limits = array();

	/**
	 * Creates the rules. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param SettingsStore   $store     The store's settings.
	 * @param Setting[]       $alongside The settings read in the same statement: the ones the
	 *                                   calculation reads next.
	 * @param CheckoutField[] $fields    The declared fields, in form order.
	 *
	 * @phpstan-param list<Setting>       $alongside
	 * @phpstan-param list<CheckoutField> $fields
	 */
	public function __construct( SettingsStore $store, array $alongside, array $fields ) {
		$this->store     = $store;
		$this->alongside = $alongside;
		$this->fields    = $fields;
	}

	/**
	 * Returns the fields with the presence each has in this store, reading the store's choices the first time it is asked in a request.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `settings.stored_value_invalid` when a setting of the group holds a value
	 *                        its check refuses.
	 *
	 * @return CompiledFields The fields.
	 */
	public function current(): CompiledFields {
		if ( null === $this->compiled ) {
			$values = $this->store->values( array_merge( CheckoutSettings::settings(), $this->alongside ) );

			$this->limits   = CheckoutSettings::limits( (string) $values[ CheckoutSettings::ORDER_LIMITS ] );
			$this->compiled = new CompiledFields( $this->fields, CheckoutSettings::presences( (string) $values[ CheckoutSettings::FIELD_PRESENCE ] ) );
		}

		return $this->compiled;
	}

	/**
	 * Returns the declared fields, in form order, without reading the store's choices.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CheckoutField> The fields.
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Returns the order limits, read with the fields.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{max: int|null, min: int|null}> The limits, by currency code; a currency without an entry has none.
	 */
	public function orderLimits(): array {
		$this->current();

		return $this->limits;
	}

	/**
	 * Refuses a session write that gives a value the checkout does not accept, naming every fault at once.
	 *
	 * Every value given is judged, the addresses' members in form order, then any member no field
	 * of that address is: its field must be in force and carried by that address, and its value
	 * within its length, plain text (CheckoutText), what its type is, and then what its
	 * declaration's own check accepts. An empty value is not given and never a fault, but for the
	 * country: an address is not held without one (Address). A required field is not asked for here.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.invalid_fields`, with the detail `fields`: a list of
	 *                        `{ field, reason }`, the wire path and why, one per value refused, with
	 *                        the check's sentence as `message` when a check refused it with one.
	 *
	 * @param array<string, mixed> $input The prepared input of `checkout.update_session`.
	 */
	public function validate( array $input ): void {
		$fields = $this->current();
		$faults = array();

		foreach ( array( Carrier::BILLING, Carrier::SHIPPING ) as $address ) {
			if ( isset( $input[ $address ] ) ) {
				$faults = array_merge( $faults, $this->addressFaults( $fields, $address, (array) $input[ $address ] ) );
			}
		}

		self::refuseFaults( $faults );
	}

	/**
	 * Returns a placement's session as the rules in force let it be placed: its values for hidden fields left out, every required field given, every value one the declarations accept.
	 *
	 * Called once, on the session the placement read, before it is priced: the order, the gateway's
	 * billing contact and the kept answer are all built from what this answers. The checkout must
	 * have both addresses and a payment method (CheckoutDetails::missingForPlacement()); each
	 * address then needs a value for every required field it carries. A value for a field hidden
	 * or no longer declared is left out; a value kept from before a declaration changed, which it
	 * now refuses, is refused.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.session_incomplete` with the detail `missing`, the wire paths of
	 *                        what is missing: the details first, then each required field, billing
	 *                        before shipping; `checkout.invalid_fields`, as validate() answers it, for
	 *                        a value kept that the declarations refuse.
	 *
	 * @param CheckoutSession|null $session The session the placement read, or null when the cart has none.
	 * @return CheckoutSession The session to place, its addresses holding only the values in force.
	 */
	public function forPlacement( ?CheckoutSession $session ): CheckoutSession {
		if ( null === $session ) {
			CodedException::raise( CheckoutError::SessionIncomplete, array(), array( 'missing' => array( Carrier::BILLING, Carrier::SHIPPING, 'payment_method_key' ) ) );
		}

		$fields    = $this->current();
		$addresses = array(
			Carrier::BILLING  => $session->details->billingAddress,
			Carrier::SHIPPING => $session->details->shippingAddress,
		);
		$missing   = $session->details->missingForPlacement();
		$faults    = array();
		$placed    = array();

		foreach ( $addresses as $address => $held ) {
			if ( null === $held ) {
				continue;
			}

			$document           = self::inForce( $fields, $address, AddressDocument::of( $held ) );
			$missing            = array_merge( $missing, self::missingFrom( $fields, $address, $document ) );
			$faults             = array_merge( $faults, $this->addressFaults( $fields, $address, $document ) );
			$placed[ $address ] = AddressDocument::toAddress( $document );
		}

		if ( array() !== $missing ) {
			CodedException::raise( CheckoutError::SessionIncomplete, array(), array( 'missing' => $missing ) );
		}

		self::refuseFaults( $faults );

		return new CheckoutSession(
			$session->cartId,
			new CheckoutDetails( $placed[ Carrier::BILLING ] ?? null, $placed[ Carrier::SHIPPING ] ?? null, $session->details->shippingMethodKey, $session->details->paymentMethodKey ),
			$session->quotedAtCartVersion,
			$session->quotes
		);
	}

	/**
	 * Returns the faults of an address's values: each given value its field does not accept, by wire path, in form order, then each member no field of the address is.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields       $fields   The fields in force.
	 * @param string               $address  Carrier::BILLING or Carrier::SHIPPING.
	 * @param array<string, mixed> $document The address's values, by member.
	 * @return list<array{field: string, reason: string, message?: string}> The faults.
	 */
	private function addressFaults( CompiledFields $fields, string $address, array $document ): array {
		$faults  = array();
		$carried = array();

		foreach ( $fields->all() as $field ) {
			if ( ! in_array( $address, Carrier::addressesOf( $field ), true ) ) {
				continue;
			}

			$carried[ $field->key ] = true;
			$fault                  = self::faultOf( $fields, $field, $address . '.' . $field->key, $document[ $field->key ] ?? '' );

			if ( null !== $fault ) {
				$faults[] = $fault;
			}
		}

		foreach ( array_diff_key( $document, $carried ) as $member => $value ) {
			if ( '' !== $value ) {
				$faults[] = self::fault( $address . '.' . $member, self::NOT_ACCEPTED );
			}
		}

		return $faults;
	}

	/**
	 * Returns the fault of a value given for a field, or null when it is accepted or not given.
	 *
	 * The field's own rules judge it first (reasonFor()); a value they accept then meets its
	 * declaration's own check (checkFault()), the last rule.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields $fields The fields in force.
	 * @param CheckoutField  $field  The field.
	 * @param string         $path   The value's wire path, such as billing_address.phone.
	 * @param mixed          $value  The value given; an empty string for none.
	 * @return array{field: string, reason: string, message?: string}|null The fault, or null.
	 */
	private static function faultOf( CompiledFields $fields, CheckoutField $field, string $path, mixed $value ): ?array {
		$reason = self::reasonFor( $fields, $field, $value );

		if ( null !== $reason ) {
			return self::fault( $path, $reason );
		}

		return is_string( $value ) && '' !== $value ? self::checkFault( $field, $path, $value ) : null;
	}

	/**
	 * Returns the fault of a value its declaration's own check refuses, or null when the field has no check or the check accepts the value.
	 *
	 * The check is given a value every other rule of its field accepted, and answers null, or the
	 * sentence that says why it refuses it, which the fault carries as its message. A check that
	 * throws refuses the value too, with no message: what it threw is not for the shopper to read.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @param string        $path  The value's wire path.
	 * @param string        $value The value, which every other rule of the field accepted.
	 * @return array{field: string, reason: string, message?: string}|null The fault, or null.
	 */
	private static function checkFault( CheckoutField $field, string $path, string $value ): ?array {
		if ( null === $field->check ) {
			return null;
		}

		try {
			$refusal = ( $field->check )( $value );
		} catch ( \Throwable ) {
			return self::fault( $path, self::REJECTED );
		}

		return null === $refusal ? null : self::fault( $path, self::REJECTED ) + array( 'message' => (string) $refusal );
	}

	/**
	 * Returns why a field's own rules refuse a value given for it, or null when they accept it or it is not given.
	 *
	 * In the order the checkout judges a value: whether the field is asked for, its length, its
	 * characters, then its type.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields $fields The fields in force.
	 * @param CheckoutField  $field  The field.
	 * @param mixed          $value  The value given; an empty string for none.
	 * @return string|null The reason, or null.
	 */
	private static function reasonFor( CompiledFields $fields, CheckoutField $field, mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return self::FORMAT;
		}

		if ( '' === $value ) {
			// An address is not held without its country: an empty one is no country at all.
			return CheckoutFieldType::Country === $field->type ? self::FORMAT : null;
		}

		if ( Presence::Hidden === $fields->presenceOf( $field ) ) {
			return self::NOT_ACCEPTED;
		}

		if ( mb_strlen( $value ) > $field->maxLength ) {
			return self::TOO_LONG;
		}

		if ( ! CheckoutText::accepts( $value, $field->type->isMultiline() ) ) {
			return self::CHARACTERS;
		}

		return self::typeFault( $field, $value );
	}

	/**
	 * Returns why a value is not what its field's type is, or null when it is.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @param string        $value The value, plain text within its length.
	 * @return string|null FORMAT, NOT_AN_OPTION, or null.
	 */
	private static function typeFault( CheckoutField $field, string $value ): ?string {
		return match ( $field->type ) {
			CheckoutFieldType::Email    => false === is_email( $value ) ? self::FORMAT : null,
			CheckoutFieldType::Country  => self::isCountry( $value ) ? null : self::FORMAT,
			CheckoutFieldType::Select   => isset( $field->options[ $value ] ) ? null : self::NOT_AN_OPTION,
			CheckoutFieldType::Checkbox => self::TICKED === $value ? null : self::FORMAT,
			default                     => null,
		};
	}

	/**
	 * Tells whether a value is a country an address can be held in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return bool True when Address accepts it as a country.
	 */
	private static function isCountry( string $value ): bool {
		try {
			new Address( $value );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns an address's values with each member no field in force of that address carries emptied: hidden, undeclared, or another address's.
	 *
	 * The country, which an address is not held without, is a field locked required, so it stays.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields        $fields   The fields in force.
	 * @param string                $address  Carrier::BILLING or Carrier::SHIPPING.
	 * @param array<string, string> $document The address, as AddressDocument writes it.
	 * @return array<string, string> The address's values in force.
	 */
	private static function inForce( CompiledFields $fields, string $address, array $document ): array {
		foreach ( $document as $member => $value ) {
			$field = $fields->field( $member );
			$kept  = null !== $field
				&& in_array( $address, Carrier::addressesOf( $field ), true )
				&& Presence::Hidden !== $fields->presenceOf( $field );

			if ( ! $kept ) {
				$document[ $member ] = '';
			}
		}

		return $document;
	}

	/**
	 * Returns the wire paths of the required fields an address carries and gives no value for.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields        $fields   The fields in force.
	 * @param string                $address  Carrier::BILLING or Carrier::SHIPPING.
	 * @param array<string, string> $document The address's values in force.
	 * @return list<string> The paths, in form order.
	 */
	private static function missingFrom( CompiledFields $fields, string $address, array $document ): array {
		$missing = array();

		foreach ( $fields->all() as $field ) {
			if ( Presence::Required === $fields->presenceOf( $field ) && in_array( $address, Carrier::addressesOf( $field ), true ) && '' === trim( $document[ $field->key ] ?? '' ) ) {
				$missing[] = $address . '.' . $field->key;
			}
		}

		return $missing;
	}

	/**
	 * Returns one fault.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path   The value's wire path, such as billing_address.phone.
	 * @param string $reason Why it is refused.
	 * @return array{field: string, reason: string} The fault.
	 */
	private static function fault( string $path, string $reason ): array {
		return array(
			'field'  => $path,
			'reason' => $reason,
		);
	}

	/**
	 * Refuses faults, when there are any.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.invalid_fields`, with the faults as its detail `fields`.
	 *
	 * @param array $faults The faults.
	 *
	 * @phpstan-param list<array{field: string, reason: string, message?: string}> $faults
	 */
	private static function refuseFaults( array $faults ): void {
		if ( array() !== $faults ) {
			CodedException::raise( CheckoutError::InvalidFields, array(), array( 'fields' => $faults ) );
		}
	}
}
