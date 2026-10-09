<?php
/**
 * Tests that no code settles a refund claim without the capability that overrides what the plugin knows of the money
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Every call of RefundService::settleClaim() in the plugin's code is walked, and so is what each of the settlement's two entry points does first.
 *
 * The refund repository has a settleClaim() of its own, which ends a claim inside a transaction
 * the refund service opened; a call on `$this->refunds` inside the refund service is that one, and
 * is left out. Anywhere else, a property of that name may hold the service itself, so the call is
 * walked. Every other call is a settlement: the only one is the settle operation's service, and
 * both it and settleClaim() itself check `seocart_override_money_state` as their first statement,
 * so no path reaches a claim, the gateway or a write without the capability.
 *
 * Planted violations, each shown red and removed:
 *
 * - call `$this->settleClaim( $uuid, $statement, $actor )` from a new method of RefundService: the
 *   walk names the new caller;
 * - call `$this->refunds->settleClaim( $x, $x, $x )` from a new method of Orders: the walk names it,
 *   the receiver's name being left out only inside the refund service;
 * - in RefundService::settleClaim(), move the capability check below the schema gate's: the first
 *   statement is not the check.
 *
 * @since 0.2.0
 */
final class SettleClaimCallersTest extends TestCase {

	/**
	 * The file whose `$this->refunds` is the refund repository, whose settleClaim() ends a claim and is no settlement.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUND_SERVICE = 'src/Payment/Application/RefundService.php';

	/**
	 * The first statement of each entry point of the settlement: the capability check.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CHECK = '$this->authorizer->authorize($actor,self::SETTLE_CAPABILITY);';

	/**
	 * Tests that the only caller of the settlement in the plugin's code is the settle operation's service.
	 *
	 * @since 0.2.0
	 */
	public function test_the_only_caller_of_the_settlement_is_its_operation(): void {
		$callers = array();

		foreach ( PhpSource::files( 'src' ) as $path => $source ) {
			$tokens   = PhpSource::tokens( $source );
			$function = '';

			foreach ( $tokens as $at => $token ) {
				if ( T_FUNCTION === $token->id && T_STRING === ( $tokens[ $at + 1 ]->id ?? null ) ) {
					$function = $tokens[ $at + 1 ]->text;
				}

				if ( self::callsSettleClaim( $tokens, $at ) && ! ( self::REFUND_SERVICE === $path && 'refunds' === $tokens[ $at - 2 ]->text ) ) {
					$callers[] = $path . '::' . $function;
				}
			}
		}

		$this->assertSame( array( self::REFUND_SERVICE . '::settleRefundClaim' ), $callers );
	}

	/**
	 * Tests that both entry points of the settlement check the capability that overrides what the plugin knows of the money, as their first statement.
	 *
	 * @since 0.2.0
	 */
	public function test_each_entry_point_checks_the_capability_first(): void {
		$this->assertSame( 'seocart_override_money_state', RefundService::SETTLE_CAPABILITY );

		foreach ( array( 'settleClaim', 'settleRefundClaim' ) as $method ) {
			$this->assertSame( self::CHECK, self::firstStatement( $method ), "RefundService::{$method}() checks the capability first." );
		}
	}

	/**
	 * Tells whether a token is the name of a settleClaim() method called on an object.
	 *
	 * @since 0.2.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @param int         $at     Where the token is.
	 * @return bool True for `->settleClaim(` and `?->settleClaim(`.
	 *
	 * @phpstan-param list<\PhpToken> $tokens
	 */
	private static function callsSettleClaim( array $tokens, int $at ): bool {
		return 'settleClaim' === $tokens[ $at ]->text
			&& in_array( $tokens[ $at - 1 ]->id ?? null, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true )
			&& '(' === ( $tokens[ $at + 1 ]->text ?? '' );
	}

	/**
	 * Returns the first statement of a method of the refund service, its tokens joined without white space.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @return string The statement, up to its semicolon.
	 */
	private static function firstStatement( string $method ): string {
		$reflection = new \ReflectionMethod( RefundService::class, $method );
		$lines      = array_slice( explode( "\n", PhpSource::files( 'src/Payment/Application' )['src/Payment/Application/RefundService.php'] ), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 );
		$tokens     = PhpSource::tokens( '<?php ' . implode( "\n", $lines ) );
		$body       = array_slice( $tokens, (int) array_search( '{', array_map( static fn( \PhpToken $token ): string => $token->text, $tokens ), true ) + 1 );
		$statement  = '';

		foreach ( $body as $token ) {
			$statement .= $token->text;

			if ( ';' === $token->text ) {
				break;
			}
		}

		return $statement;
	}
}
