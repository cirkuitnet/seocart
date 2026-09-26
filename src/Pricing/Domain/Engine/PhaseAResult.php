<?php
/**
 * PhaseAResult: what the first phase of a calculation hands to the quote step and the second phase
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\Intent\PromotionIntent;
use SEOCart\Pricing\Domain\Totals\CalculationTrace;

defined( 'ABSPATH' ) || exit;

/**
 * The resolved lines, the intents that apply to them, and the trace so far.
 *
 * Owns one fact: the output of the first phase, which the quotes are taken for. The lines include
 * any a promotion added, and the intents are the ones evaluated on those lines, with every
 * add-a-line intent already honoured or dropped. The packages fingerprint names the lines, so
 * the second phase can refuse quotes taken for other ones.
 *
 * @since 0.1.0
 */
final readonly class PhaseAResult {

	/**
	 * Holds the result.
	 *
	 * @since 0.1.0
	 *
	 * @param CalculationInput $input   The input the phase ran on.
	 * @param array            $lines   The resolved lines, in cart order, added lines last.
	 * @param array            $intents The intents to apply, in order; no add-a-line intent among them.
	 * @param CalculationTrace $trace   The trace so far.
	 *
	 * @phpstan-param list<ResolvedLine>    $lines
	 * @phpstan-param list<PromotionIntent> $intents
	 */
	public function __construct(
		public CalculationInput $input,
		public array $lines,
		public array $intents,
		public CalculationTrace $trace
	) {
	}

	/**
	 * Returns the fingerprint of what is shipped: each line's key, variant and quantity, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return string A SHA-256 hex digest.
	 */
	public function packagesFingerprint(): string {
		return hash(
			'sha256',
			implode(
				"\n",
				array_map( static fn( ResolvedLine $resolved ): string => $resolved->line->key . "\t" . $resolved->line->variantId . "\t" . $resolved->line->quantity, $this->lines )
			)
		);
	}
}
