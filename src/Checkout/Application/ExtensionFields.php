<?php
/**
 * ExtensionFields: the checkout fields extensions register, and the registrations refused
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Platform\Plugins\PluginFiles;
use SEOCart\Support\ContractVersion;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the checkout fields extensions register on `seocart_register_checkout_fields`, the first time the checkout's rules need their fields, and refuses what the checkout cannot carry.
 *
 * Owns one fact: which extension fields join the checkout in a request. The action fires at most
 * once, from fieldsBeside(), which the checkout's rules call the first time they need their
 * fields; nothing here runs on a request that needs none. Each accepted field is kept under its
 * stored key, `{extensionId}_{key}`, with the extension's id as its source.
 *
 * A registration is refused, reported once with the code REJECTED and kept for the request
 * (refused()), never thrown: after the action ran; with an extension id that is no snake_case key;
 * under a stored key registered already, the first registration winning, and so a duplicate even
 * at the limit; past MAX_FIELDS; for the `address` section, which would need a value per address;
 * of a type no extension may declare; under a key that SEOCart's own fields or the session write's
 * members hold, given or stored, or a stored key with a reserved prefix; and when the stored key is
 * not one a field can have.
 * A listener that throws is reported by its exception's class and the plugin its file lies in, never
 * its message. A report names the extension, the key and the reason, never a label or a value.
 *
 * @since 0.2.0
 */
final class ExtensionFields implements CheckoutFieldRegistry {

	/**
	 * The code a refused registration is reported with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REJECTED = 'checkout.field_rejected';

	/**
	 * The reason of a registration made after the action ran.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LATE = 'late';

	/**
	 * The reason of a registration past MAX_FIELDS.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TOO_MANY = 'too_many';

	/**
	 * The reason of a field of the `address` section.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ADDRESS_SECTION = 'address_section';

	/**
	 * The reason of a field of a type no extension may declare.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TYPE = 'type';

	/**
	 * The reason of a key that SEOCart's own fields or the session write hold, or a stored key with a reserved prefix.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RESERVED_KEY = 'reserved_key';

	/**
	 * The reason of a stored key registered already.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * The reason of an extension id, or a stored key, that is not lower-case snake_case of at most CheckoutField::KEY_MAX_LENGTH characters.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INVALID_KEY = 'invalid_key';

	/**
	 * The reason of a listener that threw.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILED = 'registration_failed';

	/**
	 * The types an extension's field may be.
	 *
	 * @since 0.2.0
	 *
	 * @var list<CheckoutFieldType>
	 */
	private const TYPES = array( CheckoutFieldType::Text, CheckoutFieldType::Textarea, CheckoutFieldType::Select, CheckoutFieldType::Checkbox );

	/**
	 * The prefixes no stored key may start with: SEOCart's own, and the one kept for fields a merchant will author.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const RESERVED_PREFIXES = array( 'seocart_', 'custom_' );

	/**
	 * The names of the session write's members, which no key may take.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $wireNames;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string, array<string, mixed>): void
	 */
	private \Closure $report;

	/**
	 * The keys no registration may take while the action runs, as keys: the fields declared already and the session write's members.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $reserved = array();

	/**
	 * The accepted fields, by stored key, in the order registered.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, CheckoutField>
	 */
	private array $fields = array();

	/**
	 * The extension each accepted field was registered by, by stored key.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private array $sources = array();

	/**
	 * The registrations refused in this request, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{extension: string, key: string, reason: string, plugin?: string|null}>
	 */
	private array $refused = array();

	/**
	 * Whether the action ran.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $collected = false;

	/**
	 * Whether the action is running, while registrations are accepted.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $collecting = false;

	/**
	 * Creates the registry. Fires nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $wireNames The names of the session write's members, which no key may take.
	 * @param callable $report    Receives a report code (string) and its context (array).
	 *
	 * @phpstan-param list<string>                                  $wireNames
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct( array $wireNames, callable $report ) {
		$this->wireNames = $wireNames;
		$this->report    = \Closure::fromCallable( $report );
	}

	/**
	 * Tells whether this plugin supports an extension written against a version of the checkout field contract.
	 *
	 * @since 0.2.0
	 *
	 * @param string $version The version, `major.minor` or `major.minor.patch`.
	 * @return bool True when the versions agree: the same major and minor before 1.0; from 1.0, the same major and a minor no later than the plugin's.
	 */
	public function supportsContract( string $version ): bool {
		return ContractVersion::supports( self::CONTRACT_VERSION, $version );
	}

	/**
	 * Registers a field under its stored key while the action runs; anything the class refuses is reported and left out.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $extensionId The extension's id.
	 * @param CheckoutField $field       The field, its key without the extension's id.
	 */
	public function register( string $extensionId, CheckoutField $field ): void {
		$stored = $extensionId . '_' . $field->key;
		$reason = $this->refusalOf( $extensionId, $field, $stored );

		if ( null !== $reason ) {
			$this->refuse( $extensionId, $field->key, $reason );

			return;
		}

		$this->fields[ $stored ]  = self::storedAs( $stored, $field );
		$this->sources[ $stored ] = $extensionId;
	}

