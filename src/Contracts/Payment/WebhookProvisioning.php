<?php
/**
 * WebhookProvisioning: what a gateway did to set up its provider's webhook endpoint, and the signing secret of one it created
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to its developer; they are never HTML.

/**
 * A gateway's answer to ProvisionsWebhooks::provisionWebhooks().
 *
 * Owns one fact: what was done. An endpoint reused keeps the signing secret the plugin holds, so
 * the answer carries none; one replaced or created carries its new signing secret, which the
 * plugin stores sealed and never shows. The answer also names the stale endpoints of this site
 * the gateway deleted, and counts this site's endpoints at another URL, which it left alone.
 * A debug dump shows the secret masked.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class WebhookProvisioning {

	/**
	 * The endpoint of this site and mode was kept: the plugin holds its secret.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REUSED = 'reused';

	/**
	 * The endpoint of this site and mode was deleted and created again: the plugin no longer held its secret.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPLACED = 'replaced';

	/**
	 * The site had no endpoint for the mode, and one was created.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATED = 'created';

	/**
	 * The longest endpoint id: what one line of a report and a stored reference hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const ENDPOINT_ID_MAX_LENGTH = 191;

	/**
	 * Records the answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the outcome is not one of the three, the endpoint id is
	 *                                   empty, longer than ENDPOINT_ID_MAX_LENGTH or more than one
	 *                                   line, a reused endpoint comes with a secret or a new one
	 *                                   without, the removed ids are not a list of text, or the
	 *                                   count elsewhere is negative.
	 *
	 * @param string      $outcome       REUSED, REPLACED or CREATED.
	 * @param string      $endpointId    The provider's id of the endpoint.
	 * @param string|null $signingSecret The new endpoint's signing secret: exactly when it was replaced or created.
	 * @param array       $removed       The ids of this site's stale endpoints for the mode that were deleted.
	 * @param int         $elsewhere     How many endpoints of this site and mode are at another URL, left alone.
	 *
	 * @phpstan-param list<string> $removed
	 */
	public function __construct(
		public string $outcome,
		public string $endpointId,
		#[\SensitiveParameter] public ?string $signingSecret = null,
		public array $removed = array(),
		public int $elsewhere = 0
	) {
		if ( ! in_array( $outcome, array( self::REUSED, self::REPLACED, self::CREATED ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'A webhook provisioning is %1$s, %2$s or %3$s.', self::REUSED, self::REPLACED, self::CREATED ) );
		}

		if ( '' === $endpointId || strlen( $endpointId ) > self::ENDPOINT_ID_MAX_LENGTH || 1 === preg_match( '/[\r\n]/', $endpointId ) ) {
			throw new \InvalidArgumentException( sprintf( 'A webhook endpoint id is one line of 1 to %d bytes.', self::ENDPOINT_ID_MAX_LENGTH ) );
		}

		if ( ( self::REUSED === $outcome ) !== ( null === $signingSecret ) || '' === $signingSecret ) {
			throw new \InvalidArgumentException( 'An endpoint replaced or created comes with its signing secret, and one reused with none.' );
		}

		if ( ! self::isListOfText( $removed ) || $elsewhere < 0 ) {
			throw new \InvalidArgumentException( 'The endpoints removed are a list of ids, and those elsewhere a count.' );
		}
	}

	/**
	 * Returns what a debug dump shows: everything, the signing secret masked.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The properties.
	 */
	public function __debugInfo(): array {
		return array(
			'outcome'       => $this->outcome,
			'endpointId'    => $this->endpointId,
			'signingSecret' => null === $this->signingSecret ? null : '[redacted]',
			'removed'       => $this->removed,
			'elsewhere'     => $this->elsewhere,
		);
	}

	/**
	 * Tells whether values are a list of non-empty text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $values The values, as a gateway gave them.
	 * @return bool True when they are.
	 */
	private static function isListOfText( array $values ): bool {
		if ( ! array_is_list( $values ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return false;
			}
		}

		return true;
	}
}
