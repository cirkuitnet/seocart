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
 * classification, its privacy handling and its note. A column cannot be declared without a
 * classification.
 *
 * A `pii` column also says how the privacy tools treat it, here and in no second list. The
 * eraser destroys it, anonymizes it, or retains it for a stated reason; the exporter includes
 * it unless the column says why not. A `pii` column cannot be declared without its erasure,
 * and a column of any other class cannot carry that handling.
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
	 * Erasure removes the value: the eraser empties the column or deletes its row.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ERASE_DESTROY = 'destroy';

	/**
	 * Erasure replaces the value with one that identifies nobody, and keeps the row.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ERASE_ANONYMIZE = 'anonymize';

	/**
	 * Erasure keeps the value, for the reason the column states, and reports it as retained.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ERASE_RETAIN = 'retain';

	/**
	 * Every way the eraser may treat a column.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ERASURES = array( self::ERASE_DESTROY, self::ERASE_ANONYMIZE, self::ERASE_RETAIN );

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
	 * How the eraser treats a `pii` column: one of the ERASE_* constants, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $erasure;

	/**
	 * Why the eraser retains the value, when it does.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $retainedBecause;

	/**
	 * Why the exporter leaves a `pii` column out, when it does.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $notExportedBecause;

	/**
	 * Declares a column.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a value cannot be part of a valid declaration.
	 *
	 * @param string         $name               Lowercase snake_case.
	 * @param string         $type               The type as MySQL reports it, lowercase.
	 * @param Classification $classification     The privacy class.
	 * @param string         $note               What the column holds. Not empty.
	 * @param bool           $nullable           Optional. Whether NULL is allowed. Default false.
	 * @param string|null    $defaultValue       Optional. A literal default without quotes or backslashes. Default null, none.
	 * @param string|null    $collation          Optional. A collation such as ascii_bin. Default null, the table's.
	 * @param bool           $autoIncrement      Optional. Whether the column is AUTO_INCREMENT. Default false.
	 * @param string|null    $erasure            Optional. ERASE_DESTROY, ERASE_ANONYMIZE or ERASE_RETAIN: required for
	 *                                           a `pii` column and refused for any other. Default null.
	 * @param string|null    $retainedBecause    Optional. Why erasure retains the value. Required with ERASE_RETAIN
	 *                                           and refused with anything else. Default null.
	 * @param string|null    $notExportedBecause Optional. For a `pii` column the exporter leaves out: why. Default
	 *                                           null, the column is exported.
	 */
	public function __construct(
		string $name,
		string $type,
		Classification $classification,
		string $note,
		bool $nullable = false,
		?string $defaultValue = null,
		?string $collation = null,
		bool $autoIncrement = false,
		?string $erasure = null,
		?string $retainedBecause = null,
		?string $notExportedBecause = null
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

		self::checkPrivacyHandling( $name, $classification, $erasure, $retainedBecause, $notExportedBecause );

		$this->name               = $name;
		$this->type               = $type;
		$this->classification     = $classification;
		$this->note               = $note;
		$this->nullable           = $nullable;
		$this->defaultValue       = $defaultValue;
		$this->collation          = $collation;
		$this->autoIncrement      = $autoIncrement;
		$this->erasure            = $erasure;
		$this->retainedBecause    = $retainedBecause;
		$this->notExportedBecause = $notExportedBecause;
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

	/**
	 * Returns how the privacy eraser treats the column.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null ERASE_DESTROY, ERASE_ANONYMIZE or ERASE_RETAIN for a `pii` column; null for any other.
	 */
	public function erasure(): ?string {
		return $this->erasure;
	}

	/**
	 * Returns why the eraser retains the value.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The reason, reported with the retained item; null unless erasure is ERASE_RETAIN.
	 */
	public function retainedBecause(): ?string {
		return $this->retainedBecause;
	}

	/**
	 * Tells whether the privacy exporter includes the column.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a `pii` column that does not say why it is left out; false for any other column.
	 */
	public function isExported(): bool {
		return Classification::Pii === $this->classification && null === $this->notExportedBecause;
	}

	/**
	 * Returns why the exporter leaves the column out.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The reason, or null when the column is exported or is not `pii`.
	 */
	public function notExportedBecause(): ?string {
		return $this->notExportedBecause;
	}

	/**
	 * Checks the privacy handling a column declares against its class.
	 *
	 * A `pii` column must declare it and no other column may. Its erasure is one of the three
	 * ways; a reason for retaining goes with ERASE_RETAIN and with nothing else; and a reason
	 * given for either tool is a sentence, never blank.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a `pii` column declares no erasure, when a column of another class
	 *                                   declares any handling, or when the handling is incomplete.
	 *
	 * @param string         $name               The column name, for the message.
	 * @param Classification $classification     The privacy class.
	 * @param string|null    $erasure            The declared erasure.
	 * @param string|null    $retainedBecause    The declared reason for retaining.
	 * @param string|null    $notExportedBecause The declared reason for not exporting.
	 */
	private static function checkPrivacyHandling( string $name, Classification $classification, ?string $erasure, ?string $retainedBecause, ?string $notExportedBecause ): void {
		if ( Classification::Pii !== $classification ) {
			if ( null !== $erasure || null !== $retainedBecause || null !== $notExportedBecause ) {
				throw new \InvalidArgumentException( sprintf( 'Column %s is %s, not pii: only a pii column says how the privacy exporter and eraser treat it.', $name, $classification->value ) );
			}

			return;
		}

		if ( null === $erasure ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s is pii and does not say how the privacy tools treat it: declare its erasure (destroy, anonymize, or retain with a reason), and why it is not exported if it is not.', $name ) );
		}

		if ( ! in_array( $erasure, self::ERASURES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s: erasure "%s" is not one of %s.', $name, $erasure, implode( ', ', self::ERASURES ) ) );
		}

		if ( ( self::ERASE_RETAIN === $erasure ) !== ( null !== $retainedBecause ) ) {
			throw new \InvalidArgumentException( sprintf( 'Column %s: a reason for retaining goes with ERASE_RETAIN, and only with it.', $name ) );
		}

		foreach ( array( $retainedBecause, $notExportedBecause ) as $reason ) {
			if ( null !== $reason && '' === trim( $reason ) ) {
				throw new \InvalidArgumentException( sprintf( 'Column %s: a privacy reason cannot be blank.', $name ) );
			}
		}
	}
}
