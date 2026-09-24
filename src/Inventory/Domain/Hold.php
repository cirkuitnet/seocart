<?php
/**
 * Hold: the stock a checkout holds until its expiry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What a successful hold took: its id, its expiry, the lines held and the lines that needed no hold.
 *
 * Owns one fact: the outcome of one hold. Every tracked line was held in full, one row per
 * variant, all expiring at the same instant; an untracked variant is always available, so it
 * got no row and is listed apart. The hold group is what releases it again.
 *
 * @since 0.1.0
 */
final readonly class Hold {

	/**
	 * The hold's id, shared by its rows: a version 7 UUID.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $holdGroup;

	/**
	 * When the hold expires, UTC, as the database clock computed it.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	public \DateTimeImmutable $expiresAt;

	/**
	 * The tracked lines, one per variant, in ascending variant order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<HoldLine>
	 */
	public array $lines;

	/**
	 * The untracked variants, which needed no hold.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	public array $untracked;

	/**
	 * Records a hold.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $holdGroup The hold's id.
	 * @param \DateTimeImmutable $expiresAt When it expires, UTC.
	 * @param HoldLine[]         $lines     The tracked lines held.
	 * @param int[]              $untracked The untracked variants.
	 */
	public function __construct( string $holdGroup, \DateTimeImmutable $expiresAt, array $lines, array $untracked ) {
		$this->holdGroup = $holdGroup;
		$this->expiresAt = $expiresAt;
		$this->lines     = array_values( $lines );
		$this->untracked = array_values( $untracked );
	}
}
