<?php
/**
 * ErrorCode: the contract of a module's error catalog
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Error;

defined( 'ABSPATH' ) || exit;

/**
 * A stable, machine-readable error code, declared by a module's catalog.
 *
 * This interface owns one fact: the shape of a module's error catalog. A module declares its
 * codes as the cases of one string-backed enum that implements this interface — the case's
 * value is the code, written `module.reason` — and the enum's definitions() declares one row
 * per case: HTTP status, message and placeholder names. Only an enum can implement it, so a
 * code is always a case, and a throw site cannot invent a code as a string.
 *
 * ErrorTable composes every module's catalog into the one error table (DRY rule 8).
 *
 * @since 0.1.0
 */
interface ErrorCode extends \BackedEnum {

	/**
	 * Returns the module's rows: exactly one ErrorDefinition for every case of the enum.
	 *
	 * The rows are pure data. Building them performs no I/O, reads no setting and translates
	 * nothing: each message is a closure that translates only when an adapter renders it.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> The rows, one per case.
	 */
	public static function definitions(): array;
}
