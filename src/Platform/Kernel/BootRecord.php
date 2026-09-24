<?php
/**
 * BootRecord: the site's installation record, as stored in the `seocart_boot` option
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Database\LockMode;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exceptions below name record fields for the developer; they are never rendered as HTML.

/**
 * An immutable copy of the boot record: what the plugin knows about its own installation on this site.
 *
 * Owns one fact: the shape of the record and its size. It is one JSON object with a fixed key
 * order, so that the first bytes, `{"v":1,"rev":N,`, identify the revision a conditional write
 * compares against. Every value is a scalar, null or a flat object of scalars; no PHP value is
 * ever serialized. The fields:
 *
 * - `v` (the shape version, VERSION) and `rev` (raised by one on every write);
 * - `plugin_version`: the version that last completed an installation run on the site;
 * - `schema_head`: the newest applied migration, cached for the zero-query schema gate;
 * - `lock_mode`: how locks are held on this host. Every stored record has one: the installation
 *   probes it before the first write, toJson() refuses a record without one, and a stored text
 *   without one is corrupt. So nothing that reads the record ever has to probe the host first;
 * - `install_uuid`, `home_hash`, `home_shown`, `installed_at`: the identity Safe Mode compares
 *   against. The address is kept as SiteAddress stores it, a hash and a base64url copy for
 *   display, so that a search-replace over a copied database cannot rewrite it or any part of
 *   it: no field holds the address, or a fragment of it, as text;
 * - `safe_mode`: null, or the reason an operator or the installation recorded, and since when;
 * - `adopted_at`: when the merchant last confirmed that a changed address is the same store;
 * - `kill`: reserved for per-subsystem switches, capped at 64 entries; nothing writes it yet;
 * - `canary`: null, or since when the secrets canary has failed. It is kept apart from
 *   `safe_mode`, so that a canary failure and its end never change the reason recorded there.
 *
 * During a rolling deployment an older and a newer version share one record. A newer version may
 * add keys without raising `v`; this version keeps keys it does not know and writes them back. A
 * newer version that changes the shape raises `v`, and this version then reads the record as far
 * as it understands it — a field it cannot read counts as missing, a Safe Mode entry or an
 * address it cannot read counts as a manual switch, so it never takes a copy for the store, and a
 * canary entry it cannot read counts as a failure —
 * and never writes it: isNewerShape() says so, and toJson() refuses. The newer version's fields
 * and the installation's identity survive. Only a text that is not a record at all, or a record
 * of this shape with a malformed field, is corrupt, and only that is ever replaced.
 *
 * The caps on each field keep every record under MAX_BYTES, which toJson() checks as well: the
 * record is autoloaded on every request, so its size is a budget, not a hope.
 *
 * @since 0.1.0
 */
final class BootRecord {

	/**
	 * The shape version this class reads and writes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * The largest encoded record, in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_BYTES = 8192;

	/**
	 * The most kill switches the record holds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_KILL_SWITCHES = 64;

	/**
	 * The longest recorded address, in bytes. Its base64url copy is a third longer, and with every
	 * other field at its cap the record must still fit MAX_BYTES.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_URL_BYTES = 1536;

	/**
	 * The longest stored display copy of the address: MAX_URL_BYTES in base64url.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_SHOWN_BYTES = 2048;

	/**
	 * The longest value of every other text field, in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_TEXT_BYTES = 191;

	/**
	 * The longest time the canary entry holds, in bytes: formatTime() writes 20, and the budget has
	 * no room for more than a time.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_TIME_BYTES = 32;

	/**
	 * A stored address hash: SiteAddress::hash() writes SHA-256 in lower-case hexadecimal.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HASH_PATTERN = '/^[0-9a-f]{64}\z/';

	/**
	 * A kill switch id: a lower-case word of at most 64 characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const KILL_ID_PATTERN = '/^[a-z][a-z0-9_.-]{0,63}\z/';

	/**
	 * The keys this version writes, in the order it writes them.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const KEYS = array( 'v', 'rev', 'plugin_version', 'schema_head', 'lock_mode', 'install_uuid', 'home_hash', 'home_shown', 'safe_mode', 'adopted_at', 'kill', 'installed_at', 'canary' );

	/**
	 * Whether this stands for a record that does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $absent = true;

	/**
	 * The revision, 0 for an absent record.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $rev = 0;

	/**
	 * The version that last completed an installation run.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $pluginVersion = null;

	/**
	 * The newest applied migration id.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $schemaHead = null;

	/**
	 * How locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @var LockMode|null
	 */
	private ?LockMode $lockMode = null;

