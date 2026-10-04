<?php
/**
 * GatewayUnavailable: a payment provider could not be asked, or did not answer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a gateway when it has no answer: the network failed, the provider timed out or erred, or the gateway's credentials cannot be read.
 *
 * Owns one fact: that a call to a provider ended without a result. Nothing was applied, so the
 * intent stays as it was, and the caller answers that the gateway is unavailable; the
 * reconciliation job asks the provider again later.
 *
 * @since 0.1.0
 * @since 0.2.0 Moved to the public contract, and open to CredentialUnavailable.
 *
 * @api
 */
class GatewayUnavailable extends \RuntimeException {
}
