<?php
/**
 * SettingsRegistry: every setting the plugin declares, and the options they are stored in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exceptions below name settings and groups for the developer who declared them; they are never rendered as HTML.

/**
 * The settings, checked as one set.
 *
 * This class owns one fact: which settings exist and which option holds each of them. The store
 * reads and writes nothing it cannot find here, the settings operations expose exactly its exposed
 * settings, and the data registry lists exactly its optionDefinitions(). Each option says what it
 * holds: an independent option in its setting's description, a document in the purpose its group
 * declares. The constructor refuses the whole set when:
 *
 * - an item is not a Setting;
 * - two settings share a name, which would make them one field on the wire;
 * - the settings of one group are stored in different ways: a group is a set of independent
 *   options or one document, never both;
 * - two settings of different groups would be stored under one option name, such as the scalar
 *   `name` of group `a` and the document of group `a_name`;
 * - a document setting is exposed. A client that writes a document must say which version it
 *   read, so exposing one needs a version on the wire, which arrives with the first document an
 *   operation exposes;
 * - a document has no purpose, or a purpose names a group that is not a document.
 *
 * It is pure data: it performs no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class SettingsRegistry {

	/**
	 * The module every settings option belongs to: the settings store writes each of them.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MODULE = 'Settings';

	/**
	 * The privacy classes, from the most to the least sensitive: a document is classified as its
	 * most sensitive setting.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Privacy>
	 */
	private const SENSITIVITY = array( Privacy::Secret, Privacy::Pii, Privacy::Financial, Privacy::Public );

	/**
	 * The settings, keyed by name, in declaration order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Setting>
	 */
	private array $settings = array();

	/**
	 * The settings, keyed by option name and then by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, Setting>>
	 */
	private array $options = array();

	/**
	 * The storage of each group.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Storage>
	 */
	private array $groups = array();

	/**
	 * The purpose of each document, keyed by group.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $purposes;

	/**
	 * Builds the registry.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the settings break one of the rules the class lists.
	 *
	 * @param Setting[]             $settings  The settings, in the order the operations list them.
	 * @param array<string, string> $documents Optional. One sentence saying what each document holds,
	 *                                         keyed by its group. Default none.
	 *
	 * @phpstan-param list<Setting> $settings
	 */
	public function __construct( array $settings, array $documents = array() ) {
		foreach ( $settings as $setting ) {
			// @phpstan-ignore instanceof.alwaysTrue (Declarations are written by hand; a stray value must be refused, not stored.)
			if ( ! $setting instanceof Setting ) {
				SchemaException::raise( 'Every item of the settings registry must be a Setting.' );
			}

			$name   = $setting->name();
			$group  = $setting->group();
			$option = $setting->optionName();

			if ( isset( $this->settings[ $name ] ) ) {
				SchemaException::raise( 'Two settings are named %1$s.', $name );
			}

			if ( isset( $this->groups[ $group ] ) && $this->groups[ $group ] !== $setting->storage() ) {
				SchemaException::raise( 'The group %1$s mixes independent options and a document; the setting %2$s must be stored like the rest of its group.', $group, $name );
			}

			$sharing = array_values( $this->options[ $option ] ?? array() )[0] ?? null;

			if ( null !== $sharing && $sharing->group() !== $group ) {
				SchemaException::raise( 'The settings %1$s and %2$s of the groups %3$s and %4$s would both be stored in the option %5$s.', $sharing->name(), $name, $sharing->group(), $group, $option );
			}

			if ( $setting->isExposed() && Storage::Document === $setting->storage() ) {
				SchemaException::raise( 'The setting %1$s is in a document and exposed; exposing a document needs its version on the wire, which the settings operations do not carry yet.', $name );
			}

			$this->settings[ $name ]           = $setting;
			$this->options[ $option ][ $name ] = $setting;
			$this->groups[ $group ]            = $setting->storage();
		}

		foreach ( $this->groups as $group => $storage ) {
			$purpose = $documents[ $group ] ?? '';

			if ( Storage::Document === $storage && ( '' === trim( $purpose ) || 1 === preg_match( '/[\r\n]/', $purpose ) ) ) {
				SchemaException::raise( 'The document %1$s needs a purpose: one sentence, on one line, saying what it holds.', $group );
			}
		}

		foreach ( array_keys( $documents ) as $group ) {
			if ( Storage::Document !== ( $this->groups[ $group ] ?? null ) ) {
				SchemaException::raise( 'A purpose is declared for %1$s, which is not a document of the registry.', (string) $group );
			}
		}

		$this->purposes = $documents;
	}

	/**
	 * Returns every setting.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings, in declaration order.
	 */
	public function all(): array {
		return array_values( $this->settings );
	}

	/**
	 * Returns the settings the settings operations expose.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings, in declaration order.
	 */
	public function exposed(): array {
		return array_values( array_filter( $this->settings, static fn( Setting $setting ): bool => $setting->isExposed() ) );
	}

	/**
	 * Returns one setting.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When no setting has the name: a programming error.
	 *
	 * @param string $name The setting's name.
	 * @return Setting The setting.
	 */
	public function setting( string $name ): Setting {
		if ( ! isset( $this->settings[ $name ] ) ) {
			throw new \InvalidArgumentException( 'No setting is named ' . $name . '.' );
		}

		return $this->settings[ $name ];
	}

	/**
	 * Returns the settings of one group.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When no setting is in the group: a programming error.
	 *
	 * @param string $group The group.
	 * @return list<Setting> The settings, in declaration order.
	 */
	public function group( string $group ): array {
		if ( ! isset( $this->groups[ $group ] ) ) {
			throw new \InvalidArgumentException( 'No setting is in the group ' . $group . '.' );
		}

		return array_values( array_filter( $this->settings, static fn( Setting $setting ): bool => $setting->group() === $group ) );
	}

	/**
	 * Returns every option the settings are stored in, with the settings each one holds.
	 *
	 * This is what the plugin owns in the options table on behalf of the settings; the data
	 * registry lists exactly these options, as optionDefinitions() declares them. Every option is
	 * written with autoload off.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<Setting>> The settings, keyed by option name, in declaration order.
	 */
	public function options(): array {
		return array_map( 'array_values', $this->options );
	}

	/**
	 * Declares every option the settings are stored in, as the data registry lists them.
	 *
	 * Each belongs to the settings module and never autoloads. An independent option holds what
	 * its setting's description says; a document, what its group's purpose says, and it is
	 * classified as its most sensitive setting.
	 *
	 * @since 0.1.0
	 *
	 * @return list<OptionDefinition> The options, in declaration order.
	 */
	public function optionDefinitions(): array {
		$definitions = array();

		foreach ( $this->options() as $option => $settings ) {
			$first   = $settings[0];
			$privacy = array_map( static fn( Setting $setting ): Privacy => $setting->field()->privacy(), $settings );

			$definitions[] = new OptionDefinition(
				$option,
				self::MODULE,
				Storage::Document === $first->storage() ? $this->purposes[ $first->group() ] : $first->field()->description(),
				false,
				Classification::from( self::mostSensitive( $privacy )->value )
			);
		}

		return $definitions;
	}

	/**
	 * Returns the most sensitive of some privacy classes.
	 *
	 * @since 0.1.0
	 *
	 * @param Privacy[] $classes The classes of the settings an option holds.
	 * @return Privacy The most sensitive, or Privacy::Public when there is none.
	 */
	private static function mostSensitive( array $classes ): Privacy {
		foreach ( self::SENSITIVITY as $class ) {
			if ( in_array( $class, $classes, true ) ) {
				return $class;
			}
		}

		return Privacy::Public;
	}
}