	/**
	 * The installation's random identity.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $installUuid = null;

	/**
	 * The hash of the site address recorded at installation, or at the last adoption.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $homeHash = null;

	/**
	 * The same address in base64url, for display, so that a text replace cannot match any part of it.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $homeShown = null;

	/**
	 * The recorded Safe Mode reason.
	 *
	 * @since 0.1.0
	 *
	 * @var SafeModeStatus|null
	 */
	private ?SafeModeStatus $safeModeReason = null;

	/**
	 * When the recorded Safe Mode reason was recorded.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $safeModeSince = null;

	/**
	 * When a changed address was last adopted.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $adoptedAt = null;

	/**
	 * The kill switches, keyed by subsystem id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $killSwitches = array();

	/**
	 * When the site was first installed.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $installedAt = null;

	/**
	 * Whether the secrets canary has failed and not opened since.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $canaryFailed = false;

	/**
	 * When the secrets canary failed; null when it has not, or when a newer shape's entry could not be read.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $canaryFailedSince = null;

	/**
	 * Whether the stored record is of a newer shape than this version reads, which this version never writes.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $newer = false;

	/**
	 * Keys a newer version wrote, kept as they were.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private array $extra = array();

	/**
	 * Creates an absent record. Use absent() or fromJson().
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
	}

	/**
	 * Returns the record of a site that has none.
	 *
	 * @since 0.1.0
	 *
	 * @return self The absent record.
	 */
	public static function absent(): self {
		return new self();
	}

