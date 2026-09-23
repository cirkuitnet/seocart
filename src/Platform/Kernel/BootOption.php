<?php
/**
 * BootOption: reads the boot record lazily and writes it with one conditional update
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exceptions below explain a programming error or a lost race to the developer; they are never rendered as HTML.

/**
 * The one reader and the one writer of the `seocart_boot` option.
 *
 * Owns one fact: how the boot record is read and changed safely. It is the plugin's only
 * autoloaded option, so once it exists reading it costs no query. It is read on first use, never
 * while the plugin boots: on a site where the option does not exist yet, reading it costs the one
 * query WordPress spends to learn that, and an idle request must not pay for it.
 *
 * Every write goes through mutate(), which is a compare-and-swap. It applies a change to the
 * record it read and sends one conditional statement: an UPDATE whose WHERE clause requires the
 * stored text to still begin with the revision that was read, or, when there was no record, an
 * INSERT that does nothing if another writer created one first. When the statement changes no
 * row, another writer won: the record is read again from the database and the change is applied
 * to that, up to ATTEMPTS times. A record that cannot be decoded is reported once as
 * CORRUPT, treated as absent, and replaced by the next write, again only if nobody changed it
 * in between. A record of a newer shape than this version reads is not corrupt, and is never
 * written: mutate() leaves it as it is. After every write the option caches are brought up to
 * date the way core's own option functions do.
 *
 * This is the one sanctioned write to the options table outside the settings registry, and it
 * is raw SQL on purpose: core's option functions have no compare-and-swap, and `add_option()`
 * overwrites a row another request inserted a moment earlier.
 *
 * Records are remembered per site, so the same instance serves every site of a network across
 * switch_to_blog(). The option is declared in the data registry, as the one plugin option that
 * autoloads.
 *
 * @since 0.1.0
 */
final class BootOption {

	/**
	 * The option name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'seocart_boot';

	/**
	 * The code reported when the stored record cannot be decoded. Reported, never raised.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CORRUPT = 'kernel.boot_option_corrupt';

	/**
	 * How many times mutate() applies its change before it gives up on a contended record.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ATTEMPTS = 3;

	/**
	 * The autoload value core gives an option added with autoload on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const AUTOLOAD = 'on';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * The record of each site, once read, keyed by site id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, BootRecord>
	 */
	private array $records = array();

	/**
	 * The stored text of each site whose record is corrupt, keyed by site id; null when only its
	 * decoded value is known.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, string|null>
	 */
	private array $corrupt = array();

	/**
	 * Creates the option's reader and writer. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db     The connection.
	 * @param callable $report Receives a machine code (string) and its context (array).
	 */
	public function __construct( Database $db, callable $report ) {
		$this->db     = $db;
		$this->report = $report;
	}

	/**
	 * Returns the current site's record, read once per request.
	 *
	 * @since 0.1.0
	 *
	 * @return BootRecord The record; BootRecord::absent() when there is none or it is corrupt.
	 */
	public function read(): BootRecord {
		$site = get_current_blog_id();

		if ( ! isset( $this->records[ $site ] ) ) {
			$value = get_option( self::NAME, null );

			$this->records[ $site ] = $this->decode( is_string( $value ) || null === $value ? $value : false, $site );
		}

		return $this->records[ $site ];
	}

	/**
	 * Changes the current site's record with a compare-and-swap, retrying a lost race.
	 *
	 * The change may run more than once, each time on the record as it then stands, so it must
	 * derive its answer from the record it is given and have no other effect. Returning the record
	 * unchanged writes nothing. A record of a newer shape is returned as it is, without the change:
	 * this version cannot write what it does not fully read.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the change tries to remove the record.
	 * @throws \RuntimeException When other writers won ATTEMPTS races in a row; nothing was written.
	 *
	 * @param callable(BootRecord): BootRecord $change Derives the new record from the current one.
	 * @return BootRecord The record as stored after the change.
	 */
	public function mutate( callable $change ): BootRecord {
		$current = $this->read();

		for ( $attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt ) {
			if ( $current->isNewerShape() ) {
				return $current;
			}

			$next = $change( $current );

			if ( $next->isAbsent() ) {
				if ( $current->isAbsent() ) {
					return $current;
				}

				throw new \LogicException( 'The boot record is never removed through mutate().' );
			}

			if ( $next->sameAs( $current ) ) {
				return $current;
			}

			$next = $next->withRev( $current->rev() + 1 );
			$json = $next->toJson();

			if ( $this->write( $current, $json ) ) {
				$site = get_current_blog_id();

				$this->records[ $site ] = $next;
				unset( $this->corrupt[ $site ] );
				$this->refreshCaches( $json );

				return $next;
			}

			$current = $this->reread();
		}

		throw new \RuntimeException( sprintf( 'The boot record changed under %d attempts in a row; the change was not written.', self::ATTEMPTS ) );
	}

