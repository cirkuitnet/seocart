<?php
/**
 * Tests who can be an actor
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\Actor;

/**
 * Proves the two kinds of actor, and that a system actor is always bound to a real user.
 *
 * @since 0.1.0
 */
final class ActorTest extends TestCase {

	/**
	 * Tests a user acting in person, and a visitor who is not logged in.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_acts_in_person(): void {
		$this->assertSame( 5, Actor::user( 5 )->userId() );
		$this->assertNull( Actor::user( 5 )->systemName() );
		$this->assertSame( 0, Actor::user( 0 )->userId(), 'User 0 is a visitor who is not logged in.' );
	}

	/**
	 * Tests a process acting on a user's authority.
	 *
	 * @since 0.1.0
	 */
	public function test_a_system_actor_is_bound_to_a_user(): void {
		$actor = Actor::system( 'cli', 5 );

		$this->assertSame( 5, $actor->userId(), 'The bound user is the one whose capabilities are checked.' );
		$this->assertSame( 'cli', $actor->systemName() );
	}

	/**
	 * Tests the actors that cannot exist.
	 *
	 * A system actor bound to no user would hold no capability, so building one is a mistake,
	 * not a way to act without authority.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider impossibleActors
	 *
	 * @param callable(): Actor $build Builds the actor.
	 */
	public function test_impossible_actors_are_refused( callable $build ): void {
		$this->expectException( \InvalidArgumentException::class );

		$build();
	}

	/**
	 * Provides constructions that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{callable(): Actor}>
	 */
	public function impossibleActors(): array {
		return array(
			'a negative user id'             => array( static fn(): Actor => Actor::user( -1 ) ),
			'a system actor with no name'    => array( static fn(): Actor => Actor::system( ' ', 5 ) ),
			'a system actor bound to no one' => array( static fn(): Actor => Actor::system( 'cli', 0 ) ),
		);
	}
}
