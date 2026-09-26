<?php
/**
 * ConversionContexts: freezes the exchange rates orders are placed at, and reads them back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\ConversionContext;

defined( 'ABSPATH' ) || exit;

/**
 * The port over `conversion_contexts`.
 *
 * Owns one fact: that every rate an order references is stored once, immutably, and read back
 * exactly as it was frozen. Equal contexts share one row, found by their fingerprint, so the
 * identity context of a currency is one row for the life of the store.
 *
 * @since 0.1.0
 */
interface ConversionContexts {

	/**
	 * Stores a context, or finds the row an equal context was stored in, and returns its id.
	 *
	 * Runs inside the caller's transaction, which also writes the order that references it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param ConversionContext $context The context.
	 * @return int The row's id, the same for every equal context.
	 */
	public function freeze( ConversionContext $context ): int;

	/**
	 * Reads a context back at the scale it was quoted at, so its fingerprint is the one it was frozen with.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The row's id.
	 * @return ConversionContext|null The context, or null when there is no such row.
	 */
	public function find( int $id ): ?ConversionContext;
}
