<?php
/**
 * FixtureReservationReason: a fixture enum, to prove an enum-typed event property documents cleanly
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests\Fixtures\Events;

/**
 * Why a fixture reservation was made.
 *
 * Fixture only.
 *
 * @since 0.1.0
 */
enum FixtureReservationReason: string {

	/**
	 * A person chose to reserve it.
	 *
	 * @since 0.1.0
	 */
	case Manual = 'manual';

	/**
	 * A process reserved it on a user's authority.
	 *
	 * @since 0.1.0
	 */
	case Automatic = 'automatic';
}
