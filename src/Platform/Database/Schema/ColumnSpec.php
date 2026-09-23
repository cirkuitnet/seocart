<?php
/**
 * ColumnSpec: the declaration of one column of a plugin table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * One column: its storage shape, its privacy class and what it means.
 *
 * Owns one fact: everything that is true of a column, declared once. The DDL generator and
 * the post-condition verifier read its storage shape; the data registry reads its
 * classification and note. A column cannot be declared without a classification.
 *
 * The type is written the way MySQL reports it, lowercase: `bigint unsigned`, `varchar(191)`,
 * `datetime(6)`, `decimal(24,12)`, `tinyint(1)`. A default is a plain literal, sent quoted.
 * No expression defaults, no ENUM and no generated columns: no plugin table uses any of them.
 * Pure data: constructing one does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class ColumnSpec {

	/**
	 * A column name: lowercase snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * A type: a lowercase keyword, an optional length or precision, and an optional `unsigned`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TYPE_PATTERN = '/^[a-z]+(?:\(\d+(?:,\d+)?\))?(?: unsigned)?$/';

	/**
	 * A collation name, such as ascii_bin.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const COLLATION_PATTERN = '/^[a-z0-9]+_[a-z0-9_]+$/';

	/**
	 * The column name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The type, as MySQL reports it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The privacy class.
	 *
	 * @since 0.1.0
	 *
	 * @var Classification
	 */
	private Classification $classification;

	/**
	 * What the column holds, for the generated schema reference.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $note;

	/**
	 * Whether NULL is allowed.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $nullable;

	/**
	 * The default value, or null for none (a nullable column then defaults to NULL).
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $defaultValue;

	/**
	 * The column collation, or null to inherit the table's.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $collation;

	/**
	 * Whether the column is AUTO_INCREMENT.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $autoIncrement;

	/**
	 * Declares a column.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a value cannot be part of a valid declaration.
	 *
	 * @param string         $name           Lowercase snake_case.
	 * @param string         $type           The type as MySQL reports it, lowercase.
	 * @param Classification $classification The privacy class.
	 * @param string         $note           What the column holds. Not empty.
	 * @param bool           $nullable       Optional. Whether NULL is allowed. Default false.
	 * @param string|null    $defaultValue   Optional. A literal default without quotes or backslashes. Default null, none.
	 * @param string|null    $collation      Optional. A collation such as ascii_bin. Default null, the table's.
	 * @param bool           $autoIncrement  Optional. Whether the column is AUTO_INCREMENT. Default false.
	 */
	public function __construct(
		string $name,
		string $type,
		Classification $classification,
		string $note,
		bool $nullable = false,
		?string $defaultValue = null,
		?string $collation = null,
		bool $autoIncrement = false
	) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column name "%s" must be lowercase snake_case.', $name ) );
		}

		if ( 1 !== preg_match( self::TYPE_PATTERN, $type ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s: type "%s" must be written as MySQL reports it, lowercase, for example "bigint unsigned" or "varchar(191)".', $name, $type ) );
		}

		if ( '' === trim( $note ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s needs a note saying what it holds.', $name ) );
		}

		if ( null !== $defaultValue && 1 === preg_match( "/['\\\\]/", $defaultValue ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s: a default may contain no quote and no backslash.', $name ) );
		}

		if ( null !== $collation && 1 !== preg_match( self::COLLATION_PATTERN, $collation ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s: "%s" is not a collation name.', $name, $collation ) );
		}

		if ( $autoIncrement && ( $nullable || null !== $defaultValue ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s: an AUTO_INCREMENT column is NOT NULL and has no default.', $name ) );
		}

		$this->name           = $name;
		$this->type           = $type;
		$this->classification = $classification;
		$this->note           = $note;
		$this->nullable       = $nullable;
		$this->defaultValue   = $defaultValue;
		$this->collation      = $collation;
		$this->autoIncrement  = $autoIncrement;
	}

	/**
	 * Returns the column name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Lowercase snake_case.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the type.
	 *
	 * @since 0.1.0
	 *
	 * @return string As MySQL reports it, lowercase.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the privacy class.
	 *
	 * @since 0.1.0
	 *
	 * @return Classification The class.
	 */
	public function classification(): Classification {
		return $this->classification;
	}

	/**
	 * Returns what the column holds.
	 *
	 * @since 0.1.0
	 *
	 * @return string The note.
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * Tells whether NULL is allowed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the column is nullable.
	 */
	public function nullable(): bool {
		return $this->nullable;
	}

	/**
	 * Returns the default value.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The literal, or null when none is declared.
	 */
	public function defaultValue(): ?string {
		return $this->defaultValue;
	}

	/**
	 * Returns the declared collation.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The collation, or null when the column inherits the table's.
	 */
	public function collation(): ?string {
		return $this->collation;
	}

	/**
	 * Tells whether the column is AUTO_INCREMENT.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it is.
	 */
	public function autoIncrement(): bool {
		return $this->autoIncrement;
	}
}
