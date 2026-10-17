<?php
/**
 * NeverExpires: a gateway that never answers that an intent expired
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Answers nothing about an intent the provider cancelled, as if it were still deciding.
 *
 * The plant of rule 9: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class NeverExpires extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'not a declined authorization `expired`';

	/**
	 * Answers nothing for a cancelled intent.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query  The query.
	 * @param array<mixed> $intent The provider's intent.
	 * @return GatewayResult|null Null for a cancelled intent; the reference gateway's answer otherwise.
	 */
	protected function answerAbout( PaymentQuery $query, array $intent ): ?GatewayResult {
		return 'canceled' === ( $intent['status'] ?? null ) ? null : parent::answerAbout( $query, $intent );
	}
}
