<?php
/**
 * Tests the step a provider asks of the shopper: what it refuses, and that its handle never shows in a dump
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * A next action is a redirect to an http or https page, or a handle for the provider's script, each bounded; only an answer that asks the shopper to act carries one.
 *
 * The URL is followed by the shopper's browser, so one that is not a web page, such as
 * `javascript:`, is refused when the gateway makes the step, before the plugin answers it to
 * anyone. The handle is the browser's key to the payment at the provider: a dump of the step
 * shows it masked.
 *
 * Planted violations, each shown red and removed: in NextAction::isUrl(), accept any scheme: the
 * `javascript:` step is made; in GatewayResult's constructor, drop the refusal of a next action on
 * an answer that is not `requires_action`: an approval carries one.
 *
 * @since 0.2.0
 */
final class NextActionTest extends TestCase {

	/**
	 * A handle as a provider gives one.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOKEN = 'pi_123_secret_456';

	/**
	 * Returns the steps that are refused, each with part of its refusal's message.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{\Closure(): NextAction, string}> The step to make, and part of its refusal.
	 */
	public static function refusals(): array {
		return array(
			'an unknown type'           => array( static fn(): NextAction => new NextAction( 'popup', 'https://pay.example.test/3ds' ), 'one of redirect, sdk' ),
			'a redirect with no URL'    => array( static fn(): NextAction => new NextAction( NextAction::REDIRECT, null, self::TOKEN ), 'names the page' ),
			'an sdk step with no token' => array( static fn(): NextAction => new NextAction( NextAction::SDK, 'https://pay.example.test/3ds' ), 'carries the handle' ),
			'a javascript: URL'         => array( static fn(): NextAction => NextAction::redirect( 'javascript:alert(1)' ), 'http or https' ),
			'a data: URL'               => array( static fn(): NextAction => NextAction::redirect( 'data:text/html,hi' ), 'http or https' ),
			'a relative URL'            => array( static fn(): NextAction => NextAction::redirect( '/checkout?step=3ds' ), 'http or https' ),
			'a URL with no host'        => array( static fn(): NextAction => NextAction::redirect( 'https:///3ds' ), 'http or https' ),
			'a URL with a space'        => array( static fn(): NextAction => NextAction::redirect( 'https://pay.example.test/3 ds' ), 'http or https' ),
			'a URL with a line break'   => array( static fn(): NextAction => NextAction::redirect( "https://pay.example.test/3ds\nSet-Cookie: x" ), 'http or https' ),
			'a URL too long'            => array( static fn(): NextAction => NextAction::redirect( 'https://pay.example.test/' . str_repeat( 'a', NextAction::URL_MAX_LENGTH - 24 ) ), 'at most 2048' ),
			'an empty token'            => array( static fn(): NextAction => NextAction::sdk( '' ), 'handle is 1 to 512' ),
			'a token too long'          => array( static fn(): NextAction => NextAction::sdk( str_repeat( 'a', NextAction::CLIENT_TOKEN_MAX_LENGTH + 1 ) ), 'handle is 1 to 512' ),
			'a token with a tab'        => array( static fn(): NextAction => NextAction::sdk( "pi_1\tsecret" ), 'handle is 1 to 512' ),
		);
	}

	/**
	 * Tests each refusal.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refusals
	 *
	 * @param \Closure $make    Makes the step.
	 * @param string   $message Part of the refusal's message.
	 *
	 * @phpstan-param \Closure(): NextAction $make
	 */
	public function test_a_step_the_browser_must_not_follow_is_refused( \Closure $make, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$make();
	}

	/**
	 * Tests the steps a provider may give: a redirect, with or without a handle, and a handle alone, each at its longest.
	 *
	 * @since 0.2.0
	 */
	public function test_the_steps_a_provider_gives_are_made(): void {
		$longest = 'https://pay.example.test/' . str_repeat( 'a', NextAction::URL_MAX_LENGTH - 25 );

		$this->assertSame( array( 'redirect', 'https://pay.example.test/3ds', null ), self::fields( NextAction::redirect( 'https://pay.example.test/3ds' ) ) );
		$this->assertSame( array( 'redirect', 'http://shop.example.test/?seocart_payment=1', self::TOKEN ), self::fields( NextAction::redirect( 'http://shop.example.test/?seocart_payment=1', self::TOKEN ) ) );
		$this->assertSame( array( 'sdk', null, self::TOKEN ), self::fields( NextAction::sdk( self::TOKEN ) ) );
		$this->assertSame( NextAction::URL_MAX_LENGTH, strlen( (string) NextAction::redirect( $longest )->url ) );
		$this->assertSame( NextAction::CLIENT_TOKEN_MAX_LENGTH, strlen( (string) NextAction::sdk( str_repeat( 'a', NextAction::CLIENT_TOKEN_MAX_LENGTH ) )->clientToken ) );
		$this->assertSame( 'HTTPS://pay.example.test/3ds', NextAction::redirect( 'HTTPS://pay.example.test/3ds' )->url, 'The scheme is compared without regard to case.' );
	}

	/**
	 * Tests that a dump of the step shows its handle masked, so a step dumped by mistake does not give the payment away.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dump_masks_the_handle(): void {
		$dump = print_r( NextAction::redirect( 'https://pay.example.test/3ds', self::TOKEN ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- The test reads what a dump shows.

		$this->assertStringNotContainsString( self::TOKEN, $dump );
		$this->assertStringContainsString( '[masked]', $dump );
		$this->assertStringContainsString( 'https://pay.example.test/3ds', $dump );
	}

	/**
	 * Tests that only an answer that asks the shopper to act carries a next action.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_request_to_act_carries_a_next_action(): void {
		$action = NextAction::sdk( self::TOKEN );
		$amount = Money::of( 3080, Currency::of( 'USD' ) );

		$this->assertSame( $action, ( new GatewayResult( 'stub', Operation::Authorize, Outcome::RequiresAction, 'intent', $amount, null, 'pi_123', null, null, $action ) )->nextAction );
		$this->assertNull( ( new GatewayResult( 'stub', Operation::Authorize, Outcome::RequiresAction, 'intent', $amount ) )->nextAction, 'A request to act may say nothing more.' );

		foreach ( array( Outcome::Approved, Outcome::Declined, Outcome::Pending ) as $outcome ) {
			try {
				new GatewayResult( 'stub', Operation::Authorize, $outcome, 'intent', $amount, 'ch_1', 'pi_123', null, null, $action );
				$this->fail( 'An answer ' . $outcome->value . ' carried a next action.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'asks the customer to act', $refused->getMessage() );
			}
		}
	}

	/**
	 * Returns a step's type, URL and handle.
	 *
	 * @since 0.2.0
	 *
	 * @param NextAction $action The step.
	 * @return array{0: string, 1: string|null, 2: string|null} The three.
	 */
	private static function fields( NextAction $action ): array {
		return array( $action->type, $action->url, $action->clientToken );
	}
}