	/**
	 * Returns the fields extensions registered, firing the registration action the first time it is asked in a request.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField[] $declared The fields declared already, whose keys no registration may take.
	 * @return list<CheckoutField> The accepted fields, each under its stored key, in the order registered.
	 *
	 * @phpstan-param list<CheckoutField> $declared
	 */
	public function fieldsBeside( array $declared ): array {
		if ( ! $this->collected ) {
			$this->collect( $declared );
		}

		return array_values( $this->fields );
	}

	/**
	 * Returns the extension that registered a field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The field's stored key.
	 * @return string|null The extension's id, or null for a field no extension registered.
	 */
	public function sourceOf( string $key ): ?string {
		return $this->sources[ $key ] ?? null;
	}

	/**
	 * Returns the registrations refused in this request, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{extension: string, key: string, reason: string, plugin?: string|null}> The refusals: the extension, the key it gave and why.
	 */
	public function refused(): array {
		return $this->refused;
	}

	/**
	 * Fires the registration action once, with this registry, while registrations are accepted.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField[] $declared The fields declared already.
	 *
	 * @phpstan-param list<CheckoutField> $declared
	 */
	private function collect( array $declared ): void {
		$this->collected  = true;
		$this->collecting = true;
		$this->reserved   = array_fill_keys( array_merge( array_map( static fn( CheckoutField $field ): string => $field->key, $declared ), $this->wireNames ), true );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Always seocart_register_checkout_fields, the contract's CheckoutFieldRegistry::ACTION.
			do_action( CheckoutFieldRegistry::ACTION, $this );
		} catch ( \Throwable $failed ) {
			$this->refuse( get_class( $failed ), '', self::FAILED, array( 'plugin' => PluginFiles::slugOf( $failed->getFile() ) ) );
		} finally {
			$this->collecting = false;
		}
	}

	/**
	 * Says why a registration is refused, in the order the class judges it, or null when it is accepted.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $extensionId The extension's id.
	 * @param CheckoutField $field       The field.
	 * @param string        $stored      The key it would be stored under.
	 * @return string|null The reason, or null.
	 */
	private function refusalOf( string $extensionId, CheckoutField $field, string $stored ): ?string {
		return match ( true ) {
			! $this->collecting                                            => self::LATE,
			! CheckoutField::isKey( $extensionId )                         => self::INVALID_KEY,
			isset( $this->fields[ $stored ] )                              => self::DUPLICATE,
			count( $this->fields ) >= self::MAX_FIELDS                     => self::TOO_MANY,
			Section::Address === $field->section                           => self::ADDRESS_SECTION,
			! in_array( $field->type, self::TYPES, true )                  => self::TYPE,
			$this->isReserved( $field->key ) || $this->isReserved( $stored ) => self::RESERVED_KEY,
			! CheckoutField::isKey( $stored )                              => self::INVALID_KEY,
			default                                                        => null,
		};
	}

	/**
	 * Tells whether a key is one no extension's field may take: a declared field's, a member of the session write, or one that starts with a reserved prefix.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key.
	 * @return bool True when it is reserved.
	 */
	private function isReserved( string $key ): bool {
		if ( isset( $this->reserved[ $key ] ) ) {
			return true;
		}

		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns an extension's field as the checkout keeps it: under its stored key, without an autocomplete token, and otherwise as declared.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $stored The stored key, which CheckoutField::isKey() accepted.
	 * @param CheckoutField $field  The field as registered.
	 * @return CheckoutField The field.
	 */
	private static function storedAs( string $stored, CheckoutField $field ): CheckoutField {
		return new CheckoutField(
			key: $stored,
			section: $field->section,
			type: $field->type,
			label: $field->label,
			maxLength: $field->maxLength,
			presences: $field->presences,
			help: $field->help,
			options: $field->options,
			check: $field->check
		);
	}

	/**
	 * Reports a refused registration once, and keeps it for the request: for a listener that threw, with the plugin its file lies in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $extensionId The extension's id, or the class a listener threw.
	 * @param string $key         The key it gave; empty for a listener that threw.
	 * @param string $reason      Why.
	 * @param array  $plugin      Optional. For a listener that threw, `plugin`: the slug of the plugin its
	 *                            file lies in, or null for none. Default none.
	 *
	 * @phpstan-param array{plugin?: string|null} $plugin
	 */
	private function refuse( string $extensionId, string $key, string $reason, array $plugin = array() ): void {
		$refusal = array(
			'extension' => $extensionId,
			'key'       => $key,
			'reason'    => $reason,
		) + $plugin;

		( $this->report )( self::REJECTED, $refusal );

		$this->refused[] = $refusal;
	}
}