	/**
	 * Writes a time the way the record stores it.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $time The time.
	 * @return string UTC, ISO 8601 to the second, for example `2026-09-23T12:00:00Z`.
	 */
	public static function formatTime( \DateTimeImmutable $time ): string {
		return $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Reads a stored record.
	 *
	 * A record of this shape must be well formed in every field. A record of a newer shape is read
	 * as far as this version understands it, and marked so that it is never written; see the class
	 * description.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the text is corrupt: not a JSON object with a shape
	 *                                   version and a revision, or a record of this shape with a
	 *                                   malformed field.
	 *
	 * @param string|null $json The option's value, or null when the option does not exist.
	 * @return self The record; absent() for null.
	 */
	public static function fromJson( ?string $json ): self {
		if ( null === $json ) {
			return self::absent();
		}

		try {
			$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $invalid ) {
			throw new \UnexpectedValueException( 'The boot record is not valid JSON.', 0, $invalid );
		}

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new \UnexpectedValueException( 'The boot record is not a JSON object.' );
		}

		$shape = $data['v'] ?? null;

		if ( ! is_int( $shape ) || $shape < self::VERSION ) {
			throw new \UnexpectedValueException( 'The boot record has no shape version, or one no version of SEOCart wrote.' );
		}

		if ( ! is_int( $data['rev'] ?? null ) || $data['rev'] < 1 ) {
			throw new \UnexpectedValueException( 'The boot record has no positive revision.' );
		}

		$newer = $shape > self::VERSION;

		$record         = new self();
		$record->absent = false;
		$record->newer  = $newer;
		$record->rev    = $data['rev'];

		$record->pluginVersion = self::field( static fn(): ?string => self::readText( $data, 'plugin_version', self::MAX_TEXT_BYTES ), $newer, null );
		$record->schemaHead    = self::field( static fn(): ?string => self::readText( $data, 'schema_head', self::MAX_TEXT_BYTES ), $newer, null );
		$record->installUuid   = self::field( static fn(): ?string => self::readText( $data, 'install_uuid', self::MAX_TEXT_BYTES ), $newer, null );
		$record->adoptedAt     = self::field( static fn(): ?string => self::readText( $data, 'adopted_at', self::MAX_TEXT_BYTES ), $newer, null );
		$record->installedAt   = self::field( static fn(): ?string => self::readText( $data, 'installed_at', self::MAX_TEXT_BYTES ), $newer, null );
		$record->lockMode      = self::field( static fn(): LockMode => self::readLockMode( $data ), $newer, null );
		$record->killSwitches  = self::field( static fn(): array => self::readKillSwitches( $data ), $newer, array() );

		list( $record->homeHash, $record->homeShown ) = self::field( static fn(): array => self::readAddress( $data ), $newer, array( null, null ) );

		// A Safe Mode entry a newer shape wrote and this version cannot read keeps Safe Mode on.
		list( $record->safeModeReason, $record->safeModeSince ) = self::field( static fn(): array => self::readSafeMode( $data ), $newer, array( SafeModeStatus::Manual, null ) );

		// A canary entry a newer shape wrote and this version cannot read counts as a failure.
		list( $record->canaryFailed, $record->canaryFailedSince ) = self::field( static fn(): array => self::readCanary( $data ), $newer, array( true, null ) );

		// So does a newer shape whose address this version cannot read: it could not tell a copy from the store.
		if ( $newer && null === $record->homeHash && null === $record->safeModeReason ) {
			$record->safeModeReason = SafeModeStatus::Manual;
		}

		$record->extra = array_diff_key( $data, array_flip( self::KEYS ) );

		return $record;
	}

