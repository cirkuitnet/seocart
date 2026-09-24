<?php
/**
 * Annotations: what an operation does to the store, for clients that must decide whether to ask first
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * The three behavioural annotations of an operation.
 *
 * This class owns one fact: how an operation declares its effect on the store. The Ability is
 * registered with these values as its `meta.annotations`, which is how an agent's client decides
 * whether to ask a person before calling it; the REST method is derived from `read_only`; and a
 * destructive operation is never exposed to agents. The confirmation itself belongs to the calling
 * application, not to the plugin.
 *
 * @since 0.1.0
 */
final class Annotations {

	/**
	 * Whether the operation changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $readOnly;

	/**
	 * Whether the operation may remove or overwrite something rather than only add to it.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $destructive;

	/**
	 * Whether calling the operation again with the same input has no further effect.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $idempotent;

	/**
	 * Declares the annotations. All three are stated explicitly: there is no default effect.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the operation is declared both read-only and destructive.
	 *
	 * @param bool $read_only   Whether the operation changes nothing.
	 * @param bool $destructive Whether it may remove or overwrite something.
	 * @param bool $idempotent  Whether repeating it with the same input has no further effect.
	 */
	public function __construct( bool $read_only, bool $destructive, bool $idempotent ) {
		if ( $read_only && $destructive ) {
			SchemaException::raise( 'An operation cannot be both read-only and destructive.' );
		}

		$this->readOnly    = $read_only;
		$this->destructive = $destructive;
		$this->idempotent  = $idempotent;
	}

	/**
	 * Tells whether the operation changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a read-only operation.
	 */
	public function isReadOnly(): bool {
		return $this->readOnly;
	}

	/**
	 * Tells whether the operation may remove or overwrite something.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a destructive operation.
	 */
	public function isDestructive(): bool {
		return $this->destructive;
	}

	/**
	 * Returns the annotations under the keys the Abilities API reads from `meta.annotations`.
	 *
	 * @since 0.1.0
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool} The annotations.
	 */
	public function toArray(): array {
		return array(
			'readonly'    => $this->readOnly,
			'destructive' => $this->destructive,
			'idempotent'  => $this->idempotent,
		);
	}
}
