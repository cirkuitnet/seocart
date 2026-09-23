<?php
/**
 * ReportCode: the codes the Database module logs or records without throwing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the reporter, the log or the `migrations` table, never a client.
 *
 * This enum owns one fact: the vocabulary of what the Database module reports without
 * raising an error. None of these is ever thrown, so none has a row in the error table, and
 * none may spell a code the error table uses: a test composes the table and checks it.
 *
 * @since 0.1.0
 */
enum ReportCode: string {

	/**
	 * Recorded as a failed migration's error code when its tables differ from their declarations.
	 *
	 * @since 0.1.0
	 */
	case PostconditionMismatch = 'database.postcondition_mismatch';

	/**
	 * Reported when an applied migration's class file changed since it ran.
	 *
	 * @since 0.1.0
	 */
	case MigrationChecksumMismatch = 'database.migration_checksum_mismatch';

	/**
	 * Reported when a migration that is not applied sorts before one that is: the chain is inconsistent.
	 *
	 * @since 0.1.0
	 */
	case MigrationOutOfOrder = 'database.migration_out_of_order';

	/**
	 * Reported when an after-commit callback throws; the committed unit of work stands and is not run again.
	 *
	 * @since 0.1.0
	 */
	case AfterCommitFailed = 'database.after_commit_failed';

	/**
	 * Reported when an after-rollback callback throws; the rollback's own cause still propagates.
	 *
	 * @since 0.1.0
	 */
	case AfterRollbackFailed = 'database.after_rollback_failed';
}