	/**
	 * Encodes the record for storage.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the record is absent, has no revision yet, is of a newer shape or
	 *                         has no lock mode, or when it would exceed MAX_BYTES.
	 *
	 * @return string The JSON text, beginning `{"v":1,"rev":N,`.
	 */
	public function toJson(): string {
		if ( $this->absent || $this->rev < 1 ) {
			throw new \LogicException( 'Only a stored record with a revision can be encoded.' );
		}

		if ( $this->newer ) {
			throw new \LogicException( 'A record of a newer shape is never written by this version, which cannot read all of it.' );
		}

		if ( null === $this->lockMode ) {
			throw new \LogicException( 'A stored record says how locks are held: the first write records the lock mode.' );
		}

		$data = array(
			'v'              => self::VERSION,
			'rev'            => $this->rev,
			'plugin_version' => $this->pluginVersion,
			'schema_head'    => $this->schemaHead,
			'lock_mode'      => $this->lockMode->value,
			'install_uuid'   => $this->installUuid,
			'home_hash'      => $this->homeHash,
			'home_shown'     => $this->homeShown,
			'safe_mode'      => null === $this->safeModeReason ? null : array(
				'reason' => $this->safeModeReason->value,
				'since'  => (string) $this->safeModeSince,
			),
			'adopted_at'     => $this->adoptedAt,
			'kill'           => (object) $this->killSwitches,
			'installed_at'   => $this->installedAt,
			'canary'         => $this->canaryFailed ? array( 'since' => (string) $this->canaryFailedSince ) : null,
		) + $this->extra;

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			throw new \LogicException( 'The boot record could not be encoded as JSON.' );
		}

		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new \LogicException( sprintf( 'The boot record would be %1$d bytes; it may not exceed %2$d, because it is loaded on every request.', strlen( $json ), self::MAX_BYTES ) );
		}

		return $json;
	}

	/**
	 * Tells whether the record matches another in everything but the revision.
	 *
	 * @since 0.1.0
	 *
	 * @param self $other The other record.
	 * @return bool True when writing one over the other would change nothing but `rev`.
	 */
	public function sameAs( self $other ): bool {
		if ( $this->absent || $other->absent ) {
			return $this->absent === $other->absent;
		}

		return $this->withRev( 1 )->toJson() === $other->withRev( 1 )->toJson();
	}

	/**
	 * Tells whether this stands for a record that does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for absent().
	 */
	public function isAbsent(): bool {
		return $this->absent;
	}

	/**
	 * Tells whether the stored record is of a newer shape than this version reads.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a newer version wrote it; such a record is read as far as it is understood and never written.
	 */
	public function isNewerShape(): bool {
		return $this->newer;
	}

	/**
	 * Returns the revision.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 for an absent record, otherwise at least 1.
	 */
	public function rev(): int {
		return $this->rev;
	}

	/**
	 * Returns a copy with another revision. Only the writer sets revisions.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the revision is below 1.
	 *
	 * @param int $rev The revision.
	 * @return self The copy.
	 */
	public function withRev( int $rev ): self {
		if ( $rev < 1 ) {
			throw new \InvalidArgumentException( 'A stored record\'s revision is at least 1.' );
		}

		$copy      = $this->present();
		$copy->rev = $rev;

		return $copy;
	}

	/**
	 * Returns the version that last completed an installation run.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The version, or null.
	 */
	public function pluginVersion(): ?string {
		return $this->pluginVersion;
	}

	/**
	 * Returns a copy recording the version that completed an installation run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The version.
	 * @return self The copy.
	 */
	public function withPluginVersion( string $version ): self {
		$copy                = $this->present();
		$copy->pluginVersion = self::argumentText( $version, 'plugin_version', self::MAX_TEXT_BYTES );

		return $copy;
	}

	/**
	 * Returns the newest applied migration id.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when none is recorded.
	 */
	public function schemaHead(): ?string {
		return $this->schemaHead;
	}

	/**
	 * Returns a copy recording the newest applied migration id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $migrationId The id.
	 * @return self The copy.
	 */
	public function withSchemaHead( string $migrationId ): self {
		$copy             = $this->present();
		$copy->schemaHead = self::argumentText( $migrationId, 'schema_head', self::MAX_TEXT_BYTES );

		return $copy;
	}

	/**
	 * Returns how locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @return LockMode|null The mode, or null when none is recorded.
	 */
	public function lockMode(): ?LockMode {
		return $this->lockMode;
	}

	/**
	 * Returns a copy recording how locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @param LockMode $mode The mode.
	 * @return self The copy.
	 */
	public function withLockMode( LockMode $mode ): self {
		$copy           = $this->present();
		$copy->lockMode = $mode;

		return $copy;
	}

	/**
	 * Returns the installation's random identity.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The identifier, or null.
	 */
	public function installUuid(): ?string {
		return $this->installUuid;
	}

	/**
	 * Returns a copy recording the installation's identity.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The identifier.
	 * @return self The copy.
	 */
	public function withInstallUuid( string $uuid ): self {
		$copy              = $this->present();
		$copy->installUuid = self::argumentText( $uuid, 'install_uuid', self::MAX_TEXT_BYTES );

		return $copy;
	}

	/**
	 * Returns the recorded site address, for display.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The address as it was recorded, or null.
	 */
	public function homeUrl(): ?string {
		return null === $this->homeShown ? null : SiteAddress::decode( $this->homeShown );
	}

	/**
	 * Returns the hash of the recorded site address, which Safe Mode compares.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null SiteAddress::hash() of the address, or null.
	 */
	public function homeHash(): ?string {
		return $this->homeHash;
	}

	/**
	 * Returns a copy recording the site address, as its hash and its base64url copy.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url The address, as home_url() returns it.
	 * @return self The copy.
	 */
	public function withHomeUrl( string $url ): self {
		$copy            = $this->present();
		$copy->homeHash  = SiteAddress::hash( self::argumentText( $url, 'home_url', self::MAX_URL_BYTES ) );
		$copy->homeShown = SiteAddress::encode( $url );

		return $copy;
	}

	/**
	 * Returns the recorded Safe Mode reason.
	 *
	 * @since 0.1.0
	 *
	 * @return SafeModeStatus|null One of SafeModeStatus::recordable(), or null.
	 */
	public function safeModeReason(): ?SafeModeStatus {
		return $this->safeModeReason;
	}

	/**
	 * Returns when the Safe Mode reason was recorded.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The time, or null when no reason is recorded.
	 */
	public function safeModeSince(): ?string {
		return $this->safeModeSince;
	}

	/**
	 * Returns a copy recording a Safe Mode reason, or clearing it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the reason is not recordable, or a reason comes without a time.
	 *
	 * @param SafeModeStatus|null $reason One of SafeModeStatus::recordable(), or null to clear it.
	 * @param string|null         $since  When it was decided; required with a reason.
	 * @return self The copy.
	 */
	public function withSafeMode( ?SafeModeStatus $reason, ?string $since ): self {
		$copy = $this->present();

		if ( null === $reason ) {
			$copy->safeModeReason = null;
			$copy->safeModeSince  = null;

			return $copy;
		}

		if ( ! in_array( $reason, SafeModeStatus::recordable(), true ) || null === $since ) {
			throw new \InvalidArgumentException( 'Safe Mode records one of the recordable reasons, with the time it was decided.' );
		}

		$copy->safeModeReason = $reason;
		$copy->safeModeSince  = self::argumentText( $since, 'safe_mode.since', self::MAX_TEXT_BYTES );

		return $copy;
	}

	/**
	 * Returns when a changed address was last adopted.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The time, or null.
	 */
	public function adoptedAt(): ?string {
		return $this->adoptedAt;
	}

	/**
	 * Returns a copy recording when a changed address was adopted.
	 *
	 * @since 0.1.0
	 *
	 * @param string $time The time.
	 * @return self The copy.
	 */
	public function withAdoptedAt( string $time ): self {
		$copy            = $this->present();
		$copy->adoptedAt = self::argumentText( $time, 'adopted_at', self::MAX_TEXT_BYTES );

		return $copy;
	}

	/**
	 * Returns when the site was first installed.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The time, or null.
	 */
	public function installedAt(): ?string {
		return $this->installedAt;
	}

	/**
	 * Returns a copy recording when the site was first installed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $time The time.
	 * @return self The copy.
	 */
	public function withInstalledAt( string $time ): self {
		$copy              = $this->present();
		$copy->installedAt = self::argumentText( $time, 'installed_at', self::MAX_TEXT_BYTES );

		return $copy;
	}

	/**
	 * Tells whether the secrets canary has failed and not opened since.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True while a failure is recorded.
	 */
	public function canaryFailed(): bool {
		return $this->canaryFailed;
	}

	/**
	 * Returns when the secrets canary failed.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The time, or null when no failure is recorded or its time could not be read.
	 */
	public function canaryFailedSince(): ?string {
		return $this->canaryFailedSince;
	}

	/**
	 * Returns a copy recording a failure of the secrets canary, or clearing it. The Safe Mode reason is left as it is.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $since When the canary failed, or null to record that it opens again.
	 * @return self The copy.
	 */
	public function withCanaryFailure( ?string $since ): self {
		$copy                    = $this->present();
		$copy->canaryFailed      = null !== $since;
		$copy->canaryFailedSince = null === $since ? null : self::argumentText( $since, 'canary.since', self::MAX_TIME_BYTES );

		return $copy;
	}

	/**
	 * Returns a copy that stands for a record that exists.
	 *
	 * @since 0.1.0
	 *
	 * @return self The copy.
	 */
	private function present(): self {
		$copy         = clone $this;
		$copy->absent = false;

		return $copy;
	}

	/**
	 * Reads one field of a stored record: strictly for this shape, as far as it can for a newer one.
	 *
	 * @since 0.1.0
	 *
	 * @template T
	 *
	 * @throws \UnexpectedValueException When the field is malformed and the record is of this shape.
	 *
	 * @param callable $read       Reads the field; throws \UnexpectedValueException when it is malformed.
	 * @param bool     $newer      Whether the record is of a newer shape.
	 * @param mixed    $unreadable What a field of a newer shape that this version cannot read counts as.
	 * @return mixed The field.
	 *
	 * @phpstan-param callable(): T $read
	 * @phpstan-param T $unreadable
	 * @phpstan-return T
	 */
	private static function field( callable $read, bool $newer, mixed $unreadable ): mixed {
		try {
			return $read();
		} catch ( \UnexpectedValueException $malformed ) {
			if ( ! $newer ) {
				throw $malformed;
			}

			return $unreadable;
		}
	}

	/**
	 * Reads the lock mode, which every stored record of this shape has.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When it is missing or unknown.
	 *
	 * @param array<array-key, mixed> $data The decoded record.
	 * @return LockMode The mode.
	 */
	private static function readLockMode( array $data ): LockMode {
		$mode = self::readText( $data, 'lock_mode', self::MAX_TEXT_BYTES );

		if ( null === $mode ) {
			throw new \UnexpectedValueException( 'The boot record has no lock mode, which every write records.' );
		}

		return LockMode::tryFrom( $mode ) ?? throw new \UnexpectedValueException( 'The boot record names an unknown lock mode.' );
	}

	/**
	 * Reads the recorded address: its hash and its base64url copy, both or neither.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the hash is not a SHA-256 hash, the copy is not base64url
	 *                                   of an address, or only one of the two is there.
	 *
	 * @param array<array-key, mixed> $data The decoded record.
	 * @return array{0: string|null, 1: string|null} The hash and the encoded copy.
	 */
	private static function readAddress( array $data ): array {
		$hash  = self::readText( $data, 'home_hash', self::MAX_TEXT_BYTES );
		$shown = self::readText( $data, 'home_shown', self::MAX_SHOWN_BYTES );

		if ( null !== $hash && 1 !== preg_match( self::HASH_PATTERN, $hash ) ) {
			throw new \UnexpectedValueException( 'The boot record\'s address hash is not a SHA-256 hash.' );
		}

		if ( null !== $shown && null === SiteAddress::decode( $shown ) ) {
			throw new \UnexpectedValueException( 'The boot record\'s copy of the address is not base64url.' );
		}

		if ( ( null === $hash ) !== ( null === $shown ) ) {
			throw new \UnexpectedValueException( 'The boot record keeps an address hash without its copy, or the other way round.' );
		}

		return array( $hash, $shown );
	}

	/**
	 * Reads the Safe Mode entry: null, or a recordable reason and the time it was recorded.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the entry is not a recordable reason and a time.
	 *
	 * @param array<array-key, mixed> $data The decoded record.
	 * @return array{0: SafeModeStatus|null, 1: string|null} The reason and the time, or two nulls.
	 */
	private static function readSafeMode( array $data ): array {
		$entry = $data['safe_mode'] ?? null;

		if ( null === $entry ) {
			return array( null, null );
		}

		if ( ! is_array( $entry ) || ! is_string( $entry['reason'] ?? null ) || ! is_string( $entry['since'] ?? null ) ) {
			throw new \UnexpectedValueException( 'The boot record\'s Safe Mode entry is not a reason and a time.' );
		}

		$reason = SafeModeStatus::tryFrom( $entry['reason'] );

		if ( null === $reason || ! in_array( $reason, SafeModeStatus::recordable(), true ) ) {
			throw new \UnexpectedValueException( 'The boot record names a Safe Mode reason that is never recorded.' );
		}

		return array( $reason, self::readText( $entry, 'since', self::MAX_TEXT_BYTES ) );
	}

	/**
	 * Reads the canary entry: null, or the time the canary failed.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the entry is not a time.
	 *
	 * @param array<array-key, mixed> $data The decoded record.
	 * @return array{0: bool, 1: string|null} Whether it failed, and when.
	 */
	private static function readCanary( array $data ): array {
		$entry = $data['canary'] ?? null;

		if ( null === $entry ) {
			return array( false, null );
		}

		if ( ! is_array( $entry ) || ! is_string( $entry['since'] ?? null ) ) {
			throw new \UnexpectedValueException( 'The boot record\'s canary entry is not the time the canary failed.' );
		}

		return array( true, self::readText( $entry, 'since', self::MAX_TIME_BYTES ) );
	}

	/**
	 * Reads the kill switches.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When they are not a map of subsystem ids set to true, or too many.
	 *
	 * @param array<array-key, mixed> $data The decoded record.
	 * @return array<string, true> The switches.
	 */
	private static function readKillSwitches( array $data ): array {
		$kill = $data['kill'] ?? array();

		if ( ! is_array( $kill ) ) {
			throw new \UnexpectedValueException( 'The boot record\'s kill switches are not an object.' );
		}

		$problem = self::killSwitchProblem( $kill );

		if ( null !== $problem ) {
			throw new \UnexpectedValueException( $problem );
		}

		/**
		 * The switches, checked above.
		 *
		 * @var array<string, true> $kill
		 */
		return $kill;
	}

	/**
	 * Reads an optional text field of a stored record.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the value is neither null nor a string, or is too long.
	 *
	 * @param array<array-key, mixed> $data  The decoded record.
	 * @param string                  $field The key.
	 * @param int                     $max   The longest value, in bytes.
	 * @return string|null The value.
	 */
	private static function readText( array $data, string $field, int $max ): ?string {
		$value = $data[ $field ] ?? null;

		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			throw new \UnexpectedValueException( sprintf( 'The boot record\'s %s is not text.', $field ) );
		}

		$problem = self::textProblem( $value, $field, $max );

		if ( null !== $problem ) {
			throw new \UnexpectedValueException( $problem );
		}

		return $value;
	}

	/**
	 * Checks a text value a caller gives for a field.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the value is empty or too long.
	 *
	 * @param string $value The value.
	 * @param string $field The key, for the message.
	 * @param int    $max   The longest value, in bytes.
	 * @return string The value.
	 */
	private static function argumentText( string $value, string $field, int $max ): string {
		$problem = self::textProblem( $value, $field, $max );

		if ( null !== $problem ) {
			throw new \InvalidArgumentException( $problem );
		}

		return $value;
	}

	/**
	 * Says what is wrong with a text value, if anything.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value.
	 * @param string $field The key, for the message.
	 * @param int    $max   The longest value, in bytes.
	 * @return string|null The problem, or null when the value fits its field.
	 */
	private static function textProblem( string $value, string $field, int $max ): ?string {
		return '' === $value || strlen( $value ) > $max ? sprintf( 'The boot record\'s %1$s must be between 1 and %2$d bytes.', $field, $max ) : null;
	}

	/**
	 * Says what is wrong with a map of kill switches, if anything.
	 *
	 * @since 0.1.0
	 *
	 * @param array<array-key, mixed> $switches The map.
	 * @return string|null The problem, or null when every key is a subsystem id set to true and there are at most MAX_KILL_SWITCHES.
	 */
	private static function killSwitchProblem( array $switches ): ?string {
		if ( count( $switches ) > self::MAX_KILL_SWITCHES ) {
			return sprintf( 'The boot record holds at most %d kill switches.', self::MAX_KILL_SWITCHES );
		}

		foreach ( $switches as $id => $value ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::KILL_ID_PATTERN, $id ) || true !== $value ) {
				return 'A kill switch is a lower-case subsystem id of at most 64 characters, set to true.';
			}
		}

		return null;
	}
}
