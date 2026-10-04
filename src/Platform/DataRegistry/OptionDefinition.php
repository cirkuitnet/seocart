<?php
/**
 * OptionDefinition: the declaration of one option the plugin keeps in the options table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\DataRegistry;

use SEOCart\Platform\Database\Schema\Classification;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * One plugin option: its name, who owns it, why it exists, whether it autoloads and what it holds.
 *
 * Owns one fact: what the data registry knows about an option, declared once. Uninstall and
 * "Delete all store data" remove it by this name, `doctor --residue` looks for it, and its
 * classification decides whether a support bundle or an export may carry its value. How the
 * value is typed, validated and written is the settings registry's business, not this class's.
 *
 * A family is the declaration of every option whose name begins with one prefix, such as one
 * settings document per payment gateway, when which ones exist is known only at run time. Its
 * name is the prefix followed by `%`, as SQL's LIKE reads it, and it never autoloads.
 *
 * Its class is the same four-way classification a table column has. `secret` means the value
 * is a credential or key: such an option never autoloads, so it is never read on a request that
 * does not ask for it. Autoload is always stated, never left to WordPress to decide.
 *
 * Pure data: constructing one does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class OptionDefinition {

	/**
	 * An option name: the plugin prefix, then lowercase snake_case, 191 characters at most, the
	 * width of the options table's name column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^seocart_[a-z0-9_]{1,183}$/D';

	/**
	 * A family's name: the plugin prefix, lowercase snake_case ending in `_`, then `%`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const FAMILY_PATTERN = '/^seocart_[a-z0-9_]{0,181}_%$/D';

	/**
	 * The option name, for example `seocart_boot`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The owning module, for example `Kernel`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $module;

	/**
	 * One sentence saying what the option holds.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $purpose;

	/**
	 * Whether WordPress loads the option on every request.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $autoload;

	/**
	 * What the value is, for privacy purposes.
	 *
	 * @since 0.1.0
	 *
	 * @var Classification
	 */
	private Classification $classification;

	/**
	 * Whether this declares a family of options rather than one.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $family;

	/**
	 * Declares an option.
	 *
	 * @since 0.1.0
	 *
	 * @since 0.2.0 A family may be declared; prefixed() is the readable way to.
	 *
	 * @throws \InvalidArgumentException When the declaration is incomplete, or a secret or a family would autoload.
	 *
	 * @param string         $name           `seocart_` followed by lowercase snake_case.
	 * @param string         $module         The owning module.
	 * @param string         $purpose        One sentence saying what the option holds.
	 * @param bool           $autoload       Whether WordPress loads it on every request.
	 * @param Classification $classification What the value is, for privacy purposes.
	 * @param bool           $family         Optional. Whether the name is a family's, `seocart_{prefix}_%`. Default false.
	 */
	public function __construct( string $name, string $module, string $purpose, bool $autoload, Classification $classification, bool $family = false ) {
		if ( $family && ( $autoload || 1 !== preg_match( self::FAMILY_PATTERN, $name ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'Option family "%s" must be seocart_, lowercase snake_case ending in _, then %%; a family never autoloads.', $name ) );
		}

		if ( ! $family && 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Option name "%s" must be seocart_ followed by lowercase snake_case, 191 characters at most.', $name ) );
		}

		foreach ( array(
			'module'  => $module,
			'purpose' => $purpose,
		) as $field => $value ) {
			if ( '' === trim( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'Option %s needs a %s.', $name, $field ) );
			}
		}

		if ( $autoload && Classification::Secret === $classification ) {
			throw new \InvalidArgumentException( sprintf( 'Option %s holds a secret, and a secret never autoloads.', $name ) );
		}

		$this->name           = $name;
		$this->module         = $module;
		$this->purpose        = $purpose;
		$this->autoload       = $autoload;
		$this->classification = $classification;
		$this->family         = $family;
	}

	/**
	 * Declares a family of options: every option whose name begins with a prefix. None of them autoloads.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the prefix is not `seocart_` then lowercase snake_case ending in `_`, or the declaration is incomplete.
	 *
	 * @param string         $prefix         What every option of the family begins with, for example `seocart_gateway_`.
	 * @param string         $module         The owning module.
	 * @param string         $purpose        One sentence saying what the options hold.
	 * @param Classification $classification What the values are, for privacy purposes.
	 * @return self The family, named the prefix followed by `%`.
	 */
	public static function prefixed( string $prefix, string $module, string $purpose, Classification $classification ): self {
		return new self( $prefix . '%', $module, $purpose, false, $classification, true );
	}

	/**
	 * Returns the option name.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `seocart_boot`.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Tells whether this declares a family of options.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for a family.
	 */
	public function isFamily(): bool {
		return $this->family;
	}

	/**
	 * Tells whether an option is the one this declares, or one of the family it declares.
	 *
	 * @since 0.2.0
	 *
	 * @param string $option An option name.
	 * @return bool True when the name is this option's, or begins with this family's prefix and is longer than it.
	 */
	public function covers( string $option ): bool {
		if ( ! $this->family ) {
			return $option === $this->name;
		}

		$prefix = substr( $this->name, 0, -1 );

		return strlen( $option ) > strlen( $prefix ) && str_starts_with( $option, $prefix );
	}

	/**
	 * Returns the owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `Kernel`.
	 */
	public function module(): string {
		return $this->module;
	}

	/**
	 * Returns what the option holds.
	 *
	 * @since 0.1.0
	 *
	 * @return string One sentence.
	 */
	public function purpose(): string {
		return $this->purpose;
	}

	/**
	 * Tells whether WordPress loads the option on every request.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it autoloads.
	 */
	public function autoloads(): bool {
		return $this->autoload;
	}

	/**
	 * Returns what the value is, for privacy purposes. `secret` is a credential or key: never
	 * exported, logged or shown.
	 *
	 * @since 0.1.0
	 *
	 * @return Classification The class.
	 */
	public function classification(): Classification {
		return $this->classification;
	}
}
