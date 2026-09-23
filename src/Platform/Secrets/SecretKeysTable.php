<?php
/**
 * SecretKeysTable: the declaration of the data key registry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\DataRegistry\RetentionCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `secret_keys`: one row per data key the site has ever had, never the key itself.
 *
 * This class owns one fact: the shape of the data key registry. A key's material lives in the
 * secret, non-autoloaded data keys option; this table says which keys exist, which is active,
 * which is retiring and when each was retired, so the status command and `doctor` can report
 * them and count the records each one still seals.
 *
 * @since 0.1.0
 */
final class SecretKeysTable {

	/**
	 * The table name, without the prefix.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'secret_keys';

	/**
	 * The state of the key new secrets are sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ACTIVE = 'active';

	/**
	 * The state of a key that still opens records waiting to be re-sealed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETIRING = 'retiring';

	/**
	 * The state of a key that seals nothing any more and whose material is gone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETIRED = 'retired';

	/**
	 * Returns the declaration.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The table.
	 */
	public static function definition(): TableDefinition {
		return new TableDefinition(
			self::NAME,
			'Secrets',
			'Records every data key the site has had, its state and when it was created and retired, never the key itself; rows are kept permanently because a backup may still hold values sealed with a retired key, and the registry must say what that key was.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'key_id', 'char(16)', Classification::Public, 'The key id every sealed value names: sixteen hexadecimal digits drawn at random, never derived from the key.', collation: 'ascii_bin' ),
				new ColumnSpec( 'state', 'varchar(10)', Classification::Public, 'active, retiring or retired.', collation: 'ascii_bin' ),
				new ColumnSpec( 'algorithm', 'varchar(40)', Classification::Public, 'The cipher the key is used with.', collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the key was created, UTC, from the database clock.' ),
				new ColumnSpec( 'retired_at', 'datetime(6)', Classification::Public, 'When the key was retired, UTC; NULL until then.', nullable: true ),
			),
			array( 'key_id' ),
			array(),
			array(
				IndexSpec::key( 'state', array( 'state' ), 'Finds the active and the retiring key.' ),
			),
			RetentionCatalog::PERMANENT,
			array()
		);
	}
}
