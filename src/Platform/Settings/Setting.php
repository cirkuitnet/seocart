<?php
/**
 * Setting: the one declaration of a plugin setting
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * One setting: its field, its group, how it is stored, and whether an operation exposes it.
 *
 * This class owns one fact: what a setting is. The field is an ordinary FieldSpec — the same
 * declaration an operation's input and output are made of — so the setting's name, type,
 * constraints, default, example, label and privacy class are written once, and the settings
 * operations compile their schemas from it like any other operation. What a setting adds is
 * where it lives:
 *
 * - its group, the unit a screen reads and a request primes in one query;
 * - its storage: Storage::Scalar for an independent value in an option of its own,
 *   `seocart_{group}_{name}`, or Storage::Document for a value in the group's versioned JSON
 *   document, `seocart_{group}`. The option name is derived from the group and the field name,
 *   so renaming either is a storage change that needs a migration;
 * - whether the settings operations expose it. An internal setting, such as the capability
 *   installer's record, is stored and read by its module only;
 * - an optional check, for a rule the field's constraints cannot express, such as "an ISO 4217
 *   code SEOCart supports". It receives a value that already satisfies the field and returns the
 *   value to store, and it refuses a value by raising one of the error codes declared with it, or
 *   an \InvalidArgumentException for a value no client can send.
 *
 * What the constructors refuse, so that a wrong declaration cannot be registered:
 *
 * - a group that is not snake_case, or the group `boot`, whose option name is the kernel's;
 * - an option name longer than the options table holds;
 * - a required or nullable field: a setting always has a value, the stored one or its default;
 * - an exposed setting without a default, which a read could not answer;
 * - a default the setting would refuse, or would store as another value: every site that never
 *   saved the setting reads its default, so the default obeys the same rules as a write (the
 *   check is pure, so running it here costs nothing but the check);
 * - a personal-data or secret field: those need the privacy handling that comes with them, and
 *   no setting declared so far needs it.
 *
 * Declarations are data: constructing a setting performs no I/O, calls no WordPress function and
 * translates nothing.
 *
 * @since 0.1.0
 */
final class Setting {

	/**
	 * The prefix of every option the plugin stores.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'seocart_';

	/**
	 * The shape of a group: lower-case snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const GROUP_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

	/**
	 * The groups no setting may use: `boot` is the kernel's `seocart_boot`, the one autoloaded option.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const RESERVED_GROUPS = array( 'boot' );

	/**
	 * The longest option name the options table holds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const OPTION_NAME_LIMIT = 191;

	/**
	 * The group.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $group;

	/**
	 * The field.
	 *
	 * @since 0.1.0
	 *
	 * @var FieldSpec
	 */
	private FieldSpec $field;

	/**
	 * How the setting is stored.
	 *
	 * @since 0.1.0
	 *
	 * @var Storage
	 */
	private Storage $storage;

	/**
	 * Whether the settings operations expose the setting.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $exposed;

	/**
	 * The setting's own check, or null when the field's constraints are the whole rule.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(int|string): (int|string))|null
	 */
	private ?\Closure $check;

	/**
	 * The error codes the check can raise.
	 *
	 * @since 0.1.0
	 *
	 * @var list<ErrorCode>
	 */
	private array $errors;

	/**
	 * Creates a setting. Use scalar() or inDocument().
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the declaration breaks one of the rules the class lists.
	 *
	 * @param string        $group   The group.
	 * @param FieldSpec     $field   The field.
	 * @param Storage       $storage How the setting is stored.
	 * @param bool          $exposed Whether the settings operations expose it.
	 * @param \Closure|null $check   The setting's own check, or null.
	 * @param ErrorCode[]   $errors  The error codes the check can raise.
	 *
	 * @phpstan-param (\Closure(int|string): (int|string))|null $check
	 * @phpstan-param list<ErrorCode>                         $errors
	 */
	private function __construct( string $group, FieldSpec $field, Storage $storage, bool $exposed, ?\Closure $check, array $errors ) {
		$name = $field->name();

		if ( 1 !== preg_match( self::GROUP_PATTERN, $group ) || in_array( $group, self::RESERVED_GROUPS, true ) ) {
			SchemaException::raise( 'The setting %1$s is in the group "%2$s", which is not a lower-case snake_case name, or is reserved.', $name, $group );
		}

		if ( $field->isRequired() || $field->isNullable() ) {
			SchemaException::raise( 'The setting %1$s is declared required or nullable; a setting always has a value, the stored one or its default, so declare neither.', $name );
		}

		if ( $exposed && null === $field->defaultValue() ) {
			SchemaException::raise( 'The setting %1$s is exposed but has no default, so a read of a site that never saved it would have nothing to return.', $name );
		}

		if ( Privacy::Pii === $field->privacy() || Privacy::Secret === $field->privacy() ) {
			SchemaException::raise( 'The setting %1$s holds personal data or a secret, which needs privacy handling that settings do not have yet.', $name );
		}

		foreach ( $errors as $index => $code ) {
			// @phpstan-ignore instanceof.alwaysTrue (Declarations are written by hand; a code written as a string must be refused.)
			if ( ! $code instanceof ErrorCode || array_search( $code, $errors, true ) !== $index ) {
				SchemaException::raise( 'The error codes of the setting %1$s must be distinct cases of an error catalog.', $name );
			}
		}

		if ( null === $check && array() !== $errors ) {
			SchemaException::raise( 'The setting %1$s declares error codes but no check that could raise them.', $name );
		}

		$this->group   = $group;
		$this->field   = $field;
		$this->storage = $storage;
		$this->exposed = $exposed;
		$this->check   = $check;
		$this->errors  = $errors;

		if ( strlen( $this->optionName() ) > self::OPTION_NAME_LIMIT ) {
			SchemaException::raise( 'The option name of the setting %1$s is longer than the %2$d characters the options table holds.', $name, self::OPTION_NAME_LIMIT );
		}

		$default = $field->defaultValue();

		if ( null !== $default ) {
			try {
				$stored = SettingValues::check( $this, $default );
			} catch ( \InvalidArgumentException | CodedException ) {
				$stored = null;
			}

			if ( $stored !== $default ) {
				SchemaException::raise( 'The default of the setting %1$s is not a value the setting accepts as it is: its field or its own check refuses it, or would store another value.', $name );
			}
		}
	}

