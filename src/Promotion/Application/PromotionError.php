<?php
/**
 * PromotionError: the error catalog of the promotion module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Application;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors a promotion code and its usage can end in.
 *
 * Owns one fact: how the promotion module reports a refusal. A code a customer may not apply and
 * a promotion whose uses are exhausted answer with one message each, the same for every reason,
 * so that trying codes tells a stranger nothing about which codes exist, have ended or are used
 * up. Why a code was refused is recorded in the calculation's trace, never in the response.
 *
 * @since 0.1.0
 */
enum PromotionError: string implements ErrorCode {

	/**
	 * The code cannot be applied: no promotion has it, or its promotion does not apply to this cart now.
	 *
	 * @since 0.1.0
	 */
	case CodeInvalid = 'promotion.code_invalid';

	/**
	 * The promotion may not be used again: its usage limit was reached, or it stopped being active, while the order was placed.
	 *
	 * @since 0.1.0
	 */
	case LimitReached = 'promotion.limit_reached';

	/**
	 * A promotion's use count disagrees with its usage records, so a use cannot be given back.
	 *
	 * Internal: a customer gets the status and a generic message, and the promotion it names goes
	 * to the site's log for a person to correct.
	 *
	 * @since 0.1.0
	 */
	case UsageCorrupt = 'promotion.usage_corrupt';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::CodeInvalid,
				400,
				static fn(): string => __( 'That code cannot be applied.', 'seocart' )
			),
			new ErrorDefinition(
				self::LimitReached,
				409,
				static fn(): string => __( 'That code can no longer be used. Remove it to go on.', 'seocart' )
			),
			new ErrorDefinition(
				self::UsageCorrupt,
				500,
				static fn(): string =>
					/* translators: %1$s: The id of a promotion. */
					__( 'The use count of promotion %1$s does not match its usage records, so a use could not be given back. A person must correct it.', 'seocart' ),
				array( 'promotion_id' ),
				internal: true
			),
		);
	}
}
