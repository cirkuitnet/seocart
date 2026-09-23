<?php
/**
 * KernelError: the error catalog of the kernel
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors the kernel raises.
 *
 * Owns one fact: how a write refused by the schema gate is reported. The refusal answers 503,
 * because the same request succeeds once the schema is current, and carries the gate's state as
 * its reason. Any operation that changes the store can meet it, so its row says so, and no
 * operation declares it by itself. Codes the kernel only reports, never raises, are not rows here.
 *
 * @since 0.1.0
 */
enum KernelError: string implements ErrorCode {

	/**
	 * A commerce write was refused because the database schema and the code disagree.
	 *
	 * @since 0.1.0
	 */
	case StoreUnavailable = 'store.unavailable';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::StoreUnavailable,
				503,
				static fn(): string =>
					/* translators: %1$s: Why the store refuses changes, a code such as code_newer or schema_newer. */
					__( 'The store is temporarily unavailable while its database is being updated (%1$s).', 'seocart' ),
				array( 'reason' ),
				any_write: true
			),
		);
	}
}
