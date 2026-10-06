<?php
/**
 * Tests the shapes of the webhook endpoint a site needs and of a gateway's answer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Contracts\Payment\WebhookTarget;

/**
 * An endpoint reused carries no secret and one replaced or created carries its own; an endpoint id is one short line; a debug dump masks the secret; a target is a URL with a tag and at least one event.
 *
 * Planted violation, shown red and removed: in WebhookProvisioning's constructor, check only that
 * an endpoint reused carries no secret: an endpoint created without one is accepted.
 *
 * @since 0.2.0
 */
final class WebhookTypesTest extends TestCase {

	/**
	 * Returns answers a gateway may not give.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null}> The outcome, the endpoint id and the secret.
	 */
	public static function brokenAnswers(): array {
		return array(
			'reused with a secret'      => array( WebhookProvisioning::REUSED, 'we_1', 'whsec_x' ),
			'created without a secret'  => array( WebhookProvisioning::CREATED, 'we_1', null ),
			'replaced without a secret' => array( WebhookProvisioning::REPLACED, 'we_1', null ),
			'created with an empty one' => array( WebhookProvisioning::CREATED, 'we_1', '' ),
			'no endpoint id'            => array( WebhookProvisioning::CREATED, '', 'whsec_x' ),
			'an id of two lines'        => array( WebhookProvisioning::CREATED, "we_1\nwe_2", 'whsec_x' ),
			'an id too long'            => array( WebhookProvisioning::CREATED, str_repeat( 'w', WebhookProvisioning::ENDPOINT_ID_MAX_LENGTH + 1 ), 'whsec_x' ),
			'an outcome that is none'   => array( 'kept', 'we_1', null ),
		);
	}

	/**
	 * Tests that each broken answer is refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider brokenAnswers
	 *
	 * @param string      $outcome    The outcome.
	 * @param string      $endpointId The endpoint id.
	 * @param string|null $secret     The secret.
	 */
	public function test_a_broken_answer_is_refused( string $outcome, string $endpointId, ?string $secret ): void {
		$this->expectException( \InvalidArgumentException::class );

		new WebhookProvisioning( $outcome, $endpointId, $secret );
	}

	/**
	 * Tests the answers a gateway gives, and that a debug dump masks the secret.
	 *
	 * @since 0.2.0
	 */
	public function test_an_answer_keeps_what_was_done_and_masks_its_secret(): void {
		$created = new WebhookProvisioning( WebhookProvisioning::CREATED, 'we_2', 'whsec_planted', array( 'we_1' ), 1 );
		$reused  = new WebhookProvisioning( WebhookProvisioning::REUSED, 'we_2' );

		$this->assertSame( array( 'whsec_planted', array( 'we_1' ), 1 ), array( $created->signingSecret, $created->removed, $created->elsewhere ) );
		$this->assertNull( $reused->signingSecret );
		$this->assertStringNotContainsString( 'whsec_planted', print_r( $created, true ), 'A debug dump shows the secret.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- The dump is the subject of the test.
		$this->assertSame( '[redacted]', $created->__debugInfo()['signingSecret'] );
	}

	/**
	 * Tests the target's shape: a URL, the owner tag and at least one event.
	 *
	 * @since 0.2.0
	 */
	public function test_a_target_is_a_url_with_its_tag_and_events(): void {
		$target = new WebhookTarget( 'https://shop.example/wp-json/seocart/v1/webhooks/stripe/live', Mode::Live, '0199713c-4d7b-7a3c-9e2b-3c4d5e6f7a8b', array( 'payment_intent.succeeded' ), false );

		$held = new WebhookTarget( 'https://shop.example/w', Mode::Test, 'uuid', array( 'e' ), true, 'we_1' );

		$this->assertSame( array( Mode::Live, false, null ), array( $target->mode, $target->secretHeld, $target->endpointId ) );
		$this->assertSame( array( true, 'we_1' ), array( $held->secretHeld, $held->endpointId ) );

		$broken = array(
			'an endpoint without its secret' => static fn() => new WebhookTarget( 'https://shop.example/w', Mode::Test, 'uuid', array( 'e' ), false, 'we_1' ),
			'an empty endpoint id'           => static fn() => new WebhookTarget( 'https://shop.example/w', Mode::Test, 'uuid', array( 'e' ), true, '' ),
			'an endpoint id of two lines'    => static fn() => new WebhookTarget( 'https://shop.example/w', Mode::Test, 'uuid', array( 'e' ), true, "we_1\nwe_2" ),
			'no URL'                         => static fn() => new WebhookTarget( 'not a url', Mode::Test, 'uuid', array( 'e' ), false ),
			'no install'                     => static fn() => new WebhookTarget( 'https://shop.example/w', Mode::Test, ' ', array( 'e' ), false ),
			'no events'                      => static fn() => new WebhookTarget( 'https://shop.example/w', Mode::Test, 'uuid', array(), false ),
			'an empty event'                 => static fn() => new WebhookTarget( 'https://shop.example/w', Mode::Test, 'uuid', array( '' ), false ),
		);

		foreach ( $broken as $case => $build ) {
			try {
				$build();
				$this->fail( sprintf( 'A target with %s was accepted.', $case ) );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'webhook target', $refused->getMessage(), $case );
			}
		}
	}
}
