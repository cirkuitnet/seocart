<?php
/**
 * RefundRequest: what a merchant asks a refund of an order to give back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * A request to refund an order: some units of some of its lines, optionally what is left of its shipping, and why.
 *
 * Owns one fact: what a refund may be asked for. It names units, never an amount: the money is
 * the share of the order's stored figures those units stand for, in the order's currency and at
 * its own frozen rate. A refund of an amount alone has no line or tax component to return a share
 * of, so it cannot be asked for here; it would need its own allocation rule.
 *
 * @since 0.1.0
 */
final readonly class RefundRequest {

	/**
	 * A reason: a lowercase snake_case word that fits `refunds.reason_code`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REASON_PATTERN = '/^[a-z][a-z0-9_]{0,63}\z/';

	/**
	 * The lines asked for, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @var list<RefundLineRequest>
	 */
	public array $lines;

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When it asks for nothing, names a line twice, or its reason is not a lowercase snake_case word.
	 *
	 * @param string              $orderUuid  The order's public identifier.
	 * @param RefundLineRequest[] $lines      The units of each line to refund; a line at most once.
	 * @param bool                $shipping   Whether to give back what is left of the shipping.
	 * @param string              $reasonCode Why, a lowercase snake_case word of at most 64 characters, such as `customer_return`.
	 *
	 * @phpstan-param list<RefundLineRequest> $lines
	 */
	public function __construct(
		public string $orderUuid,
		array $lines,
		public bool $shipping,
		public string $reasonCode
	) {
		if ( array() === $lines && ! $shipping ) {
			throw new \InvalidArgumentException( 'A refund gives back some units of a line, or the shipping, or both.' );
		}

		$uuids = array_map( static fn( RefundLineRequest $line ): string => $line->lineUuid, $lines );

		if ( count( array_unique( $uuids ) ) !== count( $uuids ) ) {
			throw new \InvalidArgumentException( 'A refund names each line once, with all the units it returns of it.' );
		}

		if ( 1 !== preg_match( self::REASON_PATTERN, $reasonCode ) ) {
			throw new \InvalidArgumentException( 'A refund\'s reason is a lowercase snake_case word of at most 64 characters, such as customer_return.' );
		}

		$this->lines = $lines;
	}

	/**
	 * Returns the public identifiers of the lines asked for.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The line uuids, in the order given.
	 */
	public function lineUuids(): array {
		return array_map( static fn( RefundLineRequest $line ): string => $line->lineUuid, $this->lines );
	}
}
