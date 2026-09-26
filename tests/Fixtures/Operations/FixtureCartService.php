<?php
/**
 * FixtureCartService: serves the fixture cart's operations as a cart service would use the token seam
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations;

use SEOCart\Cart\Application\CartTokens;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;

/**
 * Records every call, and hands out a token the way a cart service does: when a write finds no
 * token on the request, it creates the cart and issues the cart's token.
 *
 * The read issues a token too, which a real service must not do, so a test can show that the
 * response to a read never carries one. A write with `fail` set to `yes` issues a token and then
 * fails, so a test can show that a failed response carries none either.
 *
 * @since 0.1.0
 */
final class FixtureCartService {

	/**
	 * The cart-token seam.
	 *
	 * @since 0.1.0
	 *
	 * @var CartTokens
	 */
	private CartTokens $tokens;

	/**
	 * Every call: the operation's method and the user it ran for.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{method: string, user_id: int}>
	 */
	public array $calls = array();

	/**
	 * The tokens issued, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<CartToken>
	 */
	public array $issued = array();

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 *
	 * @param CartTokens $tokens The cart-token seam.
	 */
	public function __construct( CartTokens $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Adds a line: creates the cart when the request carries no token.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException When the input asks the write to fail.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @param Actor                $actor Who acts.
	 * @return array<string, mixed> The outcome.
	 */
	public function addLine( array $input, Actor $actor ): array {
		return $this->call( 'addLine', $input, $actor );
	}

	/**
	 * Changes a line of an existing cart.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException When the input asks the write to fail.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @param Actor                $actor Who acts.
	 * @return array<string, mixed> The outcome.
	 */
	public function changeLine( array $input, Actor $actor ): array {
		return $this->call( 'changeLine', $input, $actor );
	}

	/**
	 * Reads the cart, and wrongly issues a token.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @param Actor                $actor Who acts.
	 * @return array<string, mixed> The outcome.
	 */
	public function readCart( array $input, Actor $actor ): array {
		return $this->call( 'readCart', $input, $actor );
	}

	/**
	 * Records a call, and issues a token when the request carries none.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException When the input asks the write to fail.
	 *
	 * @param string               $method The operation's method.
	 * @param array<string, mixed> $input  The prepared input.
	 * @param Actor                $actor  Who acts.
	 * @return array<string, mixed> The outcome.
	 */
	private function call( string $method, array $input, Actor $actor ): array {
		$this->calls[] = array(
			'method'  => $method,
			'user_id' => $actor->userId(),
		);

		$token = 'presented';

		if ( null === $this->tokens->presented() ) {
			$issued         = CartToken::generate();
			$this->issued[] = $issued;
			$token          = 'issued';

			$this->tokens->issue( $issued );
		}

		if ( 'yes' === ( $input['fail'] ?? 'no' ) ) {
			CodedException::raise(
				FixtureStockError::Insufficient,
				array(
					'requested' => 1,
					'available' => 0,
				)
			);
		}

		return array(
			'calls'   => count( $this->calls ),
			'user_id' => $actor->userId(),
			'token'   => $token,
		);
	}
}
