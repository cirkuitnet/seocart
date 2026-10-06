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
	 * What a request that asks for no line and not the shipping is: nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOTHING_ASKED = 'nothing_asked';

	/**
	 * What a request that names a line twice is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LINE_REPEATED = 'line_repeated';

	/**
	 * The lines asked for, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @var list<RefundLineRequest>
	 */
	public array $lines;

	/**
	 * What the person who asks wrote about the refund; null for nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	public ?string $note;

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The note.
	 *
	 * @throws \InvalidArgumentException When it asks for nothing, names a line twice, or its reason is not a lowercase snake_case word.
	 *
	 * @param string              $orderUuid  The order's public identifier.
	 * @param RefundLineRequest[] $lines      The units of each line to refund; a line at most once.
	 * @param bool                $shipping   Whether to give back what is left of the shipping.
	 * @param string              $reasonCode Why, a lowercase snake_case word of at most 64 characters, such as `customer_return`.
	 * @param string|null         $note       Optional. What the person who asks wrote about it; an empty note is none. Default null.
	 *
	 * @phpstan-param list<RefundLineRequest> $lines
	 */
	public function __construct(
		public string $orderUuid,
		array $lines,
		public bool $shipping,
		public string $reasonCode,
		?string $note = null
	) {
		$problem = self::problem( array_map( static fn( RefundLineRequest $line ): string => $line->lineUuid, $lines ), $shipping );

		if ( self::NOTHING_ASKED === $problem ) {
			throw new \InvalidArgumentException( 'A refund gives back some units of a line, or the shipping, or both.' );
		}

		if ( self::LINE_REPEATED === $problem ) {
			throw new \InvalidArgumentException( 'A refund names each line once, with all the units it returns of it.' );
		}

		if ( 1 !== preg_match( self::REASON_PATTERN, $reasonCode ) ) {
			throw new \InvalidArgumentException( 'A refund\'s reason is a lowercase snake_case word of at most 64 characters, such as customer_return.' );
		}

		$this->lines = $lines;
		$this->note  = '' === $note ? null : $note;
	}

	/**
	 * Tells what is wrong with a request, if anything: that it asks for nothing, or names a line twice.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lineUuids The lines asked for, in the order given.
	 * @param bool     $shipping  Whether the shipping is asked for.
	 * @return string|null NOTHING_ASKED, LINE_REPEATED, or null for a request that can be made.
	 *
	 * @phpstan-param list<string> $lineUuids
	 */
	public static function problem( array $lineUuids, bool $shipping ): ?string {
		if ( array() === $lineUuids && ! $shipping ) {
			return self::NOTHING_ASKED;
		}

		return count( array_unique( $lineUuids ) ) === count( $lineUuids ) ? null : self::LINE_REPEATED;
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

	/**
	 * Returns the request in its canonical form: what a retry under the same idempotency key must ask again, whatever order it names its lines in.
	 *
	 * The order; each line with its units and whether they go back into stock, in the order of the
	 * lines' identifiers; the shipping; the reason; and the note.
	 *
	 * @since 0.2.0
	 *
	 * @return array{order_uuid: string, lines: list<array{line_uuid: string, quantity: int, restock: bool}>, shipping: bool, reason_code: string, note: string|null} The request.
	 */
	public function canonical(): array {
		$lines = array_map(
			static fn( RefundLineRequest $line ): array => array(
				'line_uuid' => $line->lineUuid,
				'quantity'  => $line->quantity,
				'restock'   => $line->restock,
			),
			$this->lines
		);

		usort( $lines, static fn( array $one, array $other ): int => strcmp( $one['line_uuid'], $other['line_uuid'] ) );

		return array(
			'order_uuid'  => $this->orderUuid,
			'lines'       => $lines,
			'shipping'    => $this->shipping,
			'reason_code' => $this->reasonCode,
			'note'        => $this->note,
		);
	}
}
