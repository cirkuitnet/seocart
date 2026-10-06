<?php
/**
 * GatewaySwitches: the payment gateways' kill switches, and Safe Mode, as the gateways see them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayDescriptor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * Whether an operator switched a gateway off, and whether Safe Mode keeps the site off live money.
 *
 * Owns one fact: how the gateways read and write their switches. A gateway's switch is the kill
 * switch `gateway.{id}` of the boot record, the record the site loads with every request: asking
 * costs no statement, and switching is one conditional write of the record, which a change made
 * by another request at the same moment never undoes. A switched-off gateway takes no new
 * payment; capturing, voiding, refunding and asking about the payments it already holds go on,
 * so the switch stops money coming in without stranding money already moving.
 *
 * Safe Mode is the kernel's: while it is on, the site makes no live call, and a gateway takes new
 * payments in its test mode only.
 *
 * Both are read from the boot record, which a request reads once, on first use. So a switch
 * turned, or Safe Mode turned on, takes effect for the requests that read the record after the
 * write, every request that starts after it among them: a placement already past that read
 * completes, its call to the gateway included. Neither stops a request already running.
 *
 * The kernel hands in how the record is read and written and whether Safe Mode is on, so this
 * module names none of the kernel's classes, and building this reads nothing.
 *
 * @since 0.2.0
 */
final class GatewaySwitches {

	/**
	 * What a gateway's kill switch is named with, before the gateway's id.
	 *
	 * With the longest gateway id, 8 + 32 characters: within the 64 a kill switch id may have.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PREFIX = 'gateway.';

	/**
	 * Tells whether a kill switch is on.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string): bool
	 */
	private \Closure $isOff;

	/**
	 * Turns a kill switch on or off, and tells whether the record holds it so afterwards.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string, bool): bool
	 */
	private \Closure $turn;

	/**
	 * Tells whether Safe Mode is on.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $safeMode;

	/**
	 * Creates the switches. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $isOff    Tells whether a kill switch (string) is on: reads the boot record.
	 * @param callable $turn     Turns a kill switch (string) on (true) or off (false) with one conditional write of
	 *                           the boot record, and tells whether the record then holds it as asked.
	 * @param callable $safeMode Tells whether Safe Mode is on.
	 *
	 * @phpstan-param callable(string): bool       $isOff
	 * @phpstan-param callable(string, bool): bool $turn
	 * @phpstan-param callable(): bool             $safeMode
	 */
	public function __construct( callable $isOff, callable $turn, callable $safeMode ) {
		$this->isOff    = \Closure::fromCallable( $isOff );
		$this->turn     = \Closure::fromCallable( $turn );
		$this->safeMode = \Closure::fromCallable( $safeMode );
	}

	/**
	 * Returns the kill switch of a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not a gateway id.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return string `gateway.{id}`, such as `gateway.stripe`.
	 */
	public static function switchOf( string $gatewayId ): string {
		if ( strlen( $gatewayId ) > GatewayDescriptor::ID_MAX_LENGTH || 1 !== preg_match( GatewayDescriptor::ID_PATTERN, $gatewayId ) ) {
			throw new \InvalidArgumentException( sprintf( 'A gateway id is lower-case snake_case of at most %d characters.', GatewayDescriptor::ID_MAX_LENGTH ) );
		}

		return self::PREFIX . $gatewayId;
	}

	/**
	 * Tells whether a gateway may take new payments: no operator switched it off.
	 *
	 * Reads the boot record, which the site has loaded already.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return bool False while its kill switch is on.
	 */
	public function isEnabled( string $gatewayId ): bool {
		return ! ( $this->isOff )( self::PREFIX . $gatewayId );
	}

	/**
	 * Switches a gateway off: it takes no new payment until it is enabled again.
	 *
	 * For the requests that read the boot record after the write: a placement that read it before
	 * completes.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not a gateway id, or the boot record holds as
	 *                                   many kill switches as it can.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return bool True when the boot record now holds the switch; false when the site has no boot
	 *              record it can write, because the plugin is not installed or a newer version's
	 *              record is stored.
	 */
	public function disable( string $gatewayId ): bool {
		return ( $this->turn )( self::switchOf( $gatewayId ), true );
	}

	/**
	 * Switches a gateway back on.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not a gateway id.
	 *
	 * @param string $gatewayId The gateway's id, registered or not: a switch left by a gateway since removed can be cleared too.
	 * @return bool True when the boot record no longer holds the switch.
	 */
	public function enable( string $gatewayId ): bool {
		return ( $this->turn )( self::switchOf( $gatewayId ), false );
	}

	/**
	 * Tells whether Safe Mode is on: the site makes no live call, and new payments are created in test mode.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True while it is on.
	 */
	public function safeMode(): bool {
		return ( $this->safeMode )();
	}
}
