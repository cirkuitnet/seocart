<?php
/**
 * IdempotencyProfile: how a provider keeps idempotency keys, and whether it can be searched by the plugin's references
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
 * A provider's facts about keys and lookups, as the plugin needs them to decide what an answer means.
 *
 * Owns one fact: how far the plugin may trust a provider's "not found". The plugin accepts that
 * answer about an intent only from a provider that can be searched by the intent's uuid, and only
 * once the intent is older than both the search delay and the reconciliation's stale threshold,
 * by the database's clock; before that, the uuid may simply not be findable yet.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class IdempotencyProfile {

	/**
	 * Records the profile.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When a number of seconds is negative.
	 *
	 * @param int|null $keyRetentionSeconds How long the provider keeps an idempotency key, in seconds; null when it has none.
	 * @param bool     $searchable          Whether the provider can be searched by the plugin's references, such as the intent's uuid.
	 * @param int      $searchDelaySeconds  How long after a call its reference becomes findable by a search, in seconds.
	 */
	public function __construct(
		public ?int $keyRetentionSeconds,
		public bool $searchable,
		public int $searchDelaySeconds
	) {
		if ( ( null !== $keyRetentionSeconds && $keyRetentionSeconds < 0 ) || $searchDelaySeconds < 0 ) {
			throw new \InvalidArgumentException( 'An idempotency profile counts seconds from 0 up.' );
		}
	}
}
