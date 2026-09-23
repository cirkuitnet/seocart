<?php
/**
 * SafeMode: whether the plugin may act on the outside world from this site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether Safe Mode is on, records why, and ends it.
 *
 * Owns one fact: the environment state that keeps a copy of a store from charging cards, sending
 * mail or running jobs as if it were the store. It is separate from the schema gate: a site may
 * be in both, and their remedies differ. The status is worked out on first use from, in order:
 *
 * 1. a recorded canary failure — the stored credentials cannot be decrypted, so none of them can
 *    be trusted; nothing but a passing canary clears it, not even SEOCART_SAFE_MODE set to false;
 * 2. SEOCART_SAFE_MODE set to true, which forces Safe Mode on;
 * 3. a missing record: nothing to compare with, so off (the next installation run rebuilds one);
 * 4. a site marked as a copy, or one whose record was lost and rebuilt: on until someone adopts
 *    the site. SEOCART_SAFE_MODE set to false does not clear these, because wp-config.php is
 *    copied along with the site, and a constant is weaker evidence than either signal;
 * 5. SEOCART_SAFE_MODE set to false, which clears what remains: a changed address and a manual switch;
 * 6. the address: the recorded home_url() differs from the current one;
 * 7. a manual switch, by an operator.
 *
 * The address comes before a manual switch. A store switched on by hand and then copied must
 * still be asked whether it is a copy: the manual switch's remedy, `wp seocart safe-mode off`,
 * adopts the current address, which on a copy is the copy's.
 *
 * Addresses compare by the hash of their key (SiteAddress): the host, case-insensitively, and
 * the path, without a trailing slash. The scheme is ignored, so moving a site from http to https
 * on the same host is not a copy. The record keeps no address as text, so a search-replace over a
 * copied database cannot make the copy's address the recorded one.
 *
 * Its effect is on the services that reach outside, each of which is given isActive() as its
 * pause switch. Nothing here stops the storefront or the admin from rendering.
 *
 * @since 0.1.0
 */
final class SafeMode {

	/**
	 * The wp-config.php constant that forces Safe Mode on (true) or off (false).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONSTANT = 'SEOCART_SAFE_MODE';

	/**
	 * The boot record.
	 *
	 * @since 0.1.0
	 *
	 * @var BootOption
	 */
	private BootOption $bootOption;

	/**
	 * Tells the time for the recorded timestamps.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * The value of SEOCART_SAFE_MODE: true, false, or null when it is not defined as a boolean.
	 *
	 * @since 0.1.0
	 *
	 * @var bool|null
	 */
	private ?bool $forced;

	/**
	 * The status per site and record revision, once worked out.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, SafeModeStatus>
	 */
	private array $statuses = array();

	/**
	 * Creates Safe Mode for the current request. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param BootOption $bootOption The boot record.
	 * @param Clock      $clock      Tells the time for the recorded timestamps.
	 * @param bool|null  $forced     The value of SEOCART_SAFE_MODE, as switchFromConstant() reads it. See the
	 *                               class description for what each value can and cannot override.
	 */
	public function __construct( BootOption $bootOption, Clock $clock, ?bool $forced ) {
		$this->bootOption = $bootOption;
		$this->clock      = $clock;
		$this->forced     = $forced;
	}

	/**
	 * Reads SEOCART_SAFE_MODE.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|null Its value when it is defined as a boolean; null otherwise, which is the same as undefined.
	 */
	public static function switchFromConstant(): ?bool {
		$value = defined( self::CONSTANT ) ? constant( self::CONSTANT ) : null;

		return is_bool( $value ) ? $value : null;
	}

	/**
	 * Returns whether Safe Mode is on for the current site, and why.
	 *
	 * @since 0.1.0
	 *
	 * @return SafeModeStatus Off, or the reason it is on.
	 */
	public function status(): SafeModeStatus {
		$record = $this->bootOption->read();
		$key    = get_current_blog_id() . ':' . $record->rev();

		if ( ! isset( $this->statuses[ $key ] ) ) {
			$this->statuses[ $key ] = $this->decide( $record );
		}

		return $this->statuses[ $key ];
	}