	/**
	 * Returns the LIKE pattern that matches a stored record of one revision, and no other.
	 *
	 * The record's text begins with its shape version and revision, so this prefix is the whole
	 * invariant of a conditional write.
	 *
	 * @since 0.1.0
	 *
	 * @param int $rev The revision the writer read.
	 * @return string The pattern, with LIKE's special characters escaped.
	 */
	public static function revisionPattern( int $rev ): string {
		return addcslashes( '{"v":' . BootRecord::VERSION . ',"rev":' . $rev . ',', '_%\\' ) . '%';
	}

	/**
	 * Sends the one conditional statement of a write.
	 *
	 * @since 0.1.0
	 *
	 * @param BootRecord $current The record the change was applied to.
	 * @param string     $json    The new record's text.
	 * @return bool True when this writer changed the row; false when another writer got there first.
	 */
	private function write( BootRecord $current, string $json ): bool {
		$site = get_current_blog_id();

		if ( ! $current->isAbsent() ) {
			return 1 === $this->db->execute(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value LIKE %s',
				$this->optionsTable(),
				$json,
				self::NAME,
				self::revisionPattern( $current->rev() )
			);
		}

		$corrupt = $this->corrupt[ $site ] ?? null;

		if ( null !== $corrupt ) {
			// Replace the unreadable text, and only that text: another writer's record stays.
			return 1 === $this->db->execute(
				'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s',
				$this->optionsTable(),
				$json,
				self::AUTOLOAD,
				self::NAME,
				$corrupt
			);
		}

		// On a duplicate name the row is left exactly as it is, so the affected-row count says who created it.
		return 1 === $this->db->execute(
			'INSERT INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s ) ON DUPLICATE KEY UPDATE option_id = option_id',
			$this->optionsTable(),
			self::NAME,
			$json,
			self::AUTOLOAD
		);
	}

	/**
	 * Reads the current site's record from the database, past every cache, and brings the caches up to date.
	 *
	 * @since 0.1.0
	 *
	 * @return BootRecord The record as stored now.
	 */
	private function reread(): BootRecord {
		$site  = get_current_blog_id();
		$value = $this->db->fetchValue( 'SELECT option_value FROM %i WHERE option_name = %s', $this->optionsTable(), self::NAME );
		$json  = null === $value ? null : (string) $value;

		$this->refreshCaches( $json );

		$this->records[ $site ] = $this->decode( $json, $site );

		return $this->records[ $site ];
	}

	/**
	 * Decodes a stored value, reporting and forgetting a corrupt one.
	 *
	 * @since 0.1.0
	 *
	 * @param string|false|null $value The stored text; null when the option does not exist; false when
	 *                                 WordPress returned something that is not text at all.
	 * @param int               $site  The site it belongs to.
	 * @return BootRecord The record, or BootRecord::absent().
	 */
	private function decode( string|false|null $value, int $site ): BootRecord {
		unset( $this->corrupt[ $site ] );

		if ( false === $value ) {
			return $this->corrupt( $site, null, 'The boot record is not stored as text.' );
		}

		try {
			return BootRecord::fromJson( $value );
		} catch ( \UnexpectedValueException $corrupt ) {
			return $this->corrupt( $site, $value, $corrupt->getMessage() );
		}
	}

	/**
	 * Reports a corrupt record once, and remembers its text so the next write can replace it.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $site   The site it belongs to.
	 * @param string|null $stored The stored text, or null when only its decoded value is known.
	 * @param string      $reason What is wrong with it. Names a field, never a value.
	 * @return BootRecord BootRecord::absent().
	 */
	private function corrupt( int $site, ?string $stored, string $reason ): BootRecord {
		$this->corrupt[ $site ] = $stored;

		( $this->report )(
			self::CORRUPT,
			array(
				'site'   => $site,
				'reason' => $reason,
			)
		);

		return BootRecord::absent();
	}

	/**
	 * Brings the option caches up to date with the stored text, as core's option functions do after a write.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $json The stored text, or null when the row does not exist.
	 */
	private function refreshCaches( ?string $json ): void {
		wp_cache_delete( self::NAME, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ self::NAME ] ) ) {
			unset( $notoptions[ self::NAME ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		if ( wp_installing() ) {
			return;
		}

		$alloptions = wp_load_alloptions( true );

		if ( null === $json ) {
			unset( $alloptions[ self::NAME ] );
		} else {
			$alloptions[ self::NAME ] = $json;
		}

		wp_cache_set( 'alloptions', $alloptions, 'options' );
	}

	/**
	 * Returns the options table of the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `wp_options`, or `wp_3_options` on site 3.
	 */
	private function optionsTable(): string {
		return $this->db->prefix() . 'options';
	}
}
