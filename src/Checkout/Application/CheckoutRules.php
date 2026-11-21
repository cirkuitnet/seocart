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
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
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
 * rule accepted, it answers null, or the sentence that says why it refuses it. A check that throws
 * refuses the value too, and is reported once with the code CHECK_FAILED, the field's key and the
 * exception's class: never the value, nor the exception's message, which may quote it.
 *
 * The declared fields are SEOCart's own, then those extensions register (ExtensionFields), asked
 * for the first time the rules need their fields in a request, which fires the registration action
 * then and never on a request that needs none.
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
	 * The reason of a field's key given twice in the custom fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DUPLICATE = 'duplicate';

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
	 * The code a declaration's own check that threw is reported with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CHECK_FAILED = 'checkout.field_check_failed';

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
	 * SEOCart's own fields, in form order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<CheckoutField>
	 */
	private array $fields;

	/**
	 * The fields extensions register, or null where none may.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionFields|null
	 */
	private ?ExtensionFields $extensions;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string, array<string, mixed>): void
	 */
	private \Closure $report;

	/**
	 * Every declared field, SEOCart's own then the extensions', once asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var list<CheckoutField>|null
	 */
	private ?array $declared = null;

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
	 * Creates the rules. Reads nothing and fires nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param SettingsStore        $store      The store's settings.
	 * @param Setting[]            $alongside  The settings read in the same statement: the ones the
	 *                                         calculation reads next.
	 * @param CheckoutField[]      $fields     SEOCart's own fields, in form order.
	 * @param ExtensionFields|null $extensions Optional. The fields extensions register. Default
	 *                                         none: the fields given are all there are.
	 * @param callable|null        $report     Optional. Receives a report code (string) and its
	 *                                         context (array). Default: reports go nowhere.
	 *
	 * @phpstan-param list<Setting>       $alongside
	 * @phpstan-param list<CheckoutField> $fields
	 * @phpstan-param (callable(string, array<string, mixed>): void)|null $report
	 */
	public function __construct( SettingsStore $store, array $alongside, array $fields, ?ExtensionFields $extensions = null, ?callable $report = null ) {
		$this->store      = $store;
		$this->alongside  = $alongside;
		$this->fields     = $fields;
		$this->extensions = $extensions;
		$this->report     = null === $report ? static function (): void {} : \Closure::fromCallable( $report );
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
			$this->compiled = new CompiledFields( $this->fields(), CheckoutSettings::presences( (string) $values[ CheckoutSettings::FIELD_PRESENCE ] ) );
		}

		return $this->compiled;
	}

	/**
	 * Returns the declared fields, in form order, without reading the store's choices: SEOCart's own, then those extensions register, which the first call in a request asks for.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CheckoutField> The fields.
	 */
	public function fields(): array {
		return $this->declared ??= array_merge( $this->fields, null === $this->extensions ? array() : $this->extensions->fieldsBeside( $this->fields ) );
	}

	/**
	 * Says who declared a field, as the store's field list names it.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field A declared field.
	 * @return string The id of the extension that registered it, or BuiltInFields::SOURCE for SEOCart's own.
	 */
	public function sourceOf( CheckoutField $field ): string {
		return $this->extensions?->sourceOf( $field->key ) ?? BuiltInFields::SOURCE;
	}

	/**
	 * Returns every declared field with who declared it, by key, in form order, asking for the fields first.
	 *
	 * An order keeps the source of each value's field, and its read gives today's label only to a
	 * row whose field today has the same key and the same source.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{field: CheckoutField, source: string}> The fields and their sources.
	 */
	public function declarations(): array {
		$declarations = array();

		foreach ( $this->fields() as $field ) {
			$declarations[ $field->key ] = array(
				'field'  => $field,
				'source' => $this->sourceOf( $field ),
			);
		}

		return $declarations;
	}

	/**
	 * Returns the registrations of extension fields refused in this request, asking for the fields first.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{extension: string, key: string, reason: string}> The refusals, in order (ExtensionFields::refused()).
	 */
	public function refusedRegistrations(): array {
		$this->fields();

		return null === $this->extensions ? array() : $this->extensions->refused();
	}

	/**
	 * Returns what every list of checkout fields tells a client a field is: its key, section, type, and its label and help in the request's language.
	 *
	 * The form read and the staff's field list both start each field with these, the members
	 * CheckoutOperations::fieldIdentity() declares, and add their own.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @return array{key: string, section: string, type: string, label: string, help: string|null} The members, by wire name.
	 */
	public static function identityOf( CheckoutField $field ): array {
		return array(
			'key'     => $field->key,
			'section' => $field->section->value,
			'type'    => $field->type->value,
			'label'   => ( $field->label )(),
			'help'    => null === $field->help ? null : ( $field->help )(),
		);
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
	 * of that address is, then the custom fields in the order given: its field must be in force and
	 * carried where it was sent, and its value within its length, plain text (CheckoutText), what
	 * its type is (an email address, a country, one of a select's options, a ticked checkbox), and
	 * then what its declaration's own check accepts. A key the custom fields give twice is refused
	 * `duplicate`, and custom fields whose document (CheckoutDetails::fieldValuesDocument()) is longer
	 * than a session keeps are refused `too_long` as a whole. An empty value is not given and never a
	 * fault, but for the country: an address is not held without one (Address). A required field is
	 * not asked for here.
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

		$faults = array_merge( $faults, $this->customFaults( $fields, (array) ( $input[ Carrier::CustomFields->value ] ?? array() ) ) );

		self::refuseFaults( $faults );
	}

	/**
	 * Returns the values of the custom fields a session write gives, by field key, in the order given, leaving out each empty one: not given.
	 *
	 * Called on an input validate() accepted, so no key is given twice.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The prepared input of `checkout.update_session`.
	 * @return array<string, string> The values, by field key.
	 */
	public static function customValues( array $input ): array {
		$values = array();

		foreach ( (array) ( $input[ Carrier::CustomFields->value ] ?? array() ) as $entry ) {
			$value = (string) ( $entry[ Carrier::VALUE ] ?? '' );

			if ( '' !== $value ) {
				$values[ (string) $entry[ Carrier::KEY ] ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Returns custom fields' values as the session write answers them: a list of each field's key and value.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $values The values, by field key.
	 * @return list<array{key: string, field_value: string}> The entries, in the order of the values.
	 */
	public static function customEntries( array $values ): array {
		$entries = array();

		foreach ( $values as $key => $value ) {
			$entries[] = array(
				Carrier::KEY   => (string) $key,
				Carrier::VALUE => $value,
			);
		}

		return $entries;
	}

	/**
	 * Returns a placement's session as the rules in force let it be placed: its values for hidden fields left out, every required field given, every value one the declarations accept.
	 *
	 * Called once, on the session the placement read, before it is priced: the order, the gateway's
	 * billing contact and the kept answer are all built from what this answers. The checkout must
	 * have both addresses and a payment method (CheckoutDetails::missingForPlacement()); each
	 * address then needs a value for every required field it carries, and the custom fields one for
	 * every required field they carry. A value for a field hidden or no longer declared is left out;
	 * a value kept from before a declaration changed, which it now refuses, is refused. The custom
	 * fields' values placed are in form order.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.session_incomplete` with the detail `missing`, the wire paths of
	 *                        what is missing: the details first, then each required field, billing
	 *                        before shipping, then the custom fields; `checkout.invalid_fields`, as
	 *                        validate() answers it, for a value kept that the declarations refuse.
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

		list( $custom, $customMissing, $customFaults ) = $this->customForPlacement( $fields, $session->details->fieldValues );

		$missing = array_merge( $missing, $customMissing );
		$faults  = array_merge( $faults, $customFaults );

		if ( array() !== $missing ) {
			CodedException::raise( CheckoutError::SessionIncomplete, array(), array( 'missing' => $missing ) );
		}

		self::refuseFaults( $faults );

		return new CheckoutSession(
			$session->cartId,
			new CheckoutDetails( $placed[ Carrier::BILLING ] ?? null, $placed[ Carrier::SHIPPING ] ?? null, $session->details->shippingMethodKey, $session->details->paymentMethodKey, $custom ),
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
			$fault                  = $this->faultOf( $fields, $field, $address . '.' . $field->key, $document[ $field->key ] ?? '' );

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
	private function faultOf( CompiledFields $fields, CheckoutField $field, string $path, mixed $value ): ?array {
		$reason = self::reasonFor( $fields, $field, $value );

		if ( null !== $reason ) {
			return self::fault( $path, $reason );
		}

		return is_string( $value ) && '' !== $value ? $this->checkFault( $field, $path, $value ) : null;
	}

	/**
	 * Returns the fault of a value its declaration's own check refuses, or null when the field has no check or the check accepts the value.
	 *
	 * The check is given a value every other rule of its field accepted, and answers null, or the
	 * sentence that says why it refuses it, which the fault carries as its message. A check that
	 * throws refuses the value too, with no message: what it threw is not for the shopper to read.
	 * It is reported with CHECK_FAILED, the field's key and the exception's class, and nothing that
	 * could quote the value.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @param string        $path  The value's wire path.
	 * @param string        $value The value, which every other rule of the field accepted.
	 * @return array{field: string, reason: string, message?: string}|null The fault, or null.
	 */
	private function checkFault( CheckoutField $field, string $path, string $value ): ?array {
		if ( null === $field->check ) {
			return null;
		}

		try {
			$refusal = ( $field->check )( $value );
		} catch ( \Throwable $failed ) {
			( $this->report )(
				self::CHECK_FAILED,
				array(
					'field'     => $field->key,
					'exception' => get_class( $failed ),
				)
			);

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
	 * Returns the faults of the custom fields a session write gives, in the order given: a key given twice, each value its field does not accept, then a document longer than a session keeps.
	 *
	 * A key no field carried in the custom fields has, or a hidden field's, is `not_accepted` when
	 * its value is given; an empty value is not given.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields $fields  The fields in force.
	 * @param array          $entries The custom fields given: each a key and a field value.
	 * @return list<array{field: string, reason: string, message?: string}> The faults.
	 *
	 * @phpstan-param array<mixed> $entries
	 */
	private function customFaults( CompiledFields $fields, array $entries ): array {
		$faults   = array();
		$seen     = array();
		$accepted = array();

		foreach ( $entries as $entry ) {
			$key   = (string) ( $entry[ Carrier::KEY ] ?? '' );
			$value = $entry[ Carrier::VALUE ] ?? '';
			$path  = self::customPath( $key );
			$field = $fields->field( $key );

			if ( isset( $seen[ $key ] ) ) {
				$faults[] = self::fault( $path, self::DUPLICATE );

				continue;
			}

			$seen[ $key ] = true;
			$fault        = null !== $field && Carrier::CustomFields === Carrier::of( $field )
				? $this->faultOf( $fields, $field, $path, $value )
				: ( '' === $value ? null : self::fault( $path, self::NOT_ACCEPTED ) );

			if ( null !== $fault ) {
				$faults[] = $fault;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$accepted[ $key ] = $value;
			}
		}

		// The values accepted one by one must still fit, together, in the document a session keeps.
		if ( strlen( CheckoutDetails::fieldValuesDocument( $accepted ) ) > CheckoutDetails::FIELD_VALUES_MAX_BYTES ) {
			$faults[] = self::fault( Carrier::CustomFields->value, self::TOO_LONG );
		}

		return $faults;
	}

	/**
	 * Judges the custom fields' values a placement's session kept: the values in force, in form order, the required fields without one, and each value the declarations now refuse.
	 *
	 * A value for a field hidden, undeclared or carried elsewhere is left out.
	 *
	 * @since 0.2.0
	 *
	 * @param CompiledFields        $fields The fields in force.
	 * @param array<string, string> $values The values kept, by field key.
	 * @return array{array<string, string>, list<string>, list<array{field: string, reason: string, message?: string}>} The values
	 *         in force by field key, the wire paths of the required fields without a value, and the faults.
	 */
	private function customForPlacement( CompiledFields $fields, array $values ): array {
		$kept    = array();
		$missing = array();
		$faults  = array();

		foreach ( $fields->inForce() as $field ) {
			if ( Carrier::CustomFields !== Carrier::of( $field ) ) {
				continue;
			}

			$value = $values[ $field->key ] ?? '';

			if ( '' === trim( $value ) ) {
				if ( Presence::Required === $fields->presenceOf( $field ) ) {
					$missing[] = self::customPath( $field->key );
				}

				continue;
			}

			$kept[ $field->key ] = $value;
			$fault               = $this->faultOf( $fields, $field, self::customPath( $field->key ), $value );

			if ( null !== $fault ) {
				$faults[] = $fault;
			}
		}

		return array( $kept, $missing, $faults );
	}

	/**
	 * Returns the wire path of a value of the custom fields, such as custom_fields.order_note.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The field's key.
	 * @return string The path.
	 */
	private static function customPath( string $key ): string {
		return Carrier::CustomFields->value . '.' . $key;
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