	/**
	 * Tells whether Safe Mode is on. The pause switch of every service that reaches outside.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for every status but Off.
	 */
	public function isActive(): bool {
		return SafeModeStatus::Off !== $this->status();
	}

	/**
	 * Records a reason for Safe Mode. Idempotent; a recorded canary failure is never replaced by a lesser reason.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the reason is not one of SafeModeStatus::recordable().
	 *
	 * @param SafeModeStatus $reason Manual, Canary, Copy or Rebuilt.
	 */
	public function enter( SafeModeStatus $reason ): void {
		if ( ! in_array( $reason, SafeModeStatus::recordable(), true ) ) {
			throw new \InvalidArgumentException( 'Safe Mode records only Manual, Canary, Copy or Rebuilt; the other statuses are worked out, not recorded.' );
		}

		$since = $this->now();

		$this->bootOption->mutate(
			static function ( BootRecord $record ) use ( $reason, $since ): BootRecord {
				$recorded = $record->safeModeReason();

				if ( $record->isAbsent() || $reason === $recorded || SafeModeStatus::Canary === $recorded ) {
					return $record;
				}

				return $record->withSafeMode( $reason, $since );
			}
		);
	}

	/**
	 * Clears the recorded reason, whatever it is. The recorded address stays, so a changed address still counts.
	 *
	 * This is how a passing canary ends a canary failure.
	 *
	 * @since 0.1.0
	 */
	public function exit(): void {
		$this->bootOption->mutate(
			static fn( BootRecord $record ): BootRecord => $record->isAbsent() || null === $record->safeModeReason() ? $record : $record->withSafeMode( null, null )
		);
	}

	/**
	 * Confirms that this site is the store: records the current address and clears every recorded
	 * reason but a canary failure, which adopting an address does not repair.
	 *
	 * The time of adoption is recorded, so a payment gateway can ask for its credentials to be
	 * confirmed again before it goes live.
	 *
	 * @since 0.1.0
	 */
	public function adopt(): void {
		$url  = home_url();
		$time = $this->now();

		$this->bootOption->mutate(
			static function ( BootRecord $record ) use ( $url, $time ): BootRecord {
				if ( $record->isAbsent() ) {
					return $record;
				}

				$adopted = $record->withHomeUrl( $url )->withAdoptedAt( $time );

				return SafeModeStatus::Canary === $record->safeModeReason() ? $adopted : $adopted->withSafeMode( null, null );
			}
		);
	}

	/**
	 * Records that this site is a copy of another store: Safe Mode stays on and stops asking.
	 *
	 * @since 0.1.0
	 */
	public function markCopy(): void {
		$this->enter( SafeModeStatus::Copy );
	}

	/**
	 * Returns the address recorded for the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The address, or null when there is no record.
	 */
	public function recordedUrl(): ?string {
		return $this->bootOption->read()->homeUrl();
	}

	/**
	 * Works out the status from the record.
	 *
	 * @since 0.1.0
	 *
	 * @param BootRecord $record The current site's record.
	 * @return SafeModeStatus The status.
	 */
	private function decide( BootRecord $record ): SafeModeStatus {
		$reason = $record->safeModeReason();

		if ( SafeModeStatus::Canary === $reason ) {
			return SafeModeStatus::Canary;
		}

		if ( true === $this->forced ) {
			return SafeModeStatus::Constant;
		}

		if ( $record->isAbsent() ) {
			return SafeModeStatus::Off;
		}

		if ( SafeModeStatus::Copy === $reason || SafeModeStatus::Rebuilt === $reason ) {
			return $reason;
		}

		if ( false === $this->forced ) {
			return SafeModeStatus::Off;
		}

		$recorded = $record->homeHash();

		if ( null !== $recorded && SiteAddress::hash( home_url() ) !== $recorded ) {
			return SafeModeStatus::UrlChanged;
		}

		return $reason ?? SafeModeStatus::Off;
	}

	/**
	 * Returns the current time for a recorded timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return string The time, as the record stores it.
	 */
	private function now(): string {
		return BootRecord::formatTime( $this->clock->now() );
	}
}
