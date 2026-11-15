<?php
/**
 * CompiledFields: the declared checkout fields with the presence each has in this store
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain\Fields;

use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Presence;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a declaration's programming error to the developer; it is never HTML.

/**
 * The checkout fields, in form order, each with its presence: the store's choice when it made one the field allows, its default otherwise.
 *
 * Owns one fact: which presence a field has. A choice stored for a key no declaration names is
 * ignored, and so is one a field no longer allows, which reads as the field's default. Everything
 * that asks whether a field is hidden, optional or required asks here.
 *
 * @since 0.2.0
 */
final readonly class CompiledFields {

	/**
	 * The fields, by key, in form order.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, CheckoutField>
	 */
	private array $fields;

	/**
	 * Each field's presence, by key.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, Presence>
	 */
	private array $presences;

	/**
	 * Compiles the fields with the store's choices.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When two fields share a key.
	 *
	 * @param CheckoutField[] $fields  The declared fields, in form order.
	 * @param Presence[]      $choices The store's choices, by field key.
	 *
	 * @phpstan-param list<CheckoutField>     $fields
	 * @phpstan-param array<string, Presence> $choices
	 */
	public function __construct( array $fields, array $choices ) {
		$byKey     = array();
		$presences = array();

		foreach ( $fields as $field ) {
			if ( isset( $byKey[ $field->key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Two checkout fields are declared with the key %s.', $field->key ) );
			}

			$chosen = $choices[ $field->key ] ?? null;

			$byKey[ $field->key ]     = $field;
			$presences[ $field->key ] = null !== $chosen && $field->allows( $chosen ) ? $chosen : $field->defaultPresence();
		}

		$this->fields    = $byKey;
		$this->presences = $presences;
	}

	/**
	 * Returns every declared field, hidden ones included, in form order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CheckoutField> The fields.
	 */
	public function all(): array {
		return array_values( $this->fields );
	}

	/**
	 * Returns the fields the form shows: every field that is not hidden, in form order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<CheckoutField> The fields.
	 */
	public function inForce(): array {
		return array_values( array_filter( $this->fields, fn( CheckoutField $field ): bool => Presence::Hidden !== $this->presences[ $field->key ] ) );
	}

	/**
	 * Returns a declared field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key.
	 * @return CheckoutField|null The field, or null when no declaration names the key.
	 */
	public function field( string $key ): ?CheckoutField {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * Returns a declared field's presence.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field A field of this list.
	 * @return Presence Its presence.
	 */
	public function presenceOf( CheckoutField $field ): Presence {
		return $this->presences[ $field->key ];
	}

	/**
	 * Returns the presences that differ from their field's default, by key, sorted: what the store's setting holds for them.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, Presence> The choices.
	 */
	public function choices(): array {
		$choices = array();

		foreach ( $this->fields as $key => $field ) {
			if ( $field->defaultPresence() !== $this->presences[ $key ] ) {
				$choices[ $key ] = $this->presences[ $key ];
			}
		}

		ksort( $choices, SORT_STRING );

		return $choices;
	}
}
