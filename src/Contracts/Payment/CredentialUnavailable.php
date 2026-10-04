<?php
/**
 * CredentialUnavailable: a gateway's credential is missing or does not open
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a gateway's credential for a mode was never saved, or its sealed value does not open.
 *
 * Owns one fact: that a gateway cannot be asked because of its own credentials. It is a
 * GatewayUnavailable, so every caller already answers that the gateway is unavailable without a
 * call to the provider; it disables that gateway only. Its message names the setting, never a value.
 *
 * @since 0.2.0
 *
 * @api
 */
final class CredentialUnavailable extends GatewayUnavailable {

	/**
	 * The reason of a credential that was never saved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MISSING = 'missing';

	/**
	 * The reason of a credential whose sealed value does not open.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNREADABLE = 'unreadable';

	/**
	 * Why the credential is unavailable: MISSING or UNREADABLE.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Records why.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the reason is neither MISSING nor UNREADABLE.
	 *
	 * @param string          $reason   MISSING or UNREADABLE.
	 * @param string          $setting  The setting's name, as the gateway declared it.
	 * @param \Throwable|null $previous Optional. What kept it from opening. Default null.
	 */
	public function __construct( string $reason, string $setting, ?\Throwable $previous = null ) {
		if ( ! in_array( $reason, array( self::MISSING, self::UNREADABLE ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A programming error for the developer; never HTML.
			throw new \InvalidArgumentException( sprintf( 'A credential is unavailable because it is %1$s or %2$s, not %3$s.', self::MISSING, self::UNREADABLE, $reason ) );
		}

		parent::__construct( sprintf( 'The credential %1$s is %2$s.', $setting, $reason ), 0, $previous );

		$this->reason = $reason;
	}
}
