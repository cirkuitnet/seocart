<?php
/**
 * Actor: who is acting when an application service is asked to do something
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * The one who acts: a WordPress user, or a named system process bound to one.
 *
 * This class owns one fact: on whose authority a use case runs. Every application service that
 * checks a capability receives an Actor from the adapter that called it, and the Authorizer
 * checks the actor's user, never "whoever is logged in". The adapter names the actor
 * explicitly: a REST controller passes the current user, a WP-CLI command the user it runs as,
 * a job the user that scheduled it.
 *
 * - user(): a WordPress user acting in person. User 0 is a visitor who is not logged in, and
 *   holds no capability.
 * - system(): a process acting on a user's authority, such as a CLI command or a job. It is
 *   bound to a real user, whose capabilities are checked; the name only says which process
 *   acted, for the record. A system actor is never exempt from a check.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class Actor {

	/**
	 * The WordPress user whose capabilities are checked, 0 for a visitor.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $userId;

	/**
	 * The name of the process acting on the user's authority, or null for the user in person.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $systemName;

	/**
	 * Creates an actor. Use the named constructors.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $userId     The user whose capabilities are checked.
	 * @param string|null $systemName The acting process, or null for the user in person.
	 */
	private function __construct( int $userId, ?string $systemName ) {
		$this->userId     = $userId;
		$this->systemName = $systemName;
	}

	/**
	 * Creates the actor for a WordPress user acting in person.
	 *
	 * @since 0.1.0
	 *
	 * @param int $userId The user's id, or 0 for a visitor who is not logged in.
	 * @return self The actor.
	 *
	 * @throws \InvalidArgumentException When the id is negative.
	 */
	public static function user( int $userId ): self {
		if ( $userId < 0 ) {
			throw new \InvalidArgumentException( 'A user id is 0, for a visitor who is not logged in, or a positive number.' );
		}

		return new self( $userId, null );
	}

	/**
	 * Creates the actor for a process acting on a user's authority.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   What is acting, such as `cli` or the name of a job.
	 * @param int    $userId The user whose authority the process acts on; a real user, never 0.
	 * @return self The actor.
	 *
	 * @throws \InvalidArgumentException When the name is blank or the user id is not positive.
	 */
	public static function system( string $name, int $userId ): self {
		if ( '' === trim( $name ) ) {
			throw new \InvalidArgumentException( 'A system actor needs the name of the process that acts.' );
		}

		if ( $userId < 1 ) {
			throw new \InvalidArgumentException( 'A system actor acts on the authority of a real user, so it needs that user\'s id.' );
		}

		return new self( $userId, $name );
	}

	/**
	 * Returns the user whose capabilities are checked.
	 *
	 * @since 0.1.0
	 *
	 * @return int The user id, 0 for a visitor.
	 */
	public function userId(): int {
		return $this->userId;
	}

	/**
	 * Returns the name of the process acting on the user's authority.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The name, or null when the user acts in person.
	 */
	public function systemName(): ?string {
		return $this->systemName;
	}
}
