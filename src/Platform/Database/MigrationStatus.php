<?php
/**
 * MigrationStatus: where the schema stands against the code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot of the migration chain as the code declares it and as the database records it.
 *
 * Owns one fact: the answer to "may the store take commerce writes?" and the facts behind it.
 * The kernel's schema gate, Site Health and `doctor` read it.
 *
 * @since 0.1.0
 */
final class MigrationStatus {

	/**
	 * The id of the newest migration the code declares.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $codeHead;

	/**
	 * The declared migrations not yet applied, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $pending;

	/**
	 * The first failed migration, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $failed;

	/**
	 * The first running migration, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $running;

	/**
	 * Whether commerce writes must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $writesBlocked;

	/**
	 * Describes the chain.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $codeHead      The newest declared migration.
	 * @param string[]    $pending       The declared migrations not yet applied.
	 * @param string|null $failed        The first failed migration.
	 * @param string|null $running       The first running migration.
	 * @param bool        $writesBlocked Whether commerce writes must be refused.
	 */
	public function __construct( string $codeHead, array $pending, ?string $failed, ?string $running, bool $writesBlocked ) {
		$this->codeHead      = $codeHead;
		$this->pending       = $pending;
		$this->failed        = $failed;
		$this->running       = $running;
		$this->writesBlocked = $writesBlocked;
	}

	/**
	 * Returns the newest migration the code declares.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function codeHead(): string {
		return $this->codeHead;
	}

	/**
	 * Returns the declared migrations not yet applied.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Ids, in order.
	 */
	public function pending(): array {
		return $this->pending;
	}

	/**
	 * Returns the first failed migration.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null.
	 */
	public function failed(): ?string {
		return $this->failed;
	}

	/**
	 * Returns the first running migration.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null.
	 */
	public function running(): ?string {
		return $this->running;
	}

	/**
	 * Tells whether commerce writes must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the schema is newer than the code, or when a migration that is not
	 *              applied cannot operate half-applied. Outstanding migrations that can operate
	 *              half-applied leave writes open.
	 */
	public function writesBlocked(): bool {
		return $this->writesBlocked;
	}
}
