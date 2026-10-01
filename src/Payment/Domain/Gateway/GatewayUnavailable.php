<?php
/**
 * GatewayUnavailable: a payment provider could not be asked, or did not answer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a gateway when it has no answer: the network failed, the provider timed out or erred.
 *
 * Owns one fact: that a call to a provider ended without a result. Nothing was applied, so the
 * intent stays as it was, and the caller answers that the gateway is unavailable; the
 * reconciliation job asks the provider again later.
 *
 * @since 0.1.0
 */
final class GatewayUnavailable extends \RuntimeException {
}