	/**
	 * Declares a setting stored in an option of its own.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the declaration breaks one of the rules the class lists.
	 *
	 * @param string        $group   The group, lower-case snake_case.
	 * @param FieldSpec     $field   The field: name, type, constraints, default, texts, privacy.
	 * @param bool          $exposed Whether the settings operations expose the setting.
	 * @param \Closure|null $check   Optional. The setting's own check. Default none.
	 * @param ErrorCode[]   $errors  Optional. The error codes the check can raise. Default none.
	 * @return self The setting.
	 *
	 * @phpstan-param (\Closure(int|string): (int|string))|null $check
	 * @phpstan-param list<ErrorCode>                         $errors
	 */
	public static function scalar( string $group, FieldSpec $field, bool $exposed, ?\Closure $check = null, array $errors = array() ): self {
		return new self( $group, $field, Storage::Scalar, $exposed, $check, $errors );
	}

	/**
	 * Declares a setting stored in its group's versioned document.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the declaration breaks one of the rules the class lists.
	 *
	 * @param string        $group   The group, lower-case snake_case: the document's name.
	 * @param FieldSpec     $field   The field: name, type, constraints, default, texts, privacy.
	 * @param bool          $exposed Whether the settings operations expose the setting.
	 * @param \Closure|null $check   Optional. The setting's own check. Default none.
	 * @param ErrorCode[]   $errors  Optional. The error codes the check can raise. Default none.
	 * @return self The setting.
	 *
	 * @phpstan-param (\Closure(int|string): (int|string))|null $check
	 * @phpstan-param list<ErrorCode>                         $errors
	 */
	public static function inDocument( string $group, FieldSpec $field, bool $exposed, ?\Closure $check = null, array $errors = array() ): self {
		return new self( $group, $field, Storage::Document, $exposed, $check, $errors );
	}

	/**
	 * Returns the setting's name: its field's wire name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public function name(): string {
		return $this->field->name();
	}

	/**
	 * Returns the group.
	 *
	 * @since 0.1.0
	 *
	 * @return string The group.
	 */
	public function group(): string {
		return $this->group;
	}

	/**
	 * Returns the field.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	public function field(): FieldSpec {
		return $this->field;
	}

	/**
	 * Returns how the setting is stored.
	 *
	 * @since 0.1.0
	 *
	 * @return Storage The storage.
	 */
	public function storage(): Storage {
		return $this->storage;
	}

	/**
	 * Tells whether the settings operations expose the setting.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when exposed.
	 */
	public function isExposed(): bool {
		return $this->exposed;
	}

	/**
	 * Returns the name of the option that holds the setting.
	 *
	 * @since 0.1.0
	 *
	 * @return string `seocart_{group}_{name}` for a scalar, `seocart_{group}` for a document.
	 */
	public function optionName(): string {
		return Storage::Scalar === $this->storage
			? self::OPTION_PREFIX . $this->group . '_' . $this->field->name()
			: self::OPTION_PREFIX . $this->group;
	}

	/**
	 * Returns the error codes the setting's check can raise.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorCode> The codes, in declaration order.
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Runs the setting's own check on a value that already satisfies the field.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $value The value.
	 * @return int|string The value to store: the check's answer, or the value itself when the
	 *                    setting has no check.
	 */
	public function applyCheck( int|string $value ): int|string {
		return null === $this->check ? $value : ( $this->check )( $value );
	}
}
