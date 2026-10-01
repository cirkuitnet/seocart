<?php
/**
 * PlacementKernel: the kernel's container as the placement tests run it, in the test's process or in a probe of its own
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Cart\Application\CartTokens;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Platform\RateLimiter\TrustedClientIp;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\KernelContainer;

/**
 * Builds the kernel's own container over a connection, with the five replacements every placement test makes.
 *
 * Owns one fact: what a placement test replaces in the production wiring. The transaction manager
 * is the connection itself (no schema gate); the cart token is the test's FakeCartTokens; the
 * gateway is the stub wrapped in a RecordingGateway, which records the transaction depth of every
 * call; the rate limiter counts in its table; and the publisher wakes the callable the test gives,
 * never a drainer. PlacementTestCase builds its containers here, and so does the placement probe,
 * so a placement in a process of its own is wired exactly as one in the test's.
 *
 * @since 0.1.0
 */
final class PlacementKernel {

	/**
	 * Returns the client identities of every placement test: one address, one user agent.
	 *
	 * @since 0.1.0
	 *
	 * @return ClientIdentities The identities.
	 */
	public static function identities(): ClientIdentities {
		return new ClientIdentities( new TrustedClientIp( array( 'REMOTE_ADDR' => '192.0.2.10' ) ), static fn(): string => 'cart tests' );
	}

	/**
	 * Builds the container.
	 *
	 * @since 0.1.0
	 *
	 * @param Database         $db         The connection.
	 * @param FakeCartTokens   $tokens     The cart token the request presents.
	 * @param ClientIdentities $identities Who the client is, for the rate limits.
	 * @param callable         $wake       Runs after a commit that stored events.
	 * @param callable         $report     Receives every report: a code (string) and its context (array).
	 * @param array            $more       Optional. Further replacements, which win, such as another
	 *                                     gateway. Default none.
	 * @return Container The container.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param array<string, callable(Container): object>   $more
	 */
	public static function over( Database $db, FakeCartTokens $tokens, ClientIdentities $identities, callable $wake, callable $report, array $more = array() ): Container {
		return KernelContainer::build(
			$db,
			$report,
			$more + array(
				TransactionManager::class => static fn(): TransactionManager => $db,
				CartTokens::class         => static fn(): CartTokens => $tokens,
				PaymentGateway::class     => static fn(): PaymentGateway => new RecordingGateway( new StubGateway(), $db ),
				ClientIdentities::class   => static fn(): ClientIdentities => $identities,
				RateLimiter::class        => static fn(): RateLimiter => new TableRateLimiter( $db ),
				EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher( $db, new Outbox( $db ), $c->get( HookBridge::class ), new EventCatalog( Modules::EVENT_CLASSES ), $c->get( CorrelationId::class ), $wake ),
			)
		);
	}
}
