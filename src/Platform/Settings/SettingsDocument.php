<?php
/**
 * SettingsDocument: one version of a group's settings document
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A document as it was read or written: its version and the values it holds.
 *
 * This class owns one fact: what a writer must hand back to replace a document — the version it
 * read. Version 0 is a document that has never been written; every write stores the next version,
 * so two writes can never leave the same version behind. The values are the ones the document
 * holds, keyed by setting name; a setting it does not hold reads as its default.
 *
 * @since 0.1.0
 */
final class SettingsDocument {

	/**
	 * The version.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * The values the document holds, keyed by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int|string>
	 */
	private array $values;

	/**
	 * Creates the document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the version is negative.
	 *
	 * @param int                       $version The version, 0 for a document never written.
	 * @param array<string, int|string> $values  The values it holds, keyed by setting name.
	 */
	public function __construct( int $version, array $values ) {
		if ( $version < 0 ) {
			throw new \InvalidArgumentException( 'A document version is 0, for a document never written, or more.' );
		}

		$this->version = $version;
		$this->values  = $values;
	}

	/**
	 * Returns the version.
	 *
	 * @since 0.1.0
	 *
	 * @return int The version, 0 for a document never written.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Returns the values the document holds.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string> The values, keyed by setting name.
	 */
	public function values(): array {
		return $this->values;
	}
}
