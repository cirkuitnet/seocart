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
	 * Declares an option.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the declaration is incomplete, or a secret would autoload.
	 *
	 * @param string         $name           `seocart_` followed by lowercase snake_case.
	 * @param string         $module         The owning module.
	 * @param string         $purpose        One sentence saying what the option holds.
	 * @param bool           $autoload       Whether WordPress loads it on every request.
	 * @param Classification $classification What the value is, for privacy purposes.
	 */
	public function __construct( string $name, string $module, string $purpose, bool $autoload, Classification $classification ) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
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
